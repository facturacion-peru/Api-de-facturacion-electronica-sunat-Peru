<?php

namespace App\DocumentLookup;

use App\Audit\AuditLogger;
use App\Enums\CustomerDocumentType;
use App\Models\Customer;
use App\Models\DocumentLookup;
use App\Models\DocumentLookupRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Búsqueda de clientes por DNI o RUC (spec 018): primero la empresa, luego la
 * tabla común de la plataforma (A-93 ⚖️) y al final apisperu. Lo que llega del
 * servicio deja al cliente verificado (A-92). Un lock por documento hace que
 * dos búsquedas simultáneas gasten una sola consulta (CE-004).
 */
class DocumentLookupService
{
    private const LOCK_SECONDS = 10;

    public function __construct(
        private ApisPeruClient $client,
        private AuditLogger $audit,
    ) {}

    public function lookup(User $actor, CustomerDocumentType $type, string $number): LookupResult
    {
        $existing = $this->companyCustomer($type, $number);

        if ($existing?->isVerified()) {
            return new LookupResult($existing, 'company');
        }

        return Cache::lock($this->lockKey($type, $number), self::LOCK_SECONDS)->block(self::LOCK_SECONDS - 2, function () use ($actor, $type, $number, $existing) {
            // Otra búsqueda simultánea pudo dejar al cliente verificado mientras se esperaba.
            $existing = $this->companyCustomer($type, $number);
            if ($existing?->isVerified()) {
                return new LookupResult($existing, 'company');
            }

            $known = DocumentLookup::query()->where('document_type', $type)->where('document_number', $number)->first();
            if ($known !== null) {
                return new LookupResult($this->saveCustomer($actor, $type, $number, $known, $existing), 'platform');
            }

            $outcome = $this->ask($actor, $type, $number);
            if ($outcome->status !== LookupStatus::Found) {
                // Un cliente manual sigue sirviendo; el motivo va aparte.
                return new LookupResult($existing, $existing ? 'company' : null, $outcome->status);
            }

            return new LookupResult($this->saveCustomer($actor, $type, $number, $this->remember($type, $number, $outcome->data), $existing), 'service');
        });
    }

    /**
     * «Actualizar datos» (A-94): siempre consulta el servicio. Si falla, el
     * cliente no cambia y se devuelve el motivo.
     */
    public function refresh(Customer $customer, User $actor): LookupStatus
    {
        $type = $customer->document_type;
        $number = $customer->document_number;

        return Cache::lock($this->lockKey($type, $number), self::LOCK_SECONDS)->block(self::LOCK_SECONDS - 2, function () use ($customer, $actor, $type, $number) {
            $outcome = $this->ask($actor, $type, $number);
            if ($outcome->status !== LookupStatus::Found) {
                return $outcome->status;
            }

            $this->saveCustomer($actor, $type, $number, $this->remember($type, $number, $outcome->data), $customer, refreshed: true);

            return LookupStatus::Found;
        });
    }

    private function ask(User $actor, CustomerDocumentType $type, string $number): LookupOutcome
    {
        $outcome = $this->client->find($type, $number);

        if ($outcome->status !== LookupStatus::Disabled) {
            DocumentLookupRequest::create([
                'company_id' => $actor->membership?->company_id,
                'user_id' => $actor->id,
                'document_type' => $type->value,
                'outcome' => $outcome->status->value,
            ]);
        }

        return $outcome;
    }

    private function remember(CustomerDocumentType $type, string $number, LookupData $data): DocumentLookup
    {
        return DocumentLookup::updateOrCreate(
            ['document_type' => $type, 'document_number' => $number],
            [
                'name' => $data->name,
                'address' => $data->address,
                'ubigeo' => $data->ubigeo,
                'ruc_status' => $data->rucStatus,
                'ruc_condition' => $data->rucCondition,
                'fetched_at' => now(),
            ],
        );
    }

    /** Crea o pone al día el cliente de la empresa con los datos verificados. */
    private function saveCustomer(User $actor, CustomerDocumentType $type, string $number, DocumentLookup $known, ?Customer $existing, bool $refreshed = false): Customer
    {
        return DB::transaction(function () use ($actor, $type, $number, $known, $existing, $refreshed) {
            $values = ['name' => $known->name, 'address' => $known->address, 'verified_at' => $known->fetched_at];

            if ($existing === null) {
                $customer = Customer::create([
                    ...$values,
                    'company_id' => $actor->membership->company_id,
                    'document_type' => $type,
                    'document_number' => $number,
                    'created_by' => $actor->id,
                ]);
                $this->audit->record('customer.created', $customer, [
                    'document_type' => $type->value, 'document_number' => $number, 'name' => $customer->name, 'verified' => true,
                ], actor: $actor);

                return $customer;
            }

            $wasVerified = $existing->isVerified();
            $existing->fill($values);
            $changes = AuditLogger::diff($existing);
            $existing->save();

            if (! $wasVerified) {
                $this->audit->record('customer.verified', $existing, $changes, actor: $actor);
            } elseif ($refreshed) {
                $this->audit->record('customer.refreshed', $existing, $changes, actor: $actor);
            }

            return $existing;
        });
    }

    private function companyCustomer(CustomerDocumentType $type, string $number): ?Customer
    {
        return Customer::query()->where('document_type', $type)->where('document_number', $number)->first();
    }

    private function lockKey(CustomerDocumentType $type, string $number): string
    {
        return "document-lookup:{$type->value}:{$number}";
    }
}

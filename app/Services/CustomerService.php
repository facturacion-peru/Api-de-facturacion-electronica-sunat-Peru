<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\Customer;
use App\Models\User;

/** Clientes de la empresa (spec 005, HU-5). Se registran al vender y no se borran (A-34). */
class CustomerService
{
    public function __construct(private AuditLogger $audit) {}

    /** @param  array{document_type: string, document_number: string, name: string, address?: ?string}  $data */
    public function create(array $data, User $actor): Customer
    {
        $customer = Customer::create([
            ...$data,
            'company_id' => $actor->membership->company_id,
            'created_by' => $actor->id,
        ]);

        $this->audit->record('customer.created', $customer, [
            'document_type' => $customer->document_type->value,
            'document_number' => $customer->document_number,
            'name' => $customer->name,
        ], actor: $actor);

        return $customer;
    }

    /**
     * Solo el administrador (lo controla la request). Los comprobantes ya
     * emitidos conservan su copia de los datos (RF-006).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data, User $actor): Customer
    {
        $customer->fill($data);
        $changes = AuditLogger::diff($customer);

        if ($changes !== []) {
            $customer->save();
            $this->audit->record('customer.updated', $customer, $changes, actor: $actor);
        }

        return $customer;
    }
}

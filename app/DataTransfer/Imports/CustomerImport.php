<?php

namespace App\DataTransfer\Imports;

use App\Audit\AuditLogger;
use App\DataTransfer\CustomerColumns;
use App\Enums\CustomerDocumentType;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerService;
use App\Validation\CustomerValidation;
use Illuminate\Support\Facades\Validator;

/**
 * Importación de clientes (spec 014, HU-5): cada fila se valida con las
 * reglas del alta manual. Al actualizar solo cambian el nombre y la
 * dirección; el tipo y el número identifican al cliente (A-70).
 */
class CustomerImport implements RowImport
{
    use ReportsRows;

    private const FIELDS = ['document_type' => 'tipo_documento', 'document_number' => 'numero_documento', 'name' => 'nombre', 'address' => 'direccion'];

    public function __construct(private CustomerService $customers) {}

    public function requiredColumns(): array
    {
        return CustomerColumns::REQUIRED;
    }

    public function knownColumns(): array
    {
        return array_keys(CustomerColumns::ALL);
    }

    public function readOnlyColumns(): array
    {
        return [];
    }

    public function analyze(array $records, array $columns, string $mode): Analysis
    {
        $analysis = new Analysis;
        $data = [];
        foreach ($records as [$row, $values]) {
            $data[$row] = $this->data($values, in_array('direccion', $columns, true));
        }

        $duplicates = $this->repeated(array_map(fn (array $d) => $d['document_number'] === null ? '' : $this->key($d), $data));
        $existing = Customer::query()->whereIn('document_number', array_filter(array_column($data, 'document_number')))->get()
            ->keyBy(fn (Customer $c) => $this->key(['document_type' => $c->document_type->value, 'document_number' => $c->document_number]));

        foreach ($data as $row => $input) {
            $key = $this->key($input);
            if ($input['document_number'] !== null && isset($duplicates[$key])) {
                $analysis->error($row, 'numero_documento', "El documento {$this->label($input)} se repite en las filas ".$this->listRows($duplicates[$key]).'.');

                continue;
            }

            $customer = $mode === 'upsert' ? $existing->get($key) : null;
            $validator = $this->validator($input, $customer);
            $messages = $validator->errors()->messages();
            if (isset($messages['document_number']) && $input['document_type'] === CustomerDocumentType::Dni->value
                && preg_match('/^\d{1,7}$/', (string) $input['document_number'])) {
                $messages['document_number'][0] .= ' Revisa que la columna esté como texto en Excel: se pierden los ceros a la izquierda.';
            }
            if ($this->collect($analysis, $row, $messages, self::FIELDS)) {
                continue;
            }

            if ($customer === null) {
                $analysis->rows[] = ['row' => $row, 'action' => 'create', 'data' => $input];
                $analysis->changes[] = ['row' => $row, 'action' => 'create', 'key' => $this->label($input), 'name' => $input['name']];

                continue;
            }

            $updates = $this->updatable($input);
            $copy = clone $customer;
            $copy->fill($updates);
            $fields = [];
            foreach (AuditLogger::diff($copy) as $field => $change) {
                $fields[self::FIELDS[$field]] = $change;
            }
            if ($fields === []) {
                $analysis->unchanged++;

                continue;
            }

            $analysis->rows[] = ['row' => $row, 'action' => 'update', 'id' => $customer->id, 'updated_at' => $customer->updated_at?->toIso8601String(), 'data' => $updates];
            $analysis->changes[] = ['row' => $row, 'action' => 'update', 'key' => $this->label($input), 'name' => $customer->name, 'fields' => $fields];
        }

        return $analysis;
    }

    public function apply(array $row, User $actor, array &$result): void
    {
        if ($row['action'] === 'update') {
            $customer = Customer::query()->whereKey($row['id'])->lockForUpdate()->first();
            if ($customer === null || $customer->updated_at?->toIso8601String() !== $row['updated_at']) {
                throw new ImportConflict("El cliente de la fila {$row['row']} cambió desde la vista previa.");
            }
            $this->customers->update($customer, $row['data'], $actor);
            $result['updated']++;

            return;
        }

        if ($this->validator($row['data'], null)->fails()) {
            throw new ImportConflict("La fila {$row['row']} ya no es válida: los clientes cambiaron desde la vista previa.");
        }
        $this->customers->create($row['data'], $actor);
        $result['created']++;
    }

    /**
     * @param  array<string, string>  $values
     * @return array{document_type: ?string, document_number: ?string, name: ?string, address?: ?string}
     */
    private function data(array $values, bool $hasAddress): array
    {
        $raw = trim($values['tipo_documento'] ?? '');
        $normalized = CustomerValidation::normalize([
            'document_number' => $values['numero_documento'] ?? '',
            'name' => $values['nombre'] ?? '',
            'address' => $values['direccion'] ?? '',
        ]);
        $data = [
            'document_type' => $raw === '' ? null : (CustomerColumns::documentType($raw)?->value ?? $raw),
            'document_number' => Cells::text($normalized['document_number']),
            'name' => Cells::text($normalized['name']),
        ];
        if ($hasAddress) {
            $data['address'] = Cells::text($normalized['address']);
        }

        return $data;
    }

    /** @param  array<string, ?string>  $input */
    private function validator(array $input, ?Customer $customer): \Illuminate\Validation\Validator
    {
        $validator = Validator::make($input, CustomerValidation::rules(false, $input, $customer?->id), CustomerValidation::messages(), self::FIELDS);
        $validator->after(CustomerValidation::after($input));

        return $validator;
    }

    /**
     * Solo nombre y dirección (si la columna está en el archivo).
     *
     * @param  array<string, ?string>  $input
     * @return array<string, ?string>
     */
    private function updatable(array $input): array
    {
        return array_intersect_key($input, array_flip(['name', 'address']));
    }

    /** @param  array<string, ?string>  $input */
    private function key(array $input): string
    {
        return ($input['document_type'] ?? '').'|'.($input['document_number'] ?? '');
    }

    /** @param  array<string, ?string>  $input */
    private function label(array $input): string
    {
        return (CustomerColumns::DOCUMENT_TYPES[$input['document_type'] ?? ''] ?? $input['document_type']).' '.$input['document_number'];
    }
}

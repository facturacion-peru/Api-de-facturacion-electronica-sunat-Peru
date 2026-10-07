<?php

namespace App\DataTransfer\Exports;

use App\DataTransfer\CustomerColumns;
use App\DataTransfer\ExportFile;
use App\DataTransfer\Spreadsheet;
use App\Models\Customer;

/** Clientes de la empresa (spec 014, HU-2). Son datos personales: solo el administrador (A-73). */
class CustomersExport
{
    public function build(string $format, ?string $search): ExportFile
    {
        $sheet = new Spreadsheet($format);
        $sheet->sheet('clientes', CustomerColumns::ALL);

        Customer::query()->listFilter($search)->orderBy('name')->orderBy('id')
            ->chunk(500, function ($customers) use ($sheet) {
                foreach ($customers as $customer) {
                    $sheet->row(CustomerColumns::values($customer));
                }
            });

        return $sheet->finish('clientes-'.today()->toDateString());
    }
}

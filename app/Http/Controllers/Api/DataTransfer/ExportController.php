<?php

namespace App\Http\Controllers\Api\DataTransfer;

use App\Audit\AuditLogger;
use App\DataTransfer\ExportFile;
use App\DataTransfer\Exports\CustomersExport;
use App\DataTransfer\Exports\ProductsExport;
use App\DataTransfer\Exports\SalesExport;
use App\Establishments\EstablishmentAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\DataTransfer\ExportCustomersRequest;
use App\Http\Requests\DataTransfer\ExportProductsRequest;
use App\Http\Requests\DataTransfer\ExportSalesRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportaciones de la spec 014. El archivo se genera al pedirlo, se envía y
 * se borra: no queda en el servidor, solo su registro en la auditoría.
 */
class ExportController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function products(ExportProductsRequest $request, ProductsExport $export, EstablishmentAccess $access): StreamedResponse
    {
        $establishments = $access->filter($request->user(), $request->filters()['establishment_id']);
        $file = $export->build($request->fileFormat(), [...$request->filters(), 'establishments' => $establishments]);

        return $this->download('export.products', $file, $request->fileFormat(), $request->filters());
    }

    public function customers(ExportCustomersRequest $request, CustomersExport $export): StreamedResponse
    {
        $filters = ['search' => $request->input('search')];
        $file = $export->build($request->fileFormat(), $filters['search']);

        return $this->download('export.customers', $file, $request->fileFormat(), $filters);
    }

    public function sales(ExportSalesRequest $request, SalesExport $export, EstablishmentAccess $access): StreamedResponse
    {
        $establishments = $access->filter($request->user(), $request->filters()['establishment_id']);
        $file = $export->build($request->fileFormat(), [...$request->filters(), 'establishments' => $establishments]);

        return $this->download('export.sales', $file, $request->fileFormat(), $request->filters());
    }

    /** @param  array<string, mixed>  $filters */
    private function download(string $action, ExportFile $file, string $format, array $filters): StreamedResponse
    {
        $this->audit->record($action, changes: ['format' => $format, 'filters' => $filters, 'rows' => $file->rows]);

        return response()->streamDownload(function () use ($file) {
            readfile($file->path);
            $file->delete();
        }, $file->filename, ['Content-Type' => $file->mimeType]);
    }
}

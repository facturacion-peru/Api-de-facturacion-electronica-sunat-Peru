<?php

namespace App\Http\Controllers\Api\Printing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Printing\PrintRequest;
use App\Printing\TestPagePrint;
use App\Tenancy\TenantContext;
use Illuminate\Http\Response;

/** Impresora térmica de la app (spec 015). El ticket y el comprobante se imprimen desde sus controladores. */
class PrintingController extends Controller
{
    public function testPage(PrintRequest $request, TenantContext $tenant, TestPagePrint $print): Response
    {
        return response($print->render($tenant->company(), $request->options()), 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }
}

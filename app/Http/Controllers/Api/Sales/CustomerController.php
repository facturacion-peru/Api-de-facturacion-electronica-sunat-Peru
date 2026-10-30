<?php

namespace App\Http\Controllers\Api\Sales;

use App\DocumentLookup\DocumentLookupService;
use App\DocumentLookup\LookupStatus;
use App\Enums\CustomerDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\IndexCustomerRequest;
use App\Http\Requests\Sales\LookupCustomerRequest;
use App\Http\Requests\Sales\StoreCustomerRequest;
use App\Http\Requests\Sales\UpdateCustomerRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Clientes de la empresa (HU-5). Buscar y registrar: todos; editar: administrador. */
class CustomerController extends Controller
{
    public function __construct(private CustomerService $customers) {}

    public function index(IndexCustomerRequest $request): ApiCollection
    {
        $customers = Customer::query()
            ->listFilter($request->input('search'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        return CustomerResource::make($this->customers->create($request->validated(), $request->user()))
            ->response()->setStatusCode(201);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        return CustomerResource::make($this->customers->update($customer, $request->validated(), $request->user()));
    }

    /**
     * Busca por DNI o RUC (spec 018): empresa, tabla común y servicio. Si el
     * servicio falla responde 200 sin cliente y con el motivo, para seguir a mano.
     */
    public function lookup(LookupCustomerRequest $request, DocumentLookupService $lookups): JsonResponse
    {
        $result = $lookups->lookup($request->user(), $request->documentType(), (string) $request->validated('document_number'));

        return response()->json([
            'success' => true,
            'data' => $result->customer ? CustomerResource::make($result->customer)->resolve($request) : null,
            'meta' => ['source' => $result->source, 'reason' => $result->reason?->value],
        ]);
    }

    /** «Actualizar datos» (spec 018, A-94): vuelve a consultar; si falla, 503 sin cambios. */
    public function refresh(Request $request, Customer $customer, DocumentLookupService $lookups): JsonResponse
    {
        if (! in_array($customer->document_type, [CustomerDocumentType::Dni, CustomerDocumentType::Ruc], true)) {
            throw ValidationException::withMessages(['document_type' => 'Solo se consultan DNI y RUC.']);
        }

        $status = $lookups->refresh($customer, $request->user());

        if ($status !== LookupStatus::Found) {
            return response()->json(['success' => false, 'message' => $status->message(), 'meta' => ['reason' => $status->value]], 503);
        }

        return CustomerResource::make($customer->fresh())->response();
    }
}

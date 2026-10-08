<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\IndexCustomerRequest;
use App\Http\Requests\Sales\StoreCustomerRequest;
use App\Http\Requests\Sales\UpdateCustomerRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;

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
}

<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IndexProductRequest;
use App\Http\Requests\Inventory\StoreProductRequest;
use App\Http\Requests\Inventory\UpdateProductRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;

/** Catálogo de productos de la empresa (HU-1). */
class ProductController extends Controller
{
    public function __construct(private ProductService $products) {}

    /**
     * Por defecto solo activos. El administrador puede pedir status=inactive
     * o status=all; el vendedor siempre ve solo los activos (HU-1.3).
     */
    public function index(IndexProductRequest $request): ApiCollection
    {
        $status = $request->user()->hasCompanyRole(CompanyRole::CompanyAdmin) ? $request->input('status', 'active') : 'active';
        $search = mb_strtolower(trim((string) $request->input('search')));

        $products = Product::query()
            ->withStock()
            ->when($status !== 'all', fn ($q) => $q->where('active', $status === 'active'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->whereRaw('LOWER(code) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(name) LIKE ?', ["%{$search}%"])))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->validated(), $request->user());

        return ProductResource::make($product)->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        return ProductResource::make($product);
    }

    /** Desactivar con stock se permite, pero se avisa (caso límite de la spec). */
    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $wasActive = $product->active;
        $product = $this->products->update($product, $request->validated(), $request->user());
        $resource = ProductResource::make($product);

        if ($wasActive && ! $product->active && $product->type->tracksStock() && bccomp($product->stock(), '0', 3) > 0) {
            $resource->additional(['warning' => "El producto queda desactivado con {$product->stock()} en stock."]);
        }

        return $resource;
    }
}

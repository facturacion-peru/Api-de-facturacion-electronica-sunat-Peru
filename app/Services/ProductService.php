<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\Product;
use App\Models\User;

/** Catálogo de productos (HU-1). El stock lo gestiona InventoryService. */
class ProductService
{
    public function __construct(private AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): Product
    {
        $product = Product::create($data);

        $this->audit->record('product.created', $product, [
            'code' => $product->code,
            'name' => $product->name,
        ], actor: $actor);

        return $product;
    }

    /** @param  array<string, mixed>  $data */
    public function update(Product $product, array $data, User $actor): Product
    {
        $product->fill($data);
        $changes = AuditLogger::diff($product);

        if ($changes !== []) {
            $product->save();
            $this->audit->record('product.updated', $product, $changes, actor: $actor);
        }

        return $product;
    }
}

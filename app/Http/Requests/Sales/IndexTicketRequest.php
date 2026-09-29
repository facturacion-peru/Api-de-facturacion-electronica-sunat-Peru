<?php

namespace App\Http\Requests\Sales;

use App\Enums\PaymentMethod;
use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros del listado de ventas (HU-4). */
class IndexTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->membership !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['sometimes', Rule::enum(TicketStatus::class)],
            'payment_method' => ['sometimes', Rule::enum(PaymentMethod::class)],
            'seller_id' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\QuoteUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST, needs lead_id + items) or update a draft (PATCH). */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'lead_id' => $creating ? ['required', 'integer', Rule::exists('leads', 'id')->whereNull('deleted_at')] : ['prohibited'],
            'items' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.unit' => ['required', Rule::enum(QuoteUnit::class)],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'vat_rate' => ['sometimes', 'numeric', Rule::in([0, 7, 19])],
            'valid_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'A quote needs at least one line item.',
            'items.min' => 'A quote needs at least one line item.',
            'items.*.quantity.gt' => 'Quantity must be greater than zero.',
            'vat_rate.in' => 'VAT rate must be 0, 7 or 19 %.',
        ];
    }
}

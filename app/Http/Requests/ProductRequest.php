<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->code ? mb_strtoupper(trim($this->code)) : null,
            'track_stock' => $this->boolean('track_stock'),
            'active' => $this->boolean('active'),
        ]);
    }

    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'code' => ['nullable', 'string', 'max:30', 'alpha_dash', Rule::unique('products', 'code')->ignore($product)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'gt:0', 'max:99999', 'decimal:0,2'],
            'track_stock' => ['boolean'],
            // El stock solo se fija al crear; luego se cambia con "Ajustar stock".
            'stock' => [Rule::excludeIf($product !== null), 'nullable', 'numeric', 'min:0', 'max:999999'],
            'stock_min' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'category_id' => 'categoría',
            'code' => 'código',
            'name' => 'nombre',
            'price' => 'precio',
            'stock_min' => 'stock mínimo',
        ];
    }
}

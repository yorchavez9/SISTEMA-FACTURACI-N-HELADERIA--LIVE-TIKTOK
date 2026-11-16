<?php

namespace App\Http\Requests;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Support\Settings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Valida la forma de la venta. Las reglas que dependen de precios/stock
 * (factura con RUC, boleta >= S/700, stock) se validan en SaleService
 * con los datos de la base de datos. Los precios nunca vienen del frontend.
 */
class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount_received' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'document_type' => ['required', Rule::enum(DocumentType::class)],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('document_type') !== DocumentType::Ninguno->value && ! Settings::billingConfigured()) {
                    $validator->errors()->add('document_type', 'La facturación electrónica no está configurada. Venda sin comprobante o configure el API.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Agregue al menos un producto.',
            'items.min' => 'Agregue al menos un producto.',
            'items.*.quantity.gt' => 'La cantidad debe ser mayor a cero.',
        ];
    }
}

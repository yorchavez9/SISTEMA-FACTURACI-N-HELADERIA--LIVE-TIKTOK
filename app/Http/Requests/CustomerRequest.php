<?php

namespace App\Http\Requests;

use App\Enums\IdentityDocType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'doc_number' => preg_replace('/\s+/', '', (string) $this->doc_number),
            'name' => mb_strtoupper(trim((string) $this->name)),
        ]);
    }

    public function rules(): array
    {
        $customer = $this->route('customer');
        $type = $this->input('doc_type');

        $docRules = match ($type) {
            IdentityDocType::Dni->value => ['digits:8'],
            IdentityDocType::Ruc->value => ['digits:11', 'regex:/^(10|15|17|20)\d{9}$/'],
            default => ['alpha_num', 'max:15'],
        };

        return [
            'doc_type' => ['required', Rule::in(array_map(fn ($c) => $c->value, IdentityDocType::forCustomers()))],
            'doc_number' => [
                'required', ...$docRules,
                Rule::unique('customers')->where('doc_type', $type)->ignore($customer),
            ],
            'name' => ['required', 'string', 'max:200'],
            'address' => [Rule::requiredIf($type === IdentityDocType::Ruc->value), 'nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
        ];
    }

    public function attributes(): array
    {
        return [
            'doc_type' => 'tipo de documento',
            'doc_number' => 'número de documento',
            'name' => 'nombre o razón social',
            'address' => 'dirección',
        ];
    }

    public function messages(): array
    {
        return [
            'doc_number.unique' => 'Ya existe un cliente con ese documento.',
            'doc_number.regex' => 'El RUC debe empezar con 10, 15, 17 o 20.',
            'address.required' => 'La dirección es obligatoria para clientes con RUC (factura).',
        ];
    }
}

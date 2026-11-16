<?php

namespace App\Http\Controllers\Settings;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSettingsController extends Controller
{
    private const KEYS = [
        'business_name', 'business_ruc', 'business_address', 'business_phone', 'ticket_footer',
        'default_document_type', 'allow_negative_stock',
    ];

    public function edit(): Response
    {
        $settings = Settings::all();

        return Inertia::render('settings/business', [
            'settings' => array_intersect_key($settings, array_flip(self::KEYS)) + [
                'allow_negative_stock' => Settings::bool('allow_negative_stock'),
            ],
            'documentTypes' => DocumentType::options(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'business_ruc' => ['nullable', 'digits:11'],
            'business_address' => ['nullable', 'string', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:30'],
            'ticket_footer' => ['nullable', 'string', 'max:200'],
            'default_document_type' => ['required', Rule::enum(DocumentType::class)],
            'allow_negative_stock' => ['boolean'],
        ], [], ['business_name' => 'nombre del negocio', 'business_ruc' => 'RUC']);

        Settings::set($data + ['allow_negative_stock' => false]);

        return back()->with('success', 'Configuración guardada.');
    }
}

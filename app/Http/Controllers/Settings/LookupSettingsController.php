<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Integrations\Consultas\DocumentLookupService;
use App\Integrations\Consultas\LookupException;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Configuración > Consulta DNI/RUC. */
class LookupSettingsController extends Controller
{
    public function edit(): Response
    {
        $token = Settings::get('lookup_token');

        return Inertia::render('settings/lookup', [
            'baseUrl' => Settings::get('lookup_base_url') ?: DocumentLookupService::DEFAULT_BASE_URL,
            'hasToken' => filled($token),
            // Solo los últimos 4 caracteres, para reconocerlo sin exponerlo.
            'tokenHint' => filled($token) ? '…'.mb_substr($token, -4) : null,
        ]);
    }

    public function update(Request $request, DocumentLookupService $lookup): RedirectResponse
    {
        $data = $request->validate([
            'base_url' => ['required', 'url:https'],
            'token' => [filled(Settings::get('lookup_token')) ? 'nullable' : 'required', 'string', 'max:500'],
        ], [], ['base_url' => 'URL del servicio', 'token' => 'token']);

        $values = ['lookup_base_url' => rtrim(trim($data['base_url']), '/')];
        if (filled($data['token'] ?? null)) {
            $values['lookup_token'] = trim($data['token']);
        }
        Settings::set($values);

        // Al guardar, prueba la conexión automáticamente.
        return $this->testResponse($lookup, 'Token guardado. ');
    }

    public function test(DocumentLookupService $lookup): RedirectResponse
    {
        return $this->testResponse($lookup);
    }

    public function destroy(): RedirectResponse
    {
        Settings::set(['lookup_token' => null]);

        return back()->with('success', 'Token eliminado. La consulta automática de DNI/RUC quedó desactivada.');
    }

    private function testResponse(DocumentLookupService $lookup, string $prefix = ''): RedirectResponse
    {
        try {
            $result = $lookup->ruc(DocumentLookupService::TEST_RUC);
        } catch (LookupException $e) {
            return back()->with('error', $prefix.'La prueba falló: '.$e->getMessage());
        }

        return back()->with('success', $prefix."Conexión correcta: RUC {$result['doc_number']} → {$result['name']}.");
    }
}

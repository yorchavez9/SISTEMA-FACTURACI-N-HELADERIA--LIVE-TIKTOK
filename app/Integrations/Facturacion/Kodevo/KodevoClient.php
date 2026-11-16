<?php

namespace App\Integrations\Facturacion\Kodevo;

use App\Integrations\Facturacion\Exceptions\ProviderAuthException;
use App\Integrations\Facturacion\Exceptions\ProviderUnavailableException;
use App\Integrations\Facturacion\Exceptions\ProviderValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP del API de facturación.
 * Autenticación: cabeceras X-Api-Key / X-Api-Secret en cada petición.
 * Nunca registra las credenciales en los logs.
 */
class KodevoClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $apiSecret,
        private int $timeout = 10,
    ) {}

    public function get(string $path, array $query = []): array
    {
        return $this->decode($this->send('get', $path, $query));
    }

    public function post(string $path, array $data = []): array
    {
        return $this->decode($this->send('post', $path, $data));
    }

    public function put(string $path, array $data = []): array
    {
        return $this->decode($this->send('put', $path, $data));
    }

    public function delete(string $path): array
    {
        return $this->decode($this->send('delete', $path));
    }

    /** Respuesta cruda (para descargas de PDF/XML/CDR). */
    public function raw(string $path, array $query = []): Response
    {
        return $this->send('get', $path, $query);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withHeaders([
                'X-Api-Key' => $this->apiKey,
                'X-Api-Secret' => $this->apiSecret,
            ])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout($this->timeout);
    }

    private function send(string $method, string $path, array $data = []): Response
    {
        try {
            $response = $this->request()->{$method}(ltrim($path, '/'), $data);
        } catch (ConnectionException $e) {
            Log::channel('facturacion')->warning("{$method} {$path}: sin conexión", ['error' => $e->getMessage()]);
            throw new ProviderUnavailableException('No se pudo conectar con el servicio de facturación.', previous: $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $body = $response->json() ?? ['body' => mb_substr($response->body(), 0, 500)];
        $message = $this->errorMessage($body) ?? "Error {$response->status()} del servicio de facturación.";

        Log::channel('facturacion')->warning("{$method} {$path}: HTTP {$response->status()}", ['response' => $body]);

        throw match (true) {
            in_array($response->status(), [401, 403], true) => new ProviderAuthException('Credenciales del API inválidas: '.$message, $body),
            $response->status() === 429, $response->serverError() => new ProviderUnavailableException($message, $body),
            default => new ProviderValidationException($message, $body),
        };
    }

    private function decode(Response $response): array
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];

        // El API envuelve todo en {"estado": "exito"|"error", "mensaje", "datos"}.
        if (($json['estado'] ?? null) === 'error') {
            Log::channel('facturacion')->warning('Respuesta con estado=error', ['response' => $json]);
            throw new ProviderValidationException($this->errorMessage($json) ?? 'El servicio de facturación devolvió un error.', $json);
        }

        return $json;
    }

    /** Extrae un mensaje legible de una respuesta de error (formato no documentado en la colección). */
    private function errorMessage(array $body): ?string
    {
        $message = $body['message'] ?? $body['mensaje'] ?? $body['error'] ?? null;
        if (is_array($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE);
        }

        $errors = $body['errors'] ?? $body['errores'] ?? null;
        if (is_array($errors) && $errors !== []) {
            $first = collect($errors)->flatten()->filter(fn ($v) => is_string($v))->take(3)->implode(' ');
            $message = trim(($message ? $message.' ' : '').$first);
        }

        return $message ?: null;
    }
}

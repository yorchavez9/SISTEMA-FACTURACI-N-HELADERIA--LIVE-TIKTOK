<?php

namespace App\Integrations\Facturacion;

use App\Enums\ElectronicDocumentType;
use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Models\SaleItem;

/**
 * Construye los JSON de emisión según la colección de Postman:
 *  - POST /boletas, POST /facturas: serie, fecha_emision, tipo_moneda, forma_pago, cliente, items
 *  - POST /notas-credito: + doc_afectado_*, cod_motivo, des_motivo
 * El correlativo lo asigna el proveedor. precio_unitario incluye IGV.
 */
class PayloadBuilder
{
    /** Cliente genérico para boletas sin identificar (válido para montos menores a S/ 700). */
    public const CONSUMIDOR_FINAL = [
        'tipo_doc' => '0',
        'num_doc' => '99999999',
        'razon_social' => 'CONSUMIDOR FINAL',
        'direccion' => null,
    ];

    public function forSale(Sale $sale, ElectronicDocumentType $type, string $series, array $customer): array
    {
        $payload = [
            'serie' => $series,
            'fecha_emision' => $sale->sold_at->toDateString(),
            'tipo_moneda' => 'PEN',
            'forma_pago' => 'Contado',
            'cliente' => $this->customer($customer),
            'items' => $this->items($sale),
        ];

        if ($type === ElectronicDocumentType::Factura) {
            $payload = ['serie' => $series, 'tipo_operacion' => '0101'] + $payload;
        }

        return $payload;
    }

    /** Nota de crédito motivo 01: anulación total de la operación. */
    public function creditNote(ElectronicDocument $original, string $series, string $reason): array
    {
        return $this->note($original, $series, '01', $reason ?: 'Anulación de la operación', $this->items($original->sale));
    }

    /**
     * Nota de crédito (POST /notas-credito) o débito (POST /notas-debito).
     *
     * @param  array<int, array<string, mixed>>  $items  en formato del API
     */
    public function note(ElectronicDocument $original, string $series, string $code, string $reason, array $items): array
    {
        return [
            'serie' => $series,
            'fecha_emision' => now()->toDateString(),
            'doc_afectado_tipo' => $original->type->value,
            'doc_afectado_serie' => $original->series,
            'doc_afectado_correlativo' => (string) $original->number,
            'cod_motivo' => $code,
            'des_motivo' => mb_substr($reason, 0, 250),
            'cliente' => $this->customer($original->customer_snapshot),
            'items' => $items,
        ];
    }

    /** Ítems de la venta: todos completos, o solo las cantidades indicadas [sale_item_id => cantidad]. */
    public function saleItems(Sale $sale, ?array $quantities = null): array
    {
        return $sale->items
            ->filter(fn (SaleItem $item) => $quantities === null || (float) ($quantities[$item->id] ?? 0) > 0)
            ->map(fn (SaleItem $item) => [
                'codigo' => $item->product_code,
                'descripcion' => $item->product_name,
                'unidad' => $item->unit,
                'cantidad' => $quantities === null ? (float) $item->quantity : (float) $quantities[$item->id],
                'precio_unitario' => (float) $item->unit_price,
                'tip_afe_igv' => $item->tax_affectation,
            ])->values()->all();
    }

    /** Una línea por monto (descuentos, intereses, penalidades). Monto con IGV incluido. */
    public function amountItem(string $description, float $amount): array
    {
        return [[
            'descripcion' => mb_substr($description, 0, 250),
            'unidad' => 'ZZ',
            'cantidad' => 1,
            'precio_unitario' => round($amount, 2),
            'tip_afe_igv' => '10',
        ]];
    }

    /** RA: POST /anulaciones. */
    public function voidCommunication(ElectronicDocument $document, string $reason): array
    {
        return [
            'fecha_generacion' => $document->issued_at->toDateString(),
            'fecha_comunicacion' => now()->toDateString(),
            'detalles' => [[
                'tipo_documento' => $document->type->value,
                'serie' => $document->series,
                'correlativo' => (string) $document->number,
                'motivo' => mb_substr($reason, 0, 100),
            ]],
        ];
    }

    /** RC de anulación: POST /resumenes con el id de la boleta en el proveedor. */
    public function voidSummary(ElectronicDocument $document, string $reason): array
    {
        return [
            'fecha_resumen' => $document->issued_at->toDateString(),
            'anular' => [[
                'id' => is_numeric($document->provider_id) ? (int) $document->provider_id : $document->provider_id,
                'motivo' => mb_substr($reason, 0, 100),
            ]],
        ];
    }

    /** Reemplaza el cliente en un payload ya guardado (corrección de rechazados). */
    public function withCustomer(array $payload, array $customer): array
    {
        $payload['cliente'] = $this->customer($customer);

        return $payload;
    }

    private function customer(array $customer): array
    {
        return array_filter([
            'tipo_doc' => (string) $customer['tipo_doc'],
            'num_doc' => (string) $customer['num_doc'],
            'razon_social' => mb_strtoupper((string) $customer['razon_social']),
            'direccion' => $customer['direccion'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function items(Sale $sale): array
    {
        return $this->saleItems($sale);
    }
}

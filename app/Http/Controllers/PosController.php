<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\IdentityDocType;
use App\Enums\PaymentMethod;
use App\Http\Requests\StoreSaleRequest;
use App\Integrations\Facturacion\ElectronicDocumentService;
use App\Models\Category;
use App\Models\Product;
use App\Services\SaleService;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(): Response
    {
        $billing = Settings::billingConfigured();

        return Inertia::render('pos/index', [
            'categories' => Category::query()->where('active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->active()
                ->whereHas('category', fn ($q) => $q->where('active', true))
                ->orderBy('name')
                ->get(['id', 'category_id', 'code', 'name', 'price', 'track_stock', 'stock']),
            'paymentMethods' => PaymentMethod::options(),
            'documentTypes' => collect(DocumentType::options())
                ->reject(fn ($o) => ! $billing && $o['value'] !== DocumentType::Ninguno->value)
                ->reject(fn ($o) => $o['value'] === DocumentType::Factura->value && ! Settings::canIssueFactura())
                ->values(),
            'defaultDocumentType' => $billing ? Settings::get('default_document_type') : DocumentType::Ninguno->value,
            'docTypes' => IdentityDocType::options(),
            'allowNegativeStock' => Settings::bool('allow_negative_stock'),
            'boletaIdThreshold' => SaleService::BOLETA_ID_THRESHOLD,
            'pdfFormat' => Settings::get('pdf_format', 'ticket-80'),
            'lastSale' => fn () => session('last_sale'),
        ]);
    }

    public function store(StoreSaleRequest $request, SaleService $sales, ElectronicDocumentService $documents): RedirectResponse
    {
        $sale = $sales->create($request->validated(), $request->user());

        $document = null;
        if ($sale->document_type !== DocumentType::Ninguno) {
            // La venta ya está guardada; si el proveedor falla, el comprobante queda pendiente de reintento.
            $document = $documents->issueForSale($sale, $sale->document_type);
        }

        return to_route('pos.index')->with('last_sale', [
            'id' => $sale->id,
            'code' => $sale->code,
            'total' => $sale->total,
            'change' => $sale->change,
            'payment_method' => $sale->payment_method->label(),
            'document' => $document ? [
                'id' => $document->id,
                'number' => $document->full_number,
                'type' => $document->type->label(),
                'status' => $document->status->value,
                'status_label' => $document->status->label(),
                'message' => $document->sunat_message,
                'has_pdf' => $document->isRegisteredInProvider(),
            ] : null,
        ]);
    }
}

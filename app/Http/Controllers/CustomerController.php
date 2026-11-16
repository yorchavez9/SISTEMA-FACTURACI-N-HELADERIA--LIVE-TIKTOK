<?php

namespace App\Http\Controllers;

use App\Enums\IdentityDocType;
use App\Http\Requests\CustomerRequest;
use App\Integrations\Consultas\DocumentLookupService;
use App\Integrations\Consultas\LookupException;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->value();

        return Inertia::render('customers/index', [
            'customers' => Customer::query()
                ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('doc_number', 'like', "{$search}%")->orWhere('name', 'like', "%{$search}%")))
                ->withCount('sales')
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'filters' => ['search' => $search],
            'docTypes' => IdentityDocType::options(),
        ]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        Customer::create($request->validated());

        return back()->with('success', 'Cliente registrado.');
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        return back()->with('success', 'Cliente actualizado.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        if ($customer->sales()->exists()) {
            return back()->with('error', 'El cliente tiene ventas registradas y no puede eliminarse.');
        }

        $customer->delete();

        return back()->with('success', 'Cliente eliminado.');
    }

    /** Búsqueda rápida para el POS (JSON). */
    public function search(Request $request): JsonResponse
    {
        $q = $request->string('q')->trim()->value();

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        return response()->json(
            Customer::query()
                ->where(fn ($query) => $query->where('doc_number', 'like', "{$q}%")->orWhere('name', 'like', "%{$q}%"))
                ->orderByRaw('doc_number = ? desc', [$q])
                ->orderBy('name')
                ->limit(8)
                ->get(['id', 'doc_type', 'doc_number', 'name', 'address'])
        );
    }

    /**
     * Consulta un DNI (8 dígitos) o RUC (11 dígitos): primero en clientes registrados,
     * luego en RENIEC/SUNAT vía api.json.pe. No guarda nada; el alta la hace quickStore.
     */
    public function lookup(Request $request, DocumentLookupService $service): JsonResponse
    {
        $number = preg_replace('/\D/', '', (string) $request->query('number'));
        $type = match (strlen($number)) {
            8 => IdentityDocType::Dni,
            11 => IdentityDocType::Ruc,
            default => null,
        };

        if (! $type) {
            return response()->json(['message' => 'Ingrese un DNI de 8 dígitos o un RUC de 11 dígitos.'], 422);
        }

        $existing = Customer::query()->where('doc_type', $type->value)->where('doc_number', $number)->first();
        if ($existing) {
            return response()->json([
                'source' => 'local',
                'customer' => $existing->only(['id', 'doc_type', 'doc_number', 'name', 'address']),
            ]);
        }

        if (! DocumentLookupService::configured()) {
            return response()->json(['source' => 'none', 'message' => 'Consulta automática no configurada.']);
        }

        try {
            $data = $type === IdentityDocType::Dni ? $service->dni($number) : $service->ruc($number);
        } catch (LookupException $e) {
            return response()->json(['message' => $e->getMessage(), 'not_found' => $e->notFound], $e->notFound ? 404 : 502);
        }

        return response()->json(['source' => 'api', 'data' => $data]);
    }

    /** Alta rápida desde el POS (JSON). */
    public function quickStore(CustomerRequest $request): JsonResponse
    {
        $customer = Customer::create($request->validated());

        return response()->json($customer->only(['id', 'doc_type', 'doc_number', 'name', 'address']), 201);
    }
}

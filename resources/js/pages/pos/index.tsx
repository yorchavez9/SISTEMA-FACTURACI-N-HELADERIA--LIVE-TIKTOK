import { CustomerPicker } from '@/components/customer-picker';
import { ReceiptViewer, type ReceiptSale } from '@/components/receipt-viewer';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { money, qty } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Customer, type Option } from '@/types';
import { Head, router } from '@inertiajs/react';
import { LoaderCircle, Minus, Plus, Search, ShoppingCart, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { toast } from 'sonner';

interface PosProduct {
    id: number;
    category_id: number;
    code: string;
    name: string;
    price: string;
    track_stock: boolean;
    stock: string;
}

interface Props {
    categories: { id: number; name: string }[];
    products: PosProduct[];
    paymentMethods: Option[];
    documentTypes: Option[];
    defaultDocumentType: string;
    docTypes: Option[];
    allowNegativeStock: boolean;
    boletaIdThreshold: number;
    lastSale?: ReceiptSale | null;
    pdfFormat: string;
}

type Line = { product: PosProduct; quantity: number };
type Action =
    | { type: 'add'; product: PosProduct }
    | { type: 'set'; id: number; quantity: number }
    | { type: 'remove'; id: number }
    | { type: 'clear' };

function cartReducer(lines: Line[], action: Action): Line[] {
    switch (action.type) {
        case 'add': {
            const existing = lines.find((l) => l.product.id === action.product.id);
            if (existing) return lines.map((l) => (l.product.id === action.product.id ? { ...l, quantity: l.quantity + 1 } : l));
            return [...lines, { product: action.product, quantity: 1 }];
        }
        case 'set':
            if (action.quantity <= 0) return lines.filter((l) => l.product.id !== action.id);
            return lines.map((l) => (l.product.id === action.id ? { ...l, quantity: Math.min(action.quantity, 999) } : l));
        case 'remove':
            return lines.filter((l) => l.product.id !== action.id);
        case 'clear':
            return [];
    }
}

const round2 = (n: number) => Math.round(n * 100) / 100;

export default function Pos(props: Props) {
    const {
        categories,
        products,
        paymentMethods,
        documentTypes,
        defaultDocumentType,
        docTypes,
        allowNegativeStock,
        boletaIdThreshold,
        lastSale,
        pdfFormat,
    } = props;

    const [lines, dispatch] = useReducer(cartReducer, []);
    const [category, setCategory] = useState<number | 'all'>('all');
    const [search, setSearch] = useState('');
    const [paymentMethod, setPaymentMethod] = useState('efectivo');
    const [received, setReceived] = useState('');
    const [documentType, setDocumentType] = useState(
        documentTypes.some((d) => d.value === defaultDocumentType) ? defaultDocumentType : (documentTypes[0]?.value ?? 'ninguno'),
    );
    const [customer, setCustomer] = useState<Customer | null>(null);
    const [processing, setProcessing] = useState(false);
    const [successOpen, setSuccessOpen] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);
    const cartRef = useRef<HTMLDivElement>(null);

    const total = useMemo(() => round2(lines.reduce((sum, l) => sum + Number(l.product.price) * l.quantity, 0)), [lines]);
    const itemsCount = lines.reduce((n, l) => n + l.quantity, 0);
    const receivedNumber = received === '' ? total : Number(received);
    const change = paymentMethod === 'efectivo' ? round2(receivedNumber - total) : 0;

    const visibleProducts = useMemo(() => {
        const term = search.trim().toLowerCase();
        return products.filter(
            (p) =>
                (category === 'all' || p.category_id === category) && (!term || p.name.toLowerCase().includes(term) || p.code.toLowerCase() === term),
        );
    }, [products, category, search]);

    // Mostrar el resultado de la venta recién registrada.
    useEffect(() => {
        if (lastSale) setSuccessOpen(true);
    }, [lastSale]);

    // Atajos: F2 buscar, F9 cobrar.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'F2') {
                e.preventDefault();
                searchRef.current?.focus();
            }
            if (e.key === 'F9') {
                e.preventDefault();
                submit();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    const needsCustomer = documentType === 'factura' || (documentType === 'boleta' && total >= boletaIdThreshold);
    const customerInvalid = documentType === 'factura' && customer !== null && customer.doc_type !== '6';
    const cashShort = paymentMethod === 'efectivo' && received !== '' && receivedNumber < total;
    const canSubmit = lines.length > 0 && !processing && !cashShort && !(needsCustomer && !customer) && !customerInvalid;

    function addProduct(product: PosProduct) {
        const inCart = lines.find((l) => l.product.id === product.id)?.quantity ?? 0;
        if (product.track_stock && !allowNegativeStock && inCart + 1 > Number(product.stock)) {
            toast.warning(`Sin stock suficiente de ${product.name} (quedan ${qty(product.stock)}).`);
            return;
        }
        dispatch({ type: 'add', product });
    }

    function resetSale() {
        dispatch({ type: 'clear' });
        setReceived('');
        setCustomer(null);
        setPaymentMethod('efectivo');
        setSearch('');
    }

    function submit() {
        if (!canSubmit) {
            if (lines.length === 0) toast.info('Agregue productos a la venta.');
            else if (needsCustomer && !customer)
                toast.warning(
                    documentType === 'factura' ? 'Seleccione un cliente con RUC.' : `Boletas desde ${money(boletaIdThreshold)} requieren cliente.`,
                );
            return;
        }

        router.post(
            '/pos',
            {
                items: lines.map((l) => ({ product_id: l.product.id, quantity: l.quantity })),
                payment_method: paymentMethod,
                amount_received: paymentMethod === 'efectivo' && received !== '' ? Number(received) : null,
                document_type: documentType,
                customer_id: customer?.id ?? null,
            },
            {
                preserveScroll: true,
                preserveState: true, // no perder el carrito si hay errores de validación
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => resetSale(),
                onError: (errors) => toast.error(Object.values(errors)[0] ?? 'No se pudo registrar la venta.'),
            },
        );
    }

    const quickAmounts = [10, 20, 50, 100].filter((a) => a > total).slice(0, 3);

    return (
        <AppLayout breadcrumbs={[{ title: 'Vender', href: '/pos' }]}>
            <Head title="Vender" />

            <div className="flex flex-1 flex-col lg:flex-row lg:overflow-hidden">
                {/* Catálogo */}
                <section className="flex min-w-0 flex-1 flex-col gap-3 p-3 md:p-4 lg:overflow-y-auto">
                    <div className="relative">
                        <Search className="text-muted-foreground absolute top-3 left-3 size-4" />
                        <Input
                            ref={searchRef}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && visibleProducts[0]) {
                                    addProduct(visibleProducts[0]);
                                    setSearch('');
                                }
                            }}
                            placeholder="Buscar producto (F2) · Enter agrega el primero"
                            className="h-11 pl-9"
                        />
                    </div>

                    <div className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1">
                        <CategoryChip active={category === 'all'} onClick={() => setCategory('all')}>
                            Todos
                        </CategoryChip>
                        {categories.map((c) => (
                            <CategoryChip key={c.id} active={category === c.id} onClick={() => setCategory(c.id)}>
                                {c.name}
                            </CategoryChip>
                        ))}
                    </div>

                    {visibleProducts.length === 0 ? (
                        <p className="text-muted-foreground py-12 text-center text-sm">No hay productos que coincidan.</p>
                    ) : (
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                            {visibleProducts.map((p) => {
                                const inCart = lines.find((l) => l.product.id === p.id)?.quantity ?? 0;
                                const outOfStock = p.track_stock && Number(p.stock) <= 0 && !allowNegativeStock;
                                return (
                                    <button
                                        key={p.id}
                                        type="button"
                                        onClick={() => addProduct(p)}
                                        disabled={outOfStock}
                                        className={cn(
                                            'hover:border-primary/60 active:bg-muted relative flex min-h-20 flex-col justify-between rounded-lg border p-3 text-left transition-colors select-none disabled:opacity-40',
                                            inCart > 0 && 'border-primary bg-primary/5',
                                        )}
                                    >
                                        <span className="line-clamp-2 text-sm leading-tight font-medium">{p.name}</span>
                                        <span className="mt-2 flex items-end justify-between gap-1">
                                            <span className="font-semibold tabular-nums">{money(p.price)}</span>
                                            {p.track_stock && (
                                                <span className={cn('text-xs', Number(p.stock) <= 5 ? 'text-amber-600' : 'text-muted-foreground')}>
                                                    {qty(p.stock)} und
                                                </span>
                                            )}
                                        </span>
                                        {inCart > 0 && (
                                            <span className="bg-primary text-primary-foreground absolute -top-2 -right-2 flex size-6 items-center justify-center rounded-full text-xs font-semibold">
                                                {qty(inCart)}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </section>

                {/* Venta actual */}
                <aside ref={cartRef} className="bg-muted/30 flex w-full flex-col border-t lg:w-[400px] lg:border-t-0 lg:border-l">
                    <div className="flex items-center justify-between px-4 pt-4 pb-2">
                        <h2 className="font-semibold">Venta actual</h2>
                        {lines.length > 0 && (
                            <Button variant="ghost" size="sm" onClick={() => dispatch({ type: 'clear' })} className="text-muted-foreground h-7">
                                Vaciar
                            </Button>
                        )}
                    </div>

                    <div className="min-h-24 flex-1 overflow-y-auto px-4">
                        {lines.length === 0 ? (
                            <div className="text-muted-foreground flex flex-col items-center gap-2 py-8 text-sm">
                                <ShoppingCart className="size-8 opacity-40" />
                                Toque un producto para agregarlo
                            </div>
                        ) : (
                            <ul className="divide-y">
                                {lines.map((l) => (
                                    <li key={l.product.id} className="flex items-center gap-2 py-2">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">{l.product.name}</p>
                                            <p className="text-muted-foreground text-xs">{money(l.product.price)} c/u</p>
                                        </div>
                                        <div className="flex items-center gap-1">
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="size-8"
                                                onClick={() => dispatch({ type: 'set', id: l.product.id, quantity: l.quantity - 1 })}
                                                aria-label="Quitar uno"
                                            >
                                                <Minus className="size-3.5" />
                                            </Button>
                                            <span className="w-7 text-center text-sm font-semibold tabular-nums">{qty(l.quantity)}</span>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="size-8"
                                                onClick={() => addProduct(l.product)}
                                                aria-label="Agregar uno"
                                            >
                                                <Plus className="size-3.5" />
                                            </Button>
                                        </div>
                                        <span className="w-20 text-right text-sm font-semibold tabular-nums">
                                            {money(Number(l.product.price) * l.quantity)}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => dispatch({ type: 'remove', id: l.product.id })}
                                            className="text-muted-foreground hover:text-red-600"
                                            aria-label="Eliminar"
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="space-y-4 border-t p-4">
                        <div className="flex items-baseline justify-between">
                            <span className="text-muted-foreground text-sm">
                                Total · {qty(itemsCount)} {itemsCount === 1 ? 'producto' : 'productos'}
                            </span>
                            <span className="text-3xl font-bold tabular-nums">{money(total)}</span>
                        </div>

                        {/* Comprobante */}
                        <div className="space-y-2">
                            <Segmented options={documentTypes} value={documentType} onChange={setDocumentType} />
                            {documentType !== 'ninguno' && (
                                <CustomerPicker
                                    value={customer}
                                    onChange={setCustomer}
                                    docTypes={docTypes}
                                    requireRuc={documentType === 'factura'}
                                    placeholder={documentType === 'boleta' && !needsCustomer ? 'Cliente (opcional) · DNI o nombre' : undefined}
                                />
                            )}
                            {customerInvalid && <p className="text-xs text-red-600">La factura requiere un cliente con RUC.</p>}
                            {documentType === 'boleta' && needsCustomer && !customer && (
                                <p className="text-xs text-amber-600">Desde {money(boletaIdThreshold)} la boleta debe identificar al cliente.</p>
                            )}
                        </div>

                        {/* Pago */}
                        <div className="space-y-2">
                            <Segmented options={paymentMethods} value={paymentMethod} onChange={setPaymentMethod} />
                            {paymentMethod === 'efectivo' && (
                                <div className="space-y-2">
                                    <div className="flex items-center gap-2">
                                        <Input
                                            type="number"
                                            inputMode="decimal"
                                            min={0}
                                            step="0.10"
                                            value={received}
                                            onChange={(e) => setReceived(e.target.value)}
                                            onKeyDown={(e) => e.key === 'Enter' && submit()}
                                            placeholder={`Recibido (${money(total)})`}
                                            className="h-10"
                                        />
                                        {quickAmounts.map((a) => (
                                            <Button
                                                key={a}
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="h-10"
                                                onClick={() => setReceived(String(a))}
                                            >
                                                {a}
                                            </Button>
                                        ))}
                                    </div>
                                    <div className="flex justify-between text-sm">
                                        <span className="text-muted-foreground">Vuelto</span>
                                        <span className={cn('font-semibold tabular-nums', cashShort && 'text-red-600')}>
                                            {cashShort ? 'Monto insuficiente' : money(Math.max(change, 0))}
                                        </span>
                                    </div>
                                </div>
                            )}
                        </div>

                        <Button size="lg" className="h-12 w-full text-base" onClick={submit} disabled={!canSubmit}>
                            {processing ? <LoaderCircle className="animate-spin" /> : null}
                            Cobrar {money(total)}
                            <kbd className="ml-auto hidden rounded border border-white/30 px-1.5 text-[10px] opacity-70 sm:inline">F9</kbd>
                        </Button>
                    </div>
                </aside>
            </div>

            {/* Barra inferior en celular */}
            {lines.length > 0 && (
                <div className="bg-background sticky bottom-0 z-10 flex items-center justify-between border-t p-3 lg:hidden">
                    <span className="font-semibold tabular-nums">
                        {qty(itemsCount)} · {money(total)}
                    </span>
                    <Button size="sm" onClick={() => cartRef.current?.scrollIntoView({ behavior: 'smooth' })}>
                        Ver venta
                    </Button>
                </div>
            )}

            <ReceiptViewer
                sale={lastSale ?? null}
                open={successOpen && !!lastSale}
                onOpenChange={setSuccessOpen}
                defaultFormat={pdfFormat}
                onNewSale={() => setTimeout(() => searchRef.current?.focus(), 50)}
            />
        </AppLayout>
    );
}

function CategoryChip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'h-9 shrink-0 rounded-full border px-4 text-sm font-medium whitespace-nowrap transition-colors',
                active ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-muted',
            )}
        >
            {children}
        </button>
    );
}

function Segmented({ options, value, onChange }: { options: Option[]; value: string; onChange: (v: string) => void }) {
    return (
        <div className="bg-muted grid gap-1 rounded-lg p-1" style={{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }}>
            {options.map((o) => (
                <button
                    key={o.value}
                    type="button"
                    onClick={() => onChange(o.value)}
                    className={cn(
                        'h-9 rounded-md px-2 text-sm font-medium transition-colors',
                        value === o.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}

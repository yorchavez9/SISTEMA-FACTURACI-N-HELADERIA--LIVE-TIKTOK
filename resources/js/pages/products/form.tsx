import { Field, Page } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { dateTime, qty } from '@/lib/format';
import { type Product } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle, PackagePlus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Movement {
    id: number;
    type: 'sale' | 'void' | 'adjustment';
    quantity: string;
    stock_after: string;
    note: string | null;
    created_at: string;
    user?: { name: string } | null;
}

interface Props {
    product: Product | null;
    categories: { id: number; name: string }[];
    movements?: Movement[];
}

const movementLabels = { sale: 'Venta', void: 'Anulación', adjustment: 'Ajuste' };

export default function ProductForm({ product, categories, movements = [] }: Props) {
    const editing = product !== null;
    const form = useForm({
        category_id: product ? String(product.category_id) : '',
        code: product?.code ?? '',
        name: product?.name ?? '',
        description: product?.description ?? '',
        price: product?.price ?? '',
        track_stock: product?.track_stock ?? false,
        stock: '0',
        stock_min: product?.stock_min ?? '0',
        active: product?.active ?? true,
    });
    const [adjustOpen, setAdjustOpen] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editing) form.put(`/products/${product.id}`);
        else form.post('/products');
    };

    const title = editing ? product.name : 'Nuevo producto';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Productos', href: '/products' },
                { title, href: '#' },
            ]}
        >
            <Head title={title} />
            <Page title={title} className="max-w-3xl">
                <form onSubmit={submit} className="grid gap-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Nombre" error={form.errors.name} className="sm:col-span-2">
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus={!editing} />
                        </Field>
                        <Field label="Categoría" error={form.errors.category_id}>
                            <Select value={form.data.category_id} onValueChange={(v) => form.setData('category_id', v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Seleccione" />
                                </SelectTrigger>
                                <SelectContent>
                                    {categories.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Precio de venta (S/, IGV incluido)" error={form.errors.price}>
                            <Input
                                type="number"
                                inputMode="decimal"
                                step="0.10"
                                min="0"
                                value={form.data.price}
                                onChange={(e) => form.setData('price', e.target.value)}
                            />
                        </Field>
                        <Field label="Código" error={form.errors.code} hint={editing ? undefined : 'Opcional. Se genera automáticamente.'}>
                            <Input value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="P0001" />
                        </Field>
                        <Field label="Descripción (opcional)" error={form.errors.description}>
                            <Input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </Field>
                    </div>

                    <div className="space-y-4 rounded-lg border p-4">
                        <label className="flex items-center justify-between gap-4">
                            <span>
                                <span className="block text-sm font-medium">Controlar stock</span>
                                <span className="text-muted-foreground text-xs">
                                    Para productos contables (potes, paletas, bebidas). No para helado por bola.
                                </span>
                            </span>
                            <Switch checked={form.data.track_stock} onCheckedChange={(v) => form.setData('track_stock', v)} />
                        </label>
                        {form.data.track_stock && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                {editing ? (
                                    <div className="grid gap-1.5">
                                        <span className="text-sm font-medium">Stock actual</span>
                                        <div className="flex items-center gap-2">
                                            <span className="text-2xl font-semibold tabular-nums">{qty(product.stock)}</span>
                                            {product.track_stock && (
                                                <Button type="button" variant="outline" size="sm" onClick={() => setAdjustOpen(true)}>
                                                    <PackagePlus /> Ajustar
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                ) : (
                                    <Field label="Stock inicial" error={form.errors.stock}>
                                        <Input
                                            type="number"
                                            min="0"
                                            value={form.data.stock}
                                            onChange={(e) => form.setData('stock', e.target.value)}
                                        />
                                    </Field>
                                )}
                                <Field label="Stock mínimo (alerta)" error={form.errors.stock_min}>
                                    <Input
                                        type="number"
                                        min="0"
                                        value={form.data.stock_min}
                                        onChange={(e) => form.setData('stock_min', e.target.value)}
                                    />
                                </Field>
                            </div>
                        )}
                    </div>

                    <label className="flex items-center gap-3">
                        <Switch checked={form.data.active} onCheckedChange={(v) => form.setData('active', v)} />
                        <span className="text-sm">Activo (visible en el punto de venta)</span>
                    </label>

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex gap-2">
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <LoaderCircle className="animate-spin" />}
                                Guardar
                            </Button>
                            <Button type="button" variant="ghost" asChild>
                                <Link href="/products">Cancelar</Link>
                            </Button>
                        </div>
                        {editing && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-red-600 hover:text-red-700"
                                onClick={() => {
                                    if (confirm(`¿Eliminar "${product.name}"? Las ventas anteriores se conservan.`)) {
                                        router.delete(`/products/${product.id}`);
                                    }
                                }}
                            >
                                <Trash2 /> Eliminar
                            </Button>
                        )}
                    </div>
                </form>

                {editing && movements.length > 0 && (
                    <section className="space-y-2 pt-4">
                        <h2 className="text-sm font-semibold">Últimos movimientos de stock</h2>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Fecha</TableHead>
                                    <TableHead>Tipo</TableHead>
                                    <TableHead className="text-right">Cantidad</TableHead>
                                    <TableHead className="text-right">Saldo</TableHead>
                                    <TableHead className="hidden sm:table-cell">Detalle</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {movements.map((m) => (
                                    <TableRow key={m.id}>
                                        <TableCell className="text-xs">{dateTime(m.created_at)}</TableCell>
                                        <TableCell>{movementLabels[m.type]}</TableCell>
                                        <TableCell
                                            className={`text-right tabular-nums ${Number(m.quantity) < 0 ? 'text-red-600' : 'text-emerald-700'}`}
                                        >
                                            {Number(m.quantity) > 0 ? '+' : ''}
                                            {qty(m.quantity)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{qty(m.stock_after)}</TableCell>
                                        <TableCell className="text-muted-foreground hidden text-xs sm:table-cell">
                                            {m.note} {m.user ? `· ${m.user.name}` : ''}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </section>
                )}
            </Page>

            {editing && <AdjustStockDialog open={adjustOpen} onOpenChange={setAdjustOpen} product={product} />}
        </AppLayout>
    );
}

function AdjustStockDialog({ open, onOpenChange, product }: { open: boolean; onOpenChange: (v: boolean) => void; product: Product }) {
    const form = useForm({ stock: String(Number(product.stock)), note: '' });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Ajustar stock</DialogTitle>
                    <DialogDescription>Ingrese la cantidad contada físicamente de {product.name}.</DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/products/${product.id}/stock`, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
                    }}
                    className="grid gap-4"
                >
                    <Field label="Stock contado" error={form.errors.stock}>
                        <Input type="number" min="0" value={form.data.stock} onChange={(e) => form.setData('stock', e.target.value)} autoFocus />
                    </Field>
                    <Field label="Motivo" error={form.errors.note}>
                        <Input
                            value={form.data.note}
                            onChange={(e) => form.setData('note', e.target.value)}
                            placeholder="Ingreso de mercadería, merma, conteo…"
                        />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

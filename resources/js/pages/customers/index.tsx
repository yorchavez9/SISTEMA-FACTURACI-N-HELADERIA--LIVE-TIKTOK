import { LookupHint } from '@/components/lookup-hint';
import { EmptyState, Field, Page } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDocumentLookup } from '@/hooks/use-document-lookup';
import AppLayout from '@/layouts/app-layout';
import { docTypeShort } from '@/lib/format';
import { type Customer, type Option, type Paginated, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    customers: Paginated<Customer>;
    filters: { search: string };
    docTypes: Option[];
}

export default function CustomersIndex({ customers, filters, docTypes }: Props) {
    const { auth } = usePage<SharedData>().props;
    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<Customer | null | 'new'>(null);

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const t = setTimeout(() => router.get('/customers', { search: search || undefined }, { preserveState: true, replace: true }), 300);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Clientes', href: '/customers' }]}>
            <Head title="Clientes" />
            <Page
                title="Clientes"
                actions={
                    <Button onClick={() => setEditing('new')}>
                        <Plus /> Nuevo cliente
                    </Button>
                }
            >
                <div className="relative w-full sm:w-80">
                    <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                    <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Buscar por DNI, RUC o nombre" className="pl-8" />
                </div>

                {customers.data.length === 0 ? (
                    <EmptyState>No se encontraron clientes.</EmptyState>
                ) : (
                    <div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Documento</TableHead>
                                    <TableHead>Nombre / Razón social</TableHead>
                                    <TableHead className="hidden lg:table-cell">Dirección</TableHead>
                                    <TableHead className="hidden sm:table-cell">Teléfono</TableHead>
                                    <TableHead className="text-right">Ventas</TableHead>
                                    <TableHead className="w-24" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {customers.data.map((c) => (
                                    <TableRow key={c.id}>
                                        <TableCell className="font-mono text-xs whitespace-nowrap">
                                            <span className="text-muted-foreground">{docTypeShort[c.doc_type]}</span> {c.doc_number}
                                        </TableCell>
                                        <TableCell className="font-medium">{c.name}</TableCell>
                                        <TableCell className="text-muted-foreground hidden max-w-64 truncate lg:table-cell">
                                            {c.address ?? '—'}
                                        </TableCell>
                                        <TableCell className="hidden sm:table-cell">{c.phone ?? '—'}</TableCell>
                                        <TableCell className="text-right tabular-nums">{c.sales_count}</TableCell>
                                        <TableCell className="text-right whitespace-nowrap">
                                            <Button variant="ghost" size="icon" className="size-8" onClick={() => setEditing(c)} aria-label="Editar">
                                                <Pencil className="size-4" />
                                            </Button>
                                            {auth.user.is_admin && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 text-red-600"
                                                    aria-label="Eliminar"
                                                    onClick={() =>
                                                        confirm(`¿Eliminar a ${c.name}?`) &&
                                                        router.delete(`/customers/${c.id}`, { preserveScroll: true })
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination data={customers} />
                    </div>
                )}
            </Page>

            {editing !== null && (
                <CustomerDialog customer={editing === 'new' ? null : editing} docTypes={docTypes} onClose={() => setEditing(null)} />
            )}
        </AppLayout>
    );
}

function CustomerDialog({ customer, docTypes, onClose }: { customer: Customer | null; docTypes: Option[]; onClose: () => void }) {
    const form = useForm({
        doc_type: customer?.doc_type ?? '1',
        doc_number: customer?.doc_number ?? '',
        name: customer?.name ?? '',
        address: customer?.address ?? '',
        phone: customer?.phone ?? '',
        email: customer?.email ?? '',
    });
    const isRuc = form.data.doc_type === '6';

    // Al crear: autocompleta desde RENIEC/SUNAT cuando el DNI/RUC está completo.
    const lookup = useDocumentLookup(
        form.data.doc_type,
        form.data.doc_number,
        (fill) => form.setData((d) => ({ ...d, name: fill.name, address: fill.address ?? d.address })),
        !customer,
    );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (customer) form.put(`/customers/${customer.id}`, options);
        else form.post('/customers', options);
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{customer ? 'Editar cliente' : 'Nuevo cliente'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid grid-cols-[140px_1fr] gap-3">
                        <Field label="Documento" error={form.errors.doc_type}>
                            <Select value={form.data.doc_type} onValueChange={(v) => form.setData('doc_type', v)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {docTypes.map((t) => (
                                        <SelectItem key={t.value} value={t.value}>
                                            {t.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Número" error={form.errors.doc_number}>
                            <Input
                                value={form.data.doc_number}
                                onChange={(e) => form.setData('doc_number', e.target.value.trim())}
                                maxLength={isRuc ? 11 : 15}
                                autoFocus
                            />
                        </Field>
                    </div>
                    {!customer && <LookupHint loading={lookup.loading} message={lookup.message} />}
                    <Field label={isRuc ? 'Razón social' : 'Nombre completo'} error={form.errors.name}>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </Field>
                    <Field label={isRuc ? 'Dirección fiscal' : 'Dirección (opcional)'} error={form.errors.address}>
                        <Input value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Teléfono" error={form.errors.phone}>
                            <Input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                        </Field>
                        <Field label="Email" error={form.errors.email}>
                            <Input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
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

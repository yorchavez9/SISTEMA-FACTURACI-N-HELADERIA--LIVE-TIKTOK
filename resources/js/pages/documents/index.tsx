import { DocumentActionsMenu } from '@/components/document-actions';
import { EmptyState, Page } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { ReceiptViewer, type ReceiptSale } from '@/components/receipt-viewer';
import { StatusBadge } from '@/components/status-badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { dateTime, docTypeShort, documentTypeLabels, money } from '@/lib/format';
import { type ElectronicDocument, type Option, type Paginated, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    documents: Paginated<ElectronicDocument>;
    filters: { from: string; to: string; type?: string; status?: string; search?: string };
    types: Option[];
    statuses: Option[];
    pdfFormat: string;
}

/** Datos que necesita el visor de comprobantes a partir de una fila. */
function toReceipt(d: ElectronicDocument): ReceiptSale {
    return {
        id: d.sale_id,
        code: 'V-' + String(d.sale_id).padStart(6, '0'),
        total: d.total,
        change: null,
        payment_method: documentTypeLabels[d.type],
        document: {
            id: d.id,
            number: d.full_number,
            type: documentTypeLabels[d.type],
            status: d.status,
            message: d.sunat_message,
            has_pdf: d.provider_id !== null,
        },
    };
}

export default function DocumentsIndex({ documents, filters, types, statuses, pdfFormat }: Props) {
    const { auth } = usePage<SharedData>().props;
    const [search, setSearch] = useState(filters.search ?? '');
    const [viewing, setViewing] = useState<ReceiptSale | null>(null);
    const apply = (changes: Partial<Props['filters']>) =>
        router.get('/documents', { ...filters, ...changes }, { preserveState: true, preserveScroll: true, replace: true });

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const t = setTimeout(() => apply({ search: search || undefined }), 400);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Comprobantes', href: '/documents' }]}>
            <Head title="Comprobantes" />
            <Page title="Comprobantes electrónicos" description="Boletas, facturas y notas de crédito y débito enviadas a SUNAT.">
                <div className="flex flex-wrap items-center gap-2">
                    <Input type="date" value={filters.from} onChange={(e) => apply({ from: e.target.value })} className="w-auto" aria-label="Desde" />
                    <span className="text-muted-foreground text-sm">a</span>
                    <Input type="date" value={filters.to} onChange={(e) => apply({ to: e.target.value })} className="w-auto" aria-label="Hasta" />
                    <Select value={filters.type ?? 'all'} onValueChange={(v) => apply({ type: v === 'all' ? undefined : v })}>
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos los tipos</SelectItem>
                            {types.map((t) => (
                                <SelectItem key={t.value} value={t.value}>
                                    {t.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={filters.status ?? 'all'} onValueChange={(v) => apply({ status: v === 'all' ? undefined : v })}>
                        <SelectTrigger className="w-44">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos los estados</SelectItem>
                            {statuses.map((s) => (
                                <SelectItem key={s.value} value={s.value}>
                                    {s.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="relative w-full sm:w-56">
                        <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="B001-123 o DNI/RUC" className="pl-8" />
                    </div>
                </div>

                {documents.data.length === 0 ? (
                    <EmptyState>No hay comprobantes en el rango seleccionado.</EmptyState>
                ) : (
                    <div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Comprobante</TableHead>
                                    <TableHead>Fecha</TableHead>
                                    <TableHead className="hidden md:table-cell">Cliente</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                    <TableHead>Estado SUNAT</TableHead>
                                    <TableHead className="w-12 text-right">
                                        <span className="sr-only">Acciones</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documents.data.map((d) => (
                                    <TableRow key={d.id} className="cursor-pointer" onClick={() => router.visit(`/documents/${d.id}`)}>
                                        <TableCell>
                                            <span className="text-muted-foreground block text-xs">{documentTypeLabels[d.type]}</span>
                                            <span className="font-mono text-sm">{d.full_number}</span>
                                            {d.related && (
                                                <span className="text-muted-foreground block text-xs">
                                                    Modifica <span className="font-mono">{d.related.full_number}</span>
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-xs whitespace-nowrap">{dateTime(d.issued_at)}</TableCell>
                                        <TableCell className="hidden max-w-60 md:table-cell">
                                            <span className="block truncate">{d.customer_snapshot.razon_social}</span>
                                            <span className="text-muted-foreground text-xs">
                                                {docTypeShort[d.customer_snapshot.tipo_doc]} {d.customer_snapshot.num_doc}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{money(d.total)}</TableCell>
                                        <TableCell>
                                            <StatusBadge status={d.status} />
                                            {d.voiding && (
                                                <span className="text-muted-foreground mt-1 block text-xs" title={d.voiding.message ?? undefined}>
                                                    {d.voiding.kind}: <StatusBadge status={d.voiding.status} className="px-1.5 py-0 text-[11px]" />
                                                </span>
                                            )}
                                        </TableCell>
                                        {/* Evita que los clics del menú y sus diálogos abran el detalle de la fila. */}
                                        <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
                                            <DocumentActionsMenu doc={d} isAdmin={auth.user.is_admin} onView={(doc) => setViewing(toReceipt(doc))} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination data={documents} />
                    </div>
                )}
            </Page>

            <ReceiptViewer sale={viewing} open={viewing !== null} onOpenChange={(v) => !v && setViewing(null)} defaultFormat={pdfFormat} />
        </AppLayout>
    );
}

import { CustomerPicker } from '@/components/customer-picker';
import { Page } from '@/components/page';
import { SaleStatusBadge, StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { dateTime, docTypeShort, documentTypeLabels, money, paymentLabels, qty } from '@/lib/format';
import { type Customer, type Sale, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Ban, FileText, LoaderCircle, Printer } from 'lucide-react';
import { useState } from 'react';

interface Props {
    sale: Sale;
    canEmit: boolean;
    canIssueFactura: boolean;
}

const docTypeOptions = [
    { value: '1', label: 'DNI' },
    { value: '6', label: 'RUC' },
    { value: '4', label: 'C. Extranjería' },
    { value: '7', label: 'Pasaporte' },
];

export default function SaleShow({ sale, canEmit, canIssueFactura }: Props) {
    const { auth } = usePage<SharedData>().props;
    const [voidOpen, setVoidOpen] = useState(false);
    const [emitOpen, setEmitOpen] = useState(false);
    const invoice = sale.documents?.find((d) => d.type !== '07' && d.status !== 'anulado') ?? null;
    const printable = sale.documents?.find((d) => d.type !== '07' && d.provider_id) ?? null;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Ventas', href: '/sales' },
                { title: sale.code, href: `/sales/${sale.id}` },
            ]}
        >
            <Head title={`Venta ${sale.code}`} />
            <Page
                title={`Venta ${sale.code}`}
                description={`${dateTime(sale.sold_at)} · ${sale.user?.name}`}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a
                                href={printable ? `/documents/${printable.id}/download/pdf` : `/sales/${sale.id}/ticket`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <Printer /> Imprimir
                            </a>
                        </Button>
                        {canEmit && (
                            <Button variant="outline" onClick={() => setEmitOpen(true)}>
                                <FileText /> Emitir comprobante
                            </Button>
                        )}
                        {auth.user.is_admin && sale.status === 'completada' && (
                            <Button variant="destructive" onClick={() => setVoidOpen(true)}>
                                <Ban /> Anular venta
                            </Button>
                        )}
                    </>
                }
            >
                <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm md:grid-cols-4">
                    <Info label="Estado">
                        <SaleStatusBadge status={sale.status} />
                    </Info>
                    <Info label="Pago">{paymentLabels[sale.payment_method]}</Info>
                    <Info label="Cliente">
                        {sale.customer ? `${sale.customer.name} (${docTypeShort[sale.customer.doc_type]} ${sale.customer.doc_number})` : '—'}
                    </Info>
                    <Info label="Comprobante">{documentTypeLabels[sale.document_type]}</Info>
                    {sale.payment_method === 'efectivo' && (
                        <>
                            <Info label="Recibido">{money(sale.amount_received)}</Info>
                            <Info label="Vuelto">{money(sale.change)}</Info>
                        </>
                    )}
                    {sale.status === 'anulada' && (
                        <Info label="Anulada" className="col-span-2">
                            {dateTime(sale.voided_at)} por {sale.voided_by?.name}: {sale.void_reason}
                        </Info>
                    )}
                </dl>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Producto</TableHead>
                            <TableHead className="text-right">Cant.</TableHead>
                            <TableHead className="text-right">P. unit.</TableHead>
                            <TableHead className="text-right">Importe</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {sale.items?.map((i) => (
                            <TableRow key={i.id}>
                                <TableCell>{i.product_name}</TableCell>
                                <TableCell className="text-right tabular-nums">{qty(i.quantity)}</TableCell>
                                <TableCell className="text-right tabular-nums">{money(i.unit_price)}</TableCell>
                                <TableCell className="text-right tabular-nums">{money(i.total)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={3} className="text-right">
                                Total
                            </TableCell>
                            <TableCell className="text-right text-base font-semibold tabular-nums">{money(sale.total)}</TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>

                {sale.documents && sale.documents.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-semibold">Comprobantes electrónicos</h2>
                        <ul className="divide-y rounded-md border">
                            {sale.documents.map((d) => (
                                <li key={d.id}>
                                    <Link
                                        href={`/documents/${d.id}`}
                                        className="hover:bg-muted/40 flex items-center justify-between gap-3 px-3 py-2.5"
                                    >
                                        <span className="text-sm">
                                            {documentTypeLabels[d.type]} <span className="font-mono">{d.full_number}</span>
                                        </span>
                                        <StatusBadge status={d.status} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </Page>

            <VoidDialog open={voidOpen} onOpenChange={setVoidOpen} sale={sale} hasAcceptedInvoice={invoice?.status === 'aceptado'} />
            <EmitDialog open={emitOpen} onOpenChange={setEmitOpen} sale={sale} canIssueFactura={canIssueFactura} />
        </AppLayout>
    );
}

function Info({ label, children, className }: { label: string; children: React.ReactNode; className?: string }) {
    return (
        <div className={className}>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="mt-0.5">{children}</dd>
        </div>
    );
}

function VoidDialog({
    open,
    onOpenChange,
    sale,
    hasAcceptedInvoice,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    sale: Sale;
    hasAcceptedInvoice: boolean;
}) {
    const form = useForm({ reason: '' });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Anular venta {sale.code}</DialogTitle>
                    <DialogDescription>
                        {hasAcceptedInvoice
                            ? 'Se emitirá una nota de crédito (motivo 01: anulación de la operación) y se repondrá el stock.'
                            : 'Se repondrá el stock de los productos. Esta acción no se puede deshacer.'}
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/sales/${sale.id}/void`, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
                    }}
                    className="space-y-4"
                >
                    <div className="space-y-1.5">
                        <label className="text-sm font-medium" htmlFor="reason">
                            Motivo
                        </label>
                        <Textarea id="reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} autoFocus />
                        {form.errors.reason && <p className="text-sm text-red-600">{form.errors.reason}</p>}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancelar
                        </Button>
                        <Button type="submit" variant="destructive" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Anular
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EmitDialog({
    open,
    onOpenChange,
    sale,
    canIssueFactura,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    sale: Sale;
    canIssueFactura: boolean;
}) {
    const [type, setType] = useState<'boleta' | 'factura'>('boleta');
    const [customer, setCustomer] = useState<Customer | null>(sale.customer ?? null);
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        router.post(
            `/sales/${sale.id}/documents`,
            { document_type: type, customer_id: customer?.id ?? null },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Emitir comprobante</DialogTitle>
                    <DialogDescription>
                        Venta {sale.code} por {money(sale.total)}.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-3">
                    <div className="bg-muted grid grid-cols-2 gap-1 rounded-lg p-1">
                        {(['boleta', 'factura'] as const)
                            .filter((t) => t === 'boleta' || canIssueFactura)
                            .map((t) => (
                                <button
                                    key={t}
                                    type="button"
                                    onClick={() => setType(t)}
                                    className={`h-9 rounded-md text-sm font-medium ${type === t ? 'bg-background shadow-sm' : 'text-muted-foreground'}`}
                                >
                                    {documentTypeLabels[t]}
                                </button>
                            ))}
                    </div>
                    <CustomerPicker value={customer} onChange={setCustomer} docTypes={docTypeOptions} requireRuc={type === 'factura'} />
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancelar
                    </Button>
                    <Button onClick={submit} disabled={processing || (type === 'factura' && customer?.doc_type !== '6')}>
                        {processing && <LoaderCircle className="animate-spin" />}
                        Emitir {documentTypeLabels[type].toLowerCase()}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

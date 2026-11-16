import { Field, Page } from '@/components/page';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { dateTime, docTypeShort, documentTypeLabels, money, qty } from '@/lib/format';
import { type ElectronicDocument, type Option } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, FileCode2, FileText, LoaderCircle, Pencil, RefreshCw, Send, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

interface Props {
    document: ElectronicDocument;
    canCorrect: boolean;
    docTypes: Option[];
}

export default function DocumentShow({ document: doc, canCorrect, docTypes }: Props) {
    const [busy, setBusy] = useState<string | null>(null);
    const [correcting, setCorrecting] = useState(false);
    const [showRaw, setShowRaw] = useState(false);

    const action = (name: string, url: string) =>
        router.post(url, {}, { preserveScroll: true, onStart: () => setBusy(name), onFinish: () => setBusy(null) });

    const registered = doc.provider_id !== null;
    const canResend = !['aceptado', 'anulado'].includes(doc.status);
    const label = `${documentTypeLabels[doc.type]} ${doc.full_number}`;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Comprobantes', href: '/documents' },
                { title: doc.full_number, href: `/documents/${doc.id}` },
            ]}
        >
            <Head title={label} />
            <Page
                title={label}
                description={`Emitido ${dateTime(doc.issued_at)}`}
                actions={
                    registered && (
                        <>
                            <Button variant="outline" asChild>
                                <a href={`/documents/${doc.id}/download/pdf`} target="_blank" rel="noreferrer">
                                    <FileText /> PDF
                                </a>
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={`/documents/${doc.id}/download/pdf?format=a4`} target="_blank" rel="noreferrer">
                                    <FileText /> PDF A4
                                </a>
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={`/documents/${doc.id}/download/xml`}>
                                    <FileCode2 /> XML
                                </a>
                            </Button>
                            {doc.status === 'aceptado' && (
                                <Button variant="outline" asChild>
                                    <a href={`/documents/${doc.id}/download/cdr`}>
                                        <Download /> CDR
                                    </a>
                                </Button>
                            )}
                        </>
                    )
                }
            >
                <div className="flex flex-wrap items-center gap-3">
                    <StatusBadge status={doc.status} className="px-3 py-1 text-sm" />
                    {doc.sunat_code && <span className="text-muted-foreground text-sm">Código SUNAT: {doc.sunat_code}</span>}
                    {doc.last_checked_at && <span className="text-muted-foreground text-xs">Consultado {dateTime(doc.last_checked_at)}</span>}
                </div>

                {doc.sunat_message && (
                    <p
                        className={`rounded-md border px-3 py-2 text-sm ${
                            ['error', 'error_envio', 'rechazado', 'revision_manual'].includes(doc.status)
                                ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200'
                                : 'bg-muted/40'
                        }`}
                    >
                        {doc.sunat_message}
                    </p>
                )}

                <div className="flex flex-wrap gap-2">
                    {registered && (
                        <Button variant="secondary" onClick={() => action('refresh', `/documents/${doc.id}/refresh`)} disabled={busy !== null}>
                            {busy === 'refresh' ? <LoaderCircle className="animate-spin" /> : <RefreshCw />} Actualizar estado
                        </Button>
                    )}
                    {canResend && (
                        <Button variant="secondary" onClick={() => action('resend', `/documents/${doc.id}/resend`)} disabled={busy !== null}>
                            {busy === 'resend' ? <LoaderCircle className="animate-spin" /> : <Send />}{' '}
                            {registered ? 'Reenviar a SUNAT' : 'Reintentar envío'}
                        </Button>
                    )}
                    {canCorrect && (
                        <Button variant="secondary" onClick={() => setCorrecting(true)}>
                            <Pencil /> Corregir cliente
                        </Button>
                    )}
                    {doc.number !== null && (
                        <Button variant="ghost" onClick={() => action('consult', `/documents/${doc.id}/consult`)} disabled={busy !== null}>
                            {busy === 'consult' ? <LoaderCircle className="animate-spin" /> : <ShieldCheck />} Verificar en SUNAT
                        </Button>
                    )}
                </div>

                <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt className="text-muted-foreground text-xs">Cliente</dt>
                        <dd>{doc.customer_snapshot.razon_social}</dd>
                        <dd className="text-muted-foreground text-xs">
                            {docTypeShort[doc.customer_snapshot.tipo_doc]} {doc.customer_snapshot.num_doc}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground text-xs">Venta</dt>
                        <dd>
                            <Link href={`/sales/${doc.sale_id}`} className="underline underline-offset-2">
                                Ver venta
                            </Link>
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground text-xs">Total</dt>
                        <dd className="font-semibold tabular-nums">{money(doc.total)}</dd>
                    </div>
                    {doc.related && (
                        <div>
                            <dt className="text-muted-foreground text-xs">Anula a</dt>
                            <dd>
                                <Link href={`/documents/${doc.related.id}`} className="font-mono underline underline-offset-2">
                                    {doc.related.full_number}
                                </Link>
                            </dd>
                        </div>
                    )}
                    {doc.credit_note && (
                        <div>
                            <dt className="text-muted-foreground text-xs">Nota de crédito</dt>
                            <dd className="flex items-center gap-2">
                                <Link href={`/documents/${doc.credit_note.id}`} className="font-mono underline underline-offset-2">
                                    {doc.credit_note.full_number}
                                </Link>
                                <StatusBadge status={doc.credit_note.status} />
                            </dd>
                        </div>
                    )}
                    {doc.hash && (
                        <div className="sm:col-span-3">
                            <dt className="text-muted-foreground text-xs">Hash</dt>
                            <dd className="font-mono text-xs break-all">{doc.hash}</dd>
                        </div>
                    )}
                </dl>

                {doc.sale?.items && (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Descripción</TableHead>
                                <TableHead className="text-right">Cant.</TableHead>
                                <TableHead className="text-right">P. unit.</TableHead>
                                <TableHead className="text-right">Importe</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {doc.sale.items.map((i) => (
                                <TableRow key={i.id}>
                                    <TableCell>{i.product_name}</TableCell>
                                    <TableCell className="text-right tabular-nums">{qty(i.quantity)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{money(i.unit_price)}</TableCell>
                                    <TableCell className="text-right tabular-nums">{money(i.total)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <div>
                    <button type="button" className="text-muted-foreground text-xs underline" onClick={() => setShowRaw(!showRaw)}>
                        {showRaw ? 'Ocultar' : 'Ver'} respuesta técnica del proveedor
                    </button>
                    {showRaw && (
                        <pre className="bg-muted mt-2 max-h-80 overflow-auto rounded-md p-3 text-xs">
                            {JSON.stringify(doc.response_payload ?? null, null, 2)}
                        </pre>
                    )}
                </div>
            </Page>

            {correcting && <CorrectDialog doc={doc} docTypes={docTypes} onClose={() => setCorrecting(false)} />}
        </AppLayout>
    );
}

function CorrectDialog({ doc, docTypes, onClose }: { doc: ElectronicDocument; docTypes: Option[]; onClose: () => void }) {
    const isFactura = doc.type === '01';
    const form = useForm({
        tipo_doc: doc.customer_snapshot.tipo_doc,
        num_doc: doc.customer_snapshot.num_doc,
        razon_social: doc.customer_snapshot.razon_social,
        direccion: doc.customer_snapshot.direccion ?? '',
    });
    const options = isFactura ? docTypes.filter((t) => t.value === '6') : [{ value: '0', label: 'Sin documento' }, ...docTypes];

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Corregir datos del cliente</DialogTitle>
                    <DialogDescription>El comprobante se reenviará a SUNAT con los datos corregidos.</DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/documents/${doc.id}`, { preserveScroll: true, onSuccess: onClose });
                    }}
                    className="grid gap-4"
                >
                    <div className="grid grid-cols-[140px_1fr] gap-3">
                        <Field label="Documento" error={form.errors.tipo_doc}>
                            <Select value={form.data.tipo_doc} onValueChange={(v) => form.setData('tipo_doc', v)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.map((t) => (
                                        <SelectItem key={t.value} value={t.value}>
                                            {t.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Número" error={form.errors.num_doc}>
                            <Input value={form.data.num_doc} onChange={(e) => form.setData('num_doc', e.target.value)} />
                        </Field>
                    </div>
                    <Field label="Nombre / Razón social" error={form.errors.razon_social}>
                        <Input value={form.data.razon_social} onChange={(e) => form.setData('razon_social', e.target.value)} />
                    </Field>
                    <Field label="Dirección" error={form.errors.direccion}>
                        <Input value={form.data.direccion} onChange={(e) => form.setData('direccion', e.target.value)} />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Guardar y reenviar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

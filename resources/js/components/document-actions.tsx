import { Field } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { documentTypeLabels, money, qty } from '@/lib/format';
import { jsonFetch } from '@/lib/http';
import { type ElectronicDocument } from '@/types';
import { Link, router } from '@inertiajs/react';
import { Ban, Eye, FileArchive, FileCode2, FileDown, FileMinus2, FilePlus2, LoaderCircle, MoreHorizontal, Printer } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Reason = { value: string; label: string; mode: 'full' | 'items' | 'amount' };

interface NoteData {
    document: { id: number; number: string; type: string; type_label: string; total: number; sale_voided: boolean };
    items: { id: number; name: string; quantity: number; unit_price: number }[];
    credited: number;
    remaining: number;
    void_kind: 'RA' | 'RC' | null;
    credit_reasons: Reason[];
    debit_reasons: Reason[];
    annul_codes: string[];
}

type DialogKind = 'credit' | 'debit' | 'void' | null;

/**
 * Menú de acciones de un comprobante: ver/imprimir, descargas y, para el administrador,
 * nota de crédito, nota de débito y comunicación de baja / resumen de anulación.
 */
export function DocumentActionsMenu({
    doc,
    isAdmin,
    onView,
}: {
    doc: ElectronicDocument;
    isAdmin: boolean;
    onView: (doc: ElectronicDocument) => void;
}) {
    const [dialog, setDialog] = useState<DialogKind>(null);
    const registered = doc.provider_id !== null;
    const accepted = doc.status === 'aceptado';
    const actions = doc.actions;
    const voidLabel = doc.type === '03' ? 'Anular (resumen diario)' : 'Comunicación de baja';

    return (
        <>
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="size-8" aria-label="Acciones" onClick={(e) => e.stopPropagation()}>
                        <MoreHorizontal className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-64" onClick={(e) => e.stopPropagation()}>
                    <DropdownMenuLabel className="font-mono text-xs">{doc.full_number}</DropdownMenuLabel>
                    <DropdownMenuItem disabled={!registered} onSelect={() => onView(doc)}>
                        <Printer /> Imprimir / ver PDF
                    </DropdownMenuItem>
                    <DropdownMenuItem disabled={!registered} asChild={registered}>
                        {registered ? (
                            <a href={`/documents/${doc.id}/download/pdf?download=1`}>
                                <FileDown /> Descargar PDF
                            </a>
                        ) : (
                            <span>
                                <FileDown /> Descargar PDF
                            </span>
                        )}
                    </DropdownMenuItem>
                    <DropdownMenuItem disabled={!registered} asChild={registered}>
                        {registered ? (
                            <a href={`/documents/${doc.id}/download/xml`}>
                                <FileCode2 /> Descargar XML
                            </a>
                        ) : (
                            <span>
                                <FileCode2 /> Descargar XML
                            </span>
                        )}
                    </DropdownMenuItem>
                    <DropdownMenuItem disabled={!accepted} asChild={accepted}>
                        {accepted ? (
                            <a href={`/documents/${doc.id}/download/cdr`}>
                                <FileArchive /> Descargar CDR
                            </a>
                        ) : (
                            <span>
                                <FileArchive /> Descargar CDR
                            </span>
                        )}
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <Link href={`/documents/${doc.id}`}>
                            <Eye /> Ver detalle
                        </Link>
                    </DropdownMenuItem>

                    {isAdmin && actions && (
                        <>
                            <DropdownMenuSeparator />
                            <ActionItem allowed={actions.credit_note} onSelect={() => setDialog('credit')} icon={<FileMinus2 />}>
                                Nota de crédito
                            </ActionItem>
                            <ActionItem allowed={actions.debit_note} onSelect={() => setDialog('debit')} icon={<FilePlus2 />}>
                                Nota de débito
                            </ActionItem>
                            <ActionItem allowed={actions.void} onSelect={() => setDialog('void')} icon={<Ban />} destructive>
                                {voidLabel}
                            </ActionItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            {dialog && <ActionDialog kind={dialog} doc={doc} onClose={() => setDialog(null)} />}
        </>
    );
}

/** Ítem del menú; si no está disponible, muestra el motivo debajo. */
function ActionItem({
    allowed,
    onSelect,
    icon,
    destructive = false,
    children,
}: {
    allowed: true | string;
    onSelect: () => void;
    icon: React.ReactNode;
    destructive?: boolean;
    children: React.ReactNode;
}) {
    const enabled = allowed === true;
    return (
        <DropdownMenuItem
            disabled={!enabled}
            onSelect={onSelect}
            className={destructive && enabled ? 'text-red-600 focus:text-red-700 [&_svg]:text-red-600' : undefined}
        >
            {icon}
            <span className="flex flex-col">
                {children}
                {!enabled && <span className="text-muted-foreground text-[11px] leading-tight">{allowed}</span>}
            </span>
        </DropdownMenuItem>
    );
}

function ActionDialog({ kind, doc, onClose }: { kind: Exclude<DialogKind, null>; doc: ElectronicDocument; onClose: () => void }) {
    const [data, setData] = useState<NoteData | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);

    useEffect(() => {
        jsonFetch<NoteData>(`/documents/${doc.id}/note-data`)
            .then(setData)
            .catch((e) => setLoadError(e.message ?? 'No se pudieron cargar los datos.'));
    }, [doc.id]);

    const title = {
        credit: 'Nota de crédito',
        debit: 'Nota de débito',
        void: doc.type === '03' ? 'Anular boleta (resumen diario)' : 'Comunicación de baja',
    }[kind];

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {documentTypeLabels[doc.type]} <span className="font-mono">{doc.full_number}</span> · {doc.customer_snapshot.razon_social} ·{' '}
                        {money(doc.total)}
                    </DialogDescription>
                </DialogHeader>
                {loadError ? (
                    <p className="text-sm text-red-600">{loadError}</p>
                ) : !data ? (
                    <div className="text-muted-foreground flex items-center justify-center gap-2 py-8 text-sm">
                        <LoaderCircle className="size-4 animate-spin" /> Cargando…
                    </div>
                ) : kind === 'credit' ? (
                    <CreditNoteForm data={data} onClose={onClose} />
                ) : kind === 'debit' ? (
                    <DebitNoteForm data={data} onClose={onClose} />
                ) : (
                    <VoidForm data={data} onClose={onClose} />
                )}
            </DialogContent>
        </Dialog>
    );
}

function useSubmit(url: string, onClose: () => void) {
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const submit = (payload: Record<string, unknown>) =>
        router.post(url, payload as never, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (e) => setErrors(e),
            onSuccess: () => onClose(),
        });
    return { processing, errors, submit };
}

function CreditNoteForm({ data, onClose }: { data: NoteData; onClose: () => void }) {
    const [code, setCode] = useState(data.credit_reasons[0]?.value ?? '01');
    const reason = data.credit_reasons.find((r) => r.value === code)!;
    const [text, setText] = useState(reason.label.replace(/^\d+ · /, ''));
    const [quantities, setQuantities] = useState<Record<number, string>>({});
    const [amount, setAmount] = useState('');
    const [voidSale, setVoidSale] = useState(!data.document.sale_voided);
    const [restock, setRestock] = useState(true);
    const { processing, errors, submit } = useSubmit(`/documents/${data.document.id}/credit-note`, onClose);

    const total = useMemo(() => {
        if (reason.mode === 'full') return data.document.total;
        if (reason.mode === 'amount') return Number(amount) || 0;
        return data.items.reduce((s, i) => s + (Number(quantities[i.id]) || 0) * i.unit_price, 0);
    }, [reason.mode, amount, quantities, data]);

    const annuls = data.annul_codes.includes(code);
    const fullBlocked = reason.mode === 'full' && data.credited > 0;

    return (
        <form
            className="grid gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                submit({
                    code,
                    reason: text,
                    quantities: Object.fromEntries(Object.entries(quantities).map(([k, v]) => [k, Number(v) || 0])),
                    amount: reason.mode === 'amount' ? Number(amount) : null,
                    void_sale: annuls && voidSale,
                    restock: reason.mode === 'items' && restock,
                });
            }}
        >
            <Field label="Motivo (catálogo 09)" error={errors.code}>
                <Select
                    value={code}
                    onValueChange={(v) => {
                        setCode(v);
                        setText(data.credit_reasons.find((r) => r.value === v)!.label.replace(/^\d+ · /, ''));
                    }}
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {data.credit_reasons.map((r) => (
                            <SelectItem key={r.value} value={r.value}>
                                {r.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>

            <Field label="Sustento" error={errors.reason}>
                <Textarea value={text} onChange={(e) => setText(e.target.value)} rows={2} maxLength={250} />
            </Field>

            {reason.mode === 'items' && (
                <div className="space-y-1.5">
                    <p className="text-sm font-medium">Cantidades devueltas</p>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Producto</TableHead>
                                <TableHead className="text-right">Vendido</TableHead>
                                <TableHead className="w-24 text-right">Devuelve</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.items.map((i) => (
                                <TableRow key={i.id}>
                                    <TableCell>
                                        {i.name}
                                        <span className="text-muted-foreground block text-xs">{money(i.unit_price)} c/u</span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">{qty(i.quantity)}</TableCell>
                                    <TableCell>
                                        <Input
                                            type="number"
                                            min={0}
                                            max={i.quantity}
                                            step="1"
                                            className="h-8 text-right"
                                            value={quantities[i.id] ?? ''}
                                            onChange={(e) => setQuantities({ ...quantities, [i.id]: e.target.value })}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    {errors.quantities && <p className="text-sm text-red-600">{errors.quantities}</p>}
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={restock} onCheckedChange={(v) => setRestock(v === true)} /> Reponer stock de lo devuelto
                    </label>
                </div>
            )}

            {reason.mode === 'amount' && (
                <Field label={`Monto (S/, IGV incluido) · máximo ${money(data.remaining)}`} error={errors.amount}>
                    <Input type="number" min="0" step="0.10" value={amount} onChange={(e) => setAmount(e.target.value)} />
                </Field>
            )}

            {reason.mode === 'full' && <p className="text-muted-foreground text-sm">Se incluyen todos los ítems del comprobante.</p>}
            {fullBlocked && <p className="text-sm text-amber-700">Ya existen notas de crédito sobre este comprobante; use un motivo parcial.</p>}

            {annuls && !data.document.sale_voided && (
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox checked={voidSale} onCheckedChange={(v) => setVoidSale(v === true)} /> Anular también la venta y reponer stock
                </label>
            )}

            {errors.amount && reason.mode !== 'amount' && <p className="text-sm text-red-600">{errors.amount}</p>}
            {errors.document && <p className="text-sm text-red-600">{errors.document}</p>}

            <DialogFooter className="items-center sm:justify-between">
                <p className="text-sm">
                    Total nota: <span className="font-semibold tabular-nums">{money(total)}</span>
                </p>
                <div className="flex gap-2">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" disabled={processing || total <= 0 || total > data.remaining + 0.001 || fullBlocked}>
                        {processing && <LoaderCircle className="animate-spin" />}
                        Emitir nota de crédito
                    </Button>
                </div>
            </DialogFooter>
        </form>
    );
}

function DebitNoteForm({ data, onClose }: { data: NoteData; onClose: () => void }) {
    const [code, setCode] = useState(data.debit_reasons[0]?.value ?? '01');
    const [text, setText] = useState(data.debit_reasons[0]?.label.replace(/^\d+ · /, '') ?? '');
    const [amount, setAmount] = useState('');
    const { processing, errors, submit } = useSubmit(`/documents/${data.document.id}/debit-note`, onClose);

    return (
        <form
            className="grid gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                submit({ code, reason: text, amount: Number(amount) });
            }}
        >
            <Field label="Motivo (catálogo 10)" error={errors.code}>
                <Select
                    value={code}
                    onValueChange={(v) => {
                        setCode(v);
                        setText(data.debit_reasons.find((r) => r.value === v)!.label.replace(/^\d+ · /, ''));
                    }}
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {data.debit_reasons.map((r) => (
                            <SelectItem key={r.value} value={r.value}>
                                {r.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <Field label="Descripción del cargo" error={errors.reason}>
                <Textarea value={text} onChange={(e) => setText(e.target.value)} rows={2} maxLength={250} />
            </Field>
            <Field label="Monto (S/, IGV incluido)" error={errors.amount}>
                <Input type="number" min="0" step="0.10" value={amount} onChange={(e) => setAmount(e.target.value)} />
            </Field>
            {errors.document && <p className="text-sm text-red-600">{errors.document}</p>}
            <DialogFooter>
                <Button type="button" variant="ghost" onClick={onClose}>
                    Cancelar
                </Button>
                <Button type="submit" disabled={processing || !(Number(amount) > 0)}>
                    {processing && <LoaderCircle className="animate-spin" />}
                    Emitir nota de débito
                </Button>
            </DialogFooter>
        </form>
    );
}

function VoidForm({ data, onClose }: { data: NoteData; onClose: () => void }) {
    const [reason, setReason] = useState('');
    const [voidSale, setVoidSale] = useState(!data.document.sale_voided);
    const { processing, errors, submit } = useSubmit(`/documents/${data.document.id}/voiding`, onClose);
    const isSummary = data.void_kind === 'RC';
    const isBase = ['01', '03'].includes(data.document.type);

    return (
        <form
            className="grid gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                submit({ reason, void_sale: isBase && voidSale });
            }}
        >
            <p className="bg-muted/50 rounded-md p-3 text-sm">
                {isSummary
                    ? 'La boleta se anulará mediante un resumen diario de anulación (RC).'
                    : 'Se enviará una comunicación de baja (RA) a SUNAT.'}{' '}
                Plazo: 7 días desde la emisión. El comprobante quedará anulado cuando SUNAT acepte la {isSummary ? 'anulación' : 'baja'}. Esta acción
                no se puede deshacer.
            </p>
            <Field label="Motivo" error={errors.reason}>
                <Input
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    maxLength={100}
                    placeholder="Ej.: Error en datos del cliente"
                    autoFocus
                />
            </Field>
            {isBase && !data.document.sale_voided && (
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox checked={voidSale} onCheckedChange={(v) => setVoidSale(v === true)} /> Anular también la venta y reponer stock
                </label>
            )}
            {errors.document && <p className="text-sm text-red-600">{errors.document}</p>}
            <DialogFooter>
                <Button type="button" variant="ghost" onClick={onClose}>
                    Cancelar
                </Button>
                <Button type="submit" variant="destructive" disabled={processing || reason.trim().length < 3}>
                    {processing && <LoaderCircle className="animate-spin" />}
                    {isSummary ? 'Anular boleta' : 'Enviar comunicación de baja'}
                </Button>
            </DialogFooter>
        </form>
    );
}

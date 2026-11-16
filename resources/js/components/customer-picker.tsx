import { LookupHint } from '@/components/lookup-hint';
import { Field } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useDocumentLookup } from '@/hooks/use-document-lookup';
import { docTypeShort } from '@/lib/format';
import { HttpError, jsonFetch } from '@/lib/http';
import { isFullDocNumber, lookupDocument, type LookupData } from '@/lib/lookup';
import { type Customer, type Option } from '@/types';
import { BadgeCheck, LoaderCircle, Search, TriangleAlert, UserPlus, X } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';

interface Props {
    value: Customer | null;
    onChange: (customer: Customer | null) => void;
    docTypes: Option[];
    /** Para factura: solo clientes con RUC. */
    requireRuc?: boolean;
    placeholder?: string;
}

type Remote = { state: 'idle' } | { state: 'loading' } | { state: 'found'; data: LookupData } | { state: 'error'; message: string };

export function CustomerPicker({ value, onChange, docTypes, requireRuc = false, placeholder }: Props) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Customer[]>([]);
    const [loading, setLoading] = useState(false);
    const [remote, setRemote] = useState<Remote>({ state: 'idle' });
    const [saving, setSaving] = useState(false);
    const [open, setOpen] = useState(false);
    const [creating, setCreating] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    const q = query.trim();
    const fullNumber = isFullDocNumber(q) && (!requireRuc || q.length === 11);

    // 1) Búsqueda en clientes registrados.
    useEffect(() => {
        setRemote({ state: 'idle' });
        if (q.length < 2) {
            setResults([]);
            return;
        }
        setLoading(true);
        let cancelled = false;
        const timer = setTimeout(() => {
            jsonFetch<Customer[]>(`/customers/search?q=${encodeURIComponent(q)}`)
                .then((rows) => {
                    if (cancelled) return;
                    const filtered = requireRuc ? rows.filter((c) => c.doc_type === '6') : rows;
                    setResults(filtered);
                    // 2) DNI/RUC completo y no registrado: consulta RENIEC/SUNAT.
                    if (fullNumber && !filtered.some((c) => c.doc_number === q)) void consultRemote();
                })
                .catch(() => !cancelled && setResults([]))
                .finally(() => !cancelled && setLoading(false));
        }, 250);
        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q, requireRuc]);

    async function consultRemote() {
        setRemote({ state: 'loading' });
        const result = await lookupDocument(q);
        if (result.source === 'api') setRemote({ state: 'found', data: result.data });
        else if (result.source === 'local') {
            setResults([result.customer]);
            setRemote({ state: 'idle' });
        } else if (result.source === 'error') setRemote({ state: 'error', message: result.message });
        else setRemote({ state: 'idle' });
    }

    useEffect(() => {
        const close = (e: MouseEvent) => {
            if (box.current && !box.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, []);

    function select(c: Customer) {
        onChange(c);
        setQuery('');
        setOpen(false);
        setRemote({ state: 'idle' });
    }

    /** Registra el cliente con los datos de RENIEC/SUNAT y lo selecciona. */
    async function registerFound(data: LookupData) {
        // RUC sin dirección (p. ej. no habido): completar en el formulario.
        if (data.doc_type === '6' && !data.address) {
            setCreating(true);
            setOpen(false);
            return;
        }
        setSaving(true);
        try {
            const customer = await jsonFetch<Customer>('/customers/quick', {
                method: 'POST',
                body: { doc_type: data.doc_type, doc_number: data.doc_number, name: data.name, address: data.address ?? '' },
            });
            select(customer);
        } catch (e) {
            setRemote({ state: 'error', message: e instanceof HttpError ? e.message : 'No se pudo registrar el cliente.' });
        } finally {
            setSaving(false);
        }
    }

    if (value) {
        return (
            <div className="bg-muted/50 flex items-center gap-2 rounded-md border px-3 py-2">
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{value.name}</p>
                    <p className="text-muted-foreground text-xs">
                        {docTypeShort[value.doc_type]} {value.doc_number}
                    </p>
                </div>
                <Button type="button" variant="ghost" size="icon" className="size-7" onClick={() => onChange(null)} aria-label="Quitar cliente">
                    <X className="size-4" />
                </Button>
            </div>
        );
    }

    const busy = loading || remote.state === 'loading' || saving;

    return (
        <div ref={box} className="relative">
            <div className="flex gap-2">
                <div className="relative flex-1">
                    <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                    <Input
                        value={query}
                        onChange={(e) => {
                            setQuery(e.target.value);
                            setOpen(true);
                        }}
                        onFocus={() => setOpen(true)}
                        onKeyDown={(e) => {
                            if (e.key !== 'Enter') return;
                            e.preventDefault();
                            if (results[0]) select(results[0]);
                            else if (remote.state === 'found') void registerFound(remote.data);
                        }}
                        placeholder={placeholder ?? (requireRuc ? 'RUC o razón social' : 'DNI, RUC o nombre')}
                        className="pl-8"
                        inputMode="search"
                    />
                    {busy && <LoaderCircle className="text-muted-foreground absolute top-2.5 right-2.5 size-4 animate-spin" />}
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    onClick={() => setCreating(true)}
                    aria-label="Nuevo cliente"
                    title="Nuevo cliente"
                >
                    <UserPlus className="size-4" />
                </Button>
            </div>

            {open && q.length >= 2 && !loading && (
                <div className="bg-popover absolute z-20 mt-1 w-full overflow-hidden rounded-md border shadow-md">
                    {results.map((c) => (
                        <button
                            key={c.id}
                            type="button"
                            className="hover:bg-muted flex w-full flex-col px-3 py-2 text-left"
                            onClick={() => select(c)}
                        >
                            <span className="truncate text-sm font-medium">{c.name}</span>
                            <span className="text-muted-foreground text-xs">
                                {docTypeShort[c.doc_type]} {c.doc_number}
                            </span>
                        </button>
                    ))}

                    {remote.state === 'loading' && (
                        <p className="text-muted-foreground flex items-center gap-2 px-3 py-2 text-sm">
                            <LoaderCircle className="size-4 animate-spin" /> Consultando {q.length === 11 ? 'SUNAT' : 'RENIEC'}…
                        </p>
                    )}

                    {remote.state === 'found' && (
                        <button
                            type="button"
                            disabled={saving}
                            className="hover:bg-muted flex w-full items-start gap-2 px-3 py-2 text-left"
                            onClick={() => void registerFound(remote.data)}
                        >
                            <BadgeCheck className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-medium">{remote.data.name}</span>
                                <span className="text-muted-foreground block text-xs">
                                    {docTypeShort[remote.data.doc_type]} {remote.data.doc_number} ·{' '}
                                    {remote.data.doc_type === '6' ? 'SUNAT' : 'RENIEC'} ·{' '}
                                    <span className="text-foreground font-medium">Clic para registrar y usar</span>
                                </span>
                                {remote.data.warning && <span className="block text-xs text-amber-700">{remote.data.warning}</span>}
                            </span>
                        </button>
                    )}

                    {remote.state === 'error' && (
                        <p className="flex items-start gap-2 px-3 py-2 text-xs text-amber-700 dark:text-amber-400">
                            <TriangleAlert className="mt-0.5 size-3.5 shrink-0" /> {remote.message}
                        </p>
                    )}

                    {results.length === 0 && remote.state !== 'found' && remote.state !== 'loading' && (
                        <button
                            type="button"
                            className="hover:bg-muted w-full px-3 py-2 text-left text-sm"
                            onClick={() => {
                                setCreating(true);
                                setOpen(false);
                            }}
                        >
                            Sin resultados · <span className="font-medium">Registrar manualmente</span>
                        </button>
                    )}
                </div>
            )}

            <QuickCustomerDialog
                open={creating}
                onOpenChange={setCreating}
                docTypes={docTypes}
                initialQuery={query}
                requireRuc={requireRuc}
                onCreated={select}
            />
        </div>
    );
}

function QuickCustomerDialog({
    open,
    onOpenChange,
    docTypes,
    initialQuery,
    requireRuc,
    onCreated,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    docTypes: Option[];
    initialQuery: string;
    requireRuc: boolean;
    onCreated: (c: Customer) => void;
}) {
    const [form, setForm] = useState({ doc_type: '1', doc_number: '', name: '', address: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [existingId, setExistingId] = useState<number | null>(null);

    useEffect(() => {
        if (!open) return;
        const q = initialQuery.trim();
        const isNumber = /^\d+$/.test(q);
        const type = requireRuc || (isNumber && q.length === 11) ? '6' : '1';
        setForm({ doc_type: type, doc_number: isNumber ? q : '', name: isNumber ? '' : q.toUpperCase(), address: '' });
        setErrors({});
        setExistingId(null);
    }, [open, initialQuery, requireRuc]);

    // Autocompleta nombre y dirección al completar el DNI/RUC.
    const lookup = useDocumentLookup(
        form.doc_type,
        form.doc_number,
        (fill) => {
            setForm((f) => ({ ...f, name: fill.name, address: fill.address ?? f.address }));
            setExistingId(fill.existingId ?? null);
        },
        open,
    );

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        if (existingId) {
            onCreated({ id: existingId, doc_type: form.doc_type, doc_number: form.doc_number, name: form.name, address: form.address || null });
            onOpenChange(false);
            return;
        }
        setSaving(true);
        try {
            const customer = await jsonFetch<Customer>('/customers/quick', { method: 'POST', body: form });
            onCreated(customer);
            onOpenChange(false);
        } catch (err) {
            if (err instanceof HttpError) {
                setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
            }
        } finally {
            setSaving(false);
        }
    };

    const isRuc = form.doc_type === '6';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Nuevo cliente</DialogTitle>
                    <DialogDescription>Ingrese el DNI o RUC: el nombre y la dirección se completan automáticamente.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid grid-cols-[130px_1fr] gap-3">
                        <Field label="Documento" error={errors.doc_type}>
                            <Select value={form.doc_type} onValueChange={(v) => setForm({ ...form, doc_type: v })} disabled={requireRuc}>
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
                        <Field label="Número" error={errors.doc_number}>
                            <Input
                                value={form.doc_number}
                                onChange={(e) => setForm({ ...form, doc_number: e.target.value.trim() })}
                                inputMode={form.doc_type === '1' || isRuc ? 'numeric' : 'text'}
                                maxLength={isRuc ? 11 : 15}
                                autoFocus
                            />
                        </Field>
                    </div>
                    <LookupHint loading={lookup.loading} message={lookup.message} />
                    <Field label={isRuc ? 'Razón social' : 'Nombre completo'} error={errors.name}>
                        <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    </Field>
                    <Field label={isRuc ? 'Dirección fiscal' : 'Dirección (opcional)'} error={errors.address}>
                        <Input value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={saving || lookup.loading}>
                            {saving && <LoaderCircle className="animate-spin" />}
                            {existingId ? 'Usar cliente' : 'Guardar'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

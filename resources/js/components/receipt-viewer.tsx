import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { money } from '@/lib/format';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';
import { type DocumentStatus } from '@/types';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { BadgeCheck, CircleX, Clock, FileArchive, FileCode2, FileDown, LoaderCircle, Plus, Printer, RefreshCw, TriangleAlert, X } from 'lucide-react';
import { type CSSProperties, useCallback, useEffect, useRef, useState } from 'react';

export interface ReceiptSale {
    id: number;
    code: string;
    total: string;
    change: string | null;
    payment_method: string;
    document: {
        id: number;
        number: string;
        type: string;
        status: DocumentStatus;
        message: string | null;
        has_pdf: boolean;
    } | null;
}

interface LiveStatus {
    number: string;
    status: DocumentStatus;
    message: string | null;
    registered: boolean;
    final: boolean;
}

const FORMATS = [
    { value: 'ticket-80', short: '80', title: 'Ticket 80 mm' },
    { value: 'ticket-58', short: '58', title: 'Ticket 58 mm' },
    { value: 'a4', short: 'A4', title: 'Hoja A4' },
    { value: 'a5', short: 'A5', title: 'Hoja A5' },
];

/**
 * Tamaño de la hoja según el formato: los tickets con su ancho de papel,
 * A4/A5 con la proporción real de la hoja (alto = 90% de la pantalla).
 */
function sheetStyle(format: string | null): CSSProperties {
    switch (format) {
        case 'ticket-58':
            return { width: 260 };
        case 'a4':
            return { aspectRatio: '210 / 297' };
        case 'a5':
            return { aspectRatio: '148 / 210' };
        default: // ticket-80 y ticket interno
            return { width: 340 };
    }
}

const POLL_MS = 3000;
const MAX_POLLS = 40; // ~2 minutos

/**
 * Visor del comprobante tras cobrar: la hoja (PDF o ticket interno) con el tamaño
 * del formato y, flotando a su lado, los controles: estado SUNAT en vivo, tamaño,
 * imprimir y descargas.
 */
export function ReceiptViewer({
    sale,
    open,
    onOpenChange,
    defaultFormat = 'ticket-80',
    onNewSale,
}: {
    sale: ReceiptSale | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    defaultFormat?: string;
    onNewSale?: () => void;
}) {
    const doc = sale?.document ?? null;
    const [format, setFormat] = useState(defaultFormat);
    const [live, setLive] = useState<LiveStatus | null>(null);
    const [checking, setChecking] = useState(false);
    const [frameKey, setFrameKey] = useState(0);
    const [frameLoading, setFrameLoading] = useState(true);
    const polls = useRef(0);
    const registeredRef = useRef(false);
    const frame = useRef<HTMLIFrameElement>(null);

    // Reinicia el estado al abrir otra venta.
    useEffect(() => {
        if (!sale) return;
        setFormat(defaultFormat);
        polls.current = 0;
        registeredRef.current = doc?.has_pdf ?? false;
        setFrameLoading(true);
        setLive(
            doc
                ? {
                      number: doc.number,
                      status: doc.status,
                      message: doc.message,
                      registered: doc.has_pdf,
                      final: !['pendiente', 'enviado', 'error_envio', 'borrador'].includes(doc.status),
                  }
                : null,
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sale?.id]);

    const check = useCallback(async () => {
        if (!doc) return;
        setChecking(true);
        try {
            const next = await jsonFetch<LiveStatus>(`/documents/${doc.id}/status`);
            // Al registrarse en el proveedor, el visor cambia del ticket interno al PDF.
            if (next.registered && !registeredRef.current) setFrameLoading(true);
            registeredRef.current = next.registered;
            setLive(next);
        } catch {
            // sin conexión momentánea: se reintenta en el siguiente ciclo
        } finally {
            setChecking(false);
        }
    }, [doc]);

    // Estado SUNAT en tiempo real mientras el modal está abierto y el estado no es definitivo.
    useEffect(() => {
        if (!open || !doc || live?.final) return;
        const timer = setInterval(() => {
            if (polls.current >= MAX_POLLS) return clearInterval(timer);
            polls.current++;
            void check();
        }, POLL_MS);
        return () => clearInterval(timer);
    }, [open, doc, live?.final, check]);

    if (!sale) return null;

    const registered = live?.registered ?? false;
    const status = live?.status ?? doc?.status;
    const showPdf = !!doc && registered;
    const viewUrl = showPdf ? `/documents/${doc.id}/download/pdf?format=${format}` : `/sales/${sale.id}/ticket?embed=1`;
    // Sin barra nativa del visor PDF y con la página ajustada al ancho de la hoja.
    const frameSrc = showPdf ? `${viewUrl}#toolbar=0&navpanes=0&view=FitH` : viewUrl;
    const accepted = status === 'aceptado';
    const waiting = !!doc && !live?.final;
    const problem = status === 'error_envio' || (status && ['error', 'rechazado', 'revision_manual'].includes(status));
    const change = sale.change !== null && Number(sale.change) > 0 ? Number(sale.change) : 0;
    const statusTooltip = [
        live?.number ?? doc?.number,
        statusLabel(status),
        problem ? live?.message : null,
        waiting ? 'consultando SUNAT…' : 'clic para actualizar',
    ]
        .filter(Boolean)
        .join(' · ');

    function print() {
        const win = frame.current?.contentWindow;
        try {
            win?.focus();
            win?.print();
        } catch {
            window.open(viewUrl, '_blank');
        }
    }

    function close() {
        onOpenChange(false);
        onNewSale?.();
    }

    return (
        <Dialog open={open} onOpenChange={(v) => (v ? onOpenChange(true) : close())}>
            <DialogContent
                hideClose
                className="flex w-auto max-w-[calc(100vw-1rem)] flex-col items-center gap-4 border-0 bg-transparent p-0 shadow-none sm:max-w-[calc(100vw-2rem)] sm:flex-row sm:items-start"
                onOpenAutoFocus={(e) => e.preventDefault()}
            >
                <DialogTitle className="sr-only">Comprobante de la venta {sale.code}</DialogTitle>
                <DialogDescription className="sr-only">Vista previa del comprobante con opciones de impresión y descarga.</DialogDescription>

                {/* Hoja */}
                <div
                    className="relative h-[72vh] max-w-full shrink-0 overflow-hidden rounded-sm bg-white shadow-2xl transition-[width,aspect-ratio] duration-200 sm:h-[90vh]"
                    style={sheetStyle(showPdf ? format : null)}
                >
                    <iframe
                        key={`${frameSrc}-${frameKey}`}
                        ref={frame}
                        src={frameSrc}
                        title="Comprobante"
                        className={cn('absolute inset-0 size-full border-0 bg-white transition-opacity', frameLoading && 'opacity-0')}
                        onLoad={() => setFrameLoading(false)}
                    />
                    {frameLoading && (
                        <div className="absolute inset-0 flex items-center justify-center text-neutral-500">
                            <LoaderCircle className="size-5 animate-spin" />
                        </div>
                    )}
                </div>

                {/* Controles flotantes: botones redondos iguales, solo ícono, con texto al pasar el mouse */}
                <TooltipProvider delayDuration={100}>
                    <div className="flex max-w-[calc(100vw-1rem)] flex-wrap items-center justify-center gap-2 sm:flex-col sm:flex-nowrap">
                        {change > 0 && (
                            <span className="text-center text-sm leading-tight font-bold whitespace-nowrap text-white tabular-nums drop-shadow sm:mb-1">
                                Vuelto
                                <br />
                                {money(change)}
                            </span>
                        )}

                        {doc && (
                            <RoundButton label={statusTooltip} onClick={() => void check()} className={statusTone(status)}>
                                <StatusIcon status={status} spinning={waiting || checking} />
                            </RoundButton>
                        )}

                        {showPdf && (
                            <>
                                <Gap />
                                {FORMATS.map((f) => (
                                    <RoundButton
                                        key={f.value}
                                        label={f.title}
                                        active={format === f.value}
                                        onClick={() => {
                                            if (f.value === format) return;
                                            setFormat(f.value);
                                            setFrameLoading(true);
                                        }}
                                    >
                                        <span className="text-xs font-bold">{f.short}</span>
                                    </RoundButton>
                                ))}
                            </>
                        )}

                        <Gap />
                        <RoundButton label="Imprimir" onClick={print} disabled={frameLoading} active>
                            <Printer />
                        </RoundButton>

                        {showPdf && (
                            <>
                                <RoundButton label="Descargar PDF" href={`/documents/${doc.id}/download/pdf?format=${format}&download=1`}>
                                    <FileDown />
                                </RoundButton>
                                <RoundButton label="Descargar XML" href={`/documents/${doc.id}/download/xml`}>
                                    <FileCode2 />
                                </RoundButton>
                                <RoundButton
                                    label={accepted ? 'Descargar CDR' : 'CDR: disponible cuando SUNAT acepte el comprobante'}
                                    href={accepted ? `/documents/${doc.id}/download/cdr` : undefined}
                                    disabled={!accepted}
                                >
                                    <FileArchive />
                                </RoundButton>
                            </>
                        )}

                        <RoundButton
                            label="Recargar vista"
                            onClick={() => {
                                setFrameLoading(true);
                                setFrameKey((k) => k + 1);
                            }}
                        >
                            <RefreshCw />
                        </RoundButton>

                        <Gap />
                        <RoundButton
                            label={onNewSale ? 'Nueva venta' : 'Cerrar'}
                            onClick={close}
                            className="bg-emerald-600 text-white hover:bg-emerald-500"
                            autoFocus
                        >
                            {onNewSale ? <Plus /> : <X />}
                        </RoundButton>
                    </div>
                </TooltipProvider>

                <button
                    type="button"
                    onClick={close}
                    className="fixed top-3 right-3 flex size-10 items-center justify-center rounded-full text-white/80 transition hover:bg-white/10 hover:text-white"
                    title="Cerrar (Esc)"
                    aria-label="Cerrar"
                >
                    <X className="size-6" />
                </button>
            </DialogContent>
        </Dialog>
    );
}

function statusLabel(status?: DocumentStatus): string {
    const labels: Record<DocumentStatus, string> = {
        aceptado: 'Aceptado por SUNAT',
        pendiente: 'Pendiente',
        enviado: 'Enviado, esperando SUNAT',
        borrador: 'Borrador',
        error_envio: 'Error de envío (se reintentará solo)',
        error: 'Error',
        rechazado: 'Rechazado por SUNAT',
        revision_manual: 'Requiere revisión',
        anulado: 'Anulado',
    };
    return status ? labels[status] : '';
}

function statusTone(status?: DocumentStatus): string {
    if (status === 'aceptado') return 'bg-emerald-500/90 text-white hover:bg-emerald-500';
    if (status && ['error', 'rechazado', 'revision_manual'].includes(status)) return 'bg-red-500/90 text-white hover:bg-red-500';
    if (status === 'error_envio') return 'bg-amber-500/90 text-white hover:bg-amber-500';
    return 'bg-sky-500/90 text-white hover:bg-sky-500';
}

function StatusIcon({ status, spinning }: { status?: DocumentStatus; spinning: boolean }) {
    if (spinning) return <LoaderCircle className="animate-spin" />;
    if (status === 'aceptado') return <BadgeCheck />;
    if (status && ['error', 'rechazado', 'revision_manual'].includes(status)) return <CircleX />;
    if (status === 'error_envio') return <TriangleAlert />;
    return <Clock />;
}

/** Separación entre grupos de botones. */
function Gap() {
    return <span className="hidden h-1.5 sm:block" />;
}

/** Botón flotante redondo, solo ícono; el texto aparece al pasar el mouse. */
function RoundButton({
    label,
    href,
    onClick,
    disabled = false,
    active = false,
    autoFocus = false,
    className,
    children,
}: {
    label: string;
    href?: string;
    onClick?: () => void;
    disabled?: boolean;
    active?: boolean;
    autoFocus?: boolean;
    className?: string;
    children: React.ReactNode;
}) {
    const classes = cn(
        'flex size-11 shrink-0 items-center justify-center rounded-full shadow-lg backdrop-blur transition [&_svg]:size-5',
        active ? 'bg-white text-neutral-900 hover:bg-neutral-100' : 'bg-white/15 text-white hover:bg-white/30',
        disabled && 'cursor-not-allowed opacity-40 hover:bg-white/15',
        className,
    );

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {href && !disabled ? (
                    <a href={href} className={classes} aria-label={label}>
                        {children}
                    </a>
                ) : (
                    <button
                        type="button"
                        onClick={disabled ? undefined : onClick}
                        aria-disabled={disabled}
                        aria-label={label}
                        autoFocus={autoFocus}
                        className={classes}
                    >
                        {children}
                    </button>
                )}
            </TooltipTrigger>
            {/* Portal: el modal usa transform y desplazaría el tooltip. */}
            <TooltipPrimitive.Portal>
                <TooltipContent side="right" className="z-[60] max-w-xs">
                    {label}
                </TooltipContent>
            </TooltipPrimitive.Portal>
        </Tooltip>
    );
}

import { cn } from '@/lib/utils';
import { type DocumentStatus } from '@/types';

const styles: Record<DocumentStatus, string> = {
    aceptado: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    pendiente: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    enviado: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    borrador: 'bg-muted text-muted-foreground',
    error_envio: 'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-300',
    error: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    rechazado: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    revision_manual: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    anulado: 'bg-muted text-muted-foreground line-through',
};

const labels: Record<DocumentStatus, string> = {
    aceptado: 'Aceptado',
    pendiente: 'Pendiente',
    enviado: 'Enviado',
    borrador: 'Borrador',
    error_envio: 'Error de envío',
    error: 'Error',
    rechazado: 'Rechazado',
    revision_manual: 'Revisión manual',
    anulado: 'Anulado',
};

export function StatusBadge({ status, className }: { status: DocumentStatus; className?: string }) {
    return (
        <span className={cn('inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap', styles[status], className)}>
            {labels[status] ?? status}
        </span>
    );
}

export function SaleStatusBadge({ status }: { status: 'completada' | 'anulada' }) {
    return status === 'anulada' ? (
        <span className="inline-flex rounded-md bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-950 dark:text-red-300">
            Anulada
        </span>
    ) : (
        <span className="text-muted-foreground text-xs">Completada</span>
    );
}

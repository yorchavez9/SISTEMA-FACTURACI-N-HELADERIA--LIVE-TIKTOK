import { cn } from '@/lib/utils';
import { CheckCircle2, LoaderCircle, TriangleAlert } from 'lucide-react';

/** Mensaje bajo el número de documento: consultando / datos encontrados / error. */
export function LookupHint({ loading, message }: { loading: boolean; message: { text: string; tone: 'ok' | 'warn' | 'error' } | null }) {
    if (loading) {
        return (
            <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
                <LoaderCircle className="size-3.5 animate-spin" /> Consultando…
            </p>
        );
    }
    if (!message) return null;

    const Icon = message.tone === 'ok' ? CheckCircle2 : TriangleAlert;
    return (
        <p
            className={cn(
                'flex items-start gap-1.5 text-xs',
                message.tone === 'ok' && 'text-emerald-700 dark:text-emerald-400',
                message.tone === 'warn' && 'text-amber-700 dark:text-amber-400',
                message.tone === 'error' && 'text-red-600 dark:text-red-400',
            )}
        >
            <Icon className="mt-0.5 size-3.5 shrink-0" /> {message.text}
        </p>
    );
}

import { cn } from '@/lib/utils';

/** Contenedor estándar de página: título, descripción y contenido. */
export function Page({
    title,
    description,
    actions,
    children,
    className,
}: {
    title?: string;
    description?: string;
    actions?: React.ReactNode;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('mx-auto flex w-full max-w-6xl flex-1 flex-col gap-5 p-4 md:p-6', className)}>
            {(title || actions) && (
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        {title && <h1 className="text-xl font-semibold tracking-tight">{title}</h1>}
                        {description && <p className="text-muted-foreground text-sm">{description}</p>}
                    </div>
                    {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
                </div>
            )}
            {children}
        </div>
    );
}

/** Cifra con etiqueta, sin tarjeta. */
export function Stat({ label, value, hint }: { label: string; value: React.ReactNode; hint?: React.ReactNode }) {
    return (
        <div className="space-y-1">
            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</p>
            <p className="text-2xl font-semibold tabular-nums">{value}</p>
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
        </div>
    );
}

export function EmptyState({ children }: { children: React.ReactNode }) {
    return <div className="text-muted-foreground py-10 text-center text-sm">{children}</div>;
}

/** Campo de formulario: label + control + error. */
export function Field({
    label,
    error,
    hint,
    children,
    className,
    htmlFor,
}: {
    label: string;
    error?: string;
    hint?: string;
    children: React.ReactNode;
    className?: string;
    htmlFor?: string;
}) {
    return (
        <div className={cn('grid gap-1.5', className)}>
            <label htmlFor={htmlFor} className="text-sm font-medium">
                {label}
            </label>
            {children}
            {hint && !error && <p className="text-muted-foreground text-xs">{hint}</p>}
            {error && <p className="text-sm text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

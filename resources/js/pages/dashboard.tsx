import { BarChart, ShareBar } from '@/components/bar-chart';
import { Page, Stat } from '@/components/page';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { money, qty } from '@/lib/format';
import { Head, Link } from '@inertiajs/react';
import { FileWarning, PackageX, PlugZap, ShoppingCart } from 'lucide-react';

interface Summary {
    count: number;
    total: number;
    average: number;
}

interface Props {
    summary: Summary;
    yesterday: Summary;
    byPaymentMethod: { method: string; label: string; count: number; total: number }[];
    byHour: { hour: string; total: number }[];
    topProducts: { product_id: number; name: string; quantity: number; total: number }[];
    alerts: {
        billingConfigured: boolean;
        documentsNeedingAttention: number;
        documentsDelayed: number;
        lowStock: { id: number; name: string; stock: string; stock_min: string }[];
    };
}

function delta(today: number, yesterday: number) {
    if (yesterday === 0) return null;
    const pct = ((today - yesterday) / yesterday) * 100;
    return `${pct >= 0 ? '+' : ''}${pct.toFixed(0)}% vs. ayer`;
}

export default function Dashboard({ summary, yesterday, byPaymentMethod, byHour, topProducts, alerts }: Props) {
    const maxMethod = Math.max(...byPaymentMethod.map((m) => m.total), 0);
    const maxProduct = Math.max(...topProducts.map((p) => p.quantity), 0);
    const hasAlerts = !alerts.billingConfigured || alerts.documentsNeedingAttention > 0 || alerts.documentsDelayed > 0 || alerts.lowStock.length > 0;

    return (
        <AppLayout breadcrumbs={[{ title: 'Dashboard', href: '/dashboard' }]}>
            <Head title="Dashboard" />
            <Page
                title="Hoy"
                description={new Date().toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' })}
                actions={
                    <Button asChild>
                        <Link href="/pos">
                            <ShoppingCart /> Ir a vender
                        </Link>
                    </Button>
                }
            >
                <div className="grid grid-cols-2 gap-6 border-b pb-6 md:grid-cols-3">
                    <Stat
                        label="Total vendido"
                        value={money(summary.total)}
                        hint={delta(summary.total, yesterday.total) ?? `Ayer: ${money(yesterday.total)}`}
                    />
                    <Stat label="Ventas" value={summary.count} hint={`Ayer: ${yesterday.count}`} />
                    <Stat label="Ticket promedio" value={money(summary.average)} />
                </div>

                {hasAlerts && (
                    <ul className="space-y-2">
                        {!alerts.billingConfigured && (
                            <Alert icon={PlugZap} href="/settings/billing">
                                La facturación electrónica no está configurada. Solo se pueden registrar ventas sin comprobante.
                            </Alert>
                        )}
                        {alerts.documentsNeedingAttention > 0 && (
                            <Alert icon={FileWarning} href="/documents?status=rechazado" tone="critical">
                                {alerts.documentsNeedingAttention} comprobante(s) con error o rechazados requieren revisión.
                            </Alert>
                        )}
                        {alerts.documentsDelayed > 0 && (
                            <Alert icon={FileWarning} href="/documents?status=pendiente">
                                {alerts.documentsDelayed} comprobante(s) llevan más de 1 hora sin confirmación de SUNAT.
                            </Alert>
                        )}
                        {alerts.lowStock.length > 0 && (
                            <Alert icon={PackageX} href="/products?status=low_stock">
                                Stock bajo: {alerts.lowStock.map((p) => `${p.name} (${qty(p.stock)})`).join(', ')}
                            </Alert>
                        )}
                    </ul>
                )}

                <section className="space-y-2">
                    <h2 className="text-sm font-semibold">Ventas por hora</h2>
                    {summary.count === 0 ? (
                        <p className="text-muted-foreground py-8 text-center text-sm">Aún no hay ventas hoy.</p>
                    ) : (
                        <BarChart data={byHour} xKey="hour" yKey="total" />
                    )}
                </section>

                <div className="grid gap-8 md:grid-cols-2">
                    <section className="space-y-3">
                        <h2 className="text-sm font-semibold">Por método de pago</h2>
                        <ul className="space-y-3">
                            {byPaymentMethod.map((m) => (
                                <li key={m.method} className="space-y-1">
                                    <div className="flex justify-between text-sm">
                                        <span>
                                            {m.label} <span className="text-muted-foreground text-xs">· {m.count}</span>
                                        </span>
                                        <span className="font-medium tabular-nums">{money(m.total)}</span>
                                    </div>
                                    <ShareBar value={m.total} max={maxMethod} />
                                </li>
                            ))}
                        </ul>
                    </section>

                    <section className="space-y-3">
                        <h2 className="text-sm font-semibold">Más vendidos hoy</h2>
                        {topProducts.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Sin datos todavía.</p>
                        ) : (
                            <ol className="space-y-3">
                                {topProducts.map((p, i) => (
                                    <li key={p.product_id} className="space-y-1">
                                        <div className="flex justify-between gap-2 text-sm">
                                            <span className="truncate">
                                                <span className="text-muted-foreground mr-1.5 tabular-nums">{i + 1}.</span>
                                                {p.name}
                                            </span>
                                            <span className="shrink-0 tabular-nums">
                                                {qty(p.quantity)} und · <span className="text-muted-foreground">{money(p.total)}</span>
                                            </span>
                                        </div>
                                        <ShareBar value={p.quantity} max={maxProduct} />
                                    </li>
                                ))}
                            </ol>
                        )}
                    </section>
                </div>
            </Page>
        </AppLayout>
    );
}

function Alert({
    icon: Icon,
    href,
    tone = 'warning',
    children,
}: {
    icon: React.ComponentType<{ className?: string }>;
    href: string;
    tone?: 'warning' | 'critical';
    children: React.ReactNode;
}) {
    return (
        <li>
            <Link
                href={href}
                className={`flex items-start gap-2 rounded-md border px-3 py-2 text-sm hover:underline ${
                    tone === 'critical'
                        ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200'
                        : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200'
                }`}
            >
                <Icon className="mt-0.5 size-4 shrink-0" />
                <span>{children}</span>
            </Link>
        </li>
    );
}

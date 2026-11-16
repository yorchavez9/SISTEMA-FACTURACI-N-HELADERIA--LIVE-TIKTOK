import { BarChart, ShareBar } from '@/components/bar-chart';
import { Page, Stat } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { date, money, qty } from '@/lib/format';
import { Head, router } from '@inertiajs/react';

interface Props {
    filters: { from: string; to: string };
    summary: { count: number; total: number; average: number };
    byDay: { date: string; count: number; total: number }[];
    byPaymentMethod: { method: string; label: string; count: number; total: number }[];
    topProducts: { product_id: number; name: string; quantity: number; total: number }[];
}

function iso(d: Date) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const presets = [
    { label: 'Hoy', range: () => [new Date(), new Date()] },
    { label: '7 días', range: () => [new Date(Date.now() - 6 * 864e5), new Date()] },
    { label: 'Este mes', range: () => [new Date(new Date().getFullYear(), new Date().getMonth(), 1), new Date()] },
    {
        label: 'Mes anterior',
        range: () => [new Date(new Date().getFullYear(), new Date().getMonth() - 1, 1), new Date(new Date().getFullYear(), new Date().getMonth(), 0)],
    },
];

export default function Reports({ filters, summary, byDay, byPaymentMethod, topProducts }: Props) {
    const apply = (from: string, to: string) => router.get('/reports', { from, to }, { preserveState: true, preserveScroll: true, replace: true });
    const maxMethod = Math.max(...byPaymentMethod.map((m) => m.total), 0);
    const maxProduct = Math.max(...topProducts.map((p) => p.quantity), 0);

    return (
        <AppLayout breadcrumbs={[{ title: 'Reportes', href: '/reports' }]}>
            <Head title="Reportes" />
            <Page title="Reportes" description="Solo ventas completadas (las anuladas no se cuentan).">
                <div className="flex flex-wrap items-center gap-2">
                    {presets.map((p) => {
                        const [f, t] = p.range().map(iso);
                        const active = f === filters.from && t === filters.to;
                        return (
                            <Button key={p.label} size="sm" variant={active ? 'default' : 'outline'} onClick={() => apply(f, t)}>
                                {p.label}
                            </Button>
                        );
                    })}
                    <div className="flex items-center gap-2">
                        <Input
                            type="date"
                            value={filters.from}
                            onChange={(e) => apply(e.target.value, filters.to)}
                            className="w-auto"
                            aria-label="Desde"
                        />
                        <span className="text-muted-foreground text-sm">a</span>
                        <Input
                            type="date"
                            value={filters.to}
                            onChange={(e) => apply(filters.from, e.target.value)}
                            className="w-auto"
                            aria-label="Hasta"
                        />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-6 border-b pb-6 md:grid-cols-3">
                    <Stat label="Total vendido" value={money(summary.total)} />
                    <Stat label="Ventas" value={summary.count} />
                    <Stat label="Ticket promedio" value={money(summary.average)} />
                </div>

                <Tabs defaultValue="days">
                    <TabsList>
                        <TabsTrigger value="days">Por día</TabsTrigger>
                        <TabsTrigger value="payment">Método de pago</TabsTrigger>
                        <TabsTrigger value="products">Productos</TabsTrigger>
                    </TabsList>

                    <TabsContent value="days" className="space-y-4">
                        {byDay.length > 1 && (
                            <BarChart data={byDay} xKey="date" yKey="total" formatX={(v) => date(String(v)).replace(/ de \d{4}$/, '')} />
                        )}
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Fecha</TableHead>
                                    <TableHead className="text-right">Ventas</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {byDay.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={3} className="text-muted-foreground py-8 text-center">
                                            Sin ventas en el rango.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {byDay.map((d) => (
                                    <TableRow key={d.date}>
                                        <TableCell>{date(d.date)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{d.count}</TableCell>
                                        <TableCell className="text-right tabular-nums">{money(d.total)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            {byDay.length > 0 && (
                                <TableFooter>
                                    <TableRow>
                                        <TableCell>Total</TableCell>
                                        <TableCell className="text-right tabular-nums">{summary.count}</TableCell>
                                        <TableCell className="text-right tabular-nums">{money(summary.total)}</TableCell>
                                    </TableRow>
                                </TableFooter>
                            )}
                        </Table>
                    </TabsContent>

                    <TabsContent value="payment">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Método</TableHead>
                                    <TableHead className="w-1/3" />
                                    <TableHead className="text-right">Ventas</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                    <TableHead className="text-right">%</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {byPaymentMethod.map((m) => (
                                    <TableRow key={m.method}>
                                        <TableCell className="font-medium">{m.label}</TableCell>
                                        <TableCell>
                                            <ShareBar value={m.total} max={maxMethod} />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{m.count}</TableCell>
                                        <TableCell className="text-right tabular-nums">{money(m.total)}</TableCell>
                                        <TableCell className="text-muted-foreground text-right tabular-nums">
                                            {summary.total > 0 ? ((m.total / summary.total) * 100).toFixed(1) : '0.0'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TabsContent>

                    <TabsContent value="products">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10">#</TableHead>
                                    <TableHead>Producto</TableHead>
                                    <TableHead className="hidden w-1/4 sm:table-cell" />
                                    <TableHead className="text-right">Cantidad</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {topProducts.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-muted-foreground py-8 text-center">
                                            Sin ventas en el rango.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {topProducts.map((p, i) => (
                                    <TableRow key={p.product_id}>
                                        <TableCell className="text-muted-foreground tabular-nums">{i + 1}</TableCell>
                                        <TableCell className="font-medium">{p.name}</TableCell>
                                        <TableCell className="hidden sm:table-cell">
                                            <ShareBar value={p.quantity} max={maxProduct} />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{qty(p.quantity)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{money(p.total)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TabsContent>
                </Tabs>
            </Page>
        </AppLayout>
    );
}

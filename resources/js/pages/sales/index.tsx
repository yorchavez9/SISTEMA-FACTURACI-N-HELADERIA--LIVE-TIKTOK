import { FilterChip } from '@/components/filter-chip';
import { EmptyState, Page } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { SaleStatusBadge, StatusBadge } from '@/components/status-badge';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { money, paymentLabels, time } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Option, type Paginated, type Sale } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface Props {
    sales: Paginated<Sale>;
    filters: { date: string; payment_method?: string; status?: string };
    paymentMethods: Option[];
    canFilterDate: boolean;
}

export default function SalesIndex({ sales, filters, paymentMethods, canFilterDate }: Props) {
    const apply = (changes: Partial<Props['filters']>) => {
        router.get('/sales', { ...filters, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Ventas', href: '/sales' }]}>
            <Head title="Ventas" />
            <Page title="Ventas" description={canFilterDate ? 'Historial de ventas por día.' : 'Ventas registradas hoy.'}>
                <div className="flex flex-wrap items-center gap-2">
                    {canFilterDate && <Input type="date" value={filters.date} onChange={(e) => apply({ date: e.target.value })} className="w-auto" />}
                    <FilterChip active={!filters.payment_method} onClick={() => apply({ payment_method: undefined })}>
                        Todos
                    </FilterChip>
                    {paymentMethods.map((m) => (
                        <FilterChip key={m.value} active={filters.payment_method === m.value} onClick={() => apply({ payment_method: m.value })}>
                            {m.label}
                        </FilterChip>
                    ))}
                    <FilterChip
                        active={filters.status === 'anulada'}
                        onClick={() => apply({ status: filters.status === 'anulada' ? undefined : 'anulada' })}
                    >
                        Anuladas
                    </FilterChip>
                </div>

                {sales.data.length === 0 ? (
                    <EmptyState>No hay ventas para los filtros seleccionados.</EmptyState>
                ) : (
                    <div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Venta</TableHead>
                                    <TableHead>Hora</TableHead>
                                    <TableHead className="hidden md:table-cell">Cliente</TableHead>
                                    <TableHead>Pago</TableHead>
                                    <TableHead>Comprobante</TableHead>
                                    <TableHead className="hidden sm:table-cell">Cajero</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sales.data.map((s) => (
                                    <TableRow
                                        key={s.id}
                                        className={cn('cursor-pointer', s.status === 'anulada' && 'opacity-60')}
                                        onClick={() => router.visit(`/sales/${s.id}`)}
                                    >
                                        <TableCell>
                                            <Link href={`/sales/${s.id}`} className="font-mono text-xs font-medium">
                                                {s.code}
                                            </Link>
                                            <div>
                                                <SaleStatusBadge status={s.status} />
                                            </div>
                                        </TableCell>
                                        <TableCell className="tabular-nums">{time(s.sold_at)}</TableCell>
                                        <TableCell className="hidden max-w-48 truncate md:table-cell">{s.customer?.name ?? '—'}</TableCell>
                                        <TableCell>{paymentLabels[s.payment_method]}</TableCell>
                                        <TableCell>
                                            {s.invoice ? (
                                                <div className="space-y-0.5">
                                                    <span className="font-mono text-xs">{s.invoice.full_number}</span>
                                                    <div>
                                                        <StatusBadge status={s.invoice.status} />
                                                    </div>
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground text-xs">Sin comprobante</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground hidden sm:table-cell">{s.user?.name}</TableCell>
                                        <TableCell className={cn('text-right font-medium tabular-nums', s.status === 'anulada' && 'line-through')}>
                                            {money(s.total)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination data={sales} />
                    </div>
                )}
            </Page>
        </AppLayout>
    );
}

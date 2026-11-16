import { FilterChip } from '@/components/filter-chip';
import { EmptyState, Page } from '@/components/page';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { money, qty } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Paginated, type Product } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    products: Paginated<Product>;
    categories: { id: number; name: string }[];
    filters: { search?: string; category_id?: string; status?: string };
}

export default function ProductsIndex({ products, categories, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const apply = (changes: Partial<Props['filters']>) =>
        router.get('/products', { ...filters, ...changes }, { preserveState: true, preserveScroll: true, replace: true });

    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const t = setTimeout(() => apply({ search: search || undefined }), 300);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Productos', href: '/products' }]}>
            <Head title="Productos" />
            <Page
                title="Productos"
                actions={
                    <Button asChild>
                        <Link href="/products/create">
                            <Plus /> Nuevo producto
                        </Link>
                    </Button>
                }
            >
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative w-full sm:w-64">
                        <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nombre o código" className="pl-8" />
                    </div>
                    <Select value={filters.category_id ?? 'all'} onValueChange={(v) => apply({ category_id: v === 'all' ? undefined : v })}>
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Categoría" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todas las categorías</SelectItem>
                            {categories.map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>
                                    {c.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <FilterChip active={!filters.status} onClick={() => apply({ status: undefined })}>
                        Todos
                    </FilterChip>
                    <FilterChip active={filters.status === 'active'} onClick={() => apply({ status: 'active' })}>
                        Activos
                    </FilterChip>
                    <FilterChip active={filters.status === 'inactive'} onClick={() => apply({ status: 'inactive' })}>
                        Inactivos
                    </FilterChip>
                    <FilterChip active={filters.status === 'low_stock'} onClick={() => apply({ status: 'low_stock' })}>
                        Stock bajo
                    </FilterChip>
                </div>

                {products.data.length === 0 ? (
                    <EmptyState>No hay productos. Cree el primero con “Nuevo producto”.</EmptyState>
                ) : (
                    <div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Código</TableHead>
                                    <TableHead>Producto</TableHead>
                                    <TableHead className="hidden md:table-cell">Categoría</TableHead>
                                    <TableHead className="text-right">Precio</TableHead>
                                    <TableHead className="text-right">Stock</TableHead>
                                    <TableHead>Estado</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {products.data.map((p) => {
                                    const low = p.track_stock && Number(p.stock) <= Number(p.stock_min);
                                    return (
                                        <TableRow key={p.id} className="cursor-pointer" onClick={() => router.visit(`/products/${p.id}/edit`)}>
                                            <TableCell className="font-mono text-xs">{p.code}</TableCell>
                                            <TableCell className="font-medium">{p.name}</TableCell>
                                            <TableCell className="text-muted-foreground hidden md:table-cell">{p.category?.name}</TableCell>
                                            <TableCell className="text-right tabular-nums">{money(p.price)}</TableCell>
                                            <TableCell className={cn('text-right tabular-nums', low && 'font-semibold text-amber-600')}>
                                                {p.track_stock ? qty(p.stock) : <span className="text-muted-foreground">—</span>}
                                            </TableCell>
                                            <TableCell>
                                                {p.active ? (
                                                    <span className="text-xs text-emerald-700 dark:text-emerald-400">Activo</span>
                                                ) : (
                                                    <span className="text-muted-foreground text-xs">Inactivo</span>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                        <Pagination data={products} />
                    </div>
                )}
            </Page>
        </AppLayout>
    );
}

import { EmptyState, Field, Page } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type Category } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

export default function CategoriesIndex({ categories }: { categories: Category[] }) {
    const [editing, setEditing] = useState<Category | null | 'new'>(null);

    return (
        <AppLayout breadcrumbs={[{ title: 'Categorías', href: '/categories' }]}>
            <Head title="Categorías" />
            <Page
                title="Categorías"
                description="Agrupan los productos en el punto de venta. El orden define cómo aparecen."
                className="max-w-3xl"
                actions={
                    <Button onClick={() => setEditing('new')}>
                        <Plus /> Nueva categoría
                    </Button>
                }
            >
                {categories.length === 0 ? (
                    <EmptyState>Aún no hay categorías.</EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-16">Orden</TableHead>
                                <TableHead>Nombre</TableHead>
                                <TableHead className="text-right">Productos</TableHead>
                                <TableHead>Estado</TableHead>
                                <TableHead className="w-24" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {categories.map((c) => (
                                <TableRow key={c.id}>
                                    <TableCell className="tabular-nums">{c.sort_order}</TableCell>
                                    <TableCell className="font-medium">{c.name}</TableCell>
                                    <TableCell className="text-right tabular-nums">{c.products_count}</TableCell>
                                    <TableCell className="text-xs">
                                        {c.active ? 'Activa' : <span className="text-muted-foreground">Inactiva</span>}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button variant="ghost" size="icon" className="size-8" onClick={() => setEditing(c)} aria-label="Editar">
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-red-600"
                                            aria-label="Eliminar"
                                            onClick={() =>
                                                confirm(`¿Eliminar la categoría "${c.name}"?`) &&
                                                router.delete(`/categories/${c.id}`, { preserveScroll: true })
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Page>

            {editing !== null && (
                <CategoryDialog category={editing === 'new' ? null : editing} nextOrder={categories.length} onClose={() => setEditing(null)} />
            )}
        </AppLayout>
    );
}

function CategoryDialog({ category, nextOrder, onClose }: { category: Category | null; nextOrder: number; onClose: () => void }) {
    const form = useForm({
        name: category?.name ?? '',
        sort_order: String(category?.sort_order ?? nextOrder),
        active: category?.active ?? true,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (category) form.put(`/categories/${category.id}`, options);
        else form.post('/categories', options);
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>{category ? 'Editar categoría' : 'Nueva categoría'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <Field label="Nombre" error={form.errors.name}>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />
                    </Field>
                    <Field label="Orden" error={form.errors.sort_order}>
                        <Input type="number" min="0" value={form.data.sort_order} onChange={(e) => form.setData('sort_order', e.target.value)} />
                    </Field>
                    <label className="flex items-center gap-3">
                        <Switch checked={form.data.active} onCheckedChange={(v) => form.setData('active', v)} />
                        <span className="text-sm">Activa</span>
                    </label>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

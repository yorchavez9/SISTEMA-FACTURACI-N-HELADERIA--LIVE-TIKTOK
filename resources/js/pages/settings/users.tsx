import { Field } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type Option } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';

interface UserRow {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'cajero';
    active: boolean;
}

export default function Users({ users, roles }: { users: UserRow[]; roles: Option[] }) {
    const [editing, setEditing] = useState<UserRow | null | 'new'>(null);
    const roleLabel = Object.fromEntries(roles.map((r) => [r.value, r.label]));

    return (
        <AppLayout breadcrumbs={[{ title: 'Configuración', href: '/settings/users' }]}>
            <Head title="Usuarios" />
            <SettingsLayout wide>
                <div className="space-y-4">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="font-medium">Usuarios</h3>
                            <p className="text-muted-foreground text-sm">El cajero solo puede vender y ver las ventas del día.</p>
                        </div>
                        <Button onClick={() => setEditing('new')}>
                            <Plus /> Nuevo usuario
                        </Button>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nombre</TableHead>
                                <TableHead className="hidden sm:table-cell">Email</TableHead>
                                <TableHead>Rol</TableHead>
                                <TableHead>Estado</TableHead>
                                <TableHead className="w-12" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.map((u) => (
                                <TableRow key={u.id}>
                                    <TableCell className="font-medium">{u.name}</TableCell>
                                    <TableCell className="text-muted-foreground hidden sm:table-cell">{u.email}</TableCell>
                                    <TableCell>{roleLabel[u.role]}</TableCell>
                                    <TableCell className="text-xs">
                                        {u.active ? 'Activo' : <span className="text-muted-foreground">Desactivado</span>}
                                    </TableCell>
                                    <TableCell>
                                        <Button variant="ghost" size="icon" className="size-8" onClick={() => setEditing(u)} aria-label="Editar">
                                            <Pencil className="size-4" />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </SettingsLayout>
            {editing !== null && <UserDialog user={editing === 'new' ? null : editing} roles={roles} onClose={() => setEditing(null)} />}
        </AppLayout>
    );
}

function UserDialog({ user, roles, onClose }: { user: UserRow | null; roles: Option[]; onClose: () => void }) {
    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        role: user?.role ?? 'cajero',
        active: user?.active ?? true,
        password: '',
    });

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{user ? 'Editar usuario' : 'Nuevo usuario'}</DialogTitle>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        const options = { preserveScroll: true, onSuccess: onClose };
                        if (user) form.put(`/settings/users/${user.id}`, options);
                        else form.post('/settings/users', options);
                    }}
                    className="grid gap-4"
                >
                    <Field label="Nombre" error={form.errors.name}>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />
                    </Field>
                    <Field label="Email (usuario de acceso)" error={form.errors.email}>
                        <Input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                    </Field>
                    <Field label="Rol" error={form.errors.role}>
                        <Select value={form.data.role} onValueChange={(v) => form.setData('role', v as UserRow['role'])}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {roles.map((r) => (
                                    <SelectItem key={r.value} value={r.value}>
                                        {r.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field label={user ? 'Nueva contraseña (opcional)' : 'Contraseña'} error={form.errors.password}>
                        <Input
                            type="password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            autoComplete="new-password"
                        />
                    </Field>
                    {user && (
                        <label className="flex items-center gap-3">
                            <Switch checked={form.data.active} onCheckedChange={(v) => form.setData('active', v)} />
                            <span className="text-sm">Activo</span>
                        </label>
                    )}
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

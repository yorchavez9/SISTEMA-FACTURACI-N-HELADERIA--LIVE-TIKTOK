import HeadingSmall from '@/components/heading-small';
import { Field } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2, LoaderCircle, PlugZap, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface Props {
    baseUrl: string;
    hasToken: boolean;
    tokenHint: string | null;
}

export default function LookupSettings({ baseUrl, hasToken, tokenHint }: Props) {
    const form = useForm({ base_url: baseUrl, token: '' });
    const [testing, setTesting] = useState(false);

    const test = () =>
        router.post('/settings/lookup/test', {}, { preserveScroll: true, onStart: () => setTesting(true), onFinish: () => setTesting(false) });

    return (
        <AppLayout breadcrumbs={[{ title: 'Configuración', href: '/settings/lookup' }]}>
            <Head title="Consulta DNI/RUC" />
            <SettingsLayout>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put('/settings/lookup', { preserveScroll: true, onSuccess: () => form.reset('token') });
                    }}
                    className="space-y-6"
                >
                    <HeadingSmall
                        title="Consulta de DNI y RUC"
                        description="Completa automáticamente el nombre y la dirección del cliente desde RENIEC y SUNAT (api.json.pe)."
                    />

                    {hasToken ? (
                        <p className="flex items-center gap-1.5 text-sm text-emerald-700 dark:text-emerald-400">
                            <CheckCircle2 className="size-4" /> Token configurado ({tokenHint})
                        </p>
                    ) : (
                        <p className="text-muted-foreground text-sm">Sin token: los datos del cliente se ingresan manualmente.</p>
                    )}

                    <Field
                        label="Token (Bearer)"
                        error={form.errors.token}
                        hint={hasToken ? 'Déjelo vacío para conservar el token actual.' : undefined}
                    >
                        <Input
                            type="password"
                            value={form.data.token}
                            onChange={(e) => form.setData('token', e.target.value)}
                            autoComplete="off"
                            placeholder={hasToken ? '••••••••••••' : 'Pegue aquí su token'}
                        />
                    </Field>

                    <Field label="URL del servicio" error={form.errors.base_url}>
                        <Input value={form.data.base_url} onChange={(e) => form.setData('base_url', e.target.value)} />
                    </Field>

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Guardar
                        </Button>
                        <Button type="button" variant="outline" onClick={test} disabled={!hasToken || testing}>
                            {testing ? <LoaderCircle className="animate-spin" /> : <PlugZap />} Probar conexión
                        </Button>
                        {hasToken && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-red-600 hover:text-red-700"
                                onClick={() =>
                                    confirm('¿Eliminar el token? La consulta automática quedará desactivada.') && router.delete('/settings/lookup')
                                }
                            >
                                <Trash2 /> Quitar token
                            </Button>
                        )}
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Al guardar se prueba la conexión automáticamente con una consulta de RUC de ejemplo (consume una consulta del plan).
                    </p>
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}

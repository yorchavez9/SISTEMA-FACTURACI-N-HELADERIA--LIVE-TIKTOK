import HeadingSmall from '@/components/heading-small';
import { Field } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2, CircleAlert, LoaderCircle, PlugZap, RefreshCw } from 'lucide-react';
import { useState } from 'react';

interface Props {
    settings: {
        api_base_url: string;
        api_key: string | null;
        has_secret: boolean;
        api_branch_id: string | null;
        series_boleta: string;
        series_factura: string;
        series_nc_boleta: string;
        series_nc_factura: string;
        series_nd_boleta: string;
        series_nd_factura: string;
        pdf_format: string;
    };
    company: { name: string | null; ruc: string | null; tax_regime: string | null; igv_rate: string | null; environment: string | null };
    configured: boolean;
    remote?: {
        branches: { id: string; name: string; code: string | null }[];
        series: { id: string; type: string; series: string; active: boolean }[];
        usage: Record<string, unknown> | null;
        error: string | null;
    };
}

const regimes: Record<string, string> = {
    general: 'Régimen General',
    rer: 'Régimen Especial (RER)',
    mype: 'MYPE Tributario',
    rus: 'Nuevo RUS (solo boletas)',
};

export default function BillingSettings({ settings, company, configured, remote }: Props) {
    const form = useForm({
        api_base_url: settings.api_base_url ?? '',
        api_key: settings.api_key ?? '',
        api_secret: '',
        api_branch_id: settings.api_branch_id ?? '',
        series_boleta: settings.series_boleta,
        series_factura: settings.series_factura,
        series_nc_boleta: settings.series_nc_boleta,
        series_nc_factura: settings.series_nc_factura,
        series_nd_boleta: settings.series_nd_boleta,
        series_nd_factura: settings.series_nd_factura,
        pdf_format: settings.pdf_format,
    });
    const [testing, setTesting] = useState(false);
    const [loadingRemote, setLoadingRemote] = useState(false);

    const test = () =>
        router.post('/settings/billing/test', {}, { preserveScroll: true, onStart: () => setTesting(true), onFinish: () => setTesting(false) });
    const loadRemote = () => router.reload({ only: ['remote'], onStart: () => setLoadingRemote(true), onFinish: () => setLoadingRemote(false) });

    return (
        <AppLayout breadcrumbs={[{ title: 'Configuración', href: '/settings/billing' }]}>
            <Head title="Facturación electrónica" />
            <SettingsLayout>
                <div className="space-y-4">
                    <HeadingSmall title="Estado de la conexión" />
                    {company.ruc ? (
                        <dl className="grid grid-cols-2 gap-3 text-sm">
                            <div className="col-span-2">
                                <dt className="text-muted-foreground text-xs">Empresa</dt>
                                <dd className="font-medium">
                                    {company.name} · RUC {company.ruc}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Régimen</dt>
                                <dd>{regimes[company.tax_regime ?? ''] ?? company.tax_regime ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Tasa IGV vigente</dt>
                                <dd>{company.igv_rate ?? '—'}%</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Entorno</dt>
                                <dd>
                                    {company.environment === 'production' ? (
                                        <span className="font-medium text-emerald-700 dark:text-emerald-400">Producción</span>
                                    ) : (
                                        <span className="font-medium text-amber-700 dark:text-amber-400">Beta (pruebas)</span>
                                    )}
                                </dd>
                            </div>
                        </dl>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            {configured
                                ? 'Credenciales guardadas. Pruebe la conexión.'
                                : 'Ingrese las credenciales del API para emitir boletas y facturas.'}
                        </p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" onClick={test} disabled={!configured || testing}>
                            {testing ? <LoaderCircle className="animate-spin" /> : <PlugZap />} Probar conexión
                        </Button>
                        <Button variant="outline" onClick={loadRemote} disabled={!configured || loadingRemote}>
                            {loadingRemote ? <LoaderCircle className="animate-spin" /> : <RefreshCw />} Ver sucursales, series y consumo
                        </Button>
                    </div>

                    {remote && (
                        <div className="space-y-3 rounded-md border p-3 text-sm">
                            {remote.error ? (
                                <p className="flex items-center gap-2 text-red-700">
                                    <CircleAlert className="size-4" /> {remote.error}
                                </p>
                            ) : (
                                <>
                                    <div>
                                        <p className="text-muted-foreground mb-1 text-xs">Sucursales en el proveedor</p>
                                        {remote.branches.map((b) => (
                                            <p key={b.id}>
                                                #{b.id} · {b.name} {b.code && <span className="text-muted-foreground">(cod. {b.code})</span>}
                                            </p>
                                        ))}
                                    </div>
                                    <div>
                                        <p className="text-muted-foreground mb-1 text-xs">Series registradas</p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {remote.series.map((s) => (
                                                <span
                                                    key={s.id}
                                                    className={`rounded border px-2 py-0.5 font-mono text-xs ${s.active ? '' : 'line-through opacity-50'}`}
                                                >
                                                    {s.series} <span className="text-muted-foreground">{s.type}</span>
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                    {remote.usage && (
                                        <div>
                                            <p className="text-muted-foreground mb-1 text-xs">Consumo del plan</p>
                                            <pre className="bg-muted overflow-auto rounded p-2 text-xs">{JSON.stringify(remote.usage, null, 2)}</pre>
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                    )}
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put('/settings/billing', { preserveScroll: true, onSuccess: () => form.reset('api_secret') });
                    }}
                    className="space-y-8"
                >
                    <div className="space-y-4">
                        <HeadingSmall title="Credenciales del API" description="Se guardan cifradas. El API Secret no se vuelve a mostrar." />
                        <Field label="URL base" error={form.errors.api_base_url}>
                            <Input value={form.data.api_base_url} onChange={(e) => form.setData('api_base_url', e.target.value)} />
                        </Field>
                        <Field label="API Key" error={form.errors.api_key}>
                            <Input value={form.data.api_key} onChange={(e) => form.setData('api_key', e.target.value)} autoComplete="off" />
                        </Field>
                        <Field
                            label="API Secret"
                            error={form.errors.api_secret}
                            hint={settings.has_secret ? 'Ya hay un secret guardado. Déjelo vacío para conservarlo.' : undefined}
                        >
                            <Input
                                type="password"
                                value={form.data.api_secret}
                                onChange={(e) => form.setData('api_secret', e.target.value)}
                                autoComplete="new-password"
                                placeholder={settings.has_secret ? '••••••••••••' : ''}
                            />
                        </Field>
                        {settings.has_secret && (
                            <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                <CheckCircle2 className="size-3.5 text-emerald-600" /> Credenciales configuradas
                            </p>
                        )}
                    </div>

                    <div className="space-y-4">
                        <HeadingSmall
                            title="Series de comprobantes"
                            description="Deben existir en el proveedor (POST /series). Las de nota de crédito y débito se usan desde el menú de acciones de Comprobantes."
                        />
                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Boleta" error={form.errors.series_boleta}>
                                <Input
                                    value={form.data.series_boleta}
                                    onChange={(e) => form.setData('series_boleta', e.target.value.toUpperCase())}
                                    maxLength={4}
                                />
                            </Field>
                            <Field label="Factura" error={form.errors.series_factura}>
                                <Input
                                    value={form.data.series_factura}
                                    onChange={(e) => form.setData('series_factura', e.target.value.toUpperCase())}
                                    maxLength={4}
                                />
                            </Field>
                            <Field label="NC de boleta" error={form.errors.series_nc_boleta}>
                                <Input
                                    value={form.data.series_nc_boleta}
                                    onChange={(e) => form.setData('series_nc_boleta', e.target.value.toUpperCase())}
                                    maxLength={4}
                                />
                            </Field>
                            <Field label="NC de factura" error={form.errors.series_nc_factura}>
                                <Input
                                    value={form.data.series_nc_factura}
                                    onChange={(e) => form.setData('series_nc_factura', e.target.value.toUpperCase())}
                                    maxLength={4}
                                />
                            </Field>
                            <Field label="ND de boleta" error={form.errors.series_nd_boleta}>
                                <Input
                                    value={form.data.series_nd_boleta}
                                    onChange={(e) => form.setData('series_nd_boleta', e.target.value.toUpperCase())}
                                    maxLength={4}
                                />
                            </Field>
                            <Field label="ND de factura" error={form.errors.series_nd_factura}>
                                <Input
                                    value={form.data.series_nd_factura}
                                    onChange={(e) => form.setData('series_nd_factura', e.target.value.toUpperCase())}
                                    maxLength={4}
                                />
                            </Field>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <Field label="ID de sucursal (opcional)" error={form.errors.api_branch_id}>
                                <Input value={form.data.api_branch_id} onChange={(e) => form.setData('api_branch_id', e.target.value)} />
                            </Field>
                            <Field label="Formato de impresión" error={form.errors.pdf_format}>
                                <Select value={form.data.pdf_format} onValueChange={(v) => form.setData('pdf_format', v)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="ticket-80">Ticket 80 mm</SelectItem>
                                        <SelectItem value="ticket-58">Ticket 58 mm</SelectItem>
                                        <SelectItem value="a4">A4</SelectItem>
                                        <SelectItem value="a5">A5</SelectItem>
                                    </SelectContent>
                                </Select>
                            </Field>
                        </div>
                    </div>

                    <Button disabled={form.processing}>Guardar</Button>
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}

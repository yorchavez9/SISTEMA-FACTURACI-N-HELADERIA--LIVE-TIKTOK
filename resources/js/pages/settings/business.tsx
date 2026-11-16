import HeadingSmall from '@/components/heading-small';
import { Field } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type Option } from '@/types';
import { Head, useForm } from '@inertiajs/react';

interface Props {
    settings: {
        business_name: string;
        business_ruc: string | null;
        business_address: string | null;
        business_phone: string | null;
        ticket_footer: string | null;
        default_document_type: string;
        allow_negative_stock: boolean;
    };
    documentTypes: Option[];
}

export default function BusinessSettings({ settings, documentTypes }: Props) {
    const form = useForm({
        business_name: settings.business_name ?? '',
        business_ruc: settings.business_ruc ?? '',
        business_address: settings.business_address ?? '',
        business_phone: settings.business_phone ?? '',
        ticket_footer: settings.ticket_footer ?? '',
        default_document_type: settings.default_document_type,
        allow_negative_stock: settings.allow_negative_stock,
    });

    return (
        <AppLayout breadcrumbs={[{ title: 'Configuración', href: '/settings/business' }]}>
            <Head title="Datos del negocio" />
            <SettingsLayout>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put('/settings/business', { preserveScroll: true });
                    }}
                    className="space-y-8"
                >
                    <div className="space-y-4">
                        <HeadingSmall title="Datos de la heladería" description="Se muestran en el sistema y en el ticket interno." />
                        <Field label="Nombre comercial" error={form.errors.business_name}>
                            <Input value={form.data.business_name} onChange={(e) => form.setData('business_name', e.target.value)} />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="RUC" error={form.errors.business_ruc}>
                                <Input value={form.data.business_ruc} onChange={(e) => form.setData('business_ruc', e.target.value)} maxLength={11} />
                            </Field>
                            <Field label="Teléfono" error={form.errors.business_phone}>
                                <Input value={form.data.business_phone} onChange={(e) => form.setData('business_phone', e.target.value)} />
                            </Field>
                        </div>
                        <Field label="Dirección" error={form.errors.business_address}>
                            <Input value={form.data.business_address} onChange={(e) => form.setData('business_address', e.target.value)} />
                        </Field>
                        <Field label="Mensaje al pie del ticket" error={form.errors.ticket_footer}>
                            <Input value={form.data.ticket_footer} onChange={(e) => form.setData('ticket_footer', e.target.value)} />
                        </Field>
                    </div>

                    <div className="space-y-4">
                        <HeadingSmall title="Punto de venta" />
                        <Field label="Comprobante por defecto" error={form.errors.default_document_type}>
                            <Select value={form.data.default_document_type} onValueChange={(v) => form.setData('default_document_type', v)}>
                                <SelectTrigger className="w-56">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {documentTypes.map((d) => (
                                        <SelectItem key={d.value} value={d.value}>
                                            {d.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <label className="flex items-center justify-between gap-4">
                            <span>
                                <span className="block text-sm font-medium">Permitir vender sin stock</span>
                                <span className="text-muted-foreground text-xs">
                                    Si está desactivado, no se puede vender más de lo que hay en stock.
                                </span>
                            </span>
                            <Switch checked={form.data.allow_negative_stock} onCheckedChange={(v) => form.setData('allow_negative_stock', v)} />
                        </label>
                    </div>

                    <Button disabled={form.processing}>Guardar</Button>
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}

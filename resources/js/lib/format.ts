const moneyFormatter = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** S/ 1,234.50 */
export function money(value: number | string | null | undefined): string {
    return `S/ ${moneyFormatter.format(Number(value ?? 0))}`;
}

/** Cantidades: sin decimales si es entero. */
export function qty(value: number | string): string {
    const n = Number(value);
    return Number.isInteger(n) ? String(n) : n.toFixed(2);
}

export function dateTime(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' });
}

export function time(value: string): string {
    return new Date(value).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' });
}

export function date(value: string): string {
    // "2026-11-16" se interpreta en hora local para no desfasar el día.
    const d = value.length === 10 ? new Date(`${value}T00:00:00`) : new Date(value);
    return d.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' });
}

export const paymentLabels: Record<string, string> = {
    efectivo: 'Efectivo',
    yape: 'Yape',
    plin: 'Plin',
    tarjeta: 'Tarjeta',
};

export const documentTypeLabels: Record<string, string> = {
    '01': 'Factura',
    '03': 'Boleta',
    '07': 'Nota de crédito',
    '08': 'Nota de débito',
    ninguno: 'Sin comprobante',
    boleta: 'Boleta',
    factura: 'Factura',
};

export const docTypeShort: Record<string, string> = {
    '0': 'S/D',
    '1': 'DNI',
    '4': 'CE',
    '6': 'RUC',
    '7': 'PAS',
};

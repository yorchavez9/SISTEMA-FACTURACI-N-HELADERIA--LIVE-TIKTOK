import { HttpError, jsonFetch } from '@/lib/http';
import { type Customer } from '@/types';

export interface LookupData {
    doc_type: string;
    doc_number: string;
    name: string;
    address: string | null;
    warning: string | null;
}

export type LookupResult =
    | { source: 'local'; customer: Customer }
    | { source: 'api'; data: LookupData }
    | { source: 'none'; message: string }
    | { source: 'error'; message: string; notFound: boolean };

/** ¿El texto es un DNI (8 dígitos) o RUC (11 dígitos) completo? */
export function isFullDocNumber(value: string): boolean {
    return /^(\d{8}|\d{11})$/.test(value.trim());
}

/** Consulta DNI/RUC: primero clientes registrados, luego RENIEC/SUNAT. */
export async function lookupDocument(number: string): Promise<LookupResult> {
    try {
        return await jsonFetch<LookupResult>(`/customers/lookup?number=${encodeURIComponent(number.trim())}`);
    } catch (e) {
        if (e instanceof HttpError) {
            return { source: 'error', message: e.message, notFound: e.status === 404 };
        }
        return { source: 'error', message: 'No se pudo consultar el documento.', notFound: false };
    }
}

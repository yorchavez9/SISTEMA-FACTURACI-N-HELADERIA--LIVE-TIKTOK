import { lookupDocument } from '@/lib/lookup';
import { useEffect, useRef, useState } from 'react';

export interface LookupFill {
    name: string;
    address: string | null;
    /** Si el cliente ya está registrado localmente. */
    existingId?: number;
}

/**
 * Autocompleta nombre y dirección cuando el número de documento queda completo
 * (DNI: 8 dígitos, RUC: 11 dígitos). Consulta solo una vez por número.
 */
export function useDocumentLookup(docType: string, number: string, onFound: (fill: LookupFill) => void, enabled = true) {
    const [loading, setLoading] = useState(false);
    const [message, setMessage] = useState<{ text: string; tone: 'ok' | 'warn' | 'error' } | null>(null);
    const lastNumber = useRef<string | null>(null);
    const callback = useRef(onFound);
    callback.current = onFound;

    const value = number.trim();
    const complete = (docType === '1' && /^\d{8}$/.test(value)) || (docType === '6' && /^\d{11}$/.test(value));

    useEffect(() => {
        if (!enabled || !complete) {
            if (!complete) {
                lastNumber.current = null;
                setMessage(null);
            }
            return;
        }
        if (lastNumber.current === value) return;

        let cancelled = false;
        const timer = setTimeout(async () => {
            lastNumber.current = value;
            setLoading(true);
            const result = await lookupDocument(value);
            if (cancelled) return;
            setLoading(false);

            switch (result.source) {
                case 'api':
                    callback.current({ name: result.data.name, address: result.data.address });
                    setMessage(
                        result.data.warning
                            ? { text: result.data.warning, tone: 'warn' }
                            : { text: docType === '6' ? 'Datos obtenidos de SUNAT.' : 'Datos obtenidos de RENIEC.', tone: 'ok' },
                    );
                    break;
                case 'local':
                    callback.current({ name: result.customer.name, address: result.customer.address, existingId: result.customer.id });
                    setMessage({ text: 'Este cliente ya está registrado.', tone: 'warn' });
                    break;
                case 'error':
                    setMessage({ text: result.message, tone: 'error' });
                    break;
                default:
                    setMessage(null); // consulta no configurada: ingreso manual
            }
        }, 300);

        return () => {
            cancelled = true;
            clearTimeout(timer);
            setLoading(false);
        };
    }, [enabled, complete, value, docType]);

    return { loading, message };
}

import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast, Toaster } from 'sonner';

/** Muestra los mensajes flash del servidor y los errores de validación como toasts. */
export function FlashToaster() {
    const { flash } = usePage<SharedData>().props;

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error, { duration: 8000 });
    }, [flash]);

    useEffect(() => {
        // Errores 403/422/500 en visitas Inertia que no devuelven una página.
        return router.on('invalid', (event) => {
            const status = event.detail.response.status;
            if (status === 403) {
                event.preventDefault();
                toast.error('No tiene permiso para realizar esta acción.');
            }
        });
    }, []);

    return <Toaster richColors position="top-right" closeButton />;
}

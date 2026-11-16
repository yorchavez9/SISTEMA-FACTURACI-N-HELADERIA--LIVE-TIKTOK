import { dateTime, money, paymentLabels, qty } from '@/lib/format';
import { type Sale } from '@/types';
import { Head } from '@inertiajs/react';
import { useEffect } from 'react';

interface Props {
    sale: Sale;
    embed?: boolean;
    business: { name: string; ruc: string | null; address: string | null; phone: string | null; footer: string | null };
}

/** Ticket interno (no es comprobante de pago). Formato 80 mm, se imprime al abrir. */
export default function Ticket({ sale, business, embed = false }: Props) {
    useEffect(() => {
        if (embed) return;
        const t = setTimeout(() => window.print(), 300);
        return () => clearTimeout(t);
    }, [embed]);

    return (
        <>
            <Head title={`Ticket ${sale.code}`} />
            <style>{`@page { size: 80mm auto; margin: 4mm; } @media print { .no-print { display: none; } body { background: #fff; } }`}</style>
            <div className="mx-auto w-[72mm] bg-white p-2 font-mono text-[12px] leading-snug text-black">
                <div className="text-center">
                    <p className="text-sm font-bold">{business.name}</p>
                    {business.ruc && <p>RUC {business.ruc}</p>}
                    {business.address && <p>{business.address}</p>}
                    {business.phone && <p>Tel. {business.phone}</p>}
                    <p className="mt-2 font-bold">TICKET DE VENTA {sale.code}</p>
                    <p>{dateTime(sale.sold_at)}</p>
                </div>
                <hr className="my-2 border-dashed border-black" />
                {sale.items?.map((i) => (
                    <div key={i.id} className="mb-1">
                        <p>{i.product_name}</p>
                        <p className="flex justify-between">
                            <span>
                                {qty(i.quantity)} x {Number(i.unit_price).toFixed(2)}
                            </span>
                            <span>{Number(i.total).toFixed(2)}</span>
                        </p>
                    </div>
                ))}
                <hr className="my-2 border-dashed border-black" />
                <p className="flex justify-between text-sm font-bold">
                    <span>TOTAL</span>
                    <span>{money(sale.total)}</span>
                </p>
                <p className="flex justify-between">
                    <span>Pago</span>
                    <span>{paymentLabels[sale.payment_method]}</span>
                </p>
                {sale.payment_method === 'efectivo' && (
                    <>
                        <p className="flex justify-between">
                            <span>Recibido</span>
                            <span>{money(sale.amount_received)}</span>
                        </p>
                        <p className="flex justify-between">
                            <span>Vuelto</span>
                            <span>{money(sale.change)}</span>
                        </p>
                    </>
                )}
                <hr className="my-2 border-dashed border-black" />
                <p className="text-center">Atendido por: {sale.user?.name}</p>
                <p className="text-center text-[10px]">Documento interno, no válido como comprobante de pago.</p>
                {business.footer && <p className="mt-2 text-center">{business.footer}</p>}
                {!embed && (
                    <button type="button" onClick={() => window.print()} className="no-print mt-4 w-full rounded border border-black py-1">
                        Imprimir
                    </button>
                )}
            </div>
        </>
    );
}

import { money } from '@/lib/format';
import { Bar, CartesianGrid, BarChart as RBarChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

/**
 * Gráfico de barras de una sola serie (montos en S/).
 * Una serie: un color, sin leyenda (el título la nombra), tooltip al pasar el cursor.
 */
export function BarChart<T extends Record<string, unknown>>({
    data,
    xKey,
    yKey,
    height = 220,
    formatX = (v) => String(v),
    label = 'Ventas',
}: {
    data: T[];
    xKey: keyof T & string;
    yKey: keyof T & string;
    height?: number;
    formatX?: (value: unknown) => string;
    label?: string;
}) {
    return (
        <div style={{ height }} className="w-full">
            <ResponsiveContainer width="100%" height="100%">
                <RBarChart data={data} margin={{ top: 8, right: 4, bottom: 0, left: 0 }} barCategoryGap="20%">
                    <CartesianGrid vertical={false} stroke="var(--border)" strokeDasharray="0" />
                    <XAxis
                        dataKey={xKey}
                        tickFormatter={formatX}
                        tickLine={false}
                        axisLine={false}
                        tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                        minTickGap={8}
                    />
                    <YAxis
                        tickLine={false}
                        axisLine={false}
                        width={44}
                        tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                        tickFormatter={(v: number) => (v >= 1000 ? `${(v / 1000).toFixed(1)}k` : String(v))}
                    />
                    <Tooltip
                        cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                        content={({ active, payload }) =>
                            active && payload?.length ? (
                                <div className="bg-popover text-popover-foreground rounded-md border px-3 py-2 text-xs shadow-md">
                                    <p className="text-muted-foreground">{formatX(payload[0].payload[xKey])}</p>
                                    <p className="font-semibold tabular-nums">
                                        {label}: {money(Number(payload[0].value))}
                                    </p>
                                </div>
                            ) : null
                        }
                    />
                    <Bar dataKey={yKey} fill="var(--chart-bar)" radius={[4, 4, 0, 0]} maxBarSize={28} />
                </RBarChart>
            </ResponsiveContainer>
        </div>
    );
}

/** Barra horizontal proporcional para tablas (participación). */
export function ShareBar({ value, max }: { value: number; max: number }) {
    const pct = max > 0 ? Math.max((value / max) * 100, value > 0 ? 2 : 0) : 0;
    return (
        <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full">
            <div className="h-full rounded-full" style={{ width: `${pct}%`, background: 'var(--chart-bar)' }} />
        </div>
    );
}

import { Button } from '@/components/ui/button';
import { type Paginated } from '@/types';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export function Pagination<T>({ data }: { data: Paginated<T> }) {
    if (data.last_page <= 1) {
        return data.total > 0 ? <p className="text-muted-foreground px-1 py-3 text-xs">{data.total} registros</p> : null;
    }

    const prev = data.links[0]?.url;
    const next = data.links[data.links.length - 1]?.url;

    return (
        <div className="flex items-center justify-between gap-2 px-1 py-3">
            <p className="text-muted-foreground text-xs">
                {data.from}–{data.to} de {data.total}
            </p>
            <div className="flex gap-1">
                <Button variant="outline" size="sm" disabled={!prev} asChild={!!prev}>
                    {prev ? (
                        <Link href={prev} preserveScroll preserveState>
                            <ChevronLeft /> Anterior
                        </Link>
                    ) : (
                        <span>
                            <ChevronLeft /> Anterior
                        </span>
                    )}
                </Button>
                <Button variant="outline" size="sm" disabled={!next} asChild={!!next}>
                    {next ? (
                        <Link href={next} preserveScroll preserveState>
                            Siguiente <ChevronRight />
                        </Link>
                    ) : (
                        <span>
                            Siguiente <ChevronRight />
                        </span>
                    )}
                </Button>
            </div>
        </div>
    );
}

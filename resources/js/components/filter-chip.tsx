import { cn } from '@/lib/utils';

export function FilterChip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'h-8 rounded-full border px-3 text-sm whitespace-nowrap transition-colors',
                active ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-muted',
            )}
        >
            {children}
        </button>
    );
}

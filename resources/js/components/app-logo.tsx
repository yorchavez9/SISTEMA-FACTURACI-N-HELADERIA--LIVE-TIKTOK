import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { IceCreamCone } from 'lucide-react';

export default function AppLogo() {
    const { business } = usePage<SharedData>().props;

    return (
        <>
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
                <IceCreamCone className="size-5" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold">{business?.name ?? 'Heladería'}</span>
            </div>
        </>
    );
}

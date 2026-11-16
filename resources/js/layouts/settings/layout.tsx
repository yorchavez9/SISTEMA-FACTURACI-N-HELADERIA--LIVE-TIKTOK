import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

const adminItems: NavItem[] = [
    { title: 'Negocio', url: '/settings/business' },
    { title: 'Facturación', url: '/settings/billing' },
    { title: 'Consulta DNI/RUC', url: '/settings/lookup' },
    { title: 'Usuarios', url: '/settings/users' },
];

const accountItems: NavItem[] = [
    { title: 'Mi perfil', url: '/settings/profile' },
    { title: 'Contraseña', url: '/settings/password' },
    { title: 'Apariencia', url: '/settings/appearance' },
];

export default function SettingsLayout({ children, wide = false }: { children: React.ReactNode; wide?: boolean }) {
    const { auth } = usePage<SharedData>().props;
    const currentPath = window.location.pathname;
    const items = auth.user.is_admin ? [...adminItems, ...accountItems] : accountItems;

    return (
        <div className="px-4 py-6">
            <Heading
                title="Configuración"
                description={auth.user.is_admin ? 'Datos del negocio, facturación electrónica y usuarios.' : 'Tu cuenta.'}
            />

            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-row flex-wrap gap-1 lg:flex-col">
                        {items.map((item, i) => (
                            <div key={item.url} className="contents">
                                {auth.user.is_admin && i === adminItems.length && <Separator className="my-2 hidden lg:block" />}
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    asChild
                                    className={cn('justify-start lg:w-full', {
                                        'bg-muted': currentPath === item.url,
                                    })}
                                >
                                    <Link href={item.url} prefetch>
                                        {item.title}
                                    </Link>
                                </Button>
                            </div>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className={cn('flex-1', wide ? 'md:max-w-4xl' : 'md:max-w-2xl')}>
                    <section className={cn('space-y-12', !wide && 'max-w-xl')}>{children}</section>
                </div>
            </div>
        </div>
    );
}

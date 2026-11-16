import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BarChart3, FileText, LayoutGrid, Package, Receipt, Settings, ShoppingCart, Tags, Users } from 'lucide-react';
import AppLogo from './app-logo';

const operationItems: NavItem[] = [
    { title: 'Vender', url: '/pos', icon: ShoppingCart },
    { title: 'Ventas', url: '/sales', icon: Receipt },
    { title: 'Comprobantes', url: '/documents', icon: FileText },
    { title: 'Clientes', url: '/customers', icon: Users },
];

const adminItems: NavItem[] = [
    { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
    { title: 'Productos', url: '/products', icon: Package },
    { title: 'Categorías', url: '/categories', icon: Tags },
    { title: 'Reportes', url: '/reports', icon: BarChart3 },
    { title: 'Configuración', url: '/settings/business', icon: Settings },
];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/pos" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain label="Operación" items={operationItems} />
                {auth.user.is_admin && <NavMain label="Administración" items={adminItems} />}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

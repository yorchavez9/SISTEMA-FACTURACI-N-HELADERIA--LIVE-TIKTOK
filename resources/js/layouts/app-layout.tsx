import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';

interface AppLayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    /** Botones en la barra superior (p. ej. "Nuevo producto"). */
    actions?: React.ReactNode;
}

export default ({ children, breadcrumbs, actions, ...props }: AppLayoutProps) => (
    <AppLayoutTemplate breadcrumbs={breadcrumbs} actions={actions} {...props}>
        {children}
    </AppLayoutTemplate>
);

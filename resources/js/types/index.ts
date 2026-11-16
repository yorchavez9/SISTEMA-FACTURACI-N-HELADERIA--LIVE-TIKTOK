import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    auth: Auth;
    business: { name: string };
    flash: { success?: string | null; error?: string | null };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'cajero';
    is_admin: boolean;
    avatar?: string;
    email_verified_at?: string | null;
    [key: string]: unknown;
}

export interface Option {
    value: string;
    label: string;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface Category {
    id: number;
    name: string;
    sort_order: number;
    active: boolean;
    products_count?: number;
}

export interface Product {
    id: number;
    category_id: number;
    code: string;
    name: string;
    description: string | null;
    price: string;
    track_stock: boolean;
    stock: string;
    stock_min: string;
    active: boolean;
    category?: { id: number; name: string };
}

export interface Customer {
    id: number;
    doc_type: string;
    doc_number: string;
    name: string;
    address: string | null;
    phone?: string | null;
    email?: string | null;
    sales_count?: number;
}

export type DocumentStatus =
    | 'borrador'
    | 'error_envio'
    | 'error'
    | 'pendiente'
    | 'enviado'
    | 'aceptado'
    | 'rechazado'
    | 'anulado'
    | 'revision_manual';

export interface CustomerSnapshot {
    tipo_doc: string;
    num_doc: string;
    razon_social: string;
    direccion?: string | null;
}

export interface ElectronicDocument {
    id: number;
    sale_id: number;
    type: '01' | '03' | '07' | '08';
    series: string;
    number: number | null;
    full_number: string;
    provider_id: string | null;
    related_document_id: number | null;
    reason_code?: string | null;
    reason?: string | null;
    customer_snapshot: CustomerSnapshot;
    total: string;
    status: DocumentStatus;
    sunat_code: string | null;
    sunat_message: string | null;
    hash: string | null;
    attempts: number;
    issued_at: string;
    last_checked_at: string | null;
    response_payload?: unknown;
    related?: Pick<ElectronicDocument, 'id' | 'type' | 'series' | 'number' | 'status' | 'full_number'> | null;
    credit_note?: Pick<ElectronicDocument, 'id' | 'type' | 'series' | 'number' | 'status' | 'full_number'> | null;
    sale?: Sale;
    /** true = disponible; texto = motivo por el que no lo está. */
    actions?: { credit_note: true | string; debit_note: true | string; void: true | string };
    voiding?: { kind: 'RA' | 'RC'; label: string; identifier: string | null; status: DocumentStatus; message: string | null } | null;
}

export interface SaleItem {
    id: number;
    product_id: number;
    product_code: string;
    product_name: string;
    unit_price: string;
    quantity: string;
    total: string;
}

export interface Sale {
    id: number;
    code: string;
    sold_at: string;
    total: string;
    payment_method: 'efectivo' | 'yape' | 'plin' | 'tarjeta';
    amount_received: string | null;
    change: string | null;
    document_type: 'ninguno' | 'boleta' | 'factura';
    status: 'completada' | 'anulada';
    void_reason: string | null;
    voided_at: string | null;
    user?: { id: number; name: string };
    voided_by?: { id: number; name: string } | null;
    customer?: Customer | null;
    items?: SaleItem[];
    invoice?: Pick<ElectronicDocument, 'id' | 'type' | 'series' | 'number' | 'status' | 'full_number'> | null;
    documents?: ElectronicDocument[];
}

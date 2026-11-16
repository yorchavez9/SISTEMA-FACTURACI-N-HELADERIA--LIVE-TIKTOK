/** fetch JSON con el token CSRF de Laravel (cookie XSRF-TOKEN). */
export class HttpError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function jsonFetch<T>(url: string, options: { method?: string; body?: unknown } = {}): Promise<T> {
    const response = await fetch(url, {
        method: options.method ?? 'GET',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        body: options.body ? JSON.stringify(options.body) : undefined,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new HttpError(data.message ?? 'Error de comunicación', response.status, data.errors ?? {});
    }

    return data as T;
}

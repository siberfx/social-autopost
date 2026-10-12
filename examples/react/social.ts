// Types and requests shared by SocialConnections.tsx and ShareButton.tsx.
// They match Siberfx\SocialAutopost\Support\SocialProps.

export type Tone = 'success' | 'warning' | 'danger' | 'neutral';

export interface SocialConnection {
    network: string;
    label: string;
    brand: { color: string; logo: string; dark: boolean };
    hint: string | null;
    autopost: boolean;
    connection: {
        connected: boolean;
        status: string | null;
        name: string | null;
        email: string | null;
        picture: string | null;
        connected_at: string | null;
        expires_at: string | null;
        days_left: number | null;
    };
    badge: { label: string; tone: Tone };
    needs_reconnect: boolean;
    confirm_disconnect: string;
    can_manage: boolean;
    urls: { connect: string; disconnect: string };
}

export interface SocialShare {
    network: string | null;
    url: string;
    text: string;
    title: string;
    confirm: string;
    disabled_reason: string | null;
    last_posted_at: string | null;
}

export interface Flash {
    type: 'success' | 'error' | string;
    message: string;
}

/** Laravel's CSRF token, from the XSRF-TOKEN cookie the "web" middleware sets. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** A JSON request to the package routes, with the session cookie and CSRF token. */
export async function request<T>(url: string, method = 'GET'): Promise<{ ok: boolean; body: T }> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
    });

    return { ok: response.ok, body: (await response.json()) as T };
}

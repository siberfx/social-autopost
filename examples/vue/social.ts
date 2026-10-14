// Types and requests shared by SocialConnections.vue and ShareButton.vue.
// They match Siberfx\SocialAutopost\Support\SocialProps.

export type Tone = 'success' | 'warning' | 'danger' | 'neutral';

/** A place a network can post to: a LinkedIn profile or organization page, or a Facebook Page. */
export interface SocialPage {
    id: string;
    type: 'member' | 'organization' | 'page';
    name: string | null;
    picture: string | null;
    selected: boolean;
}

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
        // LinkedIn and Facebook list their pages; other networks return [].
        pages: SocialPage[];
        selected_pages: string[];
    };
    badge: { label: string; tone: Tone };
    needs_reconnect: boolean;
    confirm_disconnect: string;
    can_manage: boolean;
    // urls.pages: only for networks with pages (LinkedIn, Facebook).
    urls: { connect: string; disconnect: string; pages?: string };
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
export async function request<T>(url: string, method = 'GET', body?: unknown): Promise<{ ok: boolean; body: T }> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
        headers: {
            Accept: 'application/json',
            ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
    });

    return { ok: response.ok, body: (await response.json()) as T };
}

/** Choose the pages a network shares to (one or more ids from connection.pages). */
export function selectPages(card: SocialConnection, pages: string[]) {
    return request<{ message: string; data: SocialConnection['connection'] }>(card.urls.pages as string, 'PUT', { pages });
}

// A "Social connections" page in React: one card per enabled network.
//
// With Inertia, pass the cards as props from your controller:
//     return Inertia::render('SocialConnections', [
//         'connections' => SocialProps::connections(),
//         'flash' => SocialProps::flash(),
//     ]);
// Without Inertia, render <SocialConnections /> and it fetches GET /social/connections itself.

import { useCallback, useEffect, useState } from 'react';
import { type Flash, type SocialConnection, type Tone, request } from './social';

interface Props {
    connections?: SocialConnection[];
    flash?: Flash | null;
    /** The package's JSON route: routes.prefix + "/connections". */
    endpoint?: string;
}

const tones: Record<Tone, string> = {
    success: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-950 dark:text-green-300',
    warning: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-950 dark:text-amber-300',
    danger: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-950 dark:text-red-300',
    neutral: 'bg-gray-50 text-gray-600 ring-gray-500/20 dark:bg-gray-800 dark:text-gray-300',
};

export default function SocialConnections({ connections: initial, flash: initialFlash = null, endpoint = '/social/connections' }: Props) {
    const [connections, setConnections] = useState<SocialConnection[] | null>(initial ?? null);
    const [flash, setFlash] = useState<Flash | null>(initialFlash);
    const [busy, setBusy] = useState<string | null>(null);

    // The OAuth callback returns here with ?social=…&state=…; the endpoint turns it into a message.
    const load = useCallback(async (withCallbackQuery = false) => {
        const { body } = await request<{ data: SocialConnection[]; flash: Flash | null }>(
            endpoint + (withCallbackQuery ? window.location.search : ''),
        );
        setConnections(body.data);
        if (body.flash) setFlash(body.flash);
    }, [endpoint]);

    useEffect(() => {
        if (!initial) void load(true);
        // Drop ?social=…&state=… so a reload does not show the message again.
        const url = new URL(window.location.href);
        if (url.searchParams.has('social')) {
            url.searchParams.delete('social');
            url.searchParams.delete('state');
            url.searchParams.delete('reason');
            window.history.replaceState(window.history.state, '', url);
        }
    }, [initial, load]);

    async function disconnect(card: SocialConnection) {
        if (!window.confirm(card.confirm_disconnect)) return;

        setBusy(card.network);
        try {
            const { ok, body } = await request<{ message: string }>(card.urls.disconnect, 'DELETE');
            setFlash({ type: ok ? 'success' : 'error', message: body.message });
            await load();
        } finally {
            setBusy(null);
        }
    }

    return (
        <div className="mx-auto max-w-5xl space-y-6 px-4 py-10">
            <header>
                <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">Social connections</h1>
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Connect the accounts your content is shared to.</p>
            </header>

            {flash && (
                <div role="status" className={`rounded-lg px-4 py-3 text-sm ${flash.type === 'success' ? 'bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200' : 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200'}`}>
                    {flash.message}
                </div>
            )}

            {connections === null && <p className="text-sm text-gray-500">Loading…</p>}

            {connections?.length === 0 && (
                <p className="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700">
                    No network is switched on. Set LINKEDIN_ENABLED=true (or X_, FACEBOOK_, INSTAGRAM_, TIKTOK_) in .env.
                </p>
            )}

            <div className="grid gap-4 md:grid-cols-2">
                {connections?.map((card) => (
                    <section key={card.network} className="space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        <h2 className="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            {/* The logo is SVG markup from your own config, not user input. */}
                            <span className={`size-5 shrink-0 ${card.brand.dark ? 'dark:invert' : ''}`} style={{ color: card.brand.color }} dangerouslySetInnerHTML={{ __html: card.brand.logo }} />
                            {card.label}
                        </h2>

                        {card.hint && <p className="-mt-2 text-xs text-gray-500 dark:text-gray-400">{card.hint}</p>}

                        {card.connection.connected ? (
                            <div className="flex flex-wrap items-center gap-4">
                                {card.connection.picture ? (
                                    <img src={card.connection.picture} alt="" className="size-12 rounded-full object-cover" referrerPolicy="no-referrer" />
                                ) : (
                                    <span aria-hidden className="flex size-12 items-center justify-center rounded-full text-sm font-semibold text-white" style={{ backgroundColor: card.brand.color }}>
                                        {(card.connection.name ?? card.label).slice(0, 1).toUpperCase()}
                                    </span>
                                )}
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{card.connection.name ?? `${card.label} account`}</p>
                                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${tones[card.badge.tone]}`}>{card.badge.label}</span>
                                    </div>
                                    {card.connection.expires_at && (
                                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Valid until {new Date(card.connection.expires_at).toLocaleDateString()}</p>
                                    )}
                                </div>
                                {card.can_manage && (
                                    <div className="flex gap-2">
                                        <a href={card.urls.connect} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                                            Reconnect
                                        </a>
                                        <button type="button" disabled={busy === card.network} onClick={() => void disconnect(card)} className="rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950">
                                            Disconnect
                                        </button>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="text-sm text-gray-500 dark:text-gray-400">Connect a {card.label} account to share your content.</p>
                                {card.can_manage && (
                                    // A plain link, not fetch(): the browser leaves for the network's login page.
                                    <a href={card.urls.connect} className="rounded-lg px-4 py-2 text-sm font-semibold text-white" style={{ backgroundColor: card.brand.color }}>
                                        Connect {card.label}
                                    </a>
                                )}
                            </div>
                        )}
                    </section>
                ))}
            </div>
        </div>
    );
}

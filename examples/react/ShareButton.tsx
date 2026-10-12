// A "Share" button for one model, in React. Build its props on the server, where the URL is signed:
//     'share' => SocialProps::share($post),               // every enabled, connected network
//     'shareOnX' => SocialProps::share($post, 'x'),       // one network
// then: <ShareButton share={share} />

import { useState } from 'react';
import { type SocialShare, request } from './social';

export default function ShareButton({ share }: { share: SocialShare }) {
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

    async function post() {
        if (!window.confirm(share.confirm)) return;

        setBusy(true);
        try {
            const { ok, body } = await request<{ message: string }>(share.url, 'POST');
            setMessage({ ok, text: body.message });
        } catch {
            setMessage({ ok: false, text: 'The share could not be sent. Try again.' });
        } finally {
            setBusy(false);
        }
    }

    return (
        <span className="inline-flex flex-col gap-1">
            <button
                type="button"
                title={share.title}
                disabled={busy || share.disabled_reason !== null}
                onClick={() => void post()}
                className="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-gray-900"
            >
                {busy ? 'Sharing…' : share.text}
            </button>
            {message && <small className={message.ok ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'}>{message.text}</small>}
        </span>
    );
}

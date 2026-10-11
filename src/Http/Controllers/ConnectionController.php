<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Siberfx\SocialAutopost\Exceptions\ConnectionFailed;
use Siberfx\SocialAutopost\Exceptions\NetworkDisabled;
use Siberfx\SocialAutopost\Exceptions\UnknownNetwork;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Support\StatusMessages;
use Siberfx\SocialAutopost\Support\Ui;

/** Connect, finish and disconnect one network. A switched-off or unknown network answers 404. */
final class ConnectionController extends Controller
{
    public function __construct(private readonly SocialManager $social) {}

    public function redirect(string $network): RedirectResponse
    {
        $this->enabled($network);

        try {
            return redirect()->away($this->social->connect($network));
        } catch (ConnectionFailed $e) {
            return $this->back($network, $e->state);
        }
    }

    public function callback(Request $request, string $network): RedirectResponse
    {
        $this->enabled($network);

        try {
            $this->social->completeConnection($network, $request);

            return $this->back($network, 'connected');
        } catch (ConnectionFailed $e) {
            return $this->back($network, $e->state, $e->state === 'error' ? $e->getMessage() : null);
        }
    }

    public function disconnect(Request $request, string $network): JsonResponse|RedirectResponse
    {
        $this->enabled($network);

        $revoked = $this->social->disconnect($network);
        $label = $this->social->network($network)->label();
        $message = $revoked
            ? "{$label} disconnected."
            : "{$label} disconnected here, but {$label} did not confirm revoking the connection.";

        if (! $request->expectsJson() && Ui::enabled()) {
            return back()->with(StatusMessages::FLASH_KEY, ['type' => 'success', 'message' => $message]);
        }

        return response()->json([
            'message' => $message,
            'revoked' => $revoked,
            'data' => $this->social->connection($network)->toArray(),
        ]);
    }

    /** @throws NetworkDisabled|UnknownNetwork */
    private function enabled(string $network): void
    {
        abort_unless($this->social->isEnabled($network), 404);
    }

    /** Back to the app's after_connect page with ?social=…&state=…, read by the flash message. */
    private function back(string $network, string $state, ?string $reason = null): RedirectResponse
    {
        $base = (string) config('social-autopost.routes.after_connect', '/');
        $query = array_filter(['social' => $network, 'state' => $state, 'reason' => $reason], fn (?string $value) => $value !== null);

        return redirect($base.(str_contains($base, '?') ? '&' : '?').http_build_query($query));
    }
}

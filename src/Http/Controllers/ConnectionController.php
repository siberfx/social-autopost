<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Siberfx\SocialAutopost\Exceptions\ConnectionFailed;
use Siberfx\SocialAutopost\Exceptions\NetworkDisabled;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\UnknownNetwork;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Support\NetworkNotes;
use Siberfx\SocialAutopost\Support\SocialProps;
use Siberfx\SocialAutopost\Support\StatusMessages;
use Siberfx\SocialAutopost\Support\Ui;

/** Connect, finish and disconnect one network. A switched-off or unknown network answers 404. */
final class ConnectionController extends Controller
{
    public function __construct(private readonly SocialManager $social) {}

    /** Every enabled network's card as JSON, for React, Vue or any other front end. Pass ?social=…&state=… on to get the callback message. */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => SocialProps::connections(),
            'flash' => SocialProps::flash($request),
        ]);
    }

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
        $message = NetworkNotes::disconnected($network, $label, $revoked);

        if (! $request->expectsJson() && Ui::enabled()) {
            return back()->with(StatusMessages::FLASH_KEY, ['type' => 'success', 'message' => $message]);
        }

        return response()->json([
            'message' => $message,
            'revoked' => $revoked,
            'data' => $this->social->connection($network)->toArray(),
        ]);
    }

    /** The pages the account can post to, each with a `selected` flag. 404 for a network without pages. */
    public function pages(string $network): JsonResponse
    {
        $this->withPages($network);

        $connection = $this->social->connection($network)->toArray();

        return response()->json(['data' => [
            'pages' => $connection['pages'],
            'selected_pages' => $connection['selected_pages'],
        ]]);
    }

    /** Choose the pages shares go to: `pages` is a list of one or more page ids. */
    public function selectPages(Request $request, string $network): JsonResponse|RedirectResponse
    {
        $this->withPages($network);

        $json = $request->expectsJson() || ! Ui::enabled();
        $ids = $request->input('pages');
        $ids = is_array($ids) ? array_values(array_filter($ids, fn ($id) => is_string($id) && $id !== '')) : [];
        $label = $this->social->network($network)->label();

        try {
            $connection = $this->social->selectPages($network, $ids);
        } catch (InvalidArgumentException|NotConnected $e) {
            return $json
                ? response()->json(['message' => $e->getMessage(), 'errors' => ['pages' => [$e->getMessage()]]], 422)
                : back()->with(StatusMessages::FLASH_KEY, ['type' => 'error', 'message' => $e->getMessage()]);
        }

        $message = "{$label} will post to the chosen pages.";

        if (! $json) {
            return back()->with(StatusMessages::FLASH_KEY, ['type' => 'success', 'message' => $message]);
        }

        return response()->json(['message' => $message, 'data' => $connection->toArray()]);
    }

    /** @throws NetworkDisabled|UnknownNetwork */
    private function withPages(string $network): void
    {
        $this->enabled($network);
        abort_unless($this->social->hasPages($network), 404);
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

<?php

declare(strict_types=1);

namespace Siberfx\SocialAutopost\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Siberfx\SocialAutopost\Contracts\ShareableOnSocial;
use Siberfx\SocialAutopost\Exceptions\NetworkDisabled;
use Siberfx\SocialAutopost\Exceptions\NotConnected;
use Siberfx\SocialAutopost\Exceptions\NotShareable;
use Siberfx\SocialAutopost\Exceptions\RequestFailed;
use Siberfx\SocialAutopost\Models\SharedPost;
use Siberfx\SocialAutopost\SocialManager;
use Siberfx\SocialAutopost\Support\Lines;
use Siberfx\SocialAutopost\Support\StatusMessages;
use Siberfx\SocialAutopost\Support\Ui;

/**
 * Manual share from the share button. Signed: the app signed the model class
 * and id, so the class name from the URL is trusted. With a network in the
 * route it shares there only; without one, to every enabled, connected network.
 */
final class ShareController extends Controller
{
    public function __invoke(Request $request, SocialManager $social, ?string $network = null): JsonResponse|RedirectResponse
    {
        if ($network !== null) {
            abort_unless($social->isEnabled($network), 404);
        }

        $type = (string) $request->query('type', '');
        $class = Relation::getMorphedModel($type) ?? $type;

        abort_if(! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnSocial::class), 404);

        /** @var (Model&ShareableOnSocial)|null $model */
        $model = $class::query()->find((string) $request->query('id', ''));
        abort_if($model === null, 404);

        // pages[] picks the pages for this one share, on a network route of a network with pages.
        $pages = $request->input('pages');
        $pages = $network !== null && is_array($pages) && $pages !== [] && $social->hasPages($network)
            ? array_values(array_map('strval', array_filter($pages, 'is_scalar')))
            : null;

        try {
            $records = $pages !== null
                ? $social->shareToPages($model, (string) $network, $pages, SharedPost::TRIGGER_MANUAL)
                : $social->share($model, $network, SharedPost::TRIGGER_MANUAL);
        } catch (NotShareable|NotConnected|NetworkDisabled|InvalidArgumentException $e) {
            return $this->respond($request, 422, $e->getMessage());
        } catch (RequestFailed $e) {
            return $this->respond($request, 502, $e->getMessage());
        }

        $posted = $records->filter(fn (SharedPost $record) => $record->status === SharedPost::STATUS_POSTED);

        if ($records->isEmpty()) {
            return $this->respond($request, 422, Lines::get('no_targets'));
        }

        $data = $records->map(fn (SharedPost $record) => [
            'network' => $record->network,
            'page' => $record->target ?? null,
            'status' => $record->status,
            'post_id' => $record->post_id,
            'posted_at' => $record->posted_at?->toIso8601String(),
            'error' => $record->error,
        ])->values()->all();

        if ($posted->isEmpty()) {
            return $this->respond($request, 502, Lines::get('share_failed_everywhere'), $data);
        }

        $networks = $posted->pluck('network')->unique()->implode(', ');
        $message = $posted->count() === $records->count()
            ? Lines::get('shared_on', ['networks' => $networks])
            : Lines::get('shared_on_partial', ['networks' => $networks]);

        return $this->respond($request, 201, $message, $data);
    }

    /** @param  array<int, array<string, mixed>>  $data */
    private function respond(Request $request, int $status, string $message, array $data = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || ! Ui::enabled()) {
            return response()->json(['message' => $message] + ($data === [] ? [] : ['data' => $data]), $status);
        }

        return back()->with(StatusMessages::FLASH_KEY, ['type' => $status < 300 ? 'success' : 'error', 'message' => $message]);
    }
}

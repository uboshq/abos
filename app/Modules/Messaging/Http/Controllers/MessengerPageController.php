<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Messaging\Models\Call;
use App\Modules\Messaging\Services\LiveKitToken;
use App\Modules\Messaging\Services\MessengerPresenter;
use App\Modules\Messaging\Services\MessengerService;
use App\Modules\Messaging\Services\Presence;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * The web messages page (sample M1UddRxK v7) and the light poll behind the top-bar chat icon.
 *
 * ⓘ The top bar asks `unread` every ~15 s on every ABOS page: the badge count and a ringing call for the incoming-call
 * popup (cPanel has no websockets — contract §6). One small query each.
 */
final class MessengerPageController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MessengerService $messenger,
        private readonly MessengerPresenter $present,
        private readonly Presence $presence,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:messaging.use')];
    }

    public function index(Request $request): View
    {
        return view('messaging::index', [
            'menu' => app(MenuBuilder::class)->forUser($request->user()),
            'open' => is_string($request->query('t')) ? $request->query('t') : null,
            // ⓘ calls_off: LIVEKIT_URL / KEY / SECRET empty → the page draws no call button
            'callsOn' => app(LiveKitToken::class)->configured(),
        ]);
    }

    public function unread(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        $this->presence->seen((int) $me->id);

        $ringing = Call::query()->with('caller')
            ->where('callee_id', $me->id)
            ->where('status', Call::RINGING)
            ->where('created_at', '>=', now()->subSeconds(Call::RING_SECONDS))
            ->latest('id')->first();

        return response()->json([
            'unread_total' => $this->messenger->unreadTotal($me),
            'ringing' => $ringing && $ringing->caller ? [
                'call_id' => (string) $ringing->public_id,
                'video' => (bool) $ringing->video,
                'peer' => $this->present->person($ringing->caller),
            ] : null,
        ]);
    }
}

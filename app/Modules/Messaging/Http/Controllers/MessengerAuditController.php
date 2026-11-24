<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Messaging\Models\Member;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\Thread;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⛔ The one door into another person's messages — owner's plan: "মালিক/প্রশাসক অন্যের বার্তা সাধারণত পড়েন না —
 * কেবল নিরীক্ষায়, কারণ লিখে, ইতিহাসে".
 *
 * ⭐ Three steps, none skippable:
 *   1. the key `messaging.audit` (nobody has it by default; the owner has every key);
 *   2. the list shows only WHO talked and WHEN — never a word of what was said;
 *   3. opening one thread asks a written reason (≥ 10 characters) and writes it to the audit trail on that thread
 *      (`messages_read_audit`), every time — the trail names the reader, the thread, the reason and the moment.
 * ⓘ A reading shows everything, deleted-for-everyone messages included (marked) — that is what an audit is for.
 * ⓘ The reason lives in the session for 15 minutes only, so a bookmarked page asks again.
 */
final class MessengerAuditController extends Controller implements HasMiddleware
{
    public const ACTION = 'messages_read_audit';

    private const REASON_MINUTES = 15;

    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:messaging.audit')];
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        // ⛔ the messenger follows the person across companies ([[FollowsThePerson]]); the audit stays inside the open company
        $threads = Thread::query()
            ->where('company_id', CompanyContext::id())
            ->with(['members.user:id,name'])
            ->when($q !== '', function ($query) use ($q): void {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn ($w) => $w->where('title', 'like', $like)
                    ->orWhereIn('id', Member::query()->select('thread_id')
                        ->whereIn('user_id', User::query()->select('id')->where('name', 'like', $like)
                            ->whereHas('companies', fn ($c) => $c->whereKey(CompanyContext::id())))));
            })
            ->when(in_array($request->query('kind'), ['direct', 'group'], true), fn ($query) => $query->where('kind', $request->query('kind')))
            ->orderBy('last_at', $request->query('sort') === 'oldest' ? 'asc' : 'desc')
            ->paginate(30)
            ->withQueryString();

        return view('messaging::audit.index', [
            'menu' => $this->menu->forUser($request->user()),
            'threads' => $threads,
            'q' => $q,
        ]);
    }

    public function open(Request $request, string $thread): RedirectResponse
    {
        $t = Thread::query()->where('company_id', CompanyContext::id())->wherePublicId($thread)->firstOrFail();
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        app(AuditEngine::class)->recordAction($t, self::ACTION, trim((string) $data['reason']));

        $request->session()->put($this->sessionKey($t), now()->addMinutes(self::REASON_MINUTES)->getTimestamp());

        return redirect()->route('messaging.audit.show', ['thread' => $t->public_id]);
    }

    public function show(Request $request, string $thread): View|RedirectResponse
    {
        $t = Thread::query()->where('company_id', CompanyContext::id())->wherePublicId($thread)->with('members.user:id,name')->firstOrFail();
        $until = $request->session()->get($this->sessionKey($t));

        if (! is_int($until) || $until < now()->getTimestamp()) {
            return redirect()->route('messaging.audit.index')->with('warning', __('messaging::audit.reason_needed'));
        }

        $messages = Message::query()->where('thread_id', $t->id)->with(['sender:id,name', 'file'])
            ->orderBy('id')->paginate(100);

        return view('messaging::audit.show', [
            'menu' => $this->menu->forUser($request->user()),
            'thread' => $t,
            'messages' => $messages,
        ]);
    }

    private function sessionKey(Thread $thread): string
    {
        return 'messenger.audit.'.$thread->id;
    }
}

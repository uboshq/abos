<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Core\Services\NotificationAudit;
use App\Core\Support\NotificationKinds;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationEvent;
use App\Models\UserDataScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ বিজ্ঞপ্তি কেন্দ্র — কোম্পানির সব খবর, কে পেলেন আর কে পড়লেন (মালিকের স্পেক §২, §৪; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)।
 *
 * ⓘ "আমার বিজ্ঞপ্তি" নিজের খবরের পাতা; এটা দেখভালের পাতা — যিনি কাজ চালান তিনি দেখেন কোন খবর গেল, কতজন পড়লেন,
 * কোনটা কারও চোখে পড়েনি। খোঁজা, ছাঁকনি, সাজানো, আর (আলাদা চাবিতে) বাছা খবর কেন্দ্র থেকে আর্কাইভ।
 *
 * ⛔ দেয়াল: কোম্পানি (মডেলের নিজের), আর শাখা — তালিকা দেখার শাখা মানে ([[ViewedBranch]]), একটা খবর খোলা নাগাল মানে।
 * কেন্দ্রের আর্কাইভ প্রাপকের নিজের ঘণ্টা বদলায় না — খবর কেবল কেন্দ্রের তালিকা থেকে সরে।
 */
class NotificationCenterController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly MenuBuilder $menu) {}

    public function index(Request $request): View
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'module' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', Rule::in(NotificationKinds::CATEGORIES)],
            'priority' => ['nullable', Rule::in(NotificationKinds::PRIORITIES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'archived' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'priority', 'recipients'])],
        ]);

        $query = ViewedBranch::narrow(NotificationEvent::query(), 'notification_events.branch_id')
            ->with('branch')
            ->withCount(['deliveries as read_count' => fn ($q) => $q->whereNotNull('read_at')])
            ->when(! empty($f['archived']), fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->when($f['module'] ?? null, fn ($q, $m) => $q->where('module', $m))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($f['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$term.'%')
                ->orWhere('body', 'like', '%'.$term.'%')->orWhere('type', 'like', '%'.$term.'%')));

        match ($f['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('id'),
            'priority' => $query->orderByRaw('FIELD(priority, ?, ?, ?, ?)', NotificationKinds::PRIORITIES)->orderByDesc('id'),
            'recipients' => $query->orderByDesc('recipients')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        return view('notification::center.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'filters' => $f,
            'modules' => NotificationEvent::query()->distinct()->orderBy('module')->pluck('module'),
            'canManage' => $request->user()->can('notification.manage'),
        ]);
    }

    public function show(Request $request, NotificationEvent $event): View
    {
        // ⛔ অন্য শাখার খবর ঠিকানা বদলে খোলা নয় — নাগালের বাইরে ৪০৪, অন্য মডিউলের দেয়ালের মতো
        abort_unless(app(DataScope::class)->allows($request->user(), UserDataScope::BRANCH,
            $event->branch_id === null ? null : (int) $event->branch_id), 404);

        return view('notification::center.show', [
            'menu' => $this->menu->forUser($request->user()),
            'event' => $event->load(['branch', 'actor']),
            'deliveries' => Notification::query()->where('event_id', $event->id)->with('user')->orderBy('id')->get(),
        ]);
    }

    /** ⭐ বাছা খবর কেন্দ্র থেকে আর্কাইভ বা ফেরত — নিরীক্ষার খাতায় যায় */
    public function archive(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['archive', 'restore'])],
        ]);

        $rows = ViewedBranch::narrow(NotificationEvent::query(), 'notification_events.branch_id')
            ->whereIn('id', array_map('intval', $data['ids']));

        $changed = $data['action'] === 'archive'
            ? $rows->whereNull('archived_at')->update(['archived_at' => now()])
            : $rows->whereNotNull('archived_at')->update(['archived_at' => null]);

        app(NotificationAudit::class)->record('center_'.$data['action'], null, 'done', ['asked' => count($data['ids']), 'changed' => $changed]);

        return back()->with('saved', __('notification::center.archived', ['count' => $changed]));
    }
}

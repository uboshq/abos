<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Services\ListExport;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\NotificationKinds;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\NotificationEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ বিজ্ঞপ্তির আর্কাইভ — খোঁজা, ছাঁকা, আর চাবি থাকলে নামানো (মালিকের স্পেক §৪ "Archive: Retention, Search, Export by
 * Permission"; ধাপ ৪)।
 *
 * ⓘ আর্কাইভে যায় কেন্দ্র থেকে, বা প্রতিদিনের [[RetentionService]] দিয়ে (পুরনো, সবাই পড়েছেন)। রাখার মেয়াদ পর্দায় লেখা থাকে।
 * ⛔ শাখার দেয়াল কেন্দ্রের মতোই ([[ViewedBranch::narrow()]]); নামানো আলাদা চাবিতে (`notification.archive.export`) — না থাকলে
 * বোতামও নেই, ঠিকানাতেও নামে না।
 */
class NotificationArchiveController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly MenuBuilder $menu) {}

    public function index(Request $request): View
    {
        $canExport = $request->user()->can('notification.archive.export');

        if (! $canExport) {
            app(ListExport::class)->refuse();
        }

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'module' => ['nullable', 'string', 'max:32'],
            'priority' => ['nullable', Rule::in(NotificationKinds::PRIORITIES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $rows = ViewedBranch::narrow(NotificationEvent::query(), 'notification_events.branch_id')
            ->with('branch')
            ->whereNotNull('notification_events.archived_at')
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where('notification_events.title', 'like', '%'.addcslashes($s, '%_\\').'%'))
            ->when($f['module'] ?? null, fn ($q, $m) => $q->where('notification_events.module', $m))
            ->when($f['priority'] ?? null, fn ($q, $p) => $q->where('notification_events.priority', $p))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('notification_events.created_at', '>=', $d.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('notification_events.created_at', '<=', $d.' 23:59:59'))
            ->orderByDesc('notification_events.id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $settings = app(SettingsService::class);

        return view('notification::archive.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'filters' => $f,
            'canExport' => $canExport,
            'modules' => collect(array_keys(NotificationKinds::all()))->map(fn ($t) => NotificationKinds::classify($t)['module'])->unique()->sort()->values(),
            'archiveDays' => (int) $settings->get('notification.archive_after_days', 90),
            'retentionDays' => (int) $settings->get('notification.retention_days', 0),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\NotificationAuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ⭐ বিজ্ঞপ্তির নিরীক্ষার খাতা — কে, কী, কখন, কোনটায়, ফল (মালিকের স্পেক §১৩; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)।
 *
 * ⓘ কেবল পড়া — খাতাটা কেউ বদলাতে পারেন না। কোম্পানির দেয়াল মডেলের নিজের।
 */
class NotificationAuditController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly MenuBuilder $menu) {}

    public function index(Request $request): View
    {
        $f = $request->validate([
            'action' => ['nullable', 'string', 'max:48'],
            'outcome' => ['nullable', 'string', 'max:16'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $rows = NotificationAuditLog::query()
            ->with('actor')
            ->when($f['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($f['outcome'] ?? null, fn ($q, $o) => $q->where('outcome', $o))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('notification::audit.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'filters' => $f,
            'actions' => NotificationAuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}

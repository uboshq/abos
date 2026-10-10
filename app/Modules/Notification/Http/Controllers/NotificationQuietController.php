<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\NotificationAudit;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\NotificationSuppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ⭐ নীরব সময় আর সারসংক্ষেপ — কোম্পানির স্বাভাবিক নীরব সময়, কে কী বেছেছেন, আর কত খবর পিছানো বা ধরে রাখা হলো
 * (মালিকের স্পেক §৪ "Quiet Hours & Digest", §১৪; ধাপ ৩)।
 *
 * ⓘ ব্যক্তির নিজের পছন্দ তিনি নিজে বসান (নিজের বিজ্ঞপ্তির সেটিংসে); এখানে কেবল দেখা আর কোম্পানির স্বাভাবিক মান।
 * ⛔ জরুরি খবর নীরব সময়েও যায় — এটা বদলানো যায় না।
 */
class NotificationQuietController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly MenuBuilder $menu) {}

    public function index(Request $request): View
    {
        $rows = NotificationPreference::query()->with('user:id,name')
            ->where(fn ($q) => $q->where('quiet_enabled', true)->orWhere('frequency', '!=', 'instant'))
            ->orderBy('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $settings = app(SettingsService::class);

        return view('notification::quiet.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'quietStart' => (string) $settings->get('notification.quiet_start', ''),
            'quietEnd' => (string) $settings->get('notification.quiet_end', ''),
            'deferred' => NotificationSuppression::query()->where('reason', 'quiet_hours')->where('created_at', '>=', now()->subWeek())->count(),
            'held' => NotificationSuppression::query()->where('reason', 'digest')->where('created_at', '>=', now()->subWeek())->count(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'quiet_start' => ['nullable', 'date_format:H:i', 'required_with:quiet_end'],
            'quiet_end' => ['nullable', 'date_format:H:i', 'required_with:quiet_start', 'different:quiet_start'],
        ]);

        $settings = app(SettingsService::class);
        $settings->set('notification.quiet_start', (string) ($data['quiet_start'] ?? ''));
        $settings->set('notification.quiet_end', (string) ($data['quiet_end'] ?? ''));

        app(NotificationAudit::class)->record('quiet_defaults', null, 'done', ['on' => filled($data['quiet_start'] ?? null)]);

        return back()->with('saved', __('notification::quiet.saved'));
    }
}

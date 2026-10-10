<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Notifications\ChannelRegistry;
use App\Core\Notifications\DeliveryService;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Models\NotificationDeliveryAttempt;
use App\Models\NotificationJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ ডেলিভারির পর্দা — কিউ, চেষ্টার লগ, ব্যর্থ-তালিকা, মাধ্যমের স্বাস্থ্য (মালিকের স্পেক §৪, §১৪, §১৫; ধাপ ২)।
 *
 * ⓘ দেখা এক চাবিতে (`notification.deliveries`), হাতে আবার চেষ্টা আর বাতিল আলাদা চাবিতে (`notification.retry`) — আর দুইটাই
 * নিরীক্ষার খাতায় ([[DeliveryService]])। কোম্পানির দেয়াল মডেলের নিজের।
 */
class NotificationDeliveryController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DeliveryService $delivery,
    ) {}

    /** ⭐ কিউ — অপেক্ষায়, চলছে, আবার চেষ্টা হবে */
    public function queue(Request $request): View
    {
        return $this->jobs($request, NotificationJob::OPEN, 'notification::deliveries.queue');
    }

    /** ⭐ ব্যর্থ-তালিকা (dead-letter) — কারণ, আবার চেষ্টা, বাতিল */
    public function failed(Request $request): View
    {
        return $this->jobs($request, [NotificationJob::DEAD], 'notification::deliveries.failed');
    }

    /** ⭐ চেষ্টার লগ — কখন, প্রোভাইডারের রেফারেন্স, ফল, ভুল */
    public function logs(Request $request): View
    {
        $f = $request->validate([
            'channel' => ['nullable', Rule::in(NotificationChannel::ALL)],
            'outcome' => ['nullable', Rule::in(['sent', 'transient', 'permanent'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $rows = NotificationDeliveryAttempt::query()
            ->with('job.user')
            ->when($f['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when($f['outcome'] ?? null, fn ($q, $o) => $q->where('outcome', $o))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('notification::deliveries.logs', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'filters' => $f,
        ]);
    }

    /**
     * ⭐ মাধ্যমের স্বাস্থ্য — সংযুক্ত কি, শেষ পরীক্ষা, গত ২৪ ঘণ্টা আর ৭ দিনের সাফল্য ও ভুলের হার, গড় সময়, কিউয়ের গভীরতা,
     * ব্যর্থ-তালিকার সংখ্যা (স্পেক §৪, §১৫)।
     */
    public function health(Request $request): View
    {
        $company = (int) CompanyContext::id();
        $registry = app(ChannelRegistry::class);
        $rows = [];

        foreach ($registry->all() as $key => $channel) {
            $config = $registry->config($company, $key);
            $rows[] = [
                'key' => $key,
                'config' => $config,
                'connected' => $channel->connected($config),
                'day' => $this->rates($key, now()->subDay()),
                'week' => $this->rates($key, now()->subWeek()),
                'queued' => NotificationJob::query()->where('channel', $key)->whereIn('status', NotificationJob::OPEN)->count(),
                'dead' => NotificationJob::query()->where('channel', $key)->where('status', NotificationJob::DEAD)->count(),
                'stuck' => NotificationJob::query()->where('channel', $key)->where('status', NotificationJob::PROCESSING)
                    ->where('claimed_at', '<', now()->subMinutes(15))->count(),
            ];
        }

        return view('notification::deliveries.health', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
        ]);
    }

    public function retry(Request $request, NotificationJob $job): RedirectResponse
    {
        return back()->with(...($this->delivery->retryByHand($job)
            ? ['saved', __('notification::delivery.retried')]
            : ['failed', __('notification::delivery.not_retryable')]));
    }

    public function cancel(Request $request, NotificationJob $job): RedirectResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string', 'max:255']]);

        return back()->with(...($this->delivery->cancel($job, $data['resolution'])
            ? ['saved', __('notification::delivery.cancelled')]
            : ['failed', __('notification::delivery.not_cancellable')]));
    }

    /** @param  list<string>  $statuses */
    private function jobs(Request $request, array $statuses, string $view): View
    {
        $f = $request->validate([
            'channel' => ['nullable', Rule::in(NotificationChannel::ALL)],
            'status' => ['nullable', Rule::in($statuses)],
        ]);

        $rows = NotificationJob::query()
            ->with(['user', 'notification'])
            ->whereIn('status', isset($f['status']) ? [$f['status']] : $statuses)
            ->when($f['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view($view, [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'filters' => $f,
            'statuses' => $statuses,
            'canRetry' => $request->user()->can('notification.retry'),
        ]);
    }

    /** @return array{total: int, sent: int, failed: int, success: ?float, latency: ?int} */
    private function rates(string $channel, Carbon $since): array
    {
        $base = NotificationDeliveryAttempt::query()->where('channel', $channel)->where('created_at', '>=', $since);

        $total = (clone $base)->count();
        $sent = (clone $base)->where('outcome', 'sent')->count();

        return [
            'total' => $total,
            'sent' => $sent,
            'failed' => $total - $sent,
            'success' => $total === 0 ? null : round($sent * 100 / $total, 1),
            'latency' => $total === 0 ? null : (int) (clone $base)->avg('duration_ms'),
        ];
    }
}

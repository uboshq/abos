<?php

declare(strict_types=1);

namespace App\Modules\Sales\Widgets;

use App\Core\Services\DealerScope;
use App\Core\Support\Money;
use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Modules\Sales\Metrics\SalesAnalytics;
use App\Modules\Sales\Services\SalesTargetService;
use Illuminate\Support\Carbon;

/**
 * ⭐ "লক্ষ্য বনাম অর্জন" উইজেট (৯ নম্বর, ২×১) — এই মাসের লক্ষ্য, অর্জন আর বাকি দিন (a6, ২০ অক্টোবর ২০২৬;
 * [[WidgetSummary]])।
 *
 * ⛔ অঙ্ক নিজে গোনা নয় — ওয়েবের দুই উৎসই:
 *  - দেয়ালের ভিতরের বিক্রয়কর্মী ([[DealerScope::walled()]]): লক্ষ্যের পাতার স্কোরবোর্ডে নিজের সারি
 *    ([[SalesTargetService::scoreboard()]]) — ফোনে কেবল নিজের লক্ষ্য আর নিজের অর্জন, `own: true`।
 *  - বাকি সবাই: বিক্রয়ের সারাংশ পর্দার লক্ষ্য-ঘর ([[SalesAnalytics::targetAchievement()]]) — যাঁদের লক্ষ্য বসানো তাঁদের যোগ।
 *
 * ⓘ শাখা: ওয়েবের লক্ষ্যের পাতা আর সারাংশ কোম্পানি ধরে, শাখা ধরে নয় — উইজেটও তাই, যাতে দুই জায়গায় দুই অঙ্ক না দেখায়।
 * ⓘ `target: null` মানে এই মাসে লক্ষ্যই বসানো নেই — "০ টাকার লক্ষ্য" নয় ([[SalesTargetService::setForMonth()]]-এর নিয়ম)।
 * ⓘ `days_left` আজকের দিনসহ: ২০ অক্টোবরে ১২ (২০ থেকে ৩১)।
 * ⓘ টাকা bcmath, দুই ঘরে গোল ([[Money::round()]]) — পাতার [[Money::format()]]-এর একই গোলানো।
 */
final class TargetSummary implements WidgetSummary
{
    /** ওয়েবের লক্ষ্যের পাতা (`sales.target.index`) — ফোন নিজের পর্দায় খোলে */
    public const LINK = '/sales/targets';

    public function __construct(
        private readonly SalesTargetService $targets,
        private readonly SalesAnalytics $analytics,
        private readonly DealerScope $scope,
    ) {}

    public function key(): string
    {
        return 'target';
    }

    public function module(): string
    {
        return 'sales';
    }

    /** ওয়েবের লক্ষ্যের পাতার চাবি ([[SalesTargetController::middleware()]]) */
    public function permission(): string
    {
        return 'sales.target.view';
    }

    public function data(User $user): array
    {
        $today = Carbon::today();
        $month = $today->copy()->startOfMonth();
        $daysLeft = $today->daysInMonth - $today->day + 1;

        if ($this->scope->walled($user)) {
            // ⛔ কেবল নিজের সারি — স্কোরবোর্ডে নিচের গাছও থাকে, কিন্তু উইজেট "আমার লক্ষ্য"
            $own = collect($this->targets->scoreboard($month))->first(fn (array $row) => (int) $row['user']->id === (int) $user->id);

            return [
                'month' => $month->format('Y-m'),
                'target' => $own === null || $own['target'] === null ? null : Money::round($own['target']),
                'achieved' => Money::round($own['achieved'] ?? '0'),
                'days_left' => $daysLeft,
                'own' => true,
                'link' => self::LINK,
            ];
        }

        $all = $this->analytics->targetAchievement($today);

        return [
            'month' => $all['month'],
            'target' => $all['target'] === null ? null : Money::round($all['target']),
            'achieved' => Money::round($all['achieved']),
            'days_left' => $daysLeft,
            'own' => false,
            'link' => self::LINK,
        ];
    }
}

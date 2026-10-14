<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\CreditDecision;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ ঝুঁকির মাত্রা — নিয়ম ধরে, প্রতিটা কারণ দেখিয়ে (বাকি নিয়ন্ত্রণ ধাপ ৩.১, মালিকের v2.0-এর "Risk rating", AI ছাড়া)।
 *
 * ⓘ পাঁচ লক্ষণ, প্রতিটার নম্বর কোম্পানির সেটিংসে বদলানো যায় (০ = ঐ লক্ষণ বন্ধ):
 *   ১. মেয়াদ পেরোনো বিলের বয়স — সবচেয়ে পুরনোটার প্রতিটা শুরু হওয়া ৩০ দিনে নম্বর (চার ধাপ পর্যন্ত)।
 *   ২. মেয়াদ পেরোনো টাকা — চলতি সীমার অর্ধেক বা বেশি (সীমা ০ হলে যেকোনো অঙ্ক)।
 *   ৩. শর্তের বাইরে দেরি — মেয়াদের পরেও গ্রাহকের নিজের বাকির দিনের চেয়ে বেশি দিন (বাকির দিন ০ হলে ৭ দিন)।
 *   ৪. দেয়ালে থামা — গত ৯০ দিনে কতবার দেয়াল কাগজ থামিয়েছে (দেয়ালের সাক্ষ্য, [[CreditDecision]]) — চার বার পর্যন্ত।
 *   ৫. চেক ফেরত — গত ১৮০ দিনে, দুইটা পর্যন্ত। ৬. বাকি বন্ধের ইতিহাস — গত ৩৬৫ দিনে, দুইবার পর্যন্ত।
 * যোগফল তিন সীমার সাথে মেলে: মাঝারি · বেশি · চরম; নিচে কম।
 *
 * ⛔ মাত্রা কেবল জানায় — কোনো সীমা বদলায় না, কোনো দেয়াল খোলে বা বন্ধ করে না (মালিকের নিয়ম: সীমা পরম, পথ একটাই)।
 * ⛔ বাকির অঙ্ক নিজে গোনে না — বিল আর মেয়াদ দেয়ালের হিসাব থেকে ([[CreditExposure::openBills()]], [[positionsFor()]])।
 */
final class CreditRisk
{
    public const LOW = 'low';

    public const MEDIUM = 'medium';

    public const HIGH = 'high';

    public const CRITICAL = 'critical';

    /** @var list<string> নিচ থেকে উপরে */
    public const LEVELS = [self::LOW, self::MEDIUM, self::HIGH, self::CRITICAL];

    /** সেটিংসের চাবি → ডিফল্ট নম্বর (module.php-র ঘোষণার সাথে মেলে) */
    public const RULES = [
        'credit_control.risk_points_overdue_age' => 10,
        'credit_control.risk_points_overdue_share' => 20,
        'credit_control.risk_points_late_beyond_terms' => 15,
        'credit_control.risk_points_wall_stop' => 5,
        'credit_control.risk_points_bounced_cheque' => 25,
        'credit_control.risk_points_block' => 10,
    ];

    public const MEDIUM_AT = 'credit_control.risk_medium_at';

    public const HIGH_AT = 'credit_control.risk_high_at';

    public const CRITICAL_AT = 'credit_control.risk_critical_at';

    public function __construct(
        private readonly CreditExposure $credit,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  iterable<Customer>  $customers
     * @param  array<int, array<string, mixed>>|null  $facts  আগেই তোলা উপকরণ ([[factsFor()]]) — রিপোর্ট দুইবার না তুলুক
     * @return array<int, array{level: string, score: int, reasons: list<string>}>
     */
    public function rateAll(iterable $customers, ?array $facts = null): array
    {
        $list = [];
        foreach ($customers as $customer) {
            $list[(int) $customer->id] = $customer;
        }

        if ($list === []) {
            return [];
        }

        $points = $this->points();
        $facts ??= $this->factsFor($list);
        $out = [];

        foreach ($list as $id => $customer) {
            $score = 0;
            $reasons = [];
            ['oldest' => $oldest, 'overdue' => $amount, 'limit' => $limit] = $facts[$id];
            $stops = [$id => $facts[$id]['stops']];
            $bounces = [$id => $facts[$id]['bounces']];
            $blocks = [$id => $facts[$id]['blocks']];

            if ($oldest > 0 && $points['credit_control.risk_points_overdue_age'] > 0) {
                $steps = min(4, intdiv($oldest - 1, 30) + 1);
                $score += $steps * $points['credit_control.risk_points_overdue_age'];
                $reasons[] = __('credit_control::risk.reason_overdue_age', ['days' => $oldest]);
            }

            $half = bcdiv($limit, '2', 4);
            if (bccomp($amount, '0', 4) > 0 && bccomp($amount, $half, 4) >= 0 && $points['credit_control.risk_points_overdue_share'] > 0) {
                $score += $points['credit_control.risk_points_overdue_share'];
                $reasons[] = __('credit_control::risk.reason_overdue_share', ['amount' => Money::format($amount), 'limit' => Money::format($limit)]);
            }

            // ⛔ নিজের শর্ত; ৭ দিন কেবল শর্ত ০ হলে — উপরের নিয়ম ৩ (আগে `max(7, …)` ১–৬ দিনের শর্তকেও ৭ ধরত; ১৪ অক্টোবর ২০২৬)
            $days = (int) ($customer->credit_days ?? 0);
            $terms = $days > 0 ? $days : 7;
            if ($oldest > $terms && $points['credit_control.risk_points_late_beyond_terms'] > 0) {
                $score += $points['credit_control.risk_points_late_beyond_terms'];
                $reasons[] = __('credit_control::risk.reason_late_beyond_terms', ['days' => $oldest, 'terms' => $terms]);
            }

            foreach ([
                ['credit_control.risk_points_wall_stop', $stops[$id] ?? 0, 4, 'reason_wall_stops'],
                ['credit_control.risk_points_bounced_cheque', $bounces[$id] ?? 0, 2, 'reason_bounced_cheques'],
                ['credit_control.risk_points_block', $blocks[$id] ?? 0, 2, 'reason_blocks'],
            ] as [$rule, $count, $cap, $word]) {
                if ($count > 0 && $points[$rule] > 0) {
                    $score += min($count, $cap) * $points[$rule];
                    $reasons[] = __('credit_control::risk.'.$word, ['n' => $count]);
                }
            }

            $out[$id] = ['level' => $this->levelOf($score), 'score' => $score, 'reasons' => $reasons];
        }

        return $out;
    }

    /**
     * ⭐ মাত্রার উপকরণ — কাঁচা সংখ্যা, নম্বর ছাড়া (রিপোর্টের "পরিশোধের আচরণ"-ও এটাই দেখায়, যাতে দুই জায়গা এক কথা বলে)।
     *
     * @param  array<int, Customer>  $list
     * @return array<int, array{limit: string, exposure: string, credit_days: int, open: int, overdue_bills: int, oldest: int, overdue: string, stops: int, bounces: int, blocks: int}>
     */
    public function factsFor(array $list): array
    {
        if ($list === []) {
            return [];
        }

        $ids = array_keys($list);
        $positions = $this->credit->positionsFor($list);
        $bills = $this->credit->openBills($list);
        $stops = $this->countBy('sal_credit_decisions', 'customer_id', $ids, 90, fn ($q) => $q->where('result', CreditDecision::STOP));
        $bounces = $this->countBy('acc_cheques', 'party_id', $ids, 180, fn ($q) => $q->whereNull('deleted_at')
            ->where('direction', 'received')->where('party_type', 'customer')->where('status', 'bounced'), 'updated_at');
        $blocks = $this->countBy('audit_trails', 'auditable_id', $ids, 365, fn ($q) => $q
            ->where('auditable_type', Customer::class)->where('action', 'credit_blocked'));

        $out = [];

        foreach ($list as $id => $customer) {
            $overdue = array_values(array_filter($bills[$id] ?? [], fn (array $b) => $b['days_overdue'] > 0));

            $out[$id] = [
                'limit' => $positions[$id]['limit'] ?? '0',
                'exposure' => $positions[$id]['exposure'] ?? '0',
                'credit_days' => (int) ($customer->credit_days ?? 0),
                'open' => count($bills[$id] ?? []),
                'overdue_bills' => count($overdue),
                'oldest' => $overdue === [] ? 0 : (int) max(array_column($overdue, 'days_overdue')),
                'overdue' => array_reduce($overdue, fn (string $s, array $b) => bcadd($s, $b['unpaid'], 4), '0'),
                'stops' => $stops[$id] ?? 0,
                'bounces' => $bounces[$id] ?? 0,
                'blocks' => $blocks[$id] ?? 0,
            ];
        }

        return $out;
    }

    /** @return array{level: string, score: int, reasons: list<string>} */
    public function rate(Customer $customer): array
    {
        return $this->rateAll([$customer])[(int) $customer->id];
    }

    public function levelOf(int $score): string
    {
        return match (true) {
            $score >= $this->setting(self::CRITICAL_AT, 75) => self::CRITICAL,
            $score >= $this->setting(self::HIGH_AT, 45) => self::HIGH,
            $score >= $this->setting(self::MEDIUM_AT, 20) => self::MEDIUM,
            default => self::LOW,
        };
    }

    /** @return array<string, int> */
    private function points(): array
    {
        $out = [];
        foreach (self::RULES as $key => $default) {
            $out[$key] = max(0, $this->setting($key, $default));
        }

        return $out;
    }

    private function setting(string $key, int $default): int
    {
        return (int) ($this->settings->get($key, $default) ?? $default);
    }

    /**
     * গত `$days` দিনে কতবার — গ্রাহক ধরে, কোম্পানির ভিতরে।
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function countBy(string $table, string $column, array $ids, int $days, \Closure $where, string $when = 'created_at'): array
    {
        return DB::table($table)
            ->where('company_id', CompanyContext::id())
            ->whereIn($column, $ids)
            ->where($when, '>=', Carbon::now()->subDays($days))
            ->tap($where)
            ->groupBy($column)
            ->selectRaw("{$column} as k, COUNT(*) as n")
            ->pluck('n', 'k')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}

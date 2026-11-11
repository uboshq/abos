<?php

declare(strict_types=1);

namespace App\Modules\Sales\Assistant;

use App\Core\Engines\Assistant\AssistantTool;
use App\Core\Support\FiscalYearStart;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Sales\Metrics\SalesAnalytics;
use Illuminate\Support\Carbon;

/**
 * ⭐ কোপাইলটের দরজা — "আজ কত বিক্রি হলো?", "এ মাসে কত আদায়?" (পূর্ণ পরিকল্পনা অংশ ২)।
 * ⓘ বিক্রয়ের নিজের সংখ্যা ([[SalesAnalytics::totals()]] — নিট বিক্রি, [[SalesAnalytics::collection()]] — আদায়), বিক্রয়ের দেয়াল মেনে।
 */
final class SalesSummaryTool implements AssistantTool
{
    public const PERIODS = ['today', 'yesterday', 'month', 'year'];

    public static function name(): string
    {
        return 'sales.summary';
    }

    public static function permission(): string
    {
        return 'sales.invoice.view';
    }

    public static function describe(): array
    {
        return [
            'description' => ['bn' => 'নিট বিক্রি, বিলের সংখ্যা আর আদায় — আজ, গতকাল, এ মাস বা এ অর্থবছর', 'en' => 'Net sales, bill count and collection — today, yesterday, this month or this financial year'],
            'parameters' => ['period' => ['type' => 'string', 'description' => 'which period', 'enum' => self::PERIODS]],
            'keywords' => ['বিক্রি', 'বিক্রয়', 'আদায়', 'টাকা এল', 'bikri', 'aday', 'taka elo', 'sales', 'sale', 'collection'],
        ];
    }

    public function run(User $user, array $args): array
    {
        $period = (string) ($args['period'] ?? self::periodIn((string) ($args['q'] ?? '')));
        $today = Carbon::today();
        [$from, $to] = match ($period) {
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'month' => [$today->copy()->startOfMonth(), $today],
            'year' => [Carbon::parse(FiscalYearStart::on($today)), $today],
            default => [$today, $today],
        };
        $period = in_array($period, self::PERIODS, true) ? $period : 'today';
        $a = app(SalesAnalytics::class);
        $t = $a->totals($from->toDateString(), $to->toDateString());
        $collected = $a->collection($from->toDateString(), $to->toDateString());

        return [
            'rows' => [
                ['what' => (string) __('sales::assistant.net'), 'value' => '৳'.Money::format((string) $t['net'], 0)],
                ['what' => (string) __('sales::assistant.bills'), 'value' => (string) $t['count']],
                ['what' => (string) __('sales::assistant.collected'), 'value' => '৳'.Money::format($collected, 0)],
            ],
            'columns' => ['what', 'value'],
            'summary' => (string) __('sales::assistant.summary', ['period' => __('sales::assistant.period.'.$period),
                'net' => Money::format((string) $t['net'], 0), 'count' => $t['count'], 'collected' => Money::format($collected, 0)]),
            'sources' => [['label' => (string) __('sales::assistant.source_dashboard'), 'href' => route('module.dashboard', ['module' => 'sales'])]],
        ];
    }

    /** ⓘ শব্দ-তালিকার পথে প্রশ্ন থেকে সময় — "গতকাল", "এ মাস", "এ বছর"; নইলে আজ */
    public static function periodIn(string $q): string
    {
        $q = mb_strtolower($q);

        return match (true) {
            str_contains($q, 'গতকাল') || str_contains($q, 'gotokal') || str_contains($q, 'yesterday') => 'yesterday',
            str_contains($q, 'মাস') || str_contains($q, 'mash') || str_contains($q, 'month') => 'month',
            str_contains($q, 'বছর') || str_contains($q, 'bochor') || str_contains($q, 'year') => 'year',
            default => 'today',
        };
    }
}

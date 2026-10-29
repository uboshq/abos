<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Widgets;

use App\Core\Services\DataScope;
use App\Core\Widgets\WidgetSummary;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use Illuminate\Support\Carbon;

/**
 * ⭐ ১৭ · এই মাসের মোট লাভ — কেবল মালিক (উইজেটের নকশা ১৯ অক্টোবর, পাতা ২: "শুধু মালিক"; মালিকের আদেশ, ২০ অক্টোবর ২০২৬; cb)।
 *
 * ⓘ লাভক্ষতির হিসাবের "মোট লাভ"-এর হুবহু ভাগ ([[CoreReports::plSections()]]):
 *  - বিক্রি = বিক্রি আর বিক্রি-ফেরতের খাত ও তাদের নিচের সব, ক্রেডিট − ডেবিট — ভ্যাট খাতায় আলাদা, তাই বাদ; ফেরত কাটা যায়;
 *  - বিক্রিত মালের দাম = বিক্রিত মালের দাম আর ক্রয়মূল্যের পার্থক্যের খাত, ডেবিট − ক্রেডিট (IAS 2, খরচের স্তর ধরে);
 *  - মোট লাভ = বিক্রি − মালের দাম; হার = মোট লাভ ÷ বিক্রি × ১০০, এক দশমিকে (বিক্রি শূন্য হলে নেই)।
 * দেখার শাখা হোমের নিয়মে ([[AccountsFacts::netOfType()]]); বছর বন্ধের দাখিলা বাদ।
 *
 * ⛔ চাবি `executive.view` — মালিকের পর্দার চাবি (সব Executive দরজা এটাই চায়), হিসাবরক্ষকের রিপোর্টের চাবি নয়: নকশা বলে
 * "শুধু মালিক", আর লাভের অঙ্ক হিসাবের পাতায় যার আছে তার ফোনের হোম-পর্দায়ও যে থাকবে, তা নয়।
 */
final class ProfitSummary implements WidgetSummary
{
    public function key(): string
    {
        return 'profit';
    }

    public function module(): string
    {
        return 'accounts';
    }

    public function permission(): string
    {
        return 'executive.view';
    }

    public function data(User $user): array
    {
        $from = Carbon::today()->startOfMonth()->toDateString();
        $to = Carbon::today()->toDateString();

        // ⓘ নিট বিক্রয় = ৪১০০ − ৪১১০ − ৪১২০ ([[StandardChart::NET_SALES]])
        $sales = $this->net(StandardChart::NET_SALES, $from, $to, creditSide: true);
        $cogs = $this->net([StandardChart::COST_OF_GOODS_SOLD, StandardChart::PURCHASE_PRICE_VARIANCE], $from, $to, creditSide: false);
        $gross = bcsub($sales, $cogs, 4);

        return [
            'month' => Carbon::today()->format('Y-m'),
            'sales' => $sales,
            'cogs' => $cogs,
            'gross' => $gross,
            'margin' => bccomp($sales, '0', 4) === 0 ? null : self::oneDecimal(bcmul(bcdiv($gross, $sales, 8), '100', 8)),
            'link' => '/accounts/reports/profit-loss',
        ];
    }

    /** @param list<string> $codes */
    private function net(array $codes, string $from, string $to, bool $creditSide): string
    {
        $ids = [];

        foreach ($codes as $code) {
            $ids = [...$ids, ...(StandardChart::find($code)?->selfAndDescendants()->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [])];
        }

        $row = app(DataScope::class)->inView(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->whereIn('ledger_entries.account_id', $ids ?: [0])
            ->whereBetween('ledger_entries.trx_date', [$from, $to])
            ->whereNotIn('ledger_entries.source_type', YearEndService::closingSources())
            ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) as d, COALESCE(SUM(ledger_entries.credit), 0) as c')
            ->first();

        return $creditSide
            ? bcsub((string) $row->c, (string) $row->d, 4)
            : bcsub((string) $row->d, (string) $row->c, 4);
    }

    /** হার এক দশমিকে, অর্ধেক উপরে — "13.4" */
    private static function oneDecimal(string $value): string
    {
        $shift = bccomp($value, '0', 8) < 0 ? '-0.05' : '0.05';

        return bcadd(bcadd($value, $shift, 8), '0', 1);
    }
}

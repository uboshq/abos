<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\Money;
use App\Core\Support\ViewedBranch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ "আজকের খবর" — তহবিলের ঘরের পাশে, মালিকের অনুমোদিত নমুনা (৯ নভেম্বর ২০২৬): জরুরি (৯০ দিন পেরোনো পাওনা), ৭ দিনে (সামনের
 * দেনা আর হাতে যথেষ্ট কি না), বাজেট (এ মাসে যে খাত বাজেট সবচেয়ে বেশি ছাড়াল), ভালো (তহবিল বাড়ল)।
 *
 * ⓘ একটারও নিজের হিসাব নেই: ৯০ দিনের পাওনা "বয়স" রিপোর্টের ([[customer.ageing]]) — রিপোর্ট আর খবর কখনো দুই কথা বলে না,
 * রিপোর্টের চাবি আর শাখার দেয়াল দুটোই খাটে; ৭ দিনের দেনা [[FundPosition]]-এর; বাজেট [[BudgetService::vsActual()]]-এর।
 * ⓘ বলার কিছু না থাকলে লাইনটাই নেই — "০ জনের ৳০" খবর নয়।
 */
final class FundNews
{
    public function __construct(private readonly BudgetService $budget) {}

    /**
     * @param  array<string, mixed>  $fund  [[FundPosition::on()]]
     * @return list<array{tone: string, tag: string, lead: string, bold: string, tail: string, href: ?string}>
     */
    public function items(array $fund): array
    {
        $user = auth()->user();

        return array_values(array_filter([
            $user?->can('customer.report') ? $this->overdue() : null,
            $this->dueSoon($fund),
            $user?->can('finance.budget.view') ? $this->budgetOver() : null,
            $this->good($fund),
        ]));
    }

    /** @return array{tone: string, tag: string, lead: string, bold: string, tail: string, href: ?string}|null */
    private function overdue(): ?array
    {
        if (! in_array('customer.ageing', app(ReportEngine::class)->keys(), true)) {
            return null;
        }

        $filters = ['to' => Carbon::today()->toDateString(), 'branch_id' => ViewedBranch::one()];
        $count = 0;
        $amount = '0';

        foreach (app(ReportEngine::class)->stream('customer.ageing', $filters) as $row) {
            $old = (string) (data_get($row, 'bucket_90') ?? '0');

            if (bccomp($old, '0', 4) > 0) {
                $count++;
                $amount = bcadd($amount, $old, 4);
            }
        }

        if ($count === 0) {
            return null;
        }

        return $this->line('urgent', __('finance::fund.news_overdue_lead', ['n' => $this->digits((string) $count)]), $this->lakh($amount),
            __('finance::fund.news_overdue_tail'), Route::has('customer.report.show') ? route('customer.report.show', ['slug' => 'ageing']) : null);
    }

    /**
     * @param  array<string, mixed>  $fund
     * @return array{tone: string, tag: string, lead: string, bold: string, tail: string, href: ?string}|null
     */
    private function dueSoon(array $fund): ?array
    {
        if (bccomp((string) $fund['due7'], '0', 4) <= 0) {
            return null;
        }

        $short = bcsub((string) $fund['due7'], (string) $fund['total'], 4);
        $tail = bccomp($short, '0', 4) > 0
            ? __('finance::fund.news_due7_short', ['amount' => $this->lakh($short)])
            : __('finance::fund.news_due7_enough');

        return $this->line('soon', __('finance::fund.news_due7_lead'), $this->lakh((string) $fund['due7']), $tail,
            Route::has('supplier.report.show') ? route('supplier.report.show', ['slug' => 'ageing']) : null);
    }

    /** @return array{tone: string, tag: string, lead: string, bold: string, tail: string, href: ?string}|null */
    private function budgetOver(): ?array
    {
        if (! Schema::hasTable('fin_budgets')) {
            return null;
        }

        $today = Carbon::today();
        $worst = $this->budget->vsActual((int) $today->year, (int) $today->month, (int) $today->month)
            ->filter(fn (array $r) => $r['over_is_bad'] && $r['over'] && $r['used_pct'] !== null)
            ->sort(fn (array $a, array $b) => bccomp($b['used_pct'], $a['used_pct'], 1))
            ->first();

        if ($worst === null) {
            return null;
        }

        return $this->line('budget', __('finance::fund.news_budget_lead', ['head' => $worst['account']->name()]),
            __('finance::fund.news_budget_bold', ['pct' => $this->digits(bcsub($worst['used_pct'], '100', 0))]), '',
            Route::has('finance.budget.actual') ? route('finance.budget.actual') : null);
    }

    /**
     * @param  array<string, mixed>  $fund
     * @return array{tone: string, tag: string, lead: string, bold: string, tail: string, href: ?string}|null
     */
    private function good(array $fund): ?array
    {
        if ($fund['change_pct'] === null || bccomp((string) $fund['change'], '0', 4) <= 0) {
            return null;
        }

        return $this->line('good', __('finance::fund.news_good_lead'), $this->digits((string) $fund['change_pct']).'%',
            __('finance::fund.news_good_tail', ['amount' => $this->lakh((string) $fund['change'])]), null);
    }

    /** @return array{tone: string, tag: string, lead: string, bold: string, tail: string, href: ?string} */
    private function line(string $tone, string $lead, string $bold, string $tail, ?string $href): array
    {
        return ['tone' => $tone, 'tag' => __('finance::fund.tag_'.$tone), 'lead' => $lead, 'bold' => $bold, 'tail' => $tail, 'href' => $href];
    }

    private function lakh(string $amount): string
    {
        return '৳'.$this->digits(bcdiv($amount, '100000', 1)).' '.__('finance::fund.lakh');
    }

    private function digits(string $s): string
    {
        return app()->getLocale() === 'bn' ? \App\Core\Support\BanglaDigits::of($s) : $s;
    }
}

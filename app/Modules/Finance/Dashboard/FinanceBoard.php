<?php

declare(strict_types=1);

namespace App\Modules\Finance\Dashboard;

use App\Core\Engines\Report\ReportEngine;
use App\Modules\Finance\Services\CashForecast;
use App\Modules\Finance\Services\FundPosition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * ⭐ অর্থের ড্যাশবোর্ডের অঙ্ক, সারি 2 থেকে — মালিকের চূড়ান্ত নমুনা 4h63xhNiy4m9KaJkZZQaEL (10 নভেম্বর 2026 রাত: "100% স্যাম্পলের সাথে মিল রেখে")।
 *
 * ⓘ প্রতিটা অঙ্ক আগের হিসাব থেকেই — নগদের পূর্বাভাস ([[CashForecast]]), তহবিল ([[FundPosition]]), নগদ প্রবাহ বিবরণী
 * (হিসাবের রিপোর্ট accounts.cash_flow, IAS 7) — যাতে ড্যাশবোর্ড আর রিপোর্ট কখনো দুই কথা না বলে।
 */
final class FinanceBoard
{
    private ?array $fund = null;

    public function __construct(
        private readonly CashForecast $forecast,
        private readonly FundPosition $position,
        private readonly ReportEngine $reports,
    ) {}

    /**
     * ⭐ 13 সপ্তাহের নগদের পূর্বাভাস — আজকের তহবিল আর 13টা সপ্তাহ-শেষ; নিরাপদ সীমা = দুই সপ্তাহের গড় টাকা বেরোনো
     * (শেষ 90 দিনের দিনে গড় × 14 — প্রচলিত ন্যূনতম নগদের মাপ)।
     * ⓘ সবচেয়ে টানের সপ্তাহ = যেখানে তহবিল সবচেয়ে নিচে; তার বড় দেনাটা নামসহ।
     *
     * @return array{points: list<string>, weeks: list<array{from: string, to: string, net: string, closing: string, biggest: array{who: string, amount: string}|null}>, floor: string, low: int}
     */
    public function forecast(): array
    {
        $f = $this->forecast->weekly(13);
        $points = [$f['opening'], ...array_map(fn (array $w) => $w['closing'], $f['weeks'])];
        $low = 0;

        foreach ($f['weeks'] as $i => $w) {
            if (bccomp($w['closing'], $f['weeks'][$low]['closing'], 4) < 0) {
                $low = $i;
            }
        }

        return [
            'points' => $points,
            'weeks' => $f['weeks'],
            'floor' => bcmul($this->fund()['daily_out'], '14', 4),
            'low' => $low,
        ];
    }

    /**
     * ⭐ নগদ প্রবাহ বিবরণী · এ মাস (IAS 7) — মাসের শুরুর তহবিল → পরিচালন → বিনিয়োগ → অর্থায়ন → আজকের তহবিল।
     * ⓘ হিসাবের নিজের রিপোর্ট চালিয়ে (accounts.cash_flow) — ভাগ, খাত আর শুরু-শেষের নগদ সেখান থেকেই; প্রতিটা ভাগের বড় চার খাত নামসহ।
     * ⓘ রিপোর্টের চাবি (accounts.report.final) না থাকলে null — ঘরটা বসে না।
     *
     * @return array{opening: string, closing: string, sections: list<array{key: string, net: string, lines: list<array{name: string, net: string}>}>}|null
     */
    public function cashFlow(): ?array
    {
        if (! Gate::allows('accounts.report.final')) {
            return null;
        }

        $f = ['from' => Carbon::today()->startOfMonth()->toDateString(), 'to' => Carbon::today()->toDateString()];
        $result = $this->reports->run('accounts.cash_flow', $f, 1, 100000);
        $summary = $result->report->summary === null ? ['lines' => []] : ($result->report->summary)($result->totals, $result->filters);
        $lines = array_values($summary['lines'] ?? []);

        $keys = [__('accounts::message.cf_operating') => 'operating', __('accounts::message.cf_investing') => 'investing', __('accounts::message.cf_financing') => 'financing'];
        $sections = array_map(fn (string $k) => ['key' => $k, 'net' => '0', 'lines' => []], array_values($keys));
        $at = array_flip(array_values($keys));

        foreach ($result->rows as $row) {
            $row = (array) $row;
            $i = $at[$keys[(string) ($row['section'] ?? '')] ?? 'operating'];
            $net = (string) ($row['net'] ?? '0');
            $sections[$i]['net'] = bcadd($sections[$i]['net'], $net, 4);
            $sections[$i]['lines'][] = ['name' => (string) ($row['account_name'] ?? ''), 'net' => $net];
        }

        foreach ($sections as $i => $s) {
            usort($s['lines'], fn (array $a, array $b) => bccomp(ltrim($b['net'], '-'), ltrim($a['net'], '-'), 4));
            $sections[$i]['lines'] = array_slice(array_values(array_filter($s['lines'], fn (array $l) => bccomp($l['net'], '0', 4) !== 0)), 0, 4);
        }

        return [
            'opening' => (string) ($lines[0]['value'] ?? '0'),
            'closing' => (string) ($lines[1]['value'] ?? '0'),
            'sections' => $sections,
        ];
    }

    /** @return array<string, mixed> */
    private function fund(): array
    {
        return $this->fund ??= $this->position->on();
    }
}

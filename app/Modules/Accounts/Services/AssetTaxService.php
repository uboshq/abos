<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\AssetEvent;
use App\Modules\Accounts\Models\AssetTaxYear;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Reports\MonthlyCashReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ করের অবচয় — আয়বর্ষ ধরে, শ্রেণির নিজের হার আর পদ্ধতিতে (স্থায়ী সম্পদ ধাপ ৫)।
 *
 * ⓘ খাতায় কিছু বসে না; এটা কেবল আয়কর রিটার্নের তফসিল আর খাতা-বনাম-কর প্রতিবেদনের জন্য। ⛔ আইনের হার কোডে নেই —
 * মালিক শ্রেণিতে বসান ([[AssetCategory]]-এর `tax_method`, `tax_rate`); হার না থাকলে শ্রেণিটা বাদ।
 *
 * নিয়ম (মালিকের প্রশ্ন হিসেবে PR-এ লেখা):
 *   · অবশিষ্ট মূল্যের উপর (reducing): বছরের শুরুর অবশিষ্ট মূল্য × হার।
 *   · কেনা দামের উপর (straight): কেনা দাম × হার, অবশিষ্ট মূল্যের বেশি নয়।
 *   · ব্যবহার শুরুর বছরে পুরো বছরের হার; বছরের ভেতরে সংযোজন সেই বছরের শুরুর মূল্যে যোগ।
 *   · পুনর্মূল্যায়ন আর দাম পড়া করের হিসাবে নেই — কেবল কেনা দাম আর সংযোজন।
 *   · যে বছরে খাতা থেকে বিদায়, সে বছর কিছু নয় আর শেষ মূল্য শূন্য — বিক্রির সমন্বয় হিসাবরক্ষক রিটার্নে দেখান।
 */
final class AssetTaxService
{
    public const REDUCING = 'reducing';

    public const STRAIGHT = 'straight';

    /** @var list<string> */
    public const METHODS = [self::REDUCING, self::STRAIGHT];

    /**
     * কোন আয়বর্ষ — কোম্পানির আর্থিক বছর থাকলে সেটা, নইলে জুলাই-জুন ([[MonthlyCashReport::yearStart()]])।
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function yearOf(Carbon|string $on): array
    {
        $start = Carbon::parse(MonthlyCashReport::yearStart(Carbon::parse($on)->toDateString()))->startOfDay();

        $end = DB::table('financial_years')->where('company_id', CompanyContext::id())
            ->where('starts_on', $start->toDateString())->value('ends_on');

        return [$start, $end !== null ? Carbon::parse((string) $end)->startOfDay() : $start->copy()->addYear()->subDay()];
    }

    /**
     * ⭐ একটা আয়বর্ষ পর্যন্ত হিসাব — আগের বছরগুলোসহ, কারণ প্রতিটা বছরের শুরু আগের বছরের শেষ। আবার চালালে সারি বদলায়।
     *
     * @return array{year_start: string, year_end: string, assets: int, total: string}
     */
    public function run(Carbon|string $on): array
    {
        [$targetStart, $targetEnd] = $this->yearOf($on);

        $assets = FixedAsset::acrossBranches()->with('category')
            ->where('status', '!=', FixedAsset::AWAITING)
            ->whereHas('category', fn ($q) => $q->whereNotNull('tax_rate'))
            ->get()
            ->filter(fn (FixedAsset $a) => $a->depreciatesFrom()->lte($targetEnd));

        $count = 0;
        $total = '0';

        DB::transaction(function () use ($assets, $targetEnd, &$count, &$total) {
            foreach ($assets as $asset) {
                $rows = $this->schedule($asset, $targetEnd);

                foreach ($rows as $row) {
                    AssetTaxYear::query()->updateOrCreate(
                        ['fixed_asset_id' => $asset->id, 'year_end' => $row['year_end']],
                        [...$row, 'company_id' => $asset->company_id, 'branch_id' => $asset->branch_id, 'created_by' => auth()->id()],
                    );
                }

                $last = end($rows);

                if ($last !== false && $last['year_end'] === $targetEnd->toDateString()) {
                    $count++;
                    $total = bcadd($total, $last['amount'], 4);
                }
            }
        });

        if ($assets->isEmpty()) {
            throw ValidationException::withMessages(['year' => __('accounts::asset.tax_nothing')]);
        }

        return ['year_start' => $targetStart->toDateString(), 'year_end' => $targetEnd->toDateString(), 'assets' => $count, 'total' => $total];
    }

    /**
     * একটা সম্পদের বছর-বছর তফসিল, ব্যবহার শুরুর বছর থেকে লক্ষ্যের বছর পর্যন্ত।
     *
     * @return list<array{year_start: string, year_end: string, method: string, rate: string, opening_wdv: string, amount: string, closing_wdv: string}>
     */
    public function schedule(FixedAsset $asset, Carbon $until): array
    {
        $method = (string) $asset->category->tax_method;
        $rate = (string) $asset->category->tax_rate;

        // ⓘ কেনা দাম — সব ঘটনার দাম-বদল বাদ দিয়ে; সংযোজন বছর ধরে আলাদা যোগ হয়
        $original = bcsub((string) $asset->cost, (string) ($asset->postedEvents()->sum('cost_change') ?: '0'), 4);
        $additions = $asset->postedEvents()->where('kind', AssetEvent::ADDITION)->get(['happened_on', 'cost_change']);

        $rows = [];
        $wdv = '0';
        $basis = '0';
        [$start, $end] = $this->yearOf($asset->depreciatesFrom());
        $first = true;

        while ($start->lte($until)) {
            $added = (string) $additions->filter(fn ($e) => $e->happened_on->betweenIncluded($start, $end))
                ->reduce(fn ($carry, $e) => bcadd($carry, (string) $e->cost_change, 4), '0');

            $opening = bcadd($first ? bcadd($wdv, $original, 4) : $wdv, $added, 4);
            $basis = bcadd($first ? $original : $basis, $added, 4);

            $gone = $asset->disposed_on !== null && $asset->disposed_on->lte($end);

            if ($gone && $asset->disposed_on->lt($start)) {
                break;
            }

            $amount = '0';

            if (! $gone) {
                $amount = $method === self::STRAIGHT
                    ? bcdiv(bcmul($basis, $rate, 8), '100', 4)
                    : bcdiv(bcmul($opening, $rate, 8), '100', 4);

                if (bccomp($amount, $opening, 4) > 0) {
                    $amount = $opening;
                }
            }

            $closing = $gone ? '0' : bcsub($opening, $amount, 4);

            $rows[] = [
                'year_start' => $start->toDateString(), 'year_end' => $end->toDateString(), 'method' => $method, 'rate' => $rate,
                'opening_wdv' => $opening, 'amount' => $amount, 'closing_wdv' => $closing,
            ];

            if ($gone) {
                break;
            }

            $wdv = $closing;
            $first = false;
            [$start, $end] = $this->yearOf($end->copy()->addDay());
        }

        return $rows;
    }
}

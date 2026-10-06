<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Finance\Models\RentalAdjustment;
use App\Modules\Finance\Models\RentalContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ ভাড়ার সতর্কতার হিসাব — অর্থ-মডিউলের পরিকল্পনা, অংশ ৫ঘ (৬ অক্টোবর ২০২৬, সমন্বয়কের মারফত): চুক্তি শেষের ৬০ আর ৩০ দিন
 * আগে, আর বকেয়া ভাড়া। ঘণ্টির খবর ([[RentalNotices]]), অর্থের ড্যাশবোর্ড আর ভাড়ার পাতা — তিন জায়গাই এখান থেকে পড়ে, তাই
 * তিনটা কখনো আলাদা কথা বলে না।
 *
 * ⓘ দিনের সংখ্যা আপাতত পরিকল্পনা মতো; মালিক অন্য কিছু বললে কেবল [[WINDOWS]] বদলায়।
 */
final class RentalDues
{
    /** সতর্কতার ধাপ — শেষের কত দিন আগে; বড় থেকে ছোট */
    public const WINDOWS = [60, 30];

    /**
     * যে চালু চুক্তিগুলো সবচেয়ে বড় ধাপের মধ্যে শেষ হচ্ছে, বা মেয়াদ পেরিয়েছে অথচ কেউ শেষ করেননি — শেষের দিন ধরে।
     *
     * ⓘ `$inView` — হেডারে বাছা শাখা (পর্দা আর ড্যাশবোর্ড); ঘণ্টির খবর গোটা কোম্পানি ধরে।
     *
     * @return Collection<int, RentalContract>
     */
    public function ending(bool $inView = false): Collection
    {
        return RentalContract::query()->when($inView, fn ($q) => $q->inViewedBranch())
            ->endingSoon(self::WINDOWS[0])->orderBy('ends_on')->orderBy('id')->get();
    }

    /** কোন ধাপে আছে — ৩০ দিনের ভিতরে (বা পেরিয়ে) হলে ৩০, নইলে ৬০ */
    public static function stageOf(RentalContract $contract, ?Carbon $today = null): int
    {
        $stage = self::WINDOWS[0];

        foreach (self::WINDOWS as $window) {
            if ($contract->daysLeft($today) <= $window) {
                $stage = $window;
            }
        }

        return $stage;
    }

    /**
     * বকেয়া মাস — চুক্তির শুরুর মাস থেকে, যে মাসের ভাড়া দেওয়ার দিন (`rent_day`) আজকের আগে পেরিয়েছে, আর মেয়াদ বা আগেভাগে
     * শেষের মাস পর্যন্ত; যার মাসের সারি নেই।
     *
     * ⓘ সারি থাকলে বকেয়া নয় — সই পড়েছে, সইয়ের অপেক্ষায় আছে, বা সই-ব্যবস্থার আগের (ভাউচার ছাড়া)। ⛔ সারির ভাউচার পরে
     * বাতিল হলে মাসটা আবার বকেয়া — ভাড়ার সময়সূচির একই নিয়ম ([[RentalReports::SCHEDULE]])।
     *
     * @return list<Carbon> প্রতিটা মাসের প্রথম দিন
     */
    public function overdueMonths(RentalContract $contract, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $last = $contract->ends_on->copy();

        if ($contract->closed_on !== null && $contract->closed_on->lt($last)) {
            $last = $contract->closed_on->copy();
        }

        $done = RentalAdjustment::query()
            ->where('rental_contract_id', $contract->id)
            ->where(fn ($q) => $q->whereNull('voucher_id')
                ->orWhereHas('voucher', fn ($v) => $v->whereIn('status', [DocumentStatus::CONFIRMED, DocumentStatus::DRAFT])))
            ->pluck('for_month')
            ->map(fn ($m) => Carbon::parse((string) $m)->toDateString())
            ->all();

        $day = max(1, min(28, (int) ($contract->rent_day ?: 1)));
        $out = [];

        for ($month = $contract->starts_on->copy()->startOfMonth(); $month->lte($last); $month->addMonth()) {
            if ($month->copy()->addDays($day - 1)->gte($today)) {
                break;
            }

            if (! in_array($month->toDateString(), $done, true)) {
                $out[] = $month->copy();
            }
        }

        return $out;
    }

    /**
     * যে চালু চুক্তিগুলোর বকেয়া মাস আছে — চুক্তি, মাসগুলো, আর মোট (প্রতিটা মাস সেই মাসের শর্তের দরে)।
     *
     * @return list<array{contract: RentalContract, months: list<Carbon>, amount: string}>
     */
    public function overdue(?Carbon $today = null, bool $inView = false): array
    {
        $out = [];

        foreach (RentalContract::query()->when($inView, fn ($q) => $q->inViewedBranch())->active()->orderBy('counterparty')->orderBy('id')->get() as $contract) {
            $months = $this->overdueMonths($contract, $today);

            if ($months !== []) {
                $out[] = [
                    'contract' => $contract,
                    'months' => $months,
                    // ⓘ প্রতিটা মাস নিজের দরে — শর্তের ইতিহাস ([[RentalContract::rentFor()]], মালিকের সিদ্ধান্ত প্র১)
                    'amount' => array_reduce($months, fn (string $sum, Carbon $m) => bcadd($sum, $contract->rentFor($m), 4), '0'),
                ];
            }
        }

        return $out;
    }
}

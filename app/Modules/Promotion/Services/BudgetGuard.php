<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Models\PromotionGiftIssue;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\BudgetWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * অফারের ছাদ — স্পেক §১৫ আর §২০-এর *"Budget exceeded → Block"*।
 *
 * ── ⛔ কেন এটা **অনুমোদনের আগে** বসে ───────────────────────────────
 * ⓘ কোঅর্ডিনেটর আজই মালিকের একটা ধরা জানিয়েছেন: বাকির সীমায় ৫০,০০০
 * টাকার গ্রাহকের ৮৯,৭২০ টাকার বিল অনুমোদনে চলে গিয়েছিল। ⚠️ কারণ
 * অনুমোদনের দরজা কাগজটা পাঠিয়ে ফিরে আসে — আর কড়া বাধাটা তার **পরে**
 * বসানো ছিল। সই হলেই বিলটা চলে যেত।
 *
 * ⭐ তাই এই পাহারা বসানোর **প্রথম** কাজ। ⓘ ছাদ ছাড়ানো অফার কারও সইয়ের
 * অপেক্ষায় বসে থাকে না — সোজা থামে।
 *
 * ── ⚠️ ব্যবহৃত অঙ্কটা **জমে থাকা সারি** থেকে গোনা ────────────────────
 * ⓘ [[PromotionApplication]]-এর `worth` — বসানোর মুহূর্তে জমে যাওয়া
 * অঙ্ক। ⛔ অফারের কাগজ থেকে নতুন করে হিসাব করলে মেয়াদ বা হার বদলালে
 * ব্যবহৃত অঙ্কটাও বদলে যেত, আর ছাদটা মিথ্যা বলত।
 */
final class BudgetGuard
{
    /**
     * ⛔ এই সুবিধাটা বসালে কোনো ছাদ ছাড়াবে কি না।
     *
     * @return list<string> সতর্কতার বার্তা — ছাদের কাছে পৌঁছেছে, ছাড়ায়নি
     */
    public function assertRoomFor(
        Promotion $offer,
        BenefitKind $kind,
        string $worth,
        string $qty = '0',
        ?BudgetWindow $window = null,
    ): array {
        $warnings = [];

        foreach (PromotionBudget::query()->where('promotion_id', $offer->id)->get() as $budget) {
            if (! $this->budgetCovers($budget->kind, $kind)) {
                continue;
            }

            /*
             * ⚠️ জানালার ছাদ মাপা যায় কেবল জানালাটা জানা থাকলে।
             *
             * ⓘ ক্রেতা ছাড়া বিল (নগদ, নামহীন) প্রতি-ক্রেতার ছাদে পড়ে না — ⛔
             * সব নামহীন বিল একজন *"ক্রেতা"* ধরলে প্রথম কয়েকটা বিলের পরেই
             * কাউন্টারের সবাই অফার হারাতেন। ⭐ গোটা অফারের ছাদ তবু খাটে।
             */
            $scope = $this->windowScope($budget->per ?? PromotionBudget::PER_OFFER, $window);

            if ($scope === false) {
                continue;
            }

            $used = $this->usedAgainst($offer, $budget->kind, $scope);
            $adding = $budget->kind === PromotionBudget::QUANTITY ? $qty : $worth;
            $after = bcadd($used, $adding, 4);

            /*
             * ⛔ ছাদ ছাড়ালে থামা — সীমানা **ছুঁলে নয়**।
             *
             * ⚠️ ঠিক ছাদের সমান হলে বসে যায়: ⓘ ১০ লাখের বাজেটে ১০ লাখ
             * খরচ হওয়াই পরিকল্পনা, ভুল নয়। ⛔ `>=` লিখলে শেষ বিলটা
             * আটকে যেত, আর বাজেটের শেষ টাকাটা কোনোদিনই খরচ হত না।
             */
            if (bccomp($after, (string) $budget->ceiling, 4) > 0) {
                throw ValidationException::withMessages([
                    'promotion' => __('promotion::validation.budget_exceeded', [
                        'code' => $offer->code,
                        'ceiling' => $budget->ceiling,
                        'used' => $used,
                    ]),
                ]);
            }

            $percent = bccomp((string) $budget->ceiling, '0', 4) > 0
                ? (int) bcdiv(bcmul($after, '100', 4), (string) $budget->ceiling, 0)
                : 0;

            if ($percent >= $budget->warn_at_percent) {
                $warnings[] = __('promotion::validation.budget_near', [
                    'code' => $offer->code,
                    'percent' => $percent,
                ]);
            }
        }

        return $warnings;
    }

    /**
     * ⭐ প্রতিটা ছাদ, আর তার কতটা গেছে — অফারের পাতা এটাই দেখায়।
     *
     * ⚠️ খরচের অঙ্কটা **পাহারার একই হিসাব** থেকে। ⓘ পাতার জন্য আলাদা
     * হিসাব লিখলে একদিন পাতা বলত *"৪০% বাকি"*, অথচ পাহারা বিল থামাত।
     *
     * @return list<array{budget: PromotionBudget, used: ?string, percent: int}>
     */
    public function usage(Promotion $offer): array
    {
        $rows = [];

        foreach (PromotionBudget::query()->where('promotion_id', $offer->id)->orderBy('id')->get() as $budget) {
            /*
             * ⓘ জানালার ছাদের (প্রতি বিল, প্রতি ক্রেতা…) একটা *"মোট খরচ"* হয় না —
             * প্রতিটা বিলের আলাদা। ⚠️ গোটা অফারের খরচ দেখালে মানুষ ভাবতেন
             * *"প্রতি বিলে ৫০০"* ছাদটা ১৪০% ছাড়িয়েছে। ⭐ তাই সেখানে `null`।
             */
            if (($budget->per ?? PromotionBudget::PER_OFFER) !== PromotionBudget::PER_OFFER) {
                $rows[] = ['budget' => $budget, 'used' => null, 'percent' => 0];

                continue;
            }

            $used = $this->usedAgainst($offer, $budget->kind);

            $rows[] = [
                'budget' => $budget,
                'used' => $used,
                'percent' => bccomp((string) $budget->ceiling, '0', 4) > 0
                    ? (int) bcdiv(bcmul($used, '100', 4), (string) $budget->ceiling, 0)
                    : 0,
            ];
        }

        return $rows;
    }

    /** ⓘ ছাদ বসানোর আগে — এখন পর্যন্ত কতটা গেছে, পাহারার একই হিসাবে। */
    public function used(Promotion $offer, string $budgetKind): string
    {
        return $this->usedAgainst($offer, $budgetKind);
    }

    /** ⓘ কোন সুবিধা কোন ছাদে গোনা হয়। */
    private function budgetCovers(string $budgetKind, BenefitKind $benefit): bool
    {
        return match ($budgetKind) {
            PromotionBudget::TOTAL => true,
            PromotionBudget::DISCOUNT => in_array($benefit, [BenefitKind::PERCENT, BenefitKind::AMOUNT], true),
            PromotionBudget::GIFT, PromotionBudget::QUANTITY => $benefit === BenefitKind::GOODS,
            default => false,
        };
    }

    /**
     * ⭐ এখন পর্যন্ত কতটা গেছে — জমে থাকা সারি থেকে।
     *
     * ⚠️ পরিমাণের ছাদে উপহার সারি থেকে, **ফেরত বাদ দিয়ে**। ⓘ ২০ কার্টন
     * ফেরত এলে সেটা আর ব্যবহৃত নয় — ⛔ বাদ না দিলে ফেরতের পরেও ছাদটা
     * ভরা দেখাত আর নতুন ক্রেতা অফারটা পেতেন না।
     */
    private function usedAgainst(Promotion $offer, string $budgetKind, ?\Closure $scope = null): string
    {
        $scope ??= static fn (Builder $q) => $q;

        if ($budgetKind === PromotionBudget::QUANTITY) {
            /*
             * ⭐ পরিমাণের ছাদে গোনা হয় **পাওনা**, কেবল বের-হওয়া নয়।
             *
             * ⓘ পর্যালোচনায় ধরা (২৭ সেপ্টেম্বর): আগে কেবল গুদাম থেকে
             * বেরোনো উপহার গোনা হত। ⚠️ গুদাম দেয় পরে — তাই ১০০ কার্টনের
             * ছাদে ৩০টা বিল প্রতিটা ৫ কার্টন পাওনা নিয়ে পেরিয়ে যেত (সবাই
             * দেখত "০ গেছে"), আর ১৫০ কার্টন পাওনা হত।
             *
             * ⓘ ফেরত আসা অংশ বাদ — ফেরত মাল আর ব্যবহৃত নয়।
             */
            $apps = PromotionApplication::query()
                ->where('promotion_id', $offer->id)
                ->where('benefit_kind', BenefitKind::GOODS->value)
                ->whereNull('reversed_at')
                ->tap(fn ($q) => $scope($q));

            $owed = (string) (clone $apps)->sum('benefit_amount');

            $returned = (string) PromotionGiftIssue::query()
                ->whereIn('promotion_application_id', (clone $apps)->select('id'))
                ->sum('returned_qty');

            return bcsub($owed, $returned, 4);
        }

        $kinds = match ($budgetKind) {
            PromotionBudget::DISCOUNT => [BenefitKind::PERCENT->value, BenefitKind::AMOUNT->value],
            PromotionBudget::GIFT => [BenefitKind::GOODS->value],
            default => null,
        };

        /*
         * ⛔ বাতিল হওয়া বিলের সারি গোনায় নেই।
         *
         * ⓘ বাতিল বিল মানে ক্রেতা কিছুই নেননি। ⚠️ তার সুবিধা গোনায় থাকলে
         * বাতিল বিলটা বাজেট খেয়ে বসে থাকত, আর নতুন ক্রেতা অফারটা পেতেন না।
         */
        return (string) PromotionApplication::query()
            ->where('promotion_id', $offer->id)
            ->whereNull('reversed_at')
            ->when($kinds !== null, fn ($q) => $q->whereIn('benefit_kind', $kinds))
            ->tap(fn ($q) => $scope($q))
            ->sum('worth');
    }

    /**
     * ⭐ জানালাটা প্রশ্নে বসানো — `false` মানে *"এই ছাদ এখানে মাপা যায় না"*।
     *
     * ⚠️ দিন আর মাস ধরা হয় সারির জন্মের সময় (`created_at`) দিয়ে। ⓘ পুরনো
     * বিলের ছাড় পরে হাতে বদলালেও সেটা সেই দিনের/মাসের জানালাতেই থাকে।
     *
     * @return (\Closure(Builder): Builder)|false
     */
    private function windowScope(string $per, ?BudgetWindow $w): \Closure|false
    {
        if ($per === PromotionBudget::PER_OFFER) {
            return static fn (Builder $q) => $q;
        }

        /* ⛔ জানালা ছাড়া জানালার ছাদ মাপা যায় না — পাতার হিসাব (usage) এখানে পড়ে */
        if ($w === null) {
            return false;
        }

        return match ($per) {
            PromotionBudget::PER_BILL => $w->sourceId === null ? false : static fn (Builder $q) => $q
                ->where('source_type', $w->sourceType)->where('source_id', $w->sourceId),
            PromotionBudget::PER_CUSTOMER => $w->customerId === null ? false : static fn (Builder $q) => $q
                ->where('customer_id', $w->customerId),
            PromotionBudget::PER_DAY => static fn (Builder $q) => $q
                ->whereBetween('created_at', [$w->at->copy()->startOfDay(), $w->at->copy()->endOfDay()]),
            PromotionBudget::PER_MONTH => static fn (Builder $q) => $q
                ->whereBetween('created_at', [$w->at->copy()->startOfMonth(), $w->at->copy()->endOfMonth()]),
            default => false,
        };
    }
}

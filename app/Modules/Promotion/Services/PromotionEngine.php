<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionType;
use App\Modules\Promotion\Support\ScopeKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * অফারের কেন্দ্রীয় ইঞ্জিন — স্পেকের ২২ নম্বর ধারা।
 *
 * ── ⭐ স্পেকের সরাসরি নিষেধ ──────────────────────────────────────────
 * *"Promotion/Incentive-এর calculation logic কখনো Sales Invoice, Sales
 * Order বা Direct Sales screen-এর মধ্যে hard-code করা যাবে না।"*
 *
 * ── ⚠️ কেন একটাই দরজা, আর সেটা কেন এত জরুরি ─────────────────────────
 * ⓘ আজ ABOS-এ বিল কাটা যায় অন্তত চার পথে: বিক্রয় বিল, সরাসরি বিক্রয়,
 * বিক্রয় আদেশ, আর কাউন্টার। ⛔ হিসাবটা প্রতিটায় আলাদা লিখলে একদিন
 * একটায় নিয়ম বদলাত আর বাকিগুলোয় নয় — আর তখন **একই ক্রেতা একই পণ্যে
 * দুই পর্দায় দুই রকম ছাড় পেতেন**।
 *
 * ⚠️ ঐ ভুলটা নীরব: দুইটা পর্দাই কাজ করে, দুইটাই সংখ্যা দেখায়, আর
 * কোনটা ঠিক তা কেবল ক্রেতা অভিযোগ করলে জানা যায়।
 *
 * ── ⓘ এই ইঞ্জিন কী **করে না** ───────────────────────────────────────
 * ⛔ সে কিছু বসায় না। ⓘ সে কেবল বলে *"এই সারিতে এই অফারগুলো খাটে, আর
 * সুবিধা এতটা"*। ⚠️ বসানোর কাজটা বিক্রয়ের, কারণ মজুদ ও খাতা ওদের।
 *
 * ⭐ আর সিস্টেম নিজে থেকে কোনো অননুমোদিত ছাড় বসায় না (§১০) — সে
 * প্রস্তাব দেয়, মানুষ চাপেন।
 */
final class PromotionEngine
{
    /**
     * ⭐ এই সারিতে কোন অফারগুলো খাটে।
     *
     * ── ⓘ ক্রমটা ইচ্ছাকৃত, আর প্রতিটা ধাপ আগেরটার কাজ কমায় ───────────
     *   ⓵ তারিখ ও সময় — আজ চলছে এমন অফার (SQL, সূচক ধরে)
     *   ⓶ সুযোগ — এই ক্রেতা আর এই পণ্যের জন্য কি না
     *   ⓷ শর্ত — এতটা কিনলে খোলে কি না
     *   ⓸ সংঘাত — একাধিক খাটলে কে জেতে
     *
     * @param  array{customer_id?: int|null, party_type_id?: int|null, location_id?: int|null, branch_id?: int|null, warehouse_id?: int|null, product_id: int, category_id?: int|null, brand_id?: int|null, qty: string, value: string}  $line
     * @return Collection<int, array{promotion: Promotion, benefit: PromotionBenefit, worth: string, qty: string}>
     */
    public function offersFor(array $line, ?Carbon $at = null): Collection
    {
        $at ??= Carbon::now();

        $live = Promotion::query()
            ->liveOn($at)
            /*
             * ⛔ দুই ধরনের অফার সারি ধরে খোলে না।
             *
             * ⓘ কুপন — কোড না দিলে প্রস্তাবেই আসে না; নাহলে কুপনের অফার
             * প্রতিটা বিলে কোড ছাড়াই বসত। ⓘ কম্বো/বান্ডেল — পুরো বিল দেখে
             * খোলে ([[BillPromotionEngine]]); সারি ধরে দেখলে কেবল A কিনলেই
             * *"A+B"* অফার খুলে যেত।
             */
            ->whereNotIn('type', array_map(fn ($t) => $t->value, BillPromotionEngine::BILL_TYPES))
            ->where(fn ($q) => $q->where('type', '!=', PromotionType::COUPON->value)
                ->orWhereIn('id', array_map('intval', (array) ($line['coupon_promotion_ids'] ?? []))))
            ->with(['scopes', 'conditions.benefits', 'benefits'])
            ->orderByDesc('priority')
            ->get();

        $fit = $live
            ->filter(fn (Promotion $p) => $this->scopeFits($p, $line))
            ->map(fn (Promotion $p) => $this->bestBenefitOf($p, $line))
            ->filter()
            ->values();

        return $this->settleConflicts($fit);
    }

    /**
     * ⓶ · এই অফারটা এই ক্রেতা ও এই পণ্যের জন্য কি না।
     *
     * ── ⚠️ নিয়মটা দিক-প্রতি, সারি-প্রতি নয় ─────────────────────────
     * ⓘ একটা দিকে (যেমন `customer`) কোনো সারি না থাকলে সেটা *"সব
     * ক্রেতা"*। ⛔ কিন্তু সারি থাকলে **অন্তত একটা** মিলতে হবে।
     *
     * ⚠️ উল্টোটা লিখলে — অর্থাৎ *"যেকোনো একটা দিক মিললেই হলো"* — ঢাকার
     * জন্য বানানো অফার চট্টগ্রামের ক্রেতাও পেতেন, কেবল পণ্যটা মিলেছে বলে।
     *
     * @param  array<string, mixed>  $line
     */
    private function scopeFits(Promotion $promotion, array $line): bool
    {
        $byKind = $promotion->scopes->groupBy(fn ($s) => $s->kind->value);

        foreach ($byKind as $kind => $rows) {
            $wanted = $this->lineValueFor(ScopeKind::from((string) $kind), $line);

            /*
             * ⚠️ সারিটার কথা বিলে জানা নেই — যেমন অফারটা গুদাম ধরে
             * বাঁধা, অথচ এই সারিতে কোনো গুদাম নেই।
             *
             * ⛔ তখন *"মিলেছে"* ধরা যায় না: অফারটা একটা শর্ত দিয়েছিল আর
             * শর্তটা যাচাই করা যাচ্ছে না। ⓘ না বলাই সৎ।
             */
            if ($wanted === null) {
                return false;
            }

            if (! $rows->contains(fn ($s) => (int) $s->target_id === $wanted)) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $line */
    private function lineValueFor(ScopeKind $kind, array $line): ?int
    {
        $key = match ($kind) {
            ScopeKind::BRANCH => 'branch_id',
            ScopeKind::WAREHOUSE => 'warehouse_id',
            ScopeKind::CHANNEL => 'channel_id',
            ScopeKind::CUSTOMER => 'customer_id',
            ScopeKind::PARTY_TYPE => 'party_type_id',
            ScopeKind::LOCATION => 'location_id',
            ScopeKind::PRODUCT => 'product_id',
            ScopeKind::CATEGORY => 'category_id',
            ScopeKind::BRAND => 'brand_id',
        };

        $value = $line[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * ⓷ · শর্ত মিলিয়ে সবচেয়ে বড় সুবিধাটা বের করা।
     *
     * ── ⭐ স্ল্যাবে **একটাই** ধাপ জেতে ──────────────────────────────
     * ⓘ ১২০ কার্টন কিনলে ৫০–৯৯ আর ১০০–১৯৯ দুইটা ধাপই *"পেরোনো"*
     * মনে হতে পারে। ⛔ কিন্তু `covers()` পরিসর দেখে, তাই ১২০ কেবল
     * দ্বিতীয়টায় পড়ে। ⚠️ যদি কোনোদিন ধাপগুলো ওভারল্যাপ করে, তখন
     * সবচেয়ে বড় সুবিধাটাই নেওয়া হয় — ক্রেতার পক্ষে।
     *
     * @param  array<string, mixed>  $line
     * @return array{promotion: Promotion, benefit: PromotionBenefit, worth: string, qty: string}|null
     */
    private function bestBenefitOf(Promotion $promotion, array $line): ?array
    {
        $best = null;

        foreach ($promotion->conditions as $condition) {
            $measure = match ($condition->kind->value) {
                'quantity' => (string) $line['qty'],
                'value' => (string) $line['value'],
                default => null,
            };

            if ($measure === null || ! $condition->covers($measure)) {
                continue;
            }

            foreach ($condition->benefits as $benefit) {
                $best = $this->keepBigger($best, $promotion, $benefit, $line);
            }
        }

        /* ⓘ শর্তহীন সুবিধা — অফারটা খাটলেই পাওয়া যায় */
        foreach ($promotion->benefits->whereNull('promotion_condition_id') as $benefit) {
            $best = $this->keepBigger($best, $promotion, $benefit, $line);
        }

        return $best;
    }

    /**
     * @param  array{promotion: Promotion, benefit: PromotionBenefit, worth: string, qty: string}|null  $best
     * @param  array<string, mixed>  $line
     * @return array{promotion: Promotion, benefit: PromotionBenefit, worth: string, qty: string}|null
     */
    private function keepBigger(?array $best, Promotion $promotion, PromotionBenefit $benefit, array $line): ?array
    {
        /*
         * ⛔ যে সুবিধা দেওয়াই যায় না, সে তালিকায় ওঠে না।
         *
         * ⚠️ একটা `goods` সারিতে পণ্যটা `null` থাকলে অফারটা *"চলছে"*
         * বলত, বিলে খাটত, আর উপহারের জায়গায় কিছুই দিত না — নীরবে।
         */
        if (! $benefit->isDeliverable()) {
            return $best;
        }

        [$worth, $qty] = $this->worthOf($benefit, $line);

        if ($best === null || bccomp($worth, $best['worth'], 4) > 0) {
            return ['promotion' => $promotion, 'benefit' => $benefit, 'worth' => $worth, 'qty' => $qty];
        }

        return $best;
    }

    /**
     * ⭐ সুবিধাটা টাকায় কত — নাহলে *"সবচেয়ে ভালোটা"* বলা যায় না।
     *
     * ── ⚠️ উপহারের দামটা এখানে **শূন্য নয়**, আর সেটা মেপে ঠিক করা ────
     * ⓘ প্রথমে মনে হয় উপহার ফ্রি, তাই তার দাম শূন্য। ⛔ কিন্তু তুলনার
     * সময় শূন্য ধরলে *"১ কার্টন ফ্রি"* সবসময় *"১ টাকা ছাড়"*-এর চেয়ে
     * ছোট হত, আর ক্রেতা কখনোই উপহারটা পেতেন না।
     *
     * ⚠️ আজ দামটা পণ্যের বিক্রয়মূল্য ধরে — ⓘ কারণ ক্রেতার কাছে উপহারের
     * মূল্য ওটাই। ⛔ মালিকের খরচ আলাদা জিনিস (§১৭ Promotion Cost), আর
     * সেটা এখানে নয়, প্রতিবেদনে।
     */
    /**
     * @param  array<string, mixed>  $line
     * @return array{0: string, 1: string} টাকায় মূল্য, আর উপহারের পরিমাণ (ছাড়ে `'0'`)
     */
    private function worthOf(PromotionBenefit $benefit, array $line): array
    {
        $cap = $benefit->cap_per_bill !== null ? (string) $benefit->cap_per_bill : null;
        $lineValue = (string) $line['value'];

        /*
         * ⛔ প্রতি বিলের সর্বোচ্চ — এতদিন ঘরটা রাখা হত, কেউ পড়ত না।
         *
         * ⓘ পর্যালোচনায় ধরা (২৭ সেপ্টেম্বর): *"৫%, বিলে সর্বোচ্চ ৫০০"* অফার
         * ১০ লাখের সারিতে ৫০,০০০ দিত। ⚠️ ছাড়ে সীমাটা টাকায়, উপহারে
         * পরিমাণে — একই ঘর, ধরন ঠিক করে কীসের সীমা।
         */
        $capped = static fn (string $v): string => $cap !== null && bccomp($v, $cap, 4) > 0 ? $cap : $v;

        /*
         * ⛔ ছাড় কখনো সারির দামের বেশি নয়।
         *
         * ⓘ ২০০ টাকার সারিতে ৫০০ টাকা ছাড় মানে সারিটা −৩০০ — ক্রেতাকে টাকা
         * দিয়ে মাল বিক্রি। ⚠️ নির্দিষ্ট ছাড়ে এটা সহজেই ঘটে, কারণ অঙ্কটা
         * সারির আকার দেখে না।
         */
        $withinLine = static fn (string $v): string => bccomp($v, $lineValue, 4) > 0 ? $lineValue : $v;

        return match ($benefit->kind) {
            BenefitKind::PERCENT => [$withinLine($capped(bcdiv(
                bcmul($lineValue, (string) $benefit->amount, 8), '100', 4))), '0'],

            BenefitKind::AMOUNT => [$withinLine($capped((string) $benefit->amount)), '0'],

            /*
             * ⚠️ উপহারের দামটা এখানে **শূন্য নয়**, আর সেটা মেপে ঠিক করা।
             *
             * ⓘ তুলনার সময় শূন্য ধরলে *"১ কার্টন ফ্রি"* সবসময় *"১ টাকা
             * ছাড়"*-এর চেয়ে ছোট হত, আর ক্রেতা কখনোই উপহারটা পেতেন না।
             * ⓘ দামটা পণ্যের বিক্রয়মূল্য — ক্রেতার কাছে উপহারের মূল্য ওটাই;
             * মালিকের খরচ আলাদা (§১৭ Promotion Cost), প্রতিবেদনে।
             */
            BenefitKind::GOODS => (function () use ($benefit, $capped): array {
                $qty = $capped((string) $benefit->amount);

                return [bcmul($qty, (string) ($benefit->giftProduct?->sale_price ?? '0'), 4), $qty];
            })(),

            /* ⓘ জমা ও পয়েন্ট আজ মুখমূল্যেই ধরা */
            BenefitKind::CREDIT, BenefitKind::POINTS => [$capped((string) $benefit->amount), '0'],
        };
    }

    /**
     * ⓸ · একাধিক অফার খাটলে কে জেতে — স্পেক §৯।
     *
     * ── ⭐ মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর ২০২৬ ─────────────────────
     * *"offer ghosonar somoyei tik korbe"* — নিয়মটা কোম্পানির সুইচ নয়,
     * প্রতিটা অফারের নিজের ঘোষণা ([[PromotionCombines]])।
     *
     * ── ⚠️ ক্রমটা ইচ্ছাকৃত, আর ওটা বদলালে উত্তরও বদলায় ───────────────
     * ⓘ প্রথমে অগ্রাধিকার (বড় সংখ্যা আগে), তারপর সুবিধার অঙ্ক। ⛔ কেবল
     * অঙ্ক দেখলে মালিকের বসানো অগ্রাধিকারের কোনো মানে থাকত না।
     *
     * ⚠️ আর তালিকাটা **স্থিতিশীল** হতে হয়: একই বিল দুইবার কাটলে একই
     * উত্তর আসতে হবে। ⓘ তাই সমান হলে `id` ধরে — নাহলে ডাটাবেজের
     * ফেরত দেওয়ার ক্রমের উপর সিদ্ধান্তটা দাঁড়াত।
     *
     * @param  Collection<int, array{promotion: Promotion, benefit: PromotionBenefit, worth: string, qty: string}>  $fit
     * @return Collection<int, array{promotion: Promotion, benefit: PromotionBenefit, worth: string, qty: string}>
     */
    private function settleConflicts(Collection $fit): Collection
    {
        if ($fit->count() < 2) {
            return $fit;
        }

        $ranked = $fit->sortBy([
            fn ($a, $b) => $b['promotion']->priority <=> $a['promotion']->priority,
            fn ($a, $b) => bccomp($b['worth'], $a['worth'], 4),
            fn ($a, $b) => $a['promotion']->id <=> $b['promotion']->id,
        ])->values();

        $winner = $ranked->first();
        $kept = collect([$winner]);

        /*
         * ⓘ বিজয়ীর পর বাকিরা কেবল তখনই যোগ হয় যখন **দুইজনেই** বলে
         * তারা যোগ হতে রাজি — [[PromotionCombines::sitsWith()]]।
         *
         * ⛔ কেবল বিজয়ীকে জিজ্ঞেস করলে একটা `ALONE` অফার একটা `ADDS`
         * অফারের সাথে বসে যেত, আর *"একা চলব"* কথাটার মানে থাকত না।
         */
        foreach ($ranked->skip(1) as $row) {
            $fitsAll = $kept->every(
                fn ($k) => $k['promotion']->combines->sitsWith($row['promotion']->combines));

            if ($fitsAll) {
                $kept->push($row);
            }
        }

        return $kept->values();
    }

    /**
     * ⭐ *"আর কতটা নিলে অফারটা খুলবে"* — স্পেক §১০-এর Almost Eligible।
     *
     * ── ⓘ কেন এটা ইঞ্জিনের কাজ, পর্দার নয় ──────────────────────────
     * ⚠️ পর্দায় লিখলে চারটা পর্দায় চারবার লিখতে হত, আর একদিন একটায়
     * সংখ্যাটা ভুল হত। ⛔ আর ভুল সংখ্যাটা নীরব: ক্রেতা আট কার্টন বাড়িয়ে
     * নিতেন আর তবু অফারটা খুলত না।
     *
     * @param  array<string, mixed>  $line
     * @return Collection<int, array{promotion: Promotion, short_by: string}>
     */
    public function almostFor(array $line, ?Carbon $at = null): Collection
    {
        $at ??= Carbon::now();

        return Promotion::query()
            ->liveOn($at)
            /*
             * ⛔ দুই ধরনের অফার সারি ধরে খোলে না।
             *
             * ⓘ কুপন — কোড না দিলে প্রস্তাবেই আসে না; নাহলে কুপনের অফার
             * প্রতিটা বিলে কোড ছাড়াই বসত। ⓘ কম্বো/বান্ডেল — পুরো বিল দেখে
             * খোলে ([[BillPromotionEngine]]); সারি ধরে দেখলে কেবল A কিনলেই
             * *"A+B"* অফার খুলে যেত।
             */
            ->whereNotIn('type', array_map(fn ($t) => $t->value, BillPromotionEngine::BILL_TYPES))
            ->where(fn ($q) => $q->where('type', '!=', PromotionType::COUPON->value)
                ->orWhereIn('id', array_map('intval', (array) ($line['coupon_promotion_ids'] ?? []))))
            ->with(['scopes', 'conditions'])
            ->get()
            ->filter(fn (Promotion $p) => $this->scopeFits($p, $line))
            ->map(function (Promotion $p) use ($line) {
                $gap = null;

                foreach ($p->conditions as $condition) {
                    $measure = match ($condition->kind->value) {
                        'quantity' => (string) $line['qty'],
                        'value' => (string) $line['value'],
                        default => null,
                    };

                    if ($measure === null || $condition->value_from === null) {
                        continue;
                    }

                    /* ⓘ ইতিমধ্যে পেরিয়ে গেছে — তাহলে এটা "প্রায়" নয় */
                    if (bccomp($measure, (string) $condition->value_from, 4) >= 0) {
                        continue;
                    }

                    $short = bcsub((string) $condition->value_from, $measure, 4);

                    if ($gap === null || bccomp($short, $gap, 4) < 0) {
                        $gap = $short;
                    }
                }

                return $gap === null ? null : ['promotion' => $p, 'short_by' => $gap];
            })
            ->filter()
            ->values();
    }
}

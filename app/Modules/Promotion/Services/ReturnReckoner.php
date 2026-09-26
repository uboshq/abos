<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\ConditionKind;

/**
 * মাল ফেরত এলে অফারটা আবার হিসাব — স্পেক §১৮।
 *
 * ── ⭐ স্পেকের উদাহরণ ────────────────────────────────────────────────
 * *"Customer 100 Carton কিনে 5 Carton Gift পেয়েছে; পরে 20 Carton return
 * করলে system re-evaluate করবে Customer এখনও eligible কি না।"*
 *
 * ── ⚠️ হিসাবটা **সেদিনের** নিয়মে, আজকের নয় ─────────────────────────
 * ⓘ ফেরত আসতে পারে অফার শেষ হওয়ার পরে, বা অফারের হার বদলানোর পরে।
 * ⛔ আজকের নিয়মে হিসাব করলে মেয়াদোত্তীর্ণ অফারে *"যোগ্য নয়"* আসত —
 * অর্থাৎ ২০ কার্টন ফেরত দিলেই পুরো পাঁচ কার্টন উপহার ফেরত চাওয়া হত,
 * যদিও ৮০ কার্টনেও ক্রেতা ঐ ধাপে পড়তেন।
 *
 * ⭐ তাই হিসাবটা দাঁড়ায় [[PromotionApplication]]-এর জমে থাকা সুবিধার
 * সারির উপর — যে ধাপটা **সেদিন** খেটেছিল।
 *
 * ── ⛔ এই সেবা নিজে কিছু কাটে না ────────────────────────────────────
 * ⓘ সে কেবল বলে কতটা ফেরত চাইতে হবে। ⚠️ ফেরত নেওয়ার সিদ্ধান্ত — আর
 * সেটায় অনুমোদন লাগবে কি না (§১৮-এর *"Approval Required"*) — বিক্রয়-
 * ফেরতের পর্দার। ⛔ এখানে নিজে কেটে দিলে একজন ক্রেতার উপহার নীরবে
 * খাতায় কমত, আর তিনি জানতেনও না কেন।
 */
final class ReturnReckoner
{
    /**
     * ⭐ ফেরতের পর কতটা সুবিধা ক্রেতার কাছে থাকার কথা, আর কতটা ফেরত চাইতে হবে।
     *
     * @return array{still_eligible: bool, keep: string, recover: string, kind: BenefitKind}
     */
    public function afterReturn(
        PromotionApplication $applied,
        string $originalQty,
        string $returnedQty,

        /*
         * ⓘ সারির মোট দাম — টাকার শর্তের অফারে লাগে।
         *
         * ⚠️ পর্যালোচনায় ধরা (২৭ সেপ্টেম্বর): আগে শর্তটা সবসময় বাকি
         * **পরিমাণ** দিয়ে মাপা হত। ⛔ *"৫০,০০০ টাকার বেশি কিনলে ৫%"*-এ
         * ১০০ কার্টন (৬০,০০০) থেকে ২০ ফেরতে ৮০-কে ৫০,০০০-এর সাথে তুলনা
         * করে *"যোগ্য নয়"* বলত, আর পুরো ছাড় ফেরত চাইত।
         */
        ?string $originalValue = null,
    ): array {
        $remaining = bcsub($originalQty, $returnedQty, 4);

        /*
         * ⛔ সব ফেরত এলে কিছুই থাকার কথা নয়।
         *
         * ⚠️ `bccomp` দিয়ে, `<= 0` দিয়ে নয় — দশমিক স্ট্রিং তুলনায় PHP
         * নিজে থেকে সংখ্যায় রূপান্তর করে, আর `"0.0000"` নিয়ে ঝুঁকি নেওয়ার
         * কারণ নেই।
         */
        if (bccomp($remaining, '0', 4) <= 0) {
            return $this->verdict($applied, false, '0');
        }

        $benefit = $applied->promotion_benefit_id !== null
            ? PromotionBenefit::query()->find($applied->promotion_benefit_id)
            : null;

        $condition = $benefit?->promotion_condition_id !== null
            ? PromotionCondition::query()->find($benefit->promotion_condition_id)
            : null;

        /*
         * ⓘ শর্তহীন সুবিধা (যেমন বিলে নির্দিষ্ট ছাড়) — ফেরতে যোগ্যতা
         * বদলায় না, কারণ কোনো পরিমাণের শর্তই ছিল না।
         *
         * ⚠️ কিন্তু শর্তের সারিটা মুছে গেলেও এখানে পড়ে — ⛔ তখন *"যোগ্য"*
         * ধরা হয়, আর সেটা ইচ্ছাকৃত: ⓘ অনিশ্চয়তায় ক্রেতার কাছ থেকে কিছু
         * কেড়ে নেওয়া হয় না। মানুষ পর্দায় দেখে সিদ্ধান্ত নেবেন।
         */
        if ($condition === null) {
            return $this->verdict($applied, true, $this->scaled($applied, $originalQty, $remaining));
        }

        /*
         * ⭐ শর্ত যা মাপে, তাই দিয়ে মাপা — পরিমাণ বা টাকা।
         *
         * ⓘ টাকার শর্তে বাকি দাম = মোট দাম × বাকি ÷ মোট পরিমাণ (একক দাম
         * একই থাকে)। ⛔ দাম না জানলে আন্দাজ নয় — ডাকার জায়গাটাকে দিতে হবে।
         */
        $measure = $remaining;

        if ($condition->kind === ConditionKind::VALUE) {
            if ($originalValue === null) {
                throw new \InvalidArgumentException('A value-based offer needs the line value to judge a return.');
            }

            $measure = bcdiv(bcmul($originalValue, $remaining, 8), $originalQty, 4);
        }

        if (! $condition->covers($measure)) {
            return $this->verdict($applied, false, '0');
        }

        return $this->verdict($applied, true, $this->scaled($applied, $originalQty, $remaining));
    }

    /**
     * ⓘ যোগ্য থাকলেও উপহারের পরিমাণ অনুপাতে কমে কি না।
     *
     * ── ⚠️ ধাপের অফারে কমে না, অনুপাতের অফারে কমে ────────────────────
     * ⓘ *"১০০ কিনলে ৫ ফ্রি"* — ১০০ থেকে ৮০-তে নামলে ক্রেতা ধাপের নিচে
     * পড়েন, তাই ওপরের `covers()` তখন *"যোগ্য নয়"* বলে। ⛔ কিন্তু *"৫০
     * থেকে ৯৯-তে ৫%"* ধাপে ৯০ থেকে ৭০-তে নামলে ধাপ একই, তাই হারও একই —
     * আর ছাড়ের **অঙ্কটা** মালের সাথে কমে।
     *
     * ⚠️ তাই শতাংশের ছাড় অনুপাতে কমে, উপহার আর নির্দিষ্ট টাকা নয়।
     */
    private function scaled(PromotionApplication $applied, string $originalQty, string $remaining): string
    {
        if ($applied->benefit_kind !== BenefitKind::PERCENT || bccomp($originalQty, '0', 4) <= 0) {
            return (string) $applied->worth;
        }

        return bcdiv(bcmul((string) $applied->worth, $remaining, 8), $originalQty, 4);
    }

    /** @return array{still_eligible: bool, keep: string, recover: string, kind: BenefitKind} */
    private function verdict(PromotionApplication $applied, bool $eligible, string $keep): array
    {
        return [
            'still_eligible' => $eligible,
            'keep' => $keep,
            'recover' => bcsub((string) $applied->worth, $keep, 4),
            'kind' => $applied->benefit_kind,
        ];
    }
}

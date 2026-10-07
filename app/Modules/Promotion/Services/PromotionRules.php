<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Models\PromotionScope;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\ConditionKind;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\ScopeKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * অফারের নিয়ম বসানো — শর্ত, সুবিধা, সুযোগ (স্পেক §৬ ধাপ ২–৪)।
 *
 * ── ⛔ নিয়ম বদলানো যায় কেবল খসড়ায় ──────────────────────────────────
 * ⓘ অনুমোদনের পুরো মানে: *"এই নিয়মগুলো দ্বিতীয় একজন দেখেছেন"*। ⚠️
 * অনুমোদনের পরে একটা ধাপ বদলানো গেলে অনুমোদনকারী যা দেখেছিলেন আর যা
 * চলছে — দুইটা আলাদা হত, আর কাগজে সই থেকেই যেত।
 *
 * ⚠️ চলতি অফারে বদলালে আরও খারাপ: একই দিনের দুইটা বিল দুই নিয়মে কাটা
 * হত। ⓘ বদলাতে হলে থামিয়ে নকল — নতুন অফার, নতুন অনুমোদন।
 *
 * ⭐ মেয়াদ এর ব্যতিক্রম (মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর) — সেটা
 * [[PromotionLifecycle::reschedule()]]-এ, কারণ জমে থাকা সারি পুরনো বিল
 * বাঁচায়। ⛔ কিন্তু ধাপ বা হার বদলালে জমে থাকা সারি নতুন বিলকে বাঁচায়
 * না — নতুন বিল নতুন হারে কাটা হত, অনুমোদন ছাড়াই।
 */
final class PromotionRules
{
    /**
     * ⭐ একটা ধাপ: শর্ত আর তার সুবিধা একসাথে।
     *
     * ⓘ দুইটা একসাথে বসে, একটা লেনদেনে। ⛔ আলাদা হলে শর্ত বসে যেত আর
     * সুবিধা ভাঙত — একটা ধাপ যেটা খাটে অথচ কিছুই দেয় না।
     *
     * @param  array<string, mixed>  $data
     */
    public function addStep(Promotion $offer, array $data): PromotionCondition
    {
        $this->assertStillADraft($offer);

        $benefitKind = BenefitKind::from((string) $data['benefit_kind']);

        /* ⛔ ধরন আর সুবিধা মিলতে হয় — [[PromotionType::allowedBenefits()]] */
        if (! in_array($benefitKind, $offer->type->allowedBenefits(), true)) {
            throw ValidationException::withMessages([
                'benefit_kind' => __('promotion::validation.benefit_not_for_type', [
                    'benefit' => $benefitKind->label(),
                    'type' => $offer->type->label(),
                ]),
            ]);
        }

        /*
         * ⛔ উপহারের সুবিধায় পণ্য বাধ্যতামূলক — আর সে এই কোম্পানির।
         *
         * ⚠️ ছাড়া একটা `goods` ধাপ বসত যেটা ইঞ্জিন চুপচাপ বাদ দিত
         * ([[PromotionBenefit::isDeliverable()]])। ⓘ মানুষ ভাবতেন অফারটা
         * চলছে, আর ক্রেতা উপহারটা কোনোদিন পেতেন না।
         */
        $giftProduct = null;

        if ($benefitKind->movesStock()) {
            $giftProduct = isset($data['gift_product_id'])
                ? Product::query()->find($data['gift_product_id'])
                : null;

            if ($giftProduct === null) {
                throw ValidationException::withMessages([
                    'gift_product_id' => __('promotion::validation.gift_needs_product'),
                ]);
            }
        }

        $from = $this->decimalOrNull($data['value_from'] ?? null);
        $to = $this->decimalOrNull($data['value_to'] ?? null);

        /* ⛔ উল্টো পরিসর — ৯৯ থেকে ৫০ — কোনো সংখ্যাকেই ধরত না */
        if ($from !== null && $to !== null && bccomp($from, $to, 4) > 0) {
            throw ValidationException::withMessages([
                'value_to' => __('promotion::validation.range_upside_down'),
            ]);
        }

        return DB::transaction(function () use ($offer, $data, $benefitKind, $giftProduct, $from, $to) {
            $condition = PromotionCondition::query()->create([
                'promotion_id' => $offer->id,
                'kind' => ConditionKind::from((string) ($data['condition_kind'] ?? 'quantity')),
                'value_from' => $from,
                'value_to' => $to,
                'step_order' => (int) $offer->conditions()->max('step_order') + 1,
            ]);

            PromotionBenefit::query()->create([
                'promotion_id' => $offer->id,
                'promotion_condition_id' => $condition->id,
                'kind' => $benefitKind,
                'amount' => (string) $data['amount'],
                'gift_product_id' => $giftProduct?->id,
                'gift_unit_id' => $giftProduct?->unit_id,
                'cap_per_bill' => $this->decimalOrNull($data['cap_per_bill'] ?? null),
            ]);

            return $condition;
        });
    }

    public function removeStep(Promotion $offer, PromotionCondition $step): void
    {
        $this->assertStillADraft($offer);

        /* ⛔ অন্য অফারের ধাপ — ঠিকানা ধরে ধরে পরের অফার ভাঙা যেত */
        if ((int) $step->promotion_id !== (int) $offer->id) {
            abort(404);
        }

        $step->delete();
    }

    /** ⓘ একটা সুযোগের সারি — *"কেবল এই ক্রেতা"*, *"কেবল এই পণ্য"*। */
    public function addScope(Promotion $offer, ScopeKind $kind, int $targetId): PromotionScope
    {
        $this->assertStillADraft($offer);

        return PromotionScope::query()->firstOrCreate([
            'promotion_id' => $offer->id,
            'kind' => $kind,
            'target_id' => $targetId,
        ]);
    }

    public function removeScope(Promotion $offer, PromotionScope $scope): void
    {
        $this->assertStillADraft($offer);

        if ((int) $scope->promotion_id !== (int) $offer->id) {
            abort(404);
        }

        $scope->delete();
    }

    private function assertStillADraft(Promotion $offer): void
    {
        if ($offer->status !== PromotionStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('promotion::validation.rules_frozen', ['status' => $offer->status->label()]),
            ]);
        }
    }

    private function decimalOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}

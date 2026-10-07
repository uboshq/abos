<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Support\PromotionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * অফারের ছাদ বসানো বা বদলানো — স্পেক §১৫।
 *
 * ── ⚠️ কেন [[BudgetGuard]]-এর ভিতরে নয় ──────────────────────────────
 * ⓘ পাহারা কেবল পড়ে আর থামায়। ⛔ লেখার কাজ তার ভিতরে বসালে যে ক্লাস
 * প্রতিটা বিলে ডাকা হয় সে-ই ছাদ বদলাতে পারত — একটা ভুল ডাকেই।
 *
 * ── ⭐ প্রতিটা ধরনে একটাই ছাদ ──────────────────────────────────────
 * ⓘ একই ধরনে দুইটা সারি থাকলে পাহারা দুইটাই মাপত, আর ছোটটা জিতত —
 * মানুষ বড়টা দেখে ভাবতেন জায়গা আছে। ⚠️ তাই নতুন অঙ্ক পুরনো সারিটাই
 * বদলায়, আর [[IsAudited]] পুরনো অঙ্কটা রেখে দেয়।
 */
final class BudgetKeeper
{
    /** @var list<string> */
    public const KINDS = [
        PromotionBudget::TOTAL,
        PromotionBudget::DISCOUNT,
        PromotionBudget::GIFT,
        PromotionBudget::QUANTITY,
    ];

    /**
     * ⓘ কোন অবস্থায় ছাদ বদলানো যায় — সেবা আর পাতা দুইটাই এটা পড়ে।
     *
     * ⛔ বাতিল বা মেয়াদ-শেষ অফারে নয়: ⓘ ওখানে ছাদ বাড়ানো কিছুই করে না,
     * কিন্তু খাতায় একটা *"বাজেট বাড়ানো হয়েছিল"* সারি রেখে যায় — মাস
     * শেষে সেটা দেখে মানুষ খুঁজতেন টাকাটা কোথায় গেল।
     *
     * @var list<PromotionStatus>
     */
    public const OPEN = [
        PromotionStatus::DRAFT,
        PromotionStatus::SUBMITTED,
        PromotionStatus::APPROVED,
        PromotionStatus::ACTIVE,
        PromotionStatus::PAUSED,
    ];

    public function __construct(private readonly BudgetGuard $guard) {}

    public function set(
        Promotion $offer,
        string $kind,
        string $ceiling,
        int $warnAt = 80,
        string $per = PromotionBudget::PER_OFFER,
    ): PromotionBudget {
        if (! in_array($offer->status, self::OPEN, true)) {
            throw ValidationException::withMessages([
                'ceiling' => __('promotion::validation.budget_closed', ['status' => $offer->status->label()]),
            ]);
        }

        if (! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('validation.in', ['attribute' => 'kind'])]);
        }

        if (! in_array($per, PromotionBudget::WINDOWS, true)) {
            throw ValidationException::withMessages(['per' => __('validation.in', ['attribute' => 'per'])]);
        }

        if (bccomp($ceiling, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'ceiling' => __('promotion::validation.budget_not_positive'),
            ]);
        }

        return DB::transaction(function () use ($offer, $kind, $ceiling, $warnAt, $per): PromotionBudget {
            /*
             * ⚠️ অফারের সারিতে তালা — ছাদ বসানো আর বিল কাটা একই মুহূর্তে।
             *
             * ⓘ তালা ছাড়া: খরচ ৯০০ পড়া হলো, ছাদ ৯০০ বসানো হলো, এর মধ্যে
             * আরেক কাউন্টারে ১০০-র বিল বসে গেল — ছাদের উপরে ১,০০০ খরচ,
             * আর কেউ টের পেলেন না।
             */
            Promotion::query()->whereKey($offer->id)->lockForUpdate()->first();

            /*
             * ⛔ ছাদ ইতিমধ্যে খরচের নিচে নামানো যায় না।
             *
             * ⓘ ৭ লাখ খরচ হওয়ার পর ছাদ ৫ লাখ করলে পাতা বলত *"১৪০%"*, আর
             * সেই টাকা ফেরানোর কোনো উপায় নেই — ছাদটা একটা মিথ্যা হয়ে থাকত।
             * ⚠️ **সমান** চলে: *"আর একটাও নয়"* বলার এটাই পথ।
             */
            /*
             * ⓘ জানালার ছাদে *"ইতিমধ্যে খরচ"* একটা সংখ্যা নয় (প্রতি বিলে আলাদা),
             * তাই নিচে-নামানোর বাধা কেবল গোটা অফারের ছাদে। ⚠️ ছোট করা জানালার
             * ছাদ কেবল **নতুন** বিলে খাটে — পুরনো বিলের ছাড় জমে থাকে।
             */
            $used = $per === PromotionBudget::PER_OFFER ? $this->guard->used($offer, $kind) : '0';

            if (bccomp($ceiling, $used, 4) < 0) {
                throw ValidationException::withMessages([
                    'ceiling' => __('promotion::validation.budget_below_used', ['used' => $used]),
                ]);
            }

            $budget = PromotionBudget::query()
                ->where('promotion_id', $offer->id)
                ->where('kind', $kind)
                ->where('per', $per)
                ->first() ?? new PromotionBudget(['promotion_id' => $offer->id, 'kind' => $kind, 'per' => $per]);

            $budget->ceiling = $ceiling;
            $budget->warn_at_percent = max(1, min(100, $warnAt));
            $budget->save();

            return $budget;
        });
    }
}

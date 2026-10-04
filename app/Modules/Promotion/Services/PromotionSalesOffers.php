<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Core\Contracts\SalesOffers;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Support\BenefitKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ বিক্রয়ের জানালার Promotion-দিক — [[SalesOffers]] চুক্তি (অডিট §১১, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ⓘ হিসাব আর বসানো সবটা পুরনো দুই জায়গার: কী খাটে [[PromotionDesk::suggest()]],
 * বসানো [[PromotionDesk::apply()]] (ইঞ্জিনকে আবার জিজ্ঞেস, ছাদে তালা, দুইবার নয়),
 * উল্টানো [[PromotionReversal::forSource()]]। ⛔ এখানে কোনো নতুন নিয়ম নেই — থাকলে
 * চালানের ছাড় আর কাউন্টারের ছাড় একদিন দুই রকম হত।
 *
 * ⓘ কেবল টাকার ছাড় (শতাংশ, নির্দিষ্ট টাকা) এখান দিয়ে বসে। ⚠️ মাল দেওয়ার অফার
 * উপহারের পর্দায় ([[PromotionGiftController]]) — ওখানে গুদাম, লট আর মজুদ নড়ে; পয়েন্ট
 * আর জমা বিলের টাকায় কিছু বদলায় না, তাই এই প্যানেলের কাজ নয়।
 */
final class PromotionSalesOffers implements SalesOffers
{
    /** @var list<BenefitKind> বিলের সারির টাকা কমায় যে সুবিধাগুলো */
    public const BILLABLE = [BenefitKind::PERCENT, BenefitKind::AMOUNT];

    public function __construct(
        private readonly PromotionDesk $desk,
        private readonly PromotionReversal $reversal,
    ) {}

    /**
     * ⛔ প্রচার মডিউলের সুইচ মানে — কোম্পানির `promotion.enabled` আর এই শাখার বন্ধ-তালিকা, মেনুর একই দুই প্রশ্ন
     * ([[MenuBuilder::moduleEnabled()]])। ⓘ আগে সবসময় `true` ছিল: যে কোম্পানি প্রচার বন্ধ রেখেছে, তার চালানেও
     * অফারের অংশ দেখাত (৪ অক্টোবর ২০২৬, abos-bb)।
     */
    public function enabled(): bool
    {
        if (! (bool) app(\App\Core\Services\SettingsService::class)->get('promotion.enabled', true)) {
            return false;
        }

        return ! in_array('promotion', \App\Models\BranchModule::switchedOffIn(\App\Core\Support\CompanyContext::branchId()), true);
    }

    public function suggest(array $line): array
    {
        $found = $this->desk->suggest($line);

        return [
            'eligible' => $found['eligible']->map(fn (array $row) => [
                'id' => (int) $row['promotion']->id,
                'code' => (string) $row['promotion']->code,
                'name' => $row['promotion']->name(),
                'kind' => $row['benefit']->kind->value,
                'kind_label' => $row['benefit']->kind->label(),
                'worth' => (string) $row['worth'],
                'billable' => in_array($row['benefit']->kind, self::BILLABLE, true),
            ])->values()->all(),

            'almost' => $found['almost']->map(fn (array $row) => [
                'id' => (int) $row['promotion']->id,
                'code' => (string) $row['promotion']->code,
                'name' => $row['promotion']->name(),
                'short_by' => (string) $row['short_by'],
            ])->values()->all(),
        ];
    }

    public function apply(int $offerId, array $line, string $sourceType, int $sourceId, int $sourceLineId): string
    {
        $offer = Promotion::query()->find($offerId);

        if ($offer === null) {
            throw ValidationException::withMessages(['promotion' => __('promotion::validation.not_found')]);
        }

        /*
         * ⛔ টাকার ছাড় না হলে এখান দিয়ে নয় — ইঞ্জিনকে আবার জিজ্ঞেস করে ধরন দেখা হয়,
         * পর্দার কথায় নয়। ⓘ মাল দেওয়ার অফার এখানে বসালে বিলে কোনো ছাড় বসত না,
         * অথচ অফারের খাতায় "প্রয়োগ হয়েছে" লেখা থাকত — মাল না দিয়েই।
         */
        $fit = collect($this->desk->suggest($line)['eligible'])
            ->first(fn (array $row) => (int) $row['promotion']->id === $offerId);

        if ($fit !== null && ! in_array($fit['benefit']->kind, self::BILLABLE, true)) {
            throw ValidationException::withMessages([
                'promotion' => __('promotion::validation.not_a_bill_discount', ['code' => $offer->code]),
            ]);
        }

        // ⓘ না খাটলে ডেস্ক নিজেই থামায় (not_eligible) — দ্বিতীয় বার্তা লেখা হয়নি
        $applied = $this->desk->apply($offer, $line, $sourceType, $sourceId, $sourceLineId);

        return (string) $applied->worth;
    }

    public function remove(int $offerId, string $sourceType, int $sourceId, int $sourceLineId): void
    {
        DB::transaction(function () use ($offerId, $sourceType, $sourceId, $sourceLineId) {
            $row = PromotionApplication::query()
                ->where('promotion_id', $offerId)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('source_line_id', $sourceLineId)
                ->whereNull('reversed_at')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw ValidationException::withMessages(['promotion' => __('promotion::validation.not_applied_here')]);
            }

            /*
             * ⓘ কেবল টাকার ছাড়ই এখান দিয়ে বসে ([[apply()]]), তাই উল্টাতে উপহার,
             * কুপন বা পয়েন্টের কিছু নেই — সারিটা উল্টানো লেখা হয়, মোছা নয়
             * (নিরীক্ষায় থাকে কে কখন বসিয়েছিলেন আর তুলেছিলেন)।
             */
            $row->reversed_at = now();
            $row->reversed_by = auth()->id();
            $row->save();
        });
    }

    public function appliedOn(string $sourceType, int $sourceId): array
    {
        return PromotionApplication::query()
            ->with('promotion')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNull('reversed_at')
            ->orderBy('id')
            ->get()
            ->map(fn (PromotionApplication $row) => [
                'offer_id' => (int) $row->promotion_id,
                'line_id' => $row->source_line_id !== null ? (int) $row->source_line_id : null,
                'code' => (string) ($row->promotion?->code ?? ''),
                'name' => (string) ($row->promotion?->name() ?? ''),
                'worth' => (string) $row->worth,
            ])->values()->all();
    }

    public function reverseAll(string $sourceType, int $sourceId): void
    {
        $this->reversal->forSource($sourceType, $sourceId);
    }
}

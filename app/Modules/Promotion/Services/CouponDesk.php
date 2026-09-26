<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Modules\Customer\Models\Customer;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionCoupon;
use App\Modules\Promotion\Models\PromotionCouponRedemption;
use App\Modules\Promotion\Support\PromotionType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * কুপন — স্পেক §৭-ঞ: কোড দিলে তবেই অফারটা বিলে বসে।
 *
 * ── ⭐ কেন কুপনের নিজের দরজা, আর কেন সেটা [[PromotionDesk]]-কে ডাকে ─────
 * ⓘ কুপনের অফারটা সাধারণ অফারের মতোই হিসাব হয় — শর্ত, সুবিধা, ছাদ।
 * ⛔ এখানে হিসাবটা আবার লিখলে একদিন কুপনের ছাড় আর সাধারণ ছাড় আলাদা
 * নিয়মে চলত, আর §১৮-এর জমে-থাকা অঙ্ক ও §১৫-এর ছাদ কুপনের পথে এড়ানো যেত।
 *
 * ⭐ তাই এই দরজা কেবল **কোডের প্রশ্নগুলো** করে (আছে কি, কতবার, কার জন্য),
 * তারপর বসানোর কাজটা [[PromotionDesk::apply()]]-কে দেয় — ছাদ সেখানেই
 * প্রথম কাজ।
 *
 * ── ⚠️ তালা কেন বাধ্যতামূলক ────────────────────────────────────────
 * ⓘ একবারের কুপন, আর দুইটা কাউন্টারে একই কোড একই মুহূর্তে। ⛔ তালা ছাড়া
 * দুইজনই `used_count = 0` পড়তেন, দুইজনই বসাতেন — দুইটা বিল, দুইটা ছাড়,
 * আর খাতা মিলত বলে কোথাও কিছু লাল হত না।
 */
final class CouponDesk
{
    private const SERIES = 'COUP';

    /** ⓘ এক ডাকে সর্বোচ্চ কয়টা — ভুলে ৫০,০০০ টাইপ করলে লেনদেনটা মিনিট ধরে তালা ধরে রাখত */
    public const MAX_BATCH = 500;

    /** ⓘ তৈরি কোডের লেজ — ০/O আর ১/I বাদ, কাউন্টারে পড়ে শোনানোর সময় গুলিয়ে যায় */
    private const TAIL_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const TAIL_LENGTH = 5;

    public function __construct(
        private readonly PromotionDesk $desk,
        private readonly NumberSeriesEngine $numbers,
    ) {}

    /**
     * ⭐ কুপন তৈরি — হয় একটা নির্দিষ্ট কোড (*"EID100"*), নয় সিরিজ থেকে কয়েকটা।
     *
     * ── ⚠️ তৈরি কোডে সিরিজের নম্বর **আর** একটা এলোমেলো লেজ ─────────────
     * ⓘ সিরিজ দেয় নিরীক্ষার ক্রম (§২১)। ⛔ কিন্তু কেবল সিরিজ হলে কোডগুলো
     * অনুমান করা যেত — একজনের হাতে *COUP-0007* থাকলে *COUP-0008* টাইপ করে
     * অন্যের ছাড় নেওয়া যেত। ⭐ লেজটা সেই দরজা বন্ধ করে।
     *
     * @param  array{max_uses?: int, max_uses_per_customer?: int|null, customer_id?: int|null, valid_from?: string|null, valid_to?: string|null}  $terms
     * @return Collection<int, PromotionCoupon>
     */
    public function issue(Promotion $offer, int $count = 1, ?string $code = null, array $terms = []): Collection
    {
        if ($offer->type !== PromotionType::COUPON) {
            throw ValidationException::withMessages([
                'promotion' => __('promotion::coupon.not_coupon_offer', ['code' => $offer->code]),
            ]);
        }

        $code = $code === null ? null : PromotionCoupon::normalise($code);

        if ($code !== null && $count !== 1) {
            throw ValidationException::withMessages([
                'count' => __('promotion::coupon.one_code_one_coupon'),
            ]);
        }

        if ($count < 1 || $count > self::MAX_BATCH) {
            throw ValidationException::withMessages([
                'count' => __('promotion::coupon.count_range', ['max' => self::MAX_BATCH]),
            ]);
        }

        if ($code !== null && preg_match('/^[A-Z0-9-]{3,40}$/', $code) !== 1) {
            throw ValidationException::withMessages([
                'code' => __('promotion::coupon.code_shape'),
            ]);
        }

        $clean = $this->cleanTerms($offer, $terms);

        return DB::transaction(function () use ($offer, $count, $code, $clean) {
            /*
             * ⚠️ অনন্যতা ডাটাবেজও পাহারা দেয় (`pcp_co_code_uq`)। ⓘ তবু আগে
             * জিজ্ঞেস করা, কারণ ডাটাবেজের ভাঙন মানুষের ভাষায় আসে না —
             * আসে একটা ৫০০ হয়ে।
             */
            if ($code !== null && PromotionCoupon::query()->where('code', $code)->exists()) {
                throw ValidationException::withMessages([
                    'code' => __('promotion::coupon.code_taken', ['code' => $code]),
                ]);
            }

            $made = collect();

            for ($i = 0; $i < $count; $i++) {
                /*
                 * ⚠️ `forceCreate` — `code` ও `used_count` ইচ্ছাকৃতভাবে
                 * `fillable`-এর বাইরে। ⛔ `create()` দিলে কোডটা চুপচাপ ফেলে
                 * দেওয়া হত আর `NOT NULL` কলামে ইনসার্ট ভাঙত ([[GiftIssuer]]-এ
                 * মেপে শেখা)।
                 */
                $made->push(PromotionCoupon::query()->forceCreate([
                    'company_id' => CompanyContext::id(),
                    'promotion_id' => $offer->id,
                    'code' => $code ?? $this->generatedCode(),
                    'used_count' => 0,
                    'issued_by' => auth()->id(),
                    ...$clean,
                ]));
            }

            return $made;
        });
    }

    /**
     * ⭐ কোডটা একটা বিলের সারিতে খাটানো।
     *
     * ── ⛔ প্রত্যাখ্যানের ক্রম, আর প্রতিটা কেন ────────────────────────
     *   ⓵ কোড নেই / বন্ধ — ⚠️ অন্য কোম্পানির কোড আর না-থাকা কোড **একই
     *      বার্তা** পায়: ⛔ আলাদা বললে কেউ অন্যের কোডের অস্তিত্ব আঁচ করতেন
     *   ⓶ কুপনের নিজের মেয়াদ, তারপর অফারের
     *   ⓷ মোট সীমা · ⓸ কার জন্য বাঁধা · ⓹ ক্রেতা-প্রতি সীমা
     *   ⓺ একই বিলে দ্বিতীয়বার — দুইবার চাপা বোতাম
     *
     * ⭐ সবগুলো কুপনের সারিতে তালা ধরে, একই লেনদেনে — তালা ছাড়ার আগে
     * সুবিধাটা বসে আর ব্যবহারটা লেখা হয়।
     *
     * @param  array<string, mixed>  $line  [[PromotionEngine::offersFor()]]-এর সারি
     */
    public function redeem(
        string $code,
        array $line,
        string $sourceType,
        int $sourceId,
        ?int $customerId = null,
        ?int $sourceLineId = null,
        ?Carbon $at = null,
    ): PromotionApplication {
        $at ??= Carbon::now();
        $code = PromotionCoupon::normalise($code);

        $lineCustomer = isset($line['customer_id']) ? (int) $line['customer_id'] : null;

        /*
         * ⛔ দরজায় একজন ক্রেতা, আর সারিতে আরেকজন — থামা।
         *
         * ⚠️ চুপচাপ একটা বেছে নিলে গোনা হত একজনের নামে, আর অফারের সুযোগ
         * (কোন ক্রেতার জন্য) যাচাই হত আরেকজনের নামে।
         */
        if ($customerId !== null && $lineCustomer !== null && $customerId !== $lineCustomer) {
            throw ValidationException::withMessages([
                'customer_id' => __('promotion::coupon.customer_mismatch'),
            ]);
        }

        $customerId ??= $lineCustomer;

        return DB::transaction(function () use ($code, $line, $sourceType, $sourceId, $customerId, $sourceLineId, $at) {
            /* ⓘ কোম্পানির পরিধি [[BelongsToCompany]] নিজেই বসায় — অন্যের কোড এখানে অদৃশ্য */
            $coupon = PromotionCoupon::query()->where('code', $code)->lockForUpdate()->first();

            if ($coupon === null || ! $coupon->is_active) {
                $this->refuse('not_found', ['code' => $code]);
            }

            if (! $coupon->isWithinDates($at)) {
                $this->refuse('outside_dates', ['code' => $code]);
            }

            $offer = $coupon->promotion;

            if ($offer === null || ! $offer->isLiveOn($at)) {
                $this->refuse('offer_not_live', ['code' => $code]);
            }

            if ($coupon->used_count >= $coupon->max_uses) {
                $this->refuse('used_up', ['code' => $code, 'max' => $coupon->max_uses]);
            }

            /* ⛔ বাঁধা কুপন — অন্য কারও হাতে, বা ক্রেতা ছাড়া বিলে, খাটে না */
            if ($coupon->customer_id !== null && (int) $coupon->customer_id !== $customerId) {
                $this->refuse('bound_elsewhere', ['code' => $code]);
            }

            if ($coupon->max_uses_per_customer !== null) {
                /*
                 * ⚠️ ক্রেতা-প্রতি সীমা থাকলে ক্রেতা ছাড়া বিল চলে না।
                 * ⛔ নাহলে নগদ বিলে নাম না বসিয়ে একই কোড যতবার খুশি।
                 */
                if ($customerId === null) {
                    $this->refuse('needs_customer', ['code' => $code]);
                }

                $theirs = PromotionCouponRedemption::query()
                    ->where('coupon_id', $coupon->id)
                    ->where('customer_id', $customerId)
                    ->whereNull('reversed_at')
                    ->count();

                if ($theirs >= $coupon->max_uses_per_customer) {
                    $this->refuse('customer_limit', ['code' => $code, 'max' => $coupon->max_uses_per_customer]);
                }
            }

            $already = PromotionCouponRedemption::query()
                ->where('coupon_id', $coupon->id)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->whereNull('reversed_at')
                ->exists();

            if ($already) {
                $this->refuse('already_on_bill', ['code' => $code]);
            }

            /*
             * ⭐ কোডটা সারির সাথে যায় — ইঞ্জিন কেবল তখনই কুপনের অফারটা দেখে।
             *
             * ⓘ `coupon_promotion_ids` ছাড়া (ওয়্যারিং হওয়ার পরে) ইঞ্জিন
             * কুপনের অফার কাউকে প্রস্তাব করে না — কোড না জানা ক্রেতাও ছাড়
             * পেতেন নাহলে।
             */
            $line['customer_id'] = $customerId;
            $line['coupon_promotion_ids'] = array_values(array_unique([
                ...array_map('intval', (array) ($line['coupon_promotion_ids'] ?? [])),
                (int) $offer->id,
            ]));

            /* ⛔ ছাদ, যোগ্যতা, জমে-থাকা অঙ্ক — সবই ওখানে, এখানে আবার নয় */
            $applied = $this->desk->apply($offer, $line, $sourceType, $sourceId, $sourceLineId, $at);

            $coupon->used_count = $coupon->used_count + 1;
            $coupon->save();

            PromotionCouponRedemption::query()->create([
                'coupon_id' => $coupon->id,
                'promotion_application_id' => $applied->id,
                'customer_id' => $customerId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'redeemed_at' => $at,
            ]);

            return $applied;
        });
    }

    /**
     * ⭐ বিল বাতিল — কুপনের ব্যবহারটা ফেরত।
     *
     * ── ⛔ দুইবার ডাকলে দুইবার ফেরে না ───────────────────────────────
     * ⓘ বাতিলের দরজা দুইবার চাপা হতে পারে, বা [[PromotionReversal]] আর
     * বিক্রয় দুইজনেই ডাকতে পারে। ⚠️ দুইবার কমালে একবারের কুপন একটা
     * বাতিলের বিনিময়ে দুইবার খাটত।
     *
     * ⭐ তাই কেবল `reversed_at` খালি সারিগুলো, আর প্রতিটা একবারই চিহ্ন পায়।
     *
     * ⚠️ বিলের সুবিধাটা (`PromotionApplication`) এখানে ফেরানো হয় না —
     * সেটা [[PromotionReversal::forSource()]]-এর কাজ, উপহারের মালসহ।
     *
     * @return int কয়টা ব্যবহার ফেরত গেল
     */
    public function release(string $sourceType, int $sourceId, ?Carbon $at = null): int
    {
        $at ??= Carbon::now();

        return DB::transaction(function () use ($sourceType, $sourceId, $at) {
            $rows = PromotionCouponRedemption::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->whereNull('reversed_at')
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $coupon = PromotionCoupon::query()->whereKey($row->coupon_id)->lockForUpdate()->first();

                /* ⓘ শূন্যের নিচে নয় — হাতে বদলানো সারি থাকলেও সংখ্যাটা অর্থহীন হয় না */
                if ($coupon !== null && $coupon->used_count > 0) {
                    $coupon->used_count = $coupon->used_count - 1;
                    $coupon->save();
                }

                $row->reversed_at = $at;
                $row->reversed_by = auth()->id();
                $row->save();
            }

            return $rows->count();
        });
    }

    /**
     * ⛔ শর্তগুলো যাচাই — কুপন জন্মানোর আগেই।
     *
     * @param  array<string, mixed>  $terms
     * @return array<string, mixed>
     */
    private function cleanTerms(Promotion $offer, array $terms): array
    {
        $max = (int) ($terms['max_uses'] ?? 1);

        if ($max < 1) {
            throw ValidationException::withMessages([
                'max_uses' => __('promotion::coupon.max_uses_min'),
            ]);
        }

        $perCustomer = isset($terms['max_uses_per_customer']) && $terms['max_uses_per_customer'] !== ''
            ? (int) $terms['max_uses_per_customer']
            : null;

        /* ⚠️ ক্রেতা-প্রতি সীমা মোটের বেশি হলে সেটা অর্থহীন — ভুল টাইপ, প্রায় সবসময় */
        if ($perCustomer !== null && ($perCustomer < 1 || $perCustomer > $max)) {
            throw ValidationException::withMessages([
                'max_uses_per_customer' => __('promotion::coupon.per_customer_range', ['max' => $max]),
            ]);
        }

        $customerId = isset($terms['customer_id']) && $terms['customer_id'] !== ''
            ? (int) $terms['customer_id']
            : null;

        /* ⛔ অন্য কোম্পানির ক্রেতা এখানে অদৃশ্য — [[BelongsToCompany]] */
        if ($customerId !== null && ! Customer::query()->whereKey($customerId)->exists()) {
            throw ValidationException::withMessages([
                'customer_id' => __('promotion::coupon.customer_unknown'),
            ]);
        }

        $from = filled($terms['valid_from'] ?? null) ? Carbon::parse($terms['valid_from'])->startOfDay() : null;
        $to = filled($terms['valid_to'] ?? null) ? Carbon::parse($terms['valid_to'])->startOfDay() : null;

        if ($from !== null && $to !== null && $from->gt($to)) {
            throw ValidationException::withMessages([
                'valid_to' => __('promotion::coupon.dates_backwards'),
            ]);
        }

        /*
         * ⚠️ কুপনের মেয়াদ অফারের মেয়াদের **ভিতরে**।
         *
         * ⓘ বাইরে গেলে পর্দায় *"৩১ ডিসেম্বর পর্যন্ত"* ছাপা হত, অথচ অফার
         * ১৫ তারিখে শেষ — ক্রেতা ১৬ তারিখে এসে শুনতেন *"খাটে না"*।
         */
        $offerFrom = $offer->starts_on->toDateString();
        $offerTo = $offer->ends_on->toDateString();

        foreach (['valid_from' => $from, 'valid_to' => $to] as $field => $day) {
            if ($day !== null && ($day->toDateString() < $offerFrom || $day->toDateString() > $offerTo)) {
                throw ValidationException::withMessages([
                    $field => __('promotion::coupon.dates_outside_offer', ['from' => $offerFrom, 'to' => $offerTo]),
                ]);
            }
        }

        return [
            'max_uses' => $max,
            'max_uses_per_customer' => $perCustomer,
            'customer_id' => $customerId,
            'valid_from' => $from?->toDateString(),
            'valid_to' => $to?->toDateString(),
            'is_active' => true,
        ];
    }

    private function generatedCode(): string
    {
        $tail = '';
        $last = strlen(self::TAIL_ALPHABET) - 1;

        for ($i = 0; $i < self::TAIL_LENGTH; $i++) {
            $tail .= self::TAIL_ALPHABET[random_int(0, $last)];
        }

        return PromotionCoupon::normalise($this->numbers->next(self::SERIES).'-'.$tail);
    }

    /** @param  array<string, mixed>  $replace */
    private function refuse(string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([
            'coupon' => __('promotion::coupon.'.$key, $replace),
        ]);
    }
}

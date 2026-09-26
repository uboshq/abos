<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Support\BudgetWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * বিক্রয়ের জন্য অফারের একমাত্র জানালা — স্পেক §১০ ও §২২।
 *
 * ── ⭐ কেন একটা আলাদা জানালা, ইঞ্জিন সরাসরি নয় ──────────────────────
 * ⓘ বিক্রয়ের চারটা পর্দা (বিল, সরাসরি বিক্রয়, আদেশ, কাউন্টার) তিনটা
 * প্রশ্নই করে: *"কী খাটে"*, *"আর কতটা নিলে খুলবে"*, আর *"এটা বসাও"*।
 *
 * ⛔ প্রতিটা পর্দা ইঞ্জিনের ভিতরে হাত দিলে একদিন একটা পর্দা অফারটা
 * বসাত অথচ সুবিধার অঙ্কটা জমাত না — আর তখন §১৮-এর পাহারাটা ঐ পথে
 * ভেঙে যেত, নীরবে।
 *
 * ⭐ তাই বসানোর কাজটা একটা জায়গায়, আর সেখানে অঙ্কটা **সবসময়** জমে।
 *
 * ── ⚠️ সিস্টেম নিজে থেকে কিছু বসায় না ───────────────────────────────
 * ⓘ স্পেক §১০: *"System কোনো unauthorized discount নিজে থেকে apply করবে
 * না — শুধু suggestion দেবে।"* ⛔ তাই `suggest()` কেবল দেখায়; `apply()`
 * ডাকে মানুষের চাপা বোতাম।
 */
final class PromotionDesk
{
    public function __construct(
        private readonly PromotionEngine $engine,
        private readonly BudgetGuard $budget,
        private readonly LoyaltyLedger $loyalty,
    ) {}

    /**
     * ⭐ এই সারিতে কী খাটে, আর কী প্রায় খাটে — পর্দার প্যানেলের জন্য।
     *
     * ⓘ দুইটা তালিকা একসাথে, কারণ পর্দা দুইটাই একসাথে দেখায় (§১০-এর
     * Eligible / Almost Eligible)।
     *
     * @param  array<string, mixed>  $line
     * @return array{eligible: Collection, almost: Collection}
     */
    public function suggest(array $line, ?Carbon $at = null): array
    {
        return [
            'eligible' => $this->engine->offersFor($line, $at),
            'almost' => $this->engine->almostFor($line, $at),
        ];
    }

    /**
     * ⭐ একটা অফার একটা বিলের সারিতে বসানো — আর অঙ্কটা **জমে যায়**।
     *
     * ── ⚠️ ইঞ্জিনকে আবার জিজ্ঞেস করা হয়, পর্দার কথা বিশ্বাস করা হয় না ──
     * ⓘ পর্দা বলতে পারে *"এই অফারে ৫% ছাড়"*। ⛔ কিন্তু পর্দাটা পাঁচ মিনিট
     * আগে আঁকা — এর মধ্যে অফারটা থামানো হতে পারে, মেয়াদ কমানো হতে পারে,
     * বা কেউ ফর্ম বদলে ৫০% পাঠাতে পারেন।
     *
     * ⭐ তাই এখানে ইঞ্জিন আবার বলে কী খাটে। ⓘ পর্দার দাবি না মিললে
     * প্রত্যাখ্যান — ⛔ নিজে থেকে অঙ্ক বদলে বসানো নয়, কারণ তখন মানুষ
     * একটা দেখতেন আর বিলে বসত আরেকটা।
     *
     * @param  array<string, mixed>  $line
     */
    public function apply(
        Promotion $offer,
        array $line,
        string $sourceType,
        int $sourceId,
        ?int $sourceLineId = null,
        ?Carbon $at = null,
    ): PromotionApplication {
        $fit = $this->engine->offersFor($line, $at)
            ->first(fn (array $row) => $row['promotion']->id === $offer->id);

        if ($fit === null) {
            throw ValidationException::withMessages([
                'promotion' => __('promotion::validation.not_eligible', ['code' => $offer->code]),
            ]);
        }

        /** @var PromotionBenefit $benefit */
        $benefit = $fit['benefit'];

        /*
         * ⛔ ছাদ মাপা আর সারি লেখা — **একই লেনদেনে, অফারের সারিতে তালা দিয়ে**।
         *
         * ⓘ পর্যালোচনায় ধরা (২৭ সেপ্টেম্বর): মাপাটা লেনদেনের বাইরে ছিল।
         * ⚠️ ছাদ ১,০০০, খরচ ৯০০ — দুই কাউন্টার একই মুহূর্তে ১০০ করে বসালে
         * দুইজনেই "৯০০" পড়তেন, দুইজনেই পেরোতেন, আর খরচ হত ১,১০০। ⭐ তালা
         * থাকলে দ্বিতীয়জন প্রথমজনের লেখা দেখে তবেই গোনেন — [[BudgetKeeper]]
         * একই সারিতে তালা দেয়, তাই ছাদ বদল আর বিল কাটাও একে অপরকে দেখে।
         *
         * ⓘ আর ছাদ বসানোর **আগে**, কোনো সইয়ের অপেক্ষার আগে — বাকির সীমায়
         * কড়া বাধাটা অনুমোদনের পরে বসানো ছিল বলে সই হলেই সীমা ছাড়ানো বিল
         * চলে যেত।
         */
        return DB::transaction(function () use ($offer, $line, $sourceType, $sourceId, $sourceLineId, $at, $benefit, $fit) {
            Promotion::query()->whereKey($offer->id)->lockForUpdate()->first();

            /*
             * ⛔ একই সারিতে একই অফার দুইবার নয়।
             *
             * ⓘ ডাবল-ক্লিক বা আবার-চেষ্টায় ছাড় দ্বিগুণ হত, বাজেটও দ্বিগুণ
             * খেত, আর উপহার দুইবার "পাওনা" দেখাত। ⚠️ বাতিল হওয়া সারি গোনায়
             * নেই — বাতিলের পরে একই বিলে নতুন করে বসানো বৈধ।
             */
            $already = PromotionApplication::query()
                ->where('promotion_id', $offer->id)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->when($sourceLineId === null,
                    fn ($q) => $q->whereNull('source_line_id'),
                    fn ($q) => $q->where('source_line_id', $sourceLineId))
                ->whereNull('reversed_at')
                ->exists();

            if ($already) {
                throw ValidationException::withMessages([
                    'promotion' => __('promotion::validation.already_applied', ['code' => $offer->code]),
                ]);
            }

            $this->budget->assertRoomFor(
                $offer,
                $benefit->kind,
                $fit['worth'],
                $benefit->kind->movesStock() ? (string) $fit['qty'] : '0',
                BudgetWindow::forNewLine($sourceType, $sourceId, isset($line['customer_id']) ? (int) $line['customer_id'] : null, $at),
            );

            $applied = PromotionApplication::query()->create([
            'promotion_id' => $offer->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_line_id' => $sourceLineId,
            'customer_id' => $line['customer_id'] ?? null,
            'product_id' => $line['product_id'] ?? null,
            'promotion_benefit_id' => $benefit->id,

            /*
             * ⭐ এই তিনটা ঘরই §১৮-এর পাহারা।
             *
             * ⓘ অঙ্কটা এখানে **জমে যায়**। ⚠️ মালিক মেয়াদ কমানোর অনুমতি
             * দিয়েছেন (২৬ সেপ্টেম্বর), তাই তারিখ দিয়ে পুরনো বিল বাঁচানো
             * যায় না — বাঁচে এই সারিতে। ⛔ ছাড়টা পরে আর অফারের কাগজ থেকে
             * পড়া হয় না।
             */
            'benefit_kind' => $benefit->kind,

            /* ⓘ উপহারে প্রতি-বিলের সীমা মানার পরের পরিমাণ — [[PromotionEngine::worthOf()]] */
            'benefit_amount' => $benefit->kind->movesStock() ? (string) $fit['qty'] : (string) $benefit->amount,
            'worth' => $fit['worth'],

            'applied_by' => auth()->id(),
            ]);

            /* ⓘ পয়েন্টের সুবিধা হলে একই লেনদেনে খাতায় জমা — অন্য সুবিধায় কিছুই করে না */
            $this->loyalty->earn($applied);

            return $applied;
        });
    }

    /**
     * ⭐ হাতে বদল — স্পেক §১৯।
     *
     * ── ⛔ কারণ বাধ্যতামূলক ─────────────────────────────────────────
     * ⓘ হাতে বদল মানে নিয়মের বাইরে টাকা দেওয়া। ⚠️ *"কেন"* না জানলে পরে
     * কেউ বলতে পারেন না ওটা বৈধ ছিল, না কেউ বন্ধুকে ছাড় দিয়েছিলেন।
     *
     * ── ⚠️ মূল অঙ্কটা একবারই জমে ────────────────────────────────────
     * ⓘ দ্বিতীয়বার বদলালে `original_worth` আবার লেখা হয় না — ইঞ্জিন
     * যা বলেছিল সেটাই থাকে। ⛔ নাহলে দুইবার বদলালে প্রথম হাতে-বদলানো
     * অঙ্কটাই "মূল" হয়ে যেত, আর আসল নিয়মের অঙ্ক হারিয়ে যেত।
     *
     * ── ⛔ বাকির সীমায় কখনো হাত নয় ──────────────────────────────────
     * ⓘ মালিকের সিদ্ধান্ত: বাকির সীমা পরম, কারও ওভাররাইড নেই। ⚠️ এই
     * দরজা কেবল অফারের সুবিধা বদলায় — বিলের মোট বা ক্রেতার সীমা নয়।
     *
     * ⛔ আর ছাদ এখানেও খাটে: হাতে বাড়ানো অঙ্ক বাজেট ছাড়ালে থামে,
     * কারণ ছাদের মানেই *"এর বেশি নয়, কেউ চাইলেও"*।
     */
    public function override(PromotionApplication $applied, string $newWorth, string $reason): PromotionApplication
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'override_reason' => __('promotion::validation.override_needs_reason'),
            ]);
        }

        if (bccomp($newWorth, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'worth' => __('promotion::validation.override_not_negative'),
            ]);
        }

        /*
         * ⚠️ পড়া আর লেখা একই লেনদেনে, দুইটা সারিতে তালা দিয়ে।
         *
         * ⓘ পর্যালোচনায় ধরা: দুইটা হাতে-বদল একই মুহূর্তে এলে দুইজনেই পুরনো
         * অঙ্ক থেকে "বাড়তি" গুনতেন, আর দুইজনেই ছাদ পেরোতেন।
         */
        return DB::transaction(function () use ($applied, $newWorth, $reason) {
            Promotion::query()->whereKey($applied->promotion_id)->lockForUpdate()->first();
            $applied = PromotionApplication::query()->whereKey($applied->id)->lockForUpdate()->firstOrFail();

            /*
             * ⛔ বাতিল বিলের সুবিধা বদলানো যায় না।
             *
             * ⓘ বাতিল সারি বাজেটে গোনা হয় না, তাই এখানে বাড়ানো অঙ্ক কোনো
             * ছাদ মানত না — অথচ হাতে-বদলের প্রতিবেদনে দেখাত।
             */
            if ($applied->reversed_at !== null) {
                throw ValidationException::withMessages([
                    'worth' => __('promotion::validation.override_on_reversed'),
                ]);
            }

            /* ⓘ কেবল **বাড়তি** অংশটা ছাদে গোনা — আগের অঙ্ক তো ইতিমধ্যে গোনা হয়ে আছে */
            $extra = bcsub($newWorth, (string) $applied->worth, 4);

            if (bccomp($extra, '0', 4) > 0) {
                $this->budget->assertRoomFor($applied->promotion, $applied->benefit_kind, $extra, '0', BudgetWindow::of($applied));
            }

            if ($applied->original_worth === null) {
                $applied->original_worth = (string) $applied->worth;
            }

            $applied->worth = $newWorth;
            $applied->was_overridden = true;
            $applied->override_reason = $reason;
            $applied->overridden_by = auth()->id();
            $applied->overridden_at = Carbon::now();
            $applied->save();

            return $applied;
        });
    }
}

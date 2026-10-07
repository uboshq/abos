<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionGiftIssue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * বিল বাতিল হলে অফারের সবকিছু ফিরিয়ে নেওয়া — স্পেক §১৮।
 *
 * ── ⭐ কী ফেরে ───────────────────────────────────────────────────────
 * ⓵ সুবিধাটা — সারিতে ফেরতের চিহ্ন পড়ে, আর বাজেটের গোনা থেকে বাদ যায়
 * ⓶ উপহারের মাল — **গুদামে ফেরে**, যে লট থেকে বেরিয়েছিল সেই লটে
 *
 * ── ⚠️ কেন উপহারের মাল ফেরানো বাধ্যতামূলক ───────────────────────────
 * ⓘ বিল বাতিল মানে ক্রেতা মাল নেননি। ⛔ উপহারটা মজুদ থেকে কমেই থাকলে
 * খাতা বলত পাঁচ কার্টন কম, অথচ গুদামে থাকত — আর পরের গোনায় ঐ পাঁচ
 * কার্টন *"বাড়তি"* হিসেবে ধরা পড়ত, কারণ ছাড়া।
 *
 * ── ⓘ কে এই সেবাকে ডাকে ─────────────────────────────────────────────
 * ⚠️ বিক্রয়ের বাতিলের দরজা — ধাপ ৯-এর কাজ, কোঅর্ডিনেটরের সংকেতের পরে।
 * ⓘ ততক্ষণ এই সেবা তৈরি থাকে, আর তার নিজের পাহারা মাপে যে সে ঠিক ফেরায়।
 */
final class PromotionReversal
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CouponDesk $coupons,
        private readonly LoyaltyLedger $loyalty,
    ) {}

    /**
     * ⭐ একটা বিলের সব অফার ফিরিয়ে নেওয়া।
     *
     * ── ⛔ দুইবার ডাকলে দুইবার ফেরে না ───────────────────────────────
     * ⓘ বাতিলের দরজা ভুলে দুইবার চাপা হতে পারে, বা একটা কাজ মাঝপথে
     * ভেঙে আবার চালানো হতে পারে। ⚠️ দুইবার ফেরালে উপহারের মাল দুইবার
     * গুদামে ঢুকত — ভুয়া মজুদ, যা বিক্রিও হয়ে যেত।
     *
     * ⭐ তাই ইতিমধ্যে ফেরানো সারি ছোঁয়া হয় না, আর উপহারের ক্ষেত্রে কেবল
     * **এখনো ক্রেতার কাছে যা আছে** ততটুকুই ফেরে।
     *
     * @return int কয়টা সারি ফেরানো হলো
     */
    public function forSource(string $sourceType, int $sourceId, ?Carbon $at = null): int
    {
        $at ??= Carbon::now();

        return DB::transaction(function () use ($sourceType, $sourceId, $at) {
            $applied = PromotionApplication::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->whereNull('reversed_at')
                ->lockForUpdate()
                ->get();

            foreach ($applied as $row) {
                foreach ($row->gifts as $gift) {
                    $this->returnGift($gift, $at);
                }

                $row->reversed_at = $at;
                $row->reversed_by = auth()->id();
                $row->save();
            }

            /*
             * ⭐ কুপন আর পয়েন্টও একই লেনদেনে ফেরে — দুইটাই নিজে থেকে
             * দুইবার-নিরাপদ, তাই আবার ডাকলে কিছুই দ্বিগুণ হয় না।
             *
             * ⚠️ অফারের সারি না থাকলেও ডাকা হয়: ⓘ যে বিল কেবল পয়েন্ট খরচ
             * করেছিল তার কোনো অফারের সারি নেই, অথচ পয়েন্টগুলো ফেরাতেই হবে।
             */
            $this->coupons->release($sourceType, $sourceId, $at);
            $this->loyalty->reverse($sourceType, $sourceId);

            return $applied->count();
        });
    }

    /**
     * ⓘ উপহারের মাল গুদামে ফেরানো — যে লট থেকে বেরিয়েছিল সেই লটে।
     *
     * ⚠️ `sourceType` আলাদা (`promotion:gift-return`) — ⓘ ছয় মাস পরে *"এই
     * মাল কেন ফিরল"* খুঁজলে ফেরতটা বিক্রির ফেরত থেকে আলাদা দেখা যায়।
     */
    private function returnGift(PromotionGiftIssue $gift, Carbon $at): void
    {
        $back = $gift->stillWithTheBuyer();

        if (bccomp($back, '0', 4) <= 0) {
            return;
        }

        $this->stock->move(
            product: $gift->product,
            warehouse: $gift->warehouse,
            sourceType: 'promotion:gift-return',
            sourceId: $gift->id,
            floor: $back,
            date: $at,
            documentNo: $gift->code,
            batch: $gift->batch,
        );

        /*
         * ⭐ খরচও ফেরে — যে স্তর থেকে যতটা গিয়েছিল ঠিক ততটা ([[CostLayerService::returnToLayers()]]), খাতায় Dr মজুদ / Cr প্রচারের
         * খরচ (৪ অক্টোবর ২০২৬; [[GiftIssuer::bookTheCost()]]-এর উল্টো)। ⓘ আগের উপহারে স্তর টানা হয়নি — তখন ফেরার কিছু নেই, শূন্য।
         */
        // ⓘ তিন রকম: খাতায় খরচ ওঠেনি (৪ অক্টোবরের আগের উপহার) → উল্টানোর কিছু নেই; স্তর থেকে টেনেছিল → স্তরে ফেরত, সেই মূল্য;
        // কেনা দামে দিয়েছিল ([[GiftIssuer::bookTheCost()]]) → সেই এককের দামে
        $booked = \App\Models\LedgerEntry::query()->where('source_type', GiftIssuer::LEDGER_SOURCE)->where('source_id', $gift->id)->exists();
        $drew = \App\Modules\Inventory\Models\CostLayerUse::query()
            ->where('source_type', 'promotion:gift')->where('source_id', $gift->id)->where('qty', '>', 0)->exists();

        $value = match (true) {
            $drew => app(\App\Modules\Inventory\Services\CostLayerService::class)->returnToLayers(
                product: $gift->product,
                qty: $back,
                issuedSourceType: 'promotion:gift',
                issuedSourceId: (int) $gift->id,
                sourceType: 'promotion:gift-return',
                sourceId: (int) $gift->id,
                documentNo: $gift->code,
                date: $at,
            ),
            $booked => bcmul((string) $gift->unit_cost, $back, 4),
            default => '0',
        };

        if (! $booked) {
            $value = '0';
        }

        if (bccomp($value, '0', 4) > 0) {
            app(\App\Core\Engines\Posting\PostingEngine::class)->post(
                GiftIssuer::LEDGER_SOURCE.'_return',
                (int) $gift->id,
                $at->toDateString(),
                [
                    ['account_id' => (int) StandardChart::find(StandardChart::INVENTORY)?->id, 'debit' => $value, 'credit' => '0'],
                    ['account_id' => (int) StandardChart::find(StandardChart::PROMOTION_EXPENSE)?->id, 'debit' => '0', 'credit' => $value],
                ],
                documentNo: $gift->code,
                branchId: \App\Core\Support\CompanyContext::branchId(),
            );
        }

        /*
         * ⓘ সিরিয়াল-রাখা পিসগুলোও ফেরে — `returned`, `in_stock` নয়।
         *
         * ⚠️ মজুদের নিয়মে `returned` মানে *"ফেরত এসেছে, সিদ্ধান্ত বাকি"*।
         * ⛔ সোজা `in_stock` করলে একটা খোলা-বাক্সের টিভি নতুনের দামে আবার
         * বিক্রি হত, কেউ না দেখেই। ⓘ সারি ধরে ধরে, যাতে নিরীক্ষা প্রতিটা লেখে।
         */
        foreach ($gift->serials()->where('status', SerialNumber::SOLD)->get() as $piece) {
            $piece->update(['status' => SerialNumber::RETURNED, 'warehouse_id' => $gift->warehouse_id]);
        }

        $gift->returned_qty = (string) $gift->qty;
        $gift->save();
    }
}

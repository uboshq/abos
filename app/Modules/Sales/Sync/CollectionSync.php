<?php

declare(strict_types=1);

namespace App\Modules\Sales\Sync;

use App\Core\Contracts\AnswersAfterSync;
use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncBatch;
use App\Core\Engines\Sync\SyncPosition;
use App\Core\Engines\Sync\SyncRecord;
use App\Core\Engines\Sync\SyncRejection;
use App\Models\User;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Http\Controllers\CollectionApiController;
use Illuminate\Support\Carbon;

/**
 * মাঠ থেকে আদায় — নেট ছাড়া নেওয়া, নেট এলে বসানো।
 *
 * ── কেন এটা অর্ডারের পরে দ্বিতীয় push-যোগ্য জিনিস ─────────────────────
 * স্পেক §৫.১০ অফলাইনে চারটা জিনিস চায়; আজ কেবল অর্ডার যেত। ডিলার এলাকায়
 * সেলসম্যান টাকা তোলেন নেট ছাড়াই — সেই আদায়টা ফোনে বসে থাকে, নেট এলে
 * সার্ভারে আসে।
 *
 * ── ⚠️ কেন খসড়া হিসেবে বসে, নিশ্চিত হয়ে নয় ──────────────────────────
 * নিশ্চিত করা মানে টাকাটা খাতায় বসা (Dr নগদ / Cr গ্রাহক) আর বিলের
 * বরাদ্দ পাকা হওয়া। মাঠ থেকে আসা একটা আদায় নিজে থেকে ওই দুইটা ঘটিয়ে
 * ফেললে অফিস নগদটা হাতে পাওয়ার আগেই খাতা টাকা দেখাত। তাই ফোন
 * প্রতিশ্রুতিটা পৌঁছে দেয়; অফিস নগদ মিলিয়ে ওয়েব থেকে নিশ্চিত করেন —
 * ঠিক যেভাবে সবসময় হয়।
 *
 * ── ⚠️ কেন সরাসরি Collection::create() নয় ────────────────────────────
 * [[CollectionService]]-এর মধ্য দিয়েই যায়, আর সেটাই এখানকার সবচেয়ে
 * জরুরি সিদ্ধান্ত: টাকা কোন খাতে বসবে তার নিয়ম ([[CollectionService::resolveMoneyAccount()]]
 * — গ্রুপ খাত ও টাকা-নয় খাত ফেরায়, holding-খাত কেবল স্পষ্ট অনুমতিতে)
 * ওয়েব ও ফোন দুই দিকেই এক থাকে। ফোনের জন্য আলাদা সহজ পথ বানালে দুইটা
 * সত্যি তৈরি হত।
 *
 * ── ⛔ ২০ নভেম্বর ২০২৬ থেকে লেখা বন্ধ (মালিক, চূড়ান্ত: "টাকা ঢোকার কাগজ একটাই") ─────────────────
 * ফোনের পাঠানো নতুন আদায় কারণসহ ফেরে ([[apply()]]) — গ্রাহকের টাকার খবর যায় "টাকা জমার খবর" দিয়ে, অফিস মিলিয়ে রসিদ ভাউচার
 * কাটে। ⓘ আগে বসা আদায় ফোনে ফিরে আসে আগের মতোই ([[pull()]], [[answerFor()]])।
 */
final class CollectionSync implements SyncsToDevices, AnswersAfterSync
{

    public static function module(): string
    {
        return 'sales';
    }

    public static function entityType(): string
    {
        return 'Collection';
    }

    /**
     * আদায় *বসানোর* চাবি — কারণ এটা এখন push-ও পাহারা দেয়।
     *
     * ⓘ চাবিটা এখন দুই দিকেই কাজ করে: ফোন আদায় *বসাতে* (push) এটা লাগে,
     * আর নিজের তোলা আদায় *ফিরে দেখতেও* (pull)। বসানোর কাজটাই মুখ্য, তাই
     * `.view` নয় — `.create`, যা ওয়েবেও আদায় বানাতে লাগে।
     */
    public static function requiredPermission(): ?string
    {
        /*
         * ⭐ কেবল অফিসের লোক লেখেন — মালিক, ৭ অক্টোবর ২০২৬: "এটা কেবল অফিসের লোকদের জন্য থাকবে, আর ফিল্ডের জন্য থাকবে
         * payment request" (মাঠের টাকা যায় জমার বিজ্ঞপ্তিতে)। ⓘ আদায়ের চাবি SR-দেরও আছে, তাই লেখার দিকে আরেকটা চাবি —
         * খাতায় টাকা তোলার ([[requiredPushPermission()]]); দুটো মিলেই [[officeMayCollect()]]।
         */
        return 'sales.collection.create';
    }

    /** ⛔ ফোন থেকে লেখার চাবি — খাতায় টাকা তোলার, কেবল অফিসের লোকের (মালিক, ৭ অক্টোবর ২০২৬); আদায়ের চাবি পড়ার দিকে ([[SyncsToDevices::requiredPushPermission()]]) */
    public static function requiredPushPermission(): ?string
    {
        return 'accounts.voucher.create';
    }

    /** ⭐ ফোনে আদায় — আদায়ের চাবি, খাতায় টাকা তোলার চাবি, আর ডিলারে বাঁধা নন; /me আর সিঙ্ক দুই জায়গায় একই প্রশ্ন */
    public static function officeMayCollect(User $user): bool
    {
        /*
         * ⛔ কারো নয় — মালিক, ২০ নভেম্বর ২০২৬ (চূড়ান্ত: "টাকা ঢোকার কাগজ একটাই")। ফোনে নতুন আদায় লেখা বন্ধ ([[apply()]]); অফিসের
         * লোক টাকা নেন রসিদ ভাউচারে (ফোনের ভাউচারের দরজা, `accounts.voucher.create`), মাঠ খবর পাঠায় "টাকা জমার খবর" দিয়ে।
         * ⓘ নতুন অ্যাপ /me-র `mayCollect` মিথ্যা দেখে "আদায়" বোতাম আড়াল করে; হোমের দ্রুত-বোতামও যায় ([[QuickActionsSummary]])।
         */
        return false;
    }

    /**
     * ⛔ দেয়ালের বিক্রয়কর্মী ফোন থেকে নতুন আদায় পাঠাতে পারেন না — মালিকের উত্তর "খ", ৩ অক্টোবর ২০২৬
     * (⛔১৬)। ⓘ আগে ফোন থেকে আসা খসড়া আদায় বৈধই থাকে; অফিস সেগুলো আগের মতোই নিশ্চিত করে।
     */
    public static function walledRefusal(): string
    {
        return (string) __('customer::binding.office_takes_money');
    }

    /**
     * ফোনে নিজের তোলা আদায়গুলো ফিরে আসে — কোনটা পৌঁছেছে আর তার নম্বর কী।
     *
     * অফলাইনে লেখার সময় নম্বর ছিল না; সিঙ্কের পর সার্ভার নম্বর দেয়, আর
     * সেটা ফিরে না এলে সেলসম্যান জানতেন না কোন আদায়টা কী নম্বর পেল।
     *
     * @return list<SyncRecord>
     */
    public function pull(User $user, ?Carbon $since, int $limit, ?SyncPosition $after = null): SyncBatch
    {
        $query = Collection::query()
            ->with(['customer:id,public_id', 'account'])
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit);

        if ($since !== null) {
            $query->where('updated_at', '>', $since);
        }

        // ⭐ পরের পাতা — (সময়, id) জোড়ার পর থেকে ([[SyncPosition]], গ১৮)
        $after?->after($query, $query->qualifyColumn('updated_at'), $query->qualifyColumn('id'));

        $rows = $query->get();

        return SyncBatch::of($rows->map(fn (Collection $collection) => new SyncRecord(
            entityType: self::entityType(),
            entityId: (string) $collection->public_id,
            payload: [
                'id' => (string) $collection->public_id,
                'documentNo' => $collection->document_no,
                'customerId' => (string) ($collection->customer?->public_id ?? ''),
                'trxDate' => $collection->trx_date?->toDateString(),
                'amount' => (string) $collection->amount,
                'status' => $collection->status,
                // ⭐ ফোনের রসিদ — কোন খাতে, কী মাধ্যমে, কোন লেনদেন-নম্বরে (৭ অক্টোবর ২০২৬); পুরনো অ্যাপ বাড়তি ঘর উপেক্ষা করে
                'accountName' => $collection->account?->name(),
                'instrument' => $collection->instrument,
                'instrumentNo' => $collection->instrument_no,
                'narration' => $collection->narration,
            ],
            updatedAt: $collection->updated_at ?? $collection->created_at ?? now(),
        ))->all(), $rows->count(), $limit, $rows->isEmpty() ? null : SyncPosition::of($rows->last()->updated_at, $rows->last()->id));
    }

    public function acceptsPush(): bool
    {
        return true;
    }

    /**
     * ⭐ বসার সাথে সাথেই ফোনের রসিদ — নম্বর, অবস্থা, টাকা কথায়, রসিদের PDF, কোন কাগজে কত, কত অপ্রয়োগকৃত (a4-এর চুক্তি,
     * ২১ অক্টোবর ২০২৬; [[CollectionApiController::answer()]], ফোনের আদায়ের পাতার একই ঘর)।
     *
     * @return array<string, mixed>
     */
    public function answerFor(string $entityId): array
    {
        $collection = Collection::acrossBranches()->where('public_id', $entityId)->first();

        return $collection === null ? [] : CollectionApiController::answer($collection);
    }

    /**
     * ফোনের তোলা একটা আদায় — ⛔ ২০ নভেম্বর ২০২৬ থেকে আর বসে না, কারণসহ ফেরে।
     *
     * ── কেন সংশোধন আগে থেকেই নয় ─────────────────────────────────────────────
     * অফলাইনে বসা একটা আদায় সংশোধন করলে অফিসে ইতিমধ্যে হয়ে যাওয়া
     * বণ্টন **নীরবে চাপা পড়ত**, আর টাকার হিসাব দুই জায়গায় দুই রকম হত —
     * অর্ডারের চেয়েও এখানে দামটা বেশি। তাই সংশোধন নেটওয়ার্কে।
     */
    public function apply(User $user, PushedChange $change): string
    {
        if (! $change->isCreate()) {
            throw SyncRejection::conflict(__('sales::sync.collection_edit_needs_network'));
        }

        /*
         * ⛔ ফোন থেকে নতুন আদায় আর নয় — মালিক, ২০ নভেম্বর ২০২৬ (চূড়ান্ত: "টাকা ঢোকার কাগজ একটাই")। গ্রাহকের টাকার খবর ফোন পাঠায়
         * "টাকা জমার খবর" দিয়ে; অফিস ব্যাংকে মিলিয়ে গ্রহণ করলে রসিদ ভাউচার হয় ([[DepositClaimService::accept()]])।
         * ⓘ সৎ প্রত্যাখ্যান, কারণসহ (REJECTED, `message`) — পুরনো অ্যাপ বার্তাটা দেখায় আর আবার পাঠায় না; চুপচাপ ফেলে দিলে ফোন
         * "বসেছে" ভাবত, অপ্রত্যাশিত ভুল হলে চিরকাল আবার পাঠাত ([[SyncService::applyOne()]])। আগে বসা আদায় পড়া ([[pull()]]) আগের মতোই।
         */
        throw new SyncRejection(self::useTheDepositReport());
    }

    /** ⓘ ফোনের সব আদায়-লেখার দরজা একই কথা বলে — সিঙ্ক, প্রিভিউ, স্লিপ ([[CollectionApiController]]) */
    public static function useTheDepositReport(): string
    {
        return (string) __('sales::sync.collection_use_deposit_report');
    }
}

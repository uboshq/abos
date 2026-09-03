<?php

declare(strict_types=1);

namespace App\Modules\Sales\Sync;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncRecord;
use App\Core\Engines\Sync\SyncRejection;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionService;
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
 */
final class CollectionSync implements SyncsToDevices
{
    public function __construct(private readonly CollectionService $collections) {}

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
        return 'sales.collection.create';
    }

    /**
     * ফোনে নিজের তোলা আদায়গুলো ফিরে আসে — কোনটা পৌঁছেছে আর তার নম্বর কী।
     *
     * অফলাইনে লেখার সময় নম্বর ছিল না; সিঙ্কের পর সার্ভার নম্বর দেয়, আর
     * সেটা ফিরে না এলে সেলসম্যান জানতেন না কোন আদায়টা কী নম্বর পেল।
     *
     * @return list<SyncRecord>
     */
    public function pull(User $user, ?Carbon $since, int $limit): array
    {
        $query = Collection::query()
            ->with('customer:id,public_id')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit);

        if ($since !== null) {
            $query->where('updated_at', '>', $since);
        }

        return $query->get()->map(fn (Collection $collection) => new SyncRecord(
            entityType: self::entityType(),
            entityId: (string) $collection->public_id,
            payload: [
                'id' => (string) $collection->public_id,
                'documentNo' => $collection->document_no,
                'customerId' => (string) ($collection->customer?->public_id ?? ''),
                'trxDate' => $collection->trx_date?->toDateString(),
                'amount' => (string) $collection->amount,
                'status' => $collection->status,
                'narration' => $collection->narration,
            ],
            updatedAt: $collection->updated_at ?? $collection->created_at ?? now(),
        ))->all();
    }

    public function acceptsPush(): bool
    {
        return true;
    }

    /**
     * ফোনের তোলা একটা আদায় — সার্ভারের নিয়ম ধরে, খসড়া হিসেবে।
     *
     * ── কেন কেবল CREATE ─────────────────────────────────────────────
     * অফলাইনে বসা একটা আদায় সংশোধন করলে অফিসে ইতিমধ্যে হয়ে যাওয়া
     * বণ্টন **নীরবে চাপা পড়ত**, আর টাকার হিসাব দুই জায়গায় দুই রকম হত —
     * অর্ডারের চেয়েও এখানে দামটা বেশি। তাই সংশোধন নেটওয়ার্কে।
     */
    public function apply(User $user, PushedChange $change): string
    {
        if (! $change->isCreate()) {
            throw SyncRejection::conflict(__('sales::sync.collection_edit_needs_network'));
        }

        $payload = $change->payload();

        // বাইরের কী থেকে ভেতরের আইডি — ফোন কেবল public_id চেনে, ক্রমিক id নয়
        $customer = Customer::query()
            ->where('public_id', (string) ($payload['customerId'] ?? ''))
            ->first();

        if ($customer === null) {
            throw new SyncRejection(__('sales::sync.unknown_customer'));
        }

        $amount = (string) ($payload['amount'] ?? '0');

        if (! is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
            throw new SyncRejection(__('sales::sync.collection_needs_amount'));
        }

        /*
         * বিলভিত্তিক বরাদ্দ — ফোন public_id ধরে বিল দেখায়। বরাদ্দ না
         * এলে টাকাটা গ্রাহকের অগ্রিম হয়ে বসে (অফিস নিশ্চিত করার সময়)।
         */
        $lines = [];

        foreach ((array) ($payload['allocations'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $invoice = SalesInvoice::query()
                ->where('public_id', (string) ($line['invoiceId'] ?? ''))
                ->first();

            if ($invoice === null) {
                throw new SyncRejection(__('sales::sync.unknown_invoice'));
            }

            $lines[] = [
                'sales_invoice_id' => $invoice->id,
                'amount' => (string) ($line['amount'] ?? '0'),
            ];
        }

        /*
         * খাত ইচ্ছাকৃতভাবে দেওয়া হয় না — `account_id` null মানে
         * CollectionService প্রধান নগদ টিলে বসায় (মাঠের আদায় নগদ)। এতে
         * টাকার-খাতের নিয়মটা এড়ানো হয় না, বরং সেটার মধ্য দিয়েই যায়।
         * চেকের আদায় কাউন্টারের আলাদা পথ (১১০৪), এখানে নয়।
         */
        $collection = $this->collections->create([
            'customer_id' => $customer->id,
            'trx_date' => $payload['trxDate'] ?? now()->toDateString(),
            'amount' => $amount,
            'instrument' => 'cash',
            'narration' => $payload['narration'] ?? null,
        ], $lines);

        return (string) $collection->public_id;
    }
}

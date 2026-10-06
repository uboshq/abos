<?php

declare(strict_types=1);

namespace App\Modules\Sales\Sync;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncBatch;
use App\Core\Engines\Sync\SyncPosition;
use App\Core\Engines\Sync\SyncRecord;
use App\Core\Engines\Sync\SyncRejection;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
        /*
         * ⭐ কেবল অফিসের লোক — মালিক, ৭ অক্টোবর ২০২৬: "এটা কেবল অফিসের লোকদের জন্য থাকবে, আর ফিল্ডের জন্য থাকবে
         * payment request" (মাঠের টাকা যায় স্লিপসহ জমার অনুরোধে)। ⓘ আদায়ের চাবি SR-দেরও আছে, তাই সিঙ্কের চাবি খাতায় টাকা
         * তোলার চাবি; আদায়ের চাবি [[apply()]]-এ আলাদা দেখা হয় ([[officeMayCollect()]])।
         */
        return 'accounts.voucher.create';
    }

    /** ⭐ ফোনে আদায় — আদায়ের চাবি, খাতায় টাকা তোলার চাবি, আর ডিলারে বাঁধা নন; /me আর সিঙ্ক দুই জায়গায় একই প্রশ্ন */
    public static function officeMayCollect(User $user): bool
    {
        return $user->can('sales.collection.create')
            && $user->can('accounts.voucher.create')
            && ! app(\App\Core\Services\DealerScope::class)->walled($user);
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

        // ⛔ আদায়ের চাবিও লাগে — সিঙ্কের চাবি কেবল খাতায় টাকা তোলার ([[requiredPermission()]])
        if (! $user->can('sales.collection.create')) {
            throw new SyncRejection(__('sync.not_allowed_offline', ['type' => self::entityType()]));
        }

        $payload = $change->payload();

        // বাইরের কী থেকে ভেতরের আইডি — ফোন কেবল public_id চেনে, ক্রমিক id নয়
        $customer = Customer::query()
            ->where('public_id', (string) ($payload['customerId'] ?? ''))
            ->first();

        if ($customer === null) {
            throw new SyncRejection(__('sales::sync.unknown_customer'));
        }

        /*
         * ⭐ ফোনের আদায় — ওয়েবের আদায়-ফর্মের একই নিয়ম ([[CollectionRequest]]), একই সেবা ([[CollectionService]]);
         * মালিক, ৭ অক্টোবর ২০২৬: "অ্যাপে পেমেন্ট অপশন চালু করো"। ⓘ টাকার খাত (নগদ/ব্যাংক/MFS), মাধ্যম, লেনদেন-নম্বর,
         * তারিখ আর মন্তব্য — পুরনো অ্যাপ এগুলো পাঠায় না, তখন আগের মতো প্রধান টিলের নগদ।
         * ⓘ অঙ্ক আর লেখার ঘর কঠোরভাবে — "1e5" বা অ্যারে এলে ৫০০ নয়, কারণসহ ফেরত (একটা ভুল সারি গোটা সারি আটকায় না)।
         */
        $account = null;

        if (filled($payload['accountId'] ?? null)) {
            // ⛔ কেবল পোস্টযোগ্য খাত — দল-খাতে টাকা নয় ([[MoneyNeverLandsOnAGroupAccountTest]]); বাকি নিয়ম সেবার ([[MoneyAccountRule]])
            $account = Account::query()->postable()->where('public_id', (string) $payload['accountId'])->first();

            if ($account === null) {
                throw new SyncRejection(__('sales::sync.unknown_money_account'));
            }
        }

        $data = [
            'trx_date' => is_string($payload['trxDate'] ?? null) ? $payload['trxDate'] : now()->toDateString(),
            'amount' => is_scalar($payload['amount'] ?? null) ? (string) $payload['amount'] : '',
            'instrument' => $payload['instrument'] ?? null,
            'instrument_no' => $payload['instrumentNo'] ?? null,
            'narration' => $payload['narration'] ?? null,
        ];

        $check = Validator::make($data, [
            'trx_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'instrument' => ['nullable', 'string', 'max:32'],
            'instrument_no' => ['nullable', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        if ($check->fails()) {
            throw new SyncRejection((string) $check->errors()->first());
        }

        /*
         * অন্য অংশের মতো এখানেও: প্রতিটা বণ্টনের বিল আসে বাইরের কী দিয়ে।
         * অচেনা বিল মানে এই পরিবর্তন প্রত্যাখ্যাত — অর্ধেক বণ্টন বসিয়ে
         * বাকিটা নীরবে বাদ দেওয়া হিসাব গুলিয়ে দেয়।
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

        try {
            $collection = $this->collections->create([
                'customer_id' => $customer->id,
                'trx_date' => $data['trx_date'],
                'amount' => $data['amount'],
                'account_id' => $account?->id,
                // ⓘ পুরনো অ্যাপ মাধ্যম পাঠায় না — তখন আগের মতো নগদ
                'instrument' => $data['instrument'] ?? ($account === null ? 'cash' : null),
                'instrument_no' => $data['instrument_no'],
                'narration' => $data['narration'],
            ], $lines);
        } catch (ValidationException $refused) {
            throw new SyncRejection((string) collect($refused->errors())->flatten()->first());
        }

        /*
         * ⭐ "এখনই নিশ্চিত" — ওয়েবের দুই চাপ (লেখা, তারপর নিশ্চিত) এক চাপে; চাবিও একই (`sales.collection.create`)।
         * ⓘ অনুমোদনের অপেক্ষায় ([[HeldForApproval]]) বা টাকার কোনো নিয়মে আটকালে খসড়াই থাকে — ওয়েবে যেমন থাকে;
         * অনুরোধটাও থাকে, ফোন পরে আদায়ের অবস্থা দেখে বলে কেন।
         */
        if (($payload['confirm'] ?? false) === true) {
            try {
                $collection = $this->collections->confirm($collection->fresh(['lines']));
            } catch (ValidationException) {
                // ⓘ খসড়া থাকল — ওয়েবের একই আচরণ
            }
        }

        return (string) $collection->public_id;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\Sync;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncRecord;
use App\Core\Engines\Sync\SyncRejection;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Illuminate\Support\Carbon;

/**
 * অর্ডার — **নেট ছাড়া লেখা যায় এমন একমাত্র জিনিস।**
 *
 * ── মালিকের সিদ্ধান্ত, ২ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"mobile app e net na thakle sudu order patate parbe"* — চালান, বিল,
 * আদায় বা POS নয়।
 *
 * কারণটা কারিগরি সুবিধা নয়, হিসাবের সততা। অফলাইনে চারটা জিনিস অন্ধ:
 *
 *   নম্বরের সিরিজ  · দুইটা ফোন একই নম্বর দিত
 *   তাকের মজুদ     · দুইজন একই শেষ কার্টনটা বেচতেন
 *   ক্রেডিট সীমা   · যাঁর টাকা আটকে তাঁকেই আরও মাল
 *   চলতি দাম       · পুরনো দামে বিল
 *
 * **অর্ডার এই চারটার একটাতেও হাত দেয় না** — ওটা প্রতিশ্রুতি, দাখিলা নয়।
 * নম্বর, মজুদ, সীমা আর দাম — চারটাই যাচাই হয় সিঙ্কের মুহূর্তে, সার্ভারে,
 * ঠিক যেভাবে ওয়েব থেকে বসালে হত।
 *
 * ── নম্বর কখন বসে ───────────────────────────────────────────────────
 * সিঙ্কের সময়, সার্ভারে ([[SalesOrderService::create()]] → `numbers->next('SO')`)।
 * মালিকের বাছাই: **(ক)**।
 *
 * বিকল্পটা ছিল ফোনে আগে থেকে নম্বর বরাদ্দ করা — সেলসম্যান তখন দোকানদারকে
 * নম্বরটা বলতে পারতেন। কিন্তু অব্যবহৃত নম্বরগুলো সিরিজে **ফাঁক** রেখে
 * যেত, আর অডিটে প্রতিটা ফাঁক ব্যাখ্যা করতে হয়। অর্ডারে দোকানদারের কোনো
 * কাগজ লাগে না, তাই দামটা মেটানোর মতো নয়।
 *
 * ── ⚠️ কেন সরাসরি `SalesOrder::create()` নয় ─────────────────────────
 * [[SalesOrderService]]-এর ভেতর দিয়েই যাওয়া হয়, আর সেটাই এই ফাইলের
 * সবচেয়ে গুরুত্বপূর্ণ সিদ্ধান্ত। এখানে নিয়মগুলো আবার লিখলে **ওয়েব আর
 * ফোন দুইটা আলাদা উত্তর দিত**, আর কোনটা সত্যি তা বলার উপায় থাকত না।
 * নম্বরের row lock, অর্থবছর, মজুদ সংরক্ষণ — সব ওখানে, আর ওখানেই থাকে।
 */
final class SalesOrderSync implements SyncsToDevices
{
    public function __construct(private readonly SalesOrderService $orders) {}

    public static function module(): string
    {
        return 'sales';
    }

    public static function entityType(): string
    {
        return 'SalesOrder';
    }

    /**
     * ওয়েবের অর্ডার-তালিকা যে চাবি চায়, ঠিক সেটাই।
     */
    public static function requiredPermission(): ?string
    {
        return 'sales.order.view';
    }

    /**
     * ফোনে নিজের অর্ডারগুলো ফিরে আসে — সেলসম্যান দেখতে পান কোনটা পৌঁছেছে
     * আর তার নম্বর কী হলো।
     *
     * ── কেন নম্বরটা ফেরত পাঠানো জরুরি ───────────────────────────────
     * অফলাইনে লেখার সময় নম্বর ছিল না (উপরের সিদ্ধান্ত (ক))। সিঙ্কের পর
     * নম্বরটা তৈরি হয়, আর সেটা ফোনে **ফিরে না এলে** সেলসম্যান কোনোদিন
     * জানতেন না কোন অর্ডারটা কী নম্বর পেল — দোকানে গিয়ে বলার মতো কিছু
     * থাকত না।
     *
     * @return list<SyncRecord>
     */
    public function pull(User $user, ?Carbon $since, int $limit): array
    {
        $query = SalesOrder::query()
            ->with('customer:id,public_id')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit);

        if ($since !== null) {
            $query->where('updated_at', '>', $since);
        }

        return $query->get()->map(fn (SalesOrder $order) => new SyncRecord(
            entityType: self::entityType(),
            entityId: (string) $order->public_id,
            payload: [
                'id' => (string) $order->public_id,
                'documentNo' => $order->document_no,
                'customerId' => (string) ($order->customer?->public_id ?? ''),
                'trxDate' => $order->trx_date?->toDateString(),
                'deliverOn' => $order->deliver_on?->toDateString(),
                'status' => $order->status,
                'total' => (string) $order->total,
                'narration' => $order->narration,
            ],
            updatedAt: $order->updated_at ?? $order->created_at ?? now(),
        ))->all();
    }

    public function acceptsPush(): bool
    {
        return true;
    }

    /**
     * ফোনের লেখা একটা অর্ডার — সার্ভারের নিয়ম ধরে বসানো।
     *
     * ── কেন কেবল CREATE ─────────────────────────────────────────────
     * অফলাইনে একটা অর্ডার **সংশোধন** করার মানে হলো ফোনে থাকা কপিটা
     * সার্ভারের কপির চেয়ে পুরনো কি না তা জানা — আর অফলাইনে সেটা জানা
     * যায় না। অফিসে কেউ ইতিমধ্যে পরিমাণ কমিয়ে থাকলে ফোনের সংশোধনটা
     * সেটা **নীরবে চাপা দিত**।
     *
     * তাই সংশোধন নেটওয়ার্কে এসে — একটা সীমা, কিন্তু সৎ সীমা।
     */
    public function apply(User $user, PushedChange $change): string
    {
        if (! $change->isCreate()) {
            throw SyncRejection::conflict(__('sales::sync.order_edit_needs_network'));
        }

        $payload = $change->payload();

        /*
         * বাইরের কী থেকে ভেতরের আইডি।
         *
         * ফোন কখনো ভেতরের ক্রমিক `id` দেখে না — সে `public_id` (UUID)
         * ধরে কথা বলে, ঠিক যেমন `CustomerSync` পাঠায়। ক্রমিক আইডি
         * পাঠালে গোনা যেত: "আমার আগে কতজন গ্রাহক ছিল"।
         */
        $customer = Customer::query()
            ->where('public_id', (string) ($payload['customerId'] ?? ''))
            ->first();

        if ($customer === null) {
            throw new SyncRejection(__('sales::sync.unknown_customer'));
        }

        $lines = [];

        foreach ((array) ($payload['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $product = Product::query()
                ->where('public_id', (string) ($line['productId'] ?? ''))
                ->first();

            if ($product === null) {
                throw new SyncRejection(__('sales::sync.unknown_product'));
            }

            $lines[] = [
                'product_id' => $product->id,
                'ordered_qty' => (string) ($line['qty'] ?? '0'),

                /*
                 * দর ফোন পাঠায়, কিন্তু সেটাই শেষ কথা নয় — সার্ভারের
                 * দর-সহনশীলতার নিয়ম ([[PricingRule]]) নিশ্চিত করার সময়
                 * এটা মাপে। অফলাইনে ফোনে বসে থাকা দামটা পুরনো হতে
                 * পারে, আর সেই ক্ষেত্রেই নিয়মটা কাজে লাগে।
                 */
                'rate' => (string) ($line['rate'] ?? '0'),
                'discount' => (string) ($line['discount'] ?? '0'),
            ];
        }

        if ($lines === []) {
            throw new SyncRejection(__('sales::sync.order_has_no_lines'));
        }

        /*
         * খসড়া হিসেবে বসে, নিশ্চিত হয়ে নয় — আর এটাই ইচ্ছাকৃত।
         *
         * নিশ্চিত করা মানে মজুদ সংরক্ষিত হওয়া আর ক্রেডিট সীমা যাচাই
         * হওয়া। মাঠ থেকে আসা একটা অর্ডার নিজে থেকে ওই দুইটা ঘটিয়ে
         * ফেললে অফিসের কেউ কিছু দেখার আগেই তাক থেকে মাল সরে যেত।
         *
         * অফিস অর্ডারটা দেখে নিশ্চিত করেন — ওয়েব থেকে, যেভাবে সবসময়
         * হয়। ফোনের কাজ প্রতিশ্রুতিটা পৌঁছে দেওয়া, সিদ্ধান্ত নেওয়া নয়।
         */
        $order = $this->orders->create([
            'customer_id' => $customer->id,
            'trx_date' => $payload['trxDate'] ?? now()->toDateString(),
            'deliver_on' => $payload['deliverOn'] ?? null,
            'narration' => $payload['narration'] ?? null,
        ], $lines);

        return (string) $order->public_id;
    }
}

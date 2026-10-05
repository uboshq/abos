<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\FreeGoodsOffers;
use App\Core\Support\CompanyContext;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\DepositClaim;

/**
 * অর্ডার পাঠানোর আগে গ্রাহকের টাকার ছবি — মালিকের নির্দেশ, ১ অক্টোবর ২০২৬।
 *
 * ── ⭐ নিয়ম ─────────────────────────────────────────────────────────────
 * *"অর্ডার পাঠাতে কোনো সীমা নেই, শুধু সতর্কতা দেবে — সীমা অতিক্রম করলে ডেলিভারি হবে না, সীমা
 * অতিক্রমের কারো অনুমতি নেই, এমনকি মালিকেরও নেই। তাই টাকা দেন আগে, তারপর অর্ডার নিশ্চিত।"*
 * পাঠানোর মুহূর্তে দেখাতে হবে: কত জমা আছে, কত দিতে হবে, আর কত অনুমোদনের অপেক্ষায়।
 *
 * ⓘ একটাই উৎস — ওয়েবের অর্ডার ও DO পর্দা আর ফোন একই সংখ্যা পায়। দেয়ালটা এখানে নয়, দেয়াল
 * [[CreditExposure::assertRoom()]]-এ (চালান/DO নিশ্চিত, বিল) আর সে একই অংশগুলো গোনে: খাতার বাকি
 * + আটকে থাকা (বিল-না-হওয়া চালান, খসড়া বিল)। ⇒ এখানে "দিতে হবে" যা বলে, দেয়াল ঠিক সেটাই চায়।
 *
 * ⚠️ অনুমোদনের অপেক্ষার জমা (জমার দাবি) **দেয়ালে গোনা হয় না** — অফিস মেনে নিলেই খাতায় বসে আর তখনই
 * জায়গা খোলে। তাই আলাদা করে দেখানো হয়, "দিতে হবে" থেকে বাদ দিয়ে নয়।
 * ⓘ সীমা গোটা কোম্পানির ([[Customer::wouldExceedCreditLimit()]]) — শাখা ধরে নয়। সীমা ০ মানে বাকি নেই।
 */
final class OrderStanding
{
    public function __construct(
        private readonly CreditExposure $exposure,
        private readonly FreeGoodsOffers $free,
    ) {}

    /**
     * @return array{due: string, advance: string, held: string, limit: string, pending_claims: string,
     *               pending_claim_count: int, order_total: string, exposure: string, to_pay: string, over_limit: bool,
     *               stop_reason: ?string, stop: bool}
     */
    public function for(Customer $customer, string $orderTotal = '0'): array
    {
        $orderTotal = bccomp($orderTotal, '0', 4) > 0 ? bcadd($orderTotal, '0', 4) : '0.0000';

        $ledger = bcadd($customer->outstanding(), '0', 4);
        $due = bccomp($ledger, '0', 4) > 0 ? $ledger : '0.0000';
        $advance = bccomp($ledger, '0', 4) < 0 ? bcmul($ledger, '-1', 4) : '0.0000';

        $held = bcadd($this->exposure->pending($customer), '0', 4);
        $limit = bcadd((string) ($customer->credit_limit ?? '0'), '0', 4);

        $claims = DepositClaim::query()
            ->where('customer_id', $customer->id)
            ->pending()
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as n')
            ->first();

        // ⓘ জমা (অগ্রিম) খাতার বাকিতেই কাটা — `outstanding()` ঋণাত্মক হয়ে আসে
        $exposure = bcadd(bcadd($ledger, $held, 4), $orderTotal, 4);
        $over = bcsub($exposure, $limit, 4);

        /*
         * ⭐ বাকি বন্ধের কথা — দেয়ালের একই প্রশ্ন ([[CreditExposure::stopsFor()]]), ৫ অক্টোবর ২০২৬। `stop` = এই অর্ডারে
         * নতুন বাকি জন্মায় (অগ্রিম যতটা ঢাকে ততটা বাকি নয়), তাই নিশ্চিত হবে না; `stop_reason` = কারণটা, অঙ্ক ছাড়াই।
         */
        $reason = $this->exposure->stopFor($customer);
        $new = bccomp($orderTotal, $exposure, 4) < 0 ? $orderTotal : $exposure;

        return [
            'due' => $due,
            'advance' => $advance,
            'held' => $held,
            'limit' => $limit,
            'pending_claims' => bcadd((string) ($claims?->total ?? '0'), '0', 4),
            'pending_claim_count' => (int) ($claims?->n ?? 0),
            'order_total' => $orderTotal,
            'exposure' => $exposure,
            'to_pay' => bccomp($over, '0', 4) > 0 ? $over : '0.0000',
            'over_limit' => bccomp($over, '0', 4) > 0,
            'stop_reason' => $reason,
            'stop' => $reason !== null && bccomp($new, '0', 4) > 0,
        ];
    }

    /**
     * প্রতিটা লাইনের অফার আর কয়টা ফ্রি — অর্ডারের পর্দার "ফ্রি" ঘরের জন্য।
     *
     * ⓘ হিসাব অফারের মডিউলের ([[FreeGoodsOffers]]); এখানে কেবল লাইনের প্রসঙ্গ — ঠিক যেভাবে
     * চালানের পর্দা বানায় ([[ChallanOffers::contextOf()]]), যাতে অর্ডারে যা দেখায় চালানে তা-ই খাটে।
     *
     * @param  list<array{product: Product, qty: string, rate?: ?string}>  $lines
     * @return list<array{product_id: int, qty: string, free_qty: string, offer: ?string, offers: list<array<string, mixed>>}>
     */
    public function offers(Customer $customer, array $lines, ?int $warehouseId = null): array
    {
        $out = [];

        foreach ($lines as $line) {
            $product = $line['product'];
            $qty = bcadd((string) $line['qty'], '0', 4);
            // ⓘ দর না এলে এই ডিলারের দর তালিকার দাম ([[SalesPrice]], ৫ অক্টোবর ২০২৬)
            $rate = (string) ($line['rate'] ?? app(SalesPrice::class)->for($customer, $product)->price);

            $offers = bccomp($qty, '0', 4) > 0 ? $this->free->forLine([
                'customer_id' => (int) $customer->id,
                'party_type_id' => $customer->party_type_id !== null ? (int) $customer->party_type_id : null,
                'location_id' => $customer->location_id !== null ? (int) $customer->location_id : null,
                'branch_id' => CompanyContext::branchId(),
                'warehouse_id' => $warehouseId,
                'product_id' => (int) $product->id,
                'category_id' => $product->category_id !== null ? (int) $product->category_id : null,
                'brand_id' => $product->brand_id !== null ? (int) $product->brand_id : null,
                'qty' => $qty,
                'value' => bcmul($qty, $rate, 4),
            ]) : [];

            // ⓘ "ফ্রি" ঘরে কেবল একই পণ্যের ফ্রি; অন্য পণ্যের উপহার তালিকায় নাম ধরে থাকে
            $same = array_filter($offers, fn (array $o) => $o['gift_product_id'] === null || $o['gift_product_id'] === (int) $product->id);

            $out[] = [
                'product_id' => (int) $product->id,
                // ⓘ ফোন ক্রমিক id চেনে না — public_id দিয়ে মেলায়
                'product' => (string) $product->public_id,
                'qty' => $qty,
                'free_qty' => array_reduce($same, fn (string $sum, array $o) => bcadd($sum, $o['free_qty'], 4), '0.0000'),
                'offer' => $offers[0]['text'] ?? null,
                'offers' => array_values($offers),
            ];
        }

        return $out;
    }
}

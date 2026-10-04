<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * একটা বিক্রয় আদেশের চালান আর বিলের অগ্রগতি — মাথায় আর প্রতি লাইনে।
 *
 * ── ⭐ মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান (পরিকল্পনার §৫.১; নকশা "DO বিক্রয় আদেশে মেশানো" §১.২) ─────────────
 * অবস্থা ([[SalesOrderStatus]]) আদেশের নিজের ঘরে লেখা; তার পাশে দুইটা অগ্রগতি — চালান (`delivery_status`) আর বিল
 * (`billing_status`), প্রতিটা none | partial | full — আর ব্যাক অর্ডার।
 *
 * ── ⚠️ একটাই সত্যি ─────────────────────────────────────────────────────────────────────────
 * ⓘ অগ্রগতি **গোনা** হয় চালানের সারি (`sales_order_line_id`) আর বিলের সারি (`delivery_challan_line_id` → চালানের সারি)
 * থেকে। [[compute()]] প্রতিবার গোনে (পাতা আর তালিকা এটাই দেখায়); [[refresh()]] একই গোনা আদেশ আর লাইনের ঘরে লেখে —
 * ⛔ ঘর দুইটা আর কেউ হাতে লেখে না। ⓘ চালান নিশ্চিত/বাতিল আর বিল পাকা/বাতিলের লেনদেনে [[refresh()]] ডাকা নকশার ধাপ ৫
 * (abos-86); তার আগে ঘরগুলো কেবল বন্ধের মুহূর্তে লেখা হয়, আর পর্দা সবসময় [[compute()]] পড়ে — তাই কোথাও বাসি সংখ্যা ফোটে না।
 *
 * ── ⓘ সংখ্যাগুলো কোথা থেকে ─────────────────────────────────────────────────────────────
 * "গেছে" = নিশ্চিত চালানের মাল — অর্ডার তালিকার ট্যাবের হুবহু নিয়ম ([[OrderTracking]]), যাতে ট্যাব আর চিপ এক কথা বলে।
 * "বিল" = পাকা বিলের পরিমাণ (খসড়া বিল বিল নয়; বাতিল আর মোছা বাদ)।
 * "চূড়ান্ত" = `ordered_qty − rejected_qty` — বাকিটা "আর দেওয়া হবে না" বললে সেটুকু আর পাওনা নয় (SAP-এর reason for rejection)।
 * ব্যাক অর্ডার (`ledger` আদেশ) = মালিকের ঠিক করা ট্যাবের সংজ্ঞা (৩ অক্টোবর ২০২৬): নিশ্চিত আদেশের লাইনের বাকি মাল আদেশের
 * গুদামের তাকের চেয়ে বেশি। ⚠️ `holds` আদেশে সংজ্ঞাটা হোল্ডের সারি থেকে (নকশা §১.২) — abos-86-এর হোল্ড-টেবিল এলে
 * [[backOrderedByShelf()]]-এর পাশে বসবে (abos-86-এর সীমানা); আজ কোনো `holds` আদেশ নেই, তাই তাদের ব্যাক অর্ডার আজ "না"।
 *
 * ⚠️ তালিকার পঞ্চাশটা আদেশে চারটা কোয়েরি, আদেশপ্রতি নয় ([[compute()]])।
 */
final class OrderProgress
{
    /**
     * ⭐ কত দিন পড়ে থাকলে খসড়া "পুরনো" — পরিকল্পনার §৪.৩: *"৩ দিন পড়ে থাকলে তালিকায় লাল"*।
     */
    public const STALE_DAYS = 3;

    /**
     * একটা আদেশের অগ্রগতি — [[compute()]]-এর একটা সারি।
     *
     * @return array{status: string, delivery: string, billing: string, back: bool, stale: bool, age_days: int, lines: array<int, array{line_status: string, delivery: string, billing: string, back: bool, ordered: string, target: string, delivered: string, billed: string, open: string}>}
     */
    public function of(SalesOrder $order): array
    {
        return $this->compute([$order])[(int) $order->id];
    }

    /**
     * অনেক আদেশের অগ্রগতি একবারে — তালিকার পাতার জন্য।
     *
     * @param  iterable<SalesOrder>  $orders
     * @return array<int, array{status: string, delivery: string, billing: string, back: bool, stale: bool, age_days: int, lines: array<int, array{line_status: string, delivery: string, billing: string, back: bool, ordered: string, target: string, delivered: string, billed: string, open: string}>}>
     */
    public function compute(iterable $orders): array
    {
        $orders = EloquentCollection::make($orders)->filter()->values();

        if ($orders->isEmpty()) {
            return [];
        }

        $orders->loadMissing('lines');

        $companies = $orders->pluck('company_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
        $lineIds = $orders->flatMap(fn (SalesOrder $o) => $o->lines->pluck('id'))->map(fn ($id) => (int) $id)->all();

        $delivered = $this->deliveredByLine($companies, $lineIds);
        $billed = $this->billedByLine($companies, $lineIds);

        // ⓘ তাকের মাল কেবল সেই নিশ্চিত `ledger` আদেশগুলোর পণ্যের — নাহলে কোয়েরিই নয়
        $shelf = $this->shelfFor($companies, $orders
            ->filter(fn (SalesOrder $o) => $this->backOrderedByShelf($o))
            ->flatMap(fn (SalesOrder $o) => $o->lines->pluck('product_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all());

        $out = [];

        foreach ($orders as $order) {
            $lines = [];

            foreach ($order->lines as $line) {
                $ordered = bcadd((string) $line->ordered_qty, '0', 4);
                $rejected = bcadd((string) ($line->rejected_qty ?? '0'), '0', 4);
                $target = bccomp($rejected, $ordered, 4) >= 0 ? '0.0000' : bcsub($ordered, $rejected, 4);
                $gone = bcadd($delivered[(int) $line->id] ?? '0', '0', 4);
                $billedQty = bcadd($billed[(int) $line->id] ?? '0', '0', 4);
                $open = bccomp($gone, $target, 4) >= 0 ? '0.0000' : bcsub($target, $gone, 4);

                $onShelf = $order->warehouse_id === null
                    ? ($shelf[(int) $line->product_id]['*'] ?? '0')
                    : ($shelf[(int) $line->product_id][(int) $order->warehouse_id] ?? '0');

                $lines[(int) $line->id] = [
                    'line_status' => (string) ($line->line_status ?? SalesOrderStatus::LINE_OPEN),
                    'delivery' => self::progress($gone, $target),
                    'billing' => self::progress($billedQty, $target),
                    'back' => $this->backOrderedByShelf($order)
                        && bccomp($open, '0', 4) > 0
                        && bccomp($open, $onShelf, 4) > 0,
                    'ordered' => $ordered,
                    'target' => $target,
                    'delivered' => $gone,
                    'billed' => $billedQty,
                    'open' => $open,
                ];
            }

            $out[(int) $order->id] = [
                'status' => (string) $order->status,
                'delivery' => self::overall($lines, 'delivery'),
                'billing' => self::overall($lines, 'billing'),
                'back' => in_array(true, array_column($lines, 'back'), true),
                'stale' => self::isStale($order),
                'age_days' => self::ageInDays($order),
                'lines' => $lines,
            ];
        }

        return $out;
    }

    /**
     * ⭐ গোনা অগ্রগতি আদেশ আর লাইনের ঘরে লেখা — এই ঘর দুইটার একমাত্র লেখক।
     *
     * ⓘ মডেলের ঘটনা ছাড়া, কোয়েরি দিয়ে: অগ্রগতি কাগজের লেখা নয়, তাই নিরীক্ষার খাতায় প্রতিটা চালানে একটা "বদল" সারি
     * জমানোর মানে নেই — সত্যিটা চালান আর বিলের সারিতেই আছে।
     *
     * @return array{status: string, delivery: string, billing: string, back: bool, stale: bool, age_days: int, lines: array<int, array<string, mixed>>}
     */
    public function refresh(SalesOrder $order): array
    {
        $now = $this->of($order->loadMissing('lines'));

        SalesOrder::query()->withoutGlobalScopes()->whereKey($order->getKey())->update([
            'delivery_status' => $now['delivery'],
            'billing_status' => $now['billing'],
        ]);

        foreach ($now['lines'] as $lineId => $line) {
            SalesOrderLine::query()->withoutGlobalScopes()->whereKey($lineId)->update([
                'delivery_status' => $line['delivery'],
                'billing_status' => $line['billing'],
            ]);
        }

        $order->forceFill(['delivery_status' => $now['delivery'], 'billing_status' => $now['billing']])->syncOriginal();

        return $now;
    }

    /**
     * একটা পরিমাণ চূড়ান্তের কতটা — none | partial | full।
     *
     * ⓘ চূড়ান্ত ০ (পুরোটা "আর দেওয়া হবে না") আর কিছু গেছে → পুরো; কিছুই যায়নি → none।
     */
    public static function progress(string $done, string $target): string
    {
        return match (true) {
            bccomp($done, '0', 4) <= 0 => SalesOrderStatus::NONE,
            bccomp($done, $target, 4) >= 0 => SalesOrderStatus::FULL,
            default => SalesOrderStatus::PARTIAL,
        };
    }

    /**
     * মাথার অগ্রগতি — লাইনগুলো থেকে।
     *
     * ⓘ "পুরো" মানে **প্রতিটা** লাইন পুরো; একটা লাইনেও কিছু গেলে "আংশিক"। ⛔ মোট পরিমাণ যোগ করে মাপলে একটা লাইনে বেশি
     * যাওয়া মাল আরেকটা লাইনের ঘাটতি ঢেকে দিত — মাথা বলত "পুরো চালান", অথচ একটা পণ্য যায়ইনি।
     * ⓘ যে লাইনের পুরোটা "আর দেওয়া হবে না" আর কিছুই যায়নি, সে মাপে আসে না — তার কাছে কিছু পাওনা নেই।
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function overall(array $lines, string $key): string
    {
        $counted = array_values(array_filter(
            $lines,
            fn (array $l) => bccomp((string) $l['target'], '0', 4) > 0 || $l[$key] !== SalesOrderStatus::NONE,
        ));

        if ($counted === []) {
            return SalesOrderStatus::NONE;
        }

        $states = array_column($counted, $key);

        return match (true) {
            array_diff($states, [SalesOrderStatus::FULL]) === [] => SalesOrderStatus::FULL,
            array_diff($states, [SalesOrderStatus::NONE]) !== [] => SalesOrderStatus::PARTIAL,
            default => SalesOrderStatus::NONE,
        };
    }

    /**
     * ⭐ পুরনো খসড়া — [[STALE_DAYS]] দিন বা তার বেশি খসড়া অবস্থায় পড়ে আছে।
     *
     * ⓘ বয়স মাপা হয় লেখার মুহূর্ত (`created_at`) থেকে — কাগজের তারিখ নয়, কারণ সেটা হাতে বসানো যায়।
     * ⚠️ জমা দেওয়া আদেশ (`submitted` আর পরের সব) এখানে নেই: সে কারো সই বা টাকার অপেক্ষায়, লেখকের ভুলে পড়ে নেই।
     * ⛔ নিয়মটা [[OrderTracking::applyListTab()]]-এর "পুরনো খসড়া" ট্যাবেরও — দুই জায়গায় একই কাটা-সময় ([[staleCutoff()]])।
     */
    public static function isStale(SalesOrder $order): bool
    {
        return $order->status === SalesOrderStatus::DRAFT
            && $order->created_at !== null
            && $order->created_at->lte(self::staleCutoff());
    }

    /** এর আগে লেখা খসড়া "পুরনো"। */
    public static function staleCutoff(): Carbon
    {
        return now()->subDays(self::STALE_DAYS);
    }

    public static function ageInDays(SalesOrder $order): int
    {
        return $order->created_at === null ? 0 : (int) floor($order->created_at->diffInDays(now(), true));
    }

    /**
     * ব্যাক অর্ডার তাক দিয়ে মাপা হবে কি না — নিশ্চিত, আজকের (`ledger`) নিয়মের আদেশ।
     */
    private function backOrderedByShelf(SalesOrder $order): bool
    {
        return $order->status === SalesOrderStatus::CONFIRMED
            && (string) ($order->hold_mode ?? SalesOrderStatus::HOLD_LEDGER) === SalesOrderStatus::HOLD_LEDGER;
    }

    /**
     * লাইনপ্রতি কতটা গেছে — নিশ্চিত চালানের মাল ([[OrderTracking]]-এর ট্যাবের হুবহু নিয়ম)।
     *
     * @param  list<int>  $companies
     * @param  list<int>  $lineIds
     * @return array<int, string>
     */
    private function deliveredByLine(array $companies, array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }

        return DB::table('sal_challan_lines as cl')
            ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
            ->whereIn('c.company_id', $companies)
            ->where('c.status', DocumentStatus::CONFIRMED)
            ->whereIn('cl.sales_order_line_id', $lineIds)
            ->groupBy('cl.sales_order_line_id')
            ->selectRaw('cl.sales_order_line_id as line_id, COALESCE(SUM(cl.delivered_qty), 0) as qty')
            ->pluck('qty', 'line_id')
            ->mapWithKeys(fn ($qty, $id) => [(int) $id => (string) $qty])
            ->all();
    }

    /**
     * লাইনপ্রতি কতটার বিল হয়েছে — পাকা বিল, চালানের সারি ধরে।
     *
     * @param  list<int>  $companies
     * @param  list<int>  $lineIds
     * @return array<int, string>
     */
    private function billedByLine(array $companies, array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }

        return DB::table('sal_invoice_lines as il')
            ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
            ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
            ->whereIn('i.company_id', $companies)
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', DocumentStatus::POSTED)
            ->whereIn('cl.sales_order_line_id', $lineIds)
            ->groupBy('cl.sales_order_line_id')
            ->selectRaw('cl.sales_order_line_id as line_id, COALESCE(SUM(il.qty), 0) as qty')
            ->pluck('qty', 'line_id')
            ->mapWithKeys(fn ($qty, $id) => [(int) $id => (string) $qty])
            ->all();
    }

    /**
     * তাকের মাল (`floor`) — পণ্য আর গুদাম ধরে, আর `*`-এ সব গুদাম মিলিয়ে।
     *
     * ⚠️ তাক, বিক্রয়যোগ্য নয় — আদেশ নিশ্চিত হলে মালটা এই আদেশের নামেই ধরা থাকে; "বিক্রয়যোগ্য" দিয়ে মাপলে প্রতিটা
     * নিশ্চিত আদেশ নিজের ধরা মালের জন্যই ব্যাক অর্ডার দেখাত ([[OrderTracking]]-এর একই যুক্তি)।
     *
     * @param  list<int>  $companies
     * @param  list<int>  $productIds
     * @return array<int, array<int|string, string>>
     */
    private function shelfFor(array $companies, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $out = [];

        $rows = DB::table('inv_stock_movements')
            ->whereIn('company_id', $companies)
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id', 'warehouse_id')
            ->selectRaw('product_id, warehouse_id, COALESCE(SUM(floor_change), 0) as qty')
            ->get();

        foreach ($rows as $row) {
            $product = (int) $row->product_id;
            $out[$product][(int) $row->warehouse_id] = bcadd((string) $row->qty, '0', 4);
            $out[$product]['*'] = bcadd($out[$product]['*'] ?? '0', (string) $row->qty, 4);
        }

        return $out;
    }
}

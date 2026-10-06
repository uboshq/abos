<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\DocumentStatus;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryEvent;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Facades\DB;

/**
 * ক্রেতা কম নিলে বাকিটা নিজে ফেরত — মালিক, ২ অক্টোবর ২০২৬ ([[docs/বিক্রয়ের কাজের ধারা — ২ অক্টোবর.md]] §৪)।
 *
 * *"ডিলার কম পেলে — ডেলিভারি নিশ্চিতে কম পরিমাণ লিখলে বাকিটা নিজে ফেরত হয়"*।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * "আংশিক পৌঁছেছে" কেবল পরিমাণটা লিখে রাখত। বিলে পুরো অঙ্কই থাকত, মাল খাতায় বেরিয়েই থাকত, আর ট্রিপ বন্ধ
 * হত না যতক্ষণ না কেউ হাতে ফেরত লেখেন ([[ShipmentService::close()]])। ক্রেতার দেনা না-পাওয়া মালের দামসহ।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * আংশিক পৌঁছানোর একই লেনদেনে একটা ফেরত — বিলের সারি ধরে, না-নেওয়া পরিমাণ, যে লট থেকে মাল বেরিয়েছিল সেই লটে।
 * ফেরতের সাধারণ পথই ([[SalesReturnService]]) — দর বিল থেকে, খাতা উল্টো, মাল গুদামে। ⓘ কোম্পানির ফেরতের ছকে সই
 * লাগলে ফেরতটা সইয়ের অপেক্ষায় থাকে (মালিকের নিয়ম: গেট পাসের পরে ফেরত মালিকের সইসহ); নাহলে তখনই পাকা।
 *
 * ⚠️ কেবল দামের পরিমাণ — ফ্রি মাল আংশিক-পৌঁছানোর ঘরে লেখা হয় না, তাই এখানে আসে না।
 *
 * ── ⭐ ভাঙা মাল — ধাপ ৭, ৬ অক্টোবর ২০২৬ ────────────────────────────────────
 * *"কম বা ভাঙা মাল → ফেরত বা দাবি"*। ভাঙা অংশও একই ফেরতে, কিন্তু আটকে রাখা মজুদে (`to_hold`) — গুদামে গোনা যায়,
 * বিক্রয়যোগ্য নয়; কারণ "ক্ষতিগ্রস্ত পণ্য" (`DAMAGE`)। ⓘ সমন্বয়কের কথায় আপাতত কেবল ফেরত — প্রিন্সিপাল বা বাহকের
 * কাছে দাবি মালিকের উত্তরের অপেক্ষায়।
 */
final class ShortDeliveryReturn
{
    public function __construct(private readonly SalesReturnService $returns) {}

    /**
     * @param  array<int, string>  $delivered  চালানের সারি → ক্রেতা যতটা ভালো অবস্থায় নিলেন
     * @param  array<int, string>  $damaged  চালানের সারি → যতটা ভাঙা পৌঁছাল
     */
    public function for(DeliveryChallan $challan, DeliveryEvent $event, array $delivered, array $damaged = []): ?SalesReturn
    {
        $challan->loadMissing('lines');

        $billLines = SalesInvoiceLine::query()
            ->whereIn('delivery_challan_line_id', $challan->lines->pluck('id'))
            ->whereHas('invoice', fn ($q) => $q->where('status', DocumentStatus::CONFIRMED))
            ->with('invoice')
            ->get()
            ->keyBy('delivery_challan_line_id');

        // ⓘ বিল ছাড়া ফেরত হয় না — রওনাতেই বিল হয় ([[DispatchBill]]), তাই এটা কেবল পুরনো ব্যতিক্রম
        if ($billLines->isEmpty()) {
            return null;
        }

        $reason = $this->reason($event);

        if ($reason === null) {
            return null;
        }

        $lines = [];
        $broken = $this->damageReason() ?? $reason;

        foreach ($challan->lines as $line) {
            $billLine = $billLines->get($line->id);
            $bad = bcadd((string) ($damaged[$line->id] ?? '0'), '0', 4);
            $back = bcsub((string) $line->delivered_qty, (string) ($delivered[$line->id] ?? $line->delivered_qty), 4);

            if ($billLine === null || bccomp($back, '0', 4) <= 0) {
                continue;
            }

            /*
             * ⓘ ফেরার মোট (কম + ভাঙা) একবারে লটে ভাগ — দুইবার ভাগ করলে একই লটের মাল দুইবার ফিরত। ভাঙা অংশ আগে
             * কাটা, বাকিটা কম।
             */
            foreach ($this->byLot($challan, (int) $line->product_id, $back) as [$batchId, $qty]) {
                $holdPart = bccomp($bad, $qty, 4) < 0 ? $bad : $qty;
                $bad = bcsub($bad, $holdPart, 4);

                foreach ([[$holdPart, true], [bcsub($qty, $holdPart, 4), false]] as [$part, $hold]) {
                    if (bccomp($part, '0', 4) <= 0) {
                        continue;
                    }

                    $lines[] = [
                        'product_id' => $line->product_id,
                        'sales_invoice_line_id' => $billLine->id,
                        'qty' => $part,
                        'batch_id' => $batchId,
                        'reason_code_id' => ($hold ? $broken : $reason)->id,
                        // ⭐ ভাঙা — আটকে রাখা মজুদে, বিক্রয়যোগ্য নয়
                        'to_hold' => $hold,
                    ];
                }
            }
        }

        if ($lines === []) {
            return null;
        }

        $invoice = $billLines->first()->invoice;

        $return = $this->returns->create([
            'customer_id' => $invoice->customer_id,
            'warehouse_id' => $challan->warehouse_id,
            'sales_invoice_id' => $invoice->id,
            'reason_code_id' => $reason->id,
            'reason_note' => __('sales::delivery.short_return_note', ['no' => $challan->document_no]),
            'narration' => __('sales::delivery.short_return_note', ['no' => $challan->document_no]),
            'trx_date' => now()->toDateString(),
        ], $lines);

        try {
            return $this->returns->confirm($return);
        } catch (HeldForApproval) {
            // ⓘ ফেরতের ছকে সই লাগে — অনুরোধ বসে গেছে, ফেরতটা খসড়ায় সইয়ের অপেক্ষায়
            return $return->fresh();
        }
    }

    /**
     * ফেরতের কারণ — আংশিক পৌঁছানোর কারণটাই, যদি সেটা ফেরতের কারণও হয়; নাহলে মজুদে-ফেরার প্রথম কারণ।
     *
     * ⛔ আগে "ফেরতের প্রথম কারণ" — তালিকায় প্রথমটা "ক্ষতিগ্রস্ত পণ্য", তাই না-পৌঁছানো ভালো মালও ভাঙা বলে লেখা হত
     * (ধাপ ৭-এর ভাঙার ঘর বসাতে গিয়ে ধরা, ৬ অক্টোবর ২০২৬)। ⭐ এখন যে কারণে মাল বিক্রয়যোগ্য মজুদে ফেরে সেটা আগে।
     */
    private function reason(DeliveryEvent $event): ?ReasonCode
    {
        $codes = ReasonCode::query()->inContext(ReasonCode::SALES_RETURN);

        if ($event->reason_code_id !== null) {
            $same = (clone $codes)->whereKey($event->reason_code_id)->first();

            if ($same !== null) {
                return $same;
            }
        }

        return $codes->orderByDesc('returns_to_stock')->orderBy('id')->first();
    }

    /** ভাঙা মালের কারণ — ফেরতের তালিকার "ক্ষতিগ্রস্ত পণ্য" (`DAMAGE`); না থাকলে null, তখন আংশিকের কারণটাই */
    private function damageReason(): ?ReasonCode
    {
        return ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->first();
    }

    /**
     * না-নেওয়া পরিমাণ কোন লটে ফেরে — চালান যে লট থেকে যতটা বের করেছিল, তার বেশি নয়।
     *
     * ⓘ শেষে বেরোনো লট আগে (FEFO-র উল্টো): গাড়ির পেছনের মাল — আর একটাই লট হলে প্রশ্নই নেই। লট-ছাড়া পণ্যে একটাই
     * সারি, লট খালি।
     *
     * @return list<array{0: ?int, 1: string}>
     */
    private function byLot(DeliveryChallan $challan, int $productId, string $short): array
    {
        $issued = DB::table('inv_stock_movements')
            ->where('company_id', $challan->company_id)
            ->where('source_type', DeliveryChallan::STOCK_SOURCE)
            ->where('source_id', $challan->id)
            ->where('product_id', $productId)
            ->groupBy('batch_id')
            ->selectRaw('batch_id, MAX(id) as last_id, -SUM(floor_change) as qty')
            ->orderByDesc('last_id')
            ->get();

        $out = [];
        $left = $short;

        foreach ($issued as $row) {
            if (bccomp($left, '0', 4) <= 0) {
                break;
            }

            $qty = bccomp((string) $row->qty, $left, 4) < 0 ? (string) $row->qty : $left;

            if (bccomp($qty, '0', 4) <= 0) {
                continue;
            }

            $out[] = [$row->batch_id !== null ? (int) $row->batch_id : null, bcadd($qty, '0', 4)];
            $left = bcsub($left, $qty, 4);
        }

        // ⓘ চলাচল না পেলে (পুরনো চালান) — লট ছাড়া একটাই সারি, পুরো পরিমাণ
        if ($out === []) {
            return [[null, $short]];
        }

        return $out;
    }
}

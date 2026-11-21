<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\LabourCost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ক্রয়ের খরচ — ক্রয় ইনভয়েস অনুযায়ী (মালিকের অনুমোদিত নমুনা v6, ট্যাব গ; ২১ নভেম্বর ২০২৬)।
 *
 * ── সারি কোনটা ───────────────────────────────────────────────────────────
 * ⓘ মাল GRN ধরে এলে সারি GRN-এর (পাকা মাল গ্রহণ); বিল সরাসরি মাল ঢোকালে (চালান-ছাড়া সারি) সারি বিলের — "(সরাসরি ক্রয়)"।
 * ⛔ GRN থেকে আসা বিল আলাদা সারি নয় — তার ভাড়া আর অন্যান্য খরচ ঐ GRN-এর সারিতে বসে, তাই একই ক্রয় দুইবার গোনা হয় না।
 *
 * ── প্রতিটা অঙ্ক কোথা থেকে ───────────────────────────────────────────────
 *  - ক্রয় মূল্য: বিলের সারি, কর বাদ (`amount − tax`)। GRN-এর যে সারির বিল এখনো হয়নি, তার মূল্য GRN-এর দরে (`qty × rate`, কর বাদ)।
 *  - কার্টন: কাগজের মাথার `unload_cartons`; না থাকলে সারির `unload_cartons`-এর যোগ।
 *  - মাল নামানো (লেবার): `unload_amount` — কেবল যখন খরচটা আমাদের ([[LabourCost::costsUs()]]); সরবরাহকারী দিলে বা লাগেনি তো ০।
 *  - আনার ভাড়া: পাকা বিলের `transport_cost`। বিলটা কয়েকটা GRN জুড়ে হলে মূল্যের অনুপাতে ভাগ।
 *  - অন্যান্য: পাকা বিলের `other_cost` + ঐ বিলে ট্যাগ করা পাকা খরচের ভাউচারের ভাগ (`acc_voucher_bill_shares`)।
 * ⛔ কেবল চূড়ান্ত কাগজ: খসড়া, অনুমোদনের অপেক্ষায়, বাতিল — কিছুই গোনা হয় না ([[DocumentStatus::POSTED]])।
 *
 * ── এক জায়গায় ভাগ ───────────────────────────────────────────────────────
 * ⓘ সব হিসাব পণ্যের সারি ধরে ("অংশ"): লেবার সারির `unload_cost`-এর অনুপাতে (কার্টনের ভাগ), ভাড়া আর অন্যান্য মূল্যের অনুপাতে।
 *   তারপর ইনভয়েস / সরবরাহকারী / মাস / পণ্য — যেভাবেই জোড়া হোক, যোগফল একই মোটে মেলে।
 */
final class PurchaseCostReport
{
    public const SETTING = 'purchase.cost_report.threshold_percent';

    public const GROUPS = ['invoice', 'supplier', 'month', 'product'];

    public function __construct(private readonly SettingsService $settings) {}

    /** সীমা % — সেটিংস থেকে, না থাকলে ৩ */
    public function defaultThreshold(): string
    {
        $value = $this->settings->get(self::SETTING);

        return is_numeric($value) && (float) $value >= 0 ? (string) $value : '3';
    }

    /**
     * গোটা রিপোর্ট — সারি, মোট, কার্ড আর চার্টের তথ্য।
     *
     * @param  array{from: string, to: string, supplier_id?: ?int, product_id?: ?int, threshold: string, by: string}  $f
     * @return array<string, mixed>
     */
    public function run(array $f): array
    {
        $parts = $this->parts($f['from'], $f['to'], $f['supplier_id'] ?? null);

        if (($f['product_id'] ?? null) !== null) {
            $parts = array_values(array_filter($parts, fn (array $p) => $p['product_id'] === (int) $f['product_id']));
        }

        $threshold = $f['threshold'];
        $by = in_array($f['by'], self::GROUPS, true) ? $f['by'] : 'invoice';

        $docs = $this->group($parts, fn (array $p) => $p['doc_key'], $threshold);
        $rows = match ($by) {
            'invoice' => $this->sortByDate($docs),
            'supplier' => $this->sortByPct($this->group($parts, fn (array $p) => 's'.$p['supplier_id'], $threshold)),
            'month' => $this->sortByPct($this->group($parts, fn (array $p) => substr($p['date'], 0, 7), $threshold)),
            'product' => $this->sortByPct($this->group($parts, fn (array $p) => 'p'.$p['product_id'], $threshold)),
        };

        $totals = $this->sum($parts, $threshold);
        $totals['count'] = count($docs);

        $chartBy = $by === 'product' ? 'product' : 'supplier';
        $chart = $this->group($parts, fn (array $p) => $chartBy === 'product' ? 'p'.$p['product_id'] : 's'.$p['supplier_id'], $threshold);
        usort($chart, fn (array $a, array $b) => ($a['pct'] ?? 0) <=> ($b['pct'] ?? 0));

        $highest = null;
        foreach ($docs as $doc) {
            if ($doc['per_carton'] !== null && ($highest === null || bccomp($doc['per_carton'], $highest['per_carton'], 4) > 0)) {
                $highest = $doc;
            }
        }

        return [
            'by' => $by,
            'rows' => $rows,
            'totals' => $totals,
            'chart' => array_slice($chart, -12),
            'chart_by' => $chartBy,
            'highest' => $highest,
            'threshold' => $threshold,
        ];
    }

    /**
     * পণ্যের সারি ধরে অংশ — প্রতিটা অংশে কার্টন, মূল্য, লেবার, ভাড়া, অন্যান্য।
     *
     * @return list<array<string, mixed>>
     */
    public function parts(string $from, string $to, ?int $supplierId = null): array
    {
        $receipts = PurchaseReceipt::query()
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereBetween('trx_date', [$from, $to])
            ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))
            ->get(['id', 'document_no', 'trx_date', 'supplier_id', 'unload_mode', 'unload_amount', 'unload_cartons']);

        $receiptLines = $receipts->isEmpty() ? collect() : DB::table('pur_receipt_lines')
            ->whereIn('purchase_receipt_id', $receipts->pluck('id'))
            ->orderBy('line_no')->orderBy('id')
            ->get(['id', 'purchase_receipt_id', 'product_id', 'received_qty', 'rate', 'unload_cartons', 'unload_cost']);

        // ⓘ GRN-এর মালের পাকা বিলগুলো — শাখা যেটাই হোক, ভাড়া ঐ GRN-এর
        $linkedBillIds = $receiptLines->isEmpty() ? collect() : DB::table('pur_bill_lines as l')
            ->join('pur_bills as b', 'b.id', '=', 'l.purchase_bill_id')
            ->where('b.company_id', CompanyContext::id())
            ->whereNull('b.deleted_at')
            ->whereIn('b.status', DocumentStatus::POSTED)
            ->whereIn('l.purchase_receipt_line_id', $receiptLines->pluck('id'))
            ->distinct()->pluck('b.id');

        $directBills = PurchaseBill::query()
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereBetween('trx_date', [$from, $to])
            ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('pur_bill_lines as dl')
                ->whereColumn('dl.purchase_bill_id', 'pur_bills.id')->whereNull('dl.purchase_receipt_line_id'))
            ->get(['id', 'document_no', 'trx_date', 'supplier_id', 'unload_mode', 'unload_amount', 'unload_cartons']);

        $billIds = $linkedBillIds->merge($directBills->pluck('id'))->unique()->values();

        $bills = $billIds->isEmpty() ? collect() : DB::table('pur_bills')
            ->whereIn('id', $billIds)
            ->get(['id', 'transport_cost', 'other_cost'])->keyBy('id');

        $billLines = $billIds->isEmpty() ? collect() : DB::table('pur_bill_lines')
            ->whereIn('purchase_bill_id', $billIds)
            ->orderBy('line_no')->orderBy('id')
            ->get(['id', 'purchase_bill_id', 'purchase_receipt_line_id', 'product_id', 'qty', 'amount', 'tax', 'unload_cartons', 'unload_cost'])
            ->groupBy('purchase_bill_id');

        $vouchers = $this->voucherShares($billIds);

        // ── প্রতিটা বিলের সারির ভাড়া আর অন্যান্য — মূল্যের অনুপাতে ──
        $fareOf = [];
        $otherOf = [];
        foreach ($billLines as $billId => $lines) {
            $bill = $bills->get($billId);
            $weights = [];
            foreach ($lines as $line) {
                $weights[$line->id] = self::value($line);
            }
            $fareOf += self::spread((string) ($bill->transport_cost ?? '0'), $weights);
            $otherOf += self::spread(bcadd((string) ($bill->other_cost ?? '0'), $vouchers[$billId] ?? '0', 4), $weights);
        }

        $names = $this->names($receipts->pluck('supplier_id')->merge($directBills->pluck('supplier_id')));

        $parts = [];

        // ── GRN-এর অংশ ──
        $billedByReceiptLine = [];
        foreach ($billLines as $lines) {
            foreach ($lines as $line) {
                if ($line->purchase_receipt_line_id !== null && $linkedBillIds->contains($line->purchase_bill_id)) {
                    $billedByReceiptLine[(int) $line->purchase_receipt_line_id][] = $line;
                }
            }
        }

        $linesByReceipt = $receiptLines->groupBy('purchase_receipt_id');

        foreach ($receipts as $receipt) {
            $lines = $linesByReceipt->get($receipt->id, collect());
            if ($lines->isEmpty()) {
                continue;
            }

            $values = [];
            $fares = [];
            $others = [];
            foreach ($lines as $line) {
                $billed = $billedByReceiptLine[(int) $line->id] ?? [];
                $value = '0';
                $billedQty = '0';
                $fare = '0';
                $other = '0';
                foreach ($billed as $b) {
                    $value = bcadd($value, self::value($b), 4);
                    $billedQty = bcadd($billedQty, (string) $b->qty, 4);
                    $fare = bcadd($fare, $fareOf[$b->id] ?? '0', 4);
                    $other = bcadd($other, $otherOf[$b->id] ?? '0', 4);
                }
                // ⓘ বিল না হওয়া অংশ GRN-এর দরে
                $open = bcsub((string) $line->received_qty, $billedQty, 4);
                if (bccomp($open, '0', 4) > 0) {
                    $value = bcadd($value, bcmul($open, (string) $line->rate, 4), 4);
                }
                $values[$line->id] = $value;
                $fares[$line->id] = $fare;
                $others[$line->id] = $other;
            }

            $labour = $this->labour($receipt, $lines, $values);
            $cartons = $this->cartons($receipt, $lines, 'received_qty');

            foreach ($lines as $line) {
                $parts[] = $this->part('grn', $receipt, $names, (int) $line->product_id,
                    $cartons[$line->id], $values[$line->id], $labour[$line->id], $fares[$line->id], $others[$line->id]);
            }
        }

        // ── সরাসরি ক্রয়ের অংশ (বিলের চালান-ছাড়া সারি) ──
        foreach ($directBills as $bill) {
            $lines = $billLines->get($bill->id, collect())->filter(fn ($l) => $l->purchase_receipt_line_id === null)->values();
            if ($lines->isEmpty()) {
                continue;
            }

            $values = [];
            foreach ($lines as $line) {
                $values[$line->id] = self::value($line);
            }

            $labour = $this->labour($bill, $lines, $values);
            $cartons = $this->cartons($bill, $lines, 'qty');

            foreach ($lines as $line) {
                $parts[] = $this->part('bill', $bill, $names, (int) $line->product_id,
                    $cartons[$line->id], $values[$line->id], $labour[$line->id], $fareOf[$line->id] ?? '0', $otherOf[$line->id] ?? '0');
            }
        }

        return $parts;
    }

    /**
     * অংশগুলো জোড়া — একই চাবির সব অংশের যোগ।
     *
     * @param  list<array<string, mixed>>  $parts
     * @return list<array<string, mixed>>
     */
    public function group(array $parts, callable $key, string $threshold): array
    {
        $groups = [];
        foreach ($parts as $part) {
            $groups[$key($part)][] = $part;
        }

        $productNames = $this->productNames(array_unique(array_column($parts, 'product_id')));

        $rows = [];
        foreach ($groups as $k => $members) {
            $first = $members[0];
            $row = $this->sum($members, $threshold);
            $k = (string) $k;
            $row += [
                'key' => $k,
                'count' => str_starts_with($k, 'p')
                    ? count($members)
                    : count(array_unique(array_column($members, 'doc_key'))),
                'doc' => $first['doc'],
                'doc_id' => $first['doc_id'],
                'doc_no' => $first['doc_no'],
                'date' => $first['date'],
                'supplier_id' => $first['supplier_id'],
                'supplier' => $first['supplier'],
                'product' => $productNames[$first['product_id']] ?? '—',
                'month' => Carbon::parse(substr($first['date'], 0, 7).'-01')->locale(app()->getLocale())->translatedFormat('F Y'),
            ];
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * যোগফল আর তা থেকে হার — খরচ % = মোট ÷ মূল্য, প্রতি কার্টন = মোট ÷ কার্টন।
     *
     * @param  list<array<string, mixed>>  $parts
     * @return array<string, mixed>
     */
    public function sum(array $parts, string $threshold): array
    {
        $s = ['cartons' => '0', 'value' => '0', 'labour' => '0', 'fare' => '0', 'other' => '0'];
        foreach ($parts as $p) {
            foreach (array_keys($s) as $f) {
                $s[$f] = bcadd($s[$f], (string) $p[$f], 4);
            }
        }

        $s['total'] = bcadd(bcadd($s['labour'], $s['fare'], 4), $s['other'], 4);
        $s['pct'] = bccomp($s['value'], '0', 4) > 0 ? bcdiv(bcmul($s['total'], '100', 8), $s['value'], 4) : null;
        $s['per_carton'] = bccomp($s['cartons'], '0', 4) > 0 ? bcdiv($s['total'], $s['cartons'], 4) : null;
        $s['over'] = $s['pct'] !== null && bccomp($s['pct'], $threshold, 4) > 0;

        return $s;
    }

    /**
     * ⭐ ভাগ — ওজনের অনুপাতে, শেষ ঘর বাকিটুকু নেয় (যোগফল ঠিক মোটের সমান)। ওজন সব শূন্য হলে সমান ভাগ।
     *
     * @param  array<int|string, string>  $weights
     * @return array<int|string, string>
     */
    public static function spread(string $total, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $sum = array_reduce($weights, fn (string $c, string $w) => bcadd($c, $w, 4), '0');
        $equal = bccomp($sum, '0', 4) <= 0;
        $n = (string) count($weights);

        $out = [];
        $given = '0';
        foreach ($weights as $id => $w) {
            $out[$id] = $equal ? bcdiv($total, $n, 4) : bcdiv(bcmul($total, $w, 8), $sum, 4);
            $given = bcadd($given, $out[$id], 4);
        }

        $last = array_key_last($out);
        $out[$last] = bcadd($out[$last], bcsub($total, $given, 4), 4);

        return $out;
    }

    /** সারির মালমূল্য — কর বাদ ([[PurchaseBillService::goodsValueOf()]]-এর একই নিয়ম) */
    private static function value(object $line): string
    {
        return bcsub((string) $line->amount, (string) ($line->tax ?? '0'), 4);
    }

    /**
     * লেবার — খরচটা আমাদের হলে তবেই; সারির `unload_cost`-এর অনুপাতে (সেটাই কার্টনের ভাগ), না থাকলে কার্টন, তারপর মূল্য।
     *
     * @param  Collection<int, object>  $lines
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function labour(object $paper, Collection $lines, array $values): array
    {
        $amount = LabourCost::costsUs($paper->unload_mode) ? (string) ($paper->unload_amount ?? '0') : '0';

        $byCost = [];
        $byCartons = [];
        foreach ($lines as $line) {
            $byCost[$line->id] = (string) ($line->unload_cost ?? '0');
            $byCartons[$line->id] = (string) ($line->unload_cartons ?? '0');
        }

        $weights = match (true) {
            self::positive($byCost) => $byCost,
            self::positive($byCartons) => $byCartons,
            default => $values,
        };

        return self::spread($amount, $weights);
    }

    /**
     * কার্টন — মাথার `unload_cartons` (সারির কার্টনের অনুপাতে, না থাকলে পরিমাণে ভাগ); মাথায় না থাকলে সারির কার্টন।
     *
     * @param  Collection<int, object>  $lines
     * @return array<int, string>
     */
    private function cartons(object $paper, Collection $lines, string $qtyField): array
    {
        $own = [];
        $qty = [];
        foreach ($lines as $line) {
            $own[$line->id] = (string) ($line->unload_cartons ?? '0');
            $qty[$line->id] = (string) ($line->{$qtyField} ?? '0');
        }

        $head = (string) ($paper->unload_cartons ?? '0');

        if (bccomp($head, '0', 4) <= 0) {
            return $own;
        }

        return self::spread($head, self::positive($own) ? $own : $qty);
    }

    /** @param  array<int|string, string>  $weights */
    private static function positive(array $weights): bool
    {
        foreach ($weights as $w) {
            if (bccomp($w, '0', 4) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function part(string $doc, object $paper, array $names, int $productId, string $cartons, string $value, string $labour, string $fare, string $other): array
    {
        $date = $paper->trx_date instanceof \DateTimeInterface ? $paper->trx_date->format('Y-m-d') : substr((string) $paper->trx_date, 0, 10);

        return [
            'doc' => $doc,
            'doc_id' => (int) $paper->id,
            'doc_key' => $doc.':'.$paper->id,
            'doc_no' => (string) $paper->document_no,
            'date' => $date,
            'supplier_id' => (int) $paper->supplier_id,
            'supplier' => $names[(int) $paper->supplier_id] ?? '—',
            'product_id' => $productId,
            'cartons' => $cartons,
            'value' => $value,
            'labour' => $labour,
            'fare' => $fare,
            'other' => $other,
        ];
    }

    /**
     * বিলে ট্যাগ করা পাকা খরচের ভাউচারের ভাগ — বিল → অঙ্ক।
     *
     * @param  Collection<int, int>  $billIds
     * @return array<int, string>
     */
    private function voucherShares(Collection $billIds): array
    {
        if ($billIds->isEmpty() || ! Schema::hasTable('acc_voucher_bill_shares')) {
            return [];
        }

        return DB::table('acc_voucher_bill_shares as s')
            ->join('vouchers as v', 'v.id', '=', 's.voucher_id')
            ->where('v.company_id', CompanyContext::id())
            ->whereNull('v.deleted_at')
            ->whereIn('v.status', DocumentStatus::POSTED)
            ->whereIn('s.purchase_bill_id', $billIds)
            ->groupBy('s.purchase_bill_id')
            ->selectRaw('s.purchase_bill_id as bill_id, SUM(s.share_amount) as amount')
            ->pluck('amount', 'bill_id')
            ->map(fn ($v) => bcadd((string) $v, '0', 4))
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return array<int, string>
     */
    private function names(Collection $ids): array
    {
        $ids = $ids->filter()->unique()->values();

        return $ids->isEmpty() ? [] : Supplier::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Supplier $s) => [(int) $s->id => (string) $s->name()])->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function productNames(array $ids): array
    {
        $ids = array_values(array_filter($ids));

        return $ids === [] ? [] : Product::query()->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Product $p) => [(int) $p->id => (string) $p->name()])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortByDate(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => [$a['date'], $a['doc_no']] <=> [$b['date'], $b['doc_no']]);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortByPct(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => (float) ($b['pct'] ?? -1) <=> (float) ($a['pct'] ?? -1));

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\MasterData\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * একটা পণ্যের base একক নামানো — কার্টন থেকে পিসে। ২০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⚠️ এটা নিয়মের ব্যতিক্রম, আর ইচ্ছাকৃত ────────────────────────────
 * নিয়ম: মজুদ-চলাচল হয়ে গেলে base আর বদলায় না
 * ([[ProductPackService::assertBaseCanChange()]])। ⓘ কিন্তু মালিকের
 * পুরনো দুইটা পণ্যের মজুদ কার্টনে গোনা, আর তিনি নিজে বলেছেন ওগুলো পিসে
 * নামাতে (১ কার্টন = ২৪ পিস)। ⛔ তাই একটা **আলাদা, স্পষ্ট** কমান্ড —
 * ফর্ম থেকে নয়, পণ্য ধরে ধরে, আর ডিফল্টে কেবল দেখায়।
 *
 * ── ⭐ একমাত্র শর্ত: টাকার অঙ্ক নড়বে না ───────────────────────────────
 * পরিমাণ ×N হয়, আর টাকা যেখানে জমা আছে সেটা **অক্ষত** থাকে; দর তার
 * থেকেই আবার বের করা হয়, উল্টোটা নয়।
 *
 * ⛔ উল্টোটা করে দেখা গেছে, আর লাইভের dry-run সেটা ধরেছে (২০ সেপ্টেম্বর):
 * ২৩ কার্টন × ১৭২.৫৪ = ৩,৯৬৮.৪২। দরটা ২৪ দিয়ে ভাগ করে চার ঘরে গোল করলে
 * ৭.১৮৯২, আর ৫৫২ পিসে সেটা দাঁড়ায় ৩,৯৬৮.৪৩৮৪ — ১.৮৪ পয়সা বেশি।
 * ⚠️ দুই পয়সার গরমিলও ছয় মাস পরে খাতায় অব্যাখ্যাত ফাঁক হয়ে ফেরে।
 *
 * ── ⓘ অবশিষ্ট কীভাবে বহন করা হয় ─────────────────────────────────────
 * এক দরে ৫৫২ পিসে ঐ টাকাটা বসেই না (৩,৯৬৮.৪২ ÷ ৫৫২ শেষ হয় না)। তাই
 * স্তরটা দুই সারিতে ভাগ হয়: বেশির ভাগ পিস নিচের দরে, আর ঠিক যতগুলোতে
 * এক ধাপ (০.০০০১) বেশি লাগে, ততগুলো পরের দরে। যোগফল তখন **হুবহু** মেলে,
 * আর দুইটা সারির দুইটা দরই সত্যি।
 *
 * ⚠️ `entered_qty` / `entered_unit_id` ছোঁয়া হয় না: ওগুলো বলে মানুষ কী
 * লিখেছিলেন ("২ কার্টন"), আর সেটা আজও সত্যি।
 */
final class PackRebase
{
    /** মজুদের ঘর — এখানে টাকা নেই, তাই সোজা গুণ। */
    private const MOVEMENT_COLUMNS = [
        'floor_change', 'reserved_change', 'hold_change', 'free_change',
        'free_reserved_change', 'unplaced_change', 'unplaced_free_change',
    ];

    /**
     * কাগজের লাইন — টেবিল → [পরিমাণের কলাম, দরের কলাম]।
     *
     * ⚠️ `amount`, `discount`, ভ্যাট — কিছুই ছোঁয়া হয় না। ওগুলোই বিলের
     * টাকা, আর একক বদলালে ছাপা বিলের অঙ্ক বদলানোর কোনো কারণ নেই।
     *
     * ⛔ প্রথমে দরটা `amount ÷ পরিমাণ` করে বসানো হয়েছিল, আর সেটা ভুল:
     * `amount`-এ ছাড় ও ভ্যাট ধরা থাকে ([[CalculatesSalesLines::lineFigures()]]),
     * তাই ছাড়টা নীরবে দরের ভিতরে ঢুকে যেত — "১০% ছাড়ে ১৭২.৫৪" হয়ে যেত
     * "দরই কম"। ⓘ দর তাই সোজা ভাগ হয়, আর টাকার ঘরগুলো যেমন আছে থাকে।
     */
    private const LINE_TABLES = [
        'sal_order_lines' => ['ordered_qty', 'rate'],
        'sal_challan_lines' => ['delivered_qty', 'rate'],
        'sal_challan_gift_lines' => ['qty', null],
        'sal_invoice_lines' => ['qty', 'rate'],
        'sal_return_lines' => ['qty', 'rate'],
        'pur_order_lines' => ['ordered_qty', 'rate'],
        'pur_receipt_lines' => ['received_qty', 'rate'],
        'pur_bill_lines' => ['qty', 'rate'],
        'pur_bill_gift_lines' => ['qty', null],
        'pur_return_lines' => ['qty', 'rate'],
        'inv_transfer_lines' => ['qty', 'rate'],
    ];

    /**
     * @return array{product: string, from: string, to: string, per: string,
     *     rows: array<string, int>, qty_before: string, qty_after: string,
     *     value_before: string, value_after: string, layers_split: int}
     */
    public function run(Product $product, Unit $to, string $per, bool $apply = false): array
    {
        if (bccomp($per, '0', 6) <= 0) {
            throw ValidationException::withMessages(['per' => __('inventory::validation.pack_qty_positive', [
                'unit' => $product->unit?->name() ?? '',
            ])]);
        }

        $from = $product->unit;

        if ($from === null) {
            throw ValidationException::withMessages(['unit_id' => __('inventory::validation.product_has_no_unit', [
                'product' => $product->name(),
            ])]);
        }

        // ⓘ আগে থেকেই ঐ এককে — নামানোর কিছু নেই, আর বার্তাটা সেটাই বলে
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['unit_id' => __('inventory::validation.already_that_unit', [
                'product' => $product->name(),
                'unit' => $to->name(),
            ])]);
        }

        $before = $this->totals($product);
        $rows = [];
        $split = 0;

        DB::beginTransaction();

        try {
            $rows['inv_stock_movements'] = $this->movements($product->id, $per);
            [$rows['inv_cost_layers'], $split] = $this->layers($product, $per);
            $rows['inv_cost_layer_uses'] = $this->uses($product->id, $per);

            foreach (self::LINE_TABLES as $table => [$qty, $rate]) {
                $rows[$table] = $this->lines($table, $product->id, $per, $qty, $rate);
            }

            $product->forceFill(['unit_id' => $to->id])->save();
            $this->packs($product, $from, $to, $per);

            $after = $this->totals($product);
        } finally {
            $apply ? DB::commit() : DB::rollBack();
        }

        return [
            'product' => (string) $product->code,
            'from' => (string) $from->code,
            'to' => (string) $to->code,
            'per' => $per,
            'rows' => array_filter($rows),
            'qty_before' => $before['qty'],
            'qty_after' => $after['qty'],
            'value_before' => $before['value'],
            'value_after' => $after['value'],
            'layers_split' => $split,
        ];
    }

    /** মজুদের চলাচল — সব ঘর ×N। এখানে টাকা নেই, তাই গোলের প্রশ্নও নেই। */
    private function movements(int $productId, string $per): int
    {
        if (! Schema::hasTable('inv_stock_movements')) {
            return 0;
        }

        $sets = [];

        foreach (self::MOVEMENT_COLUMNS as $column) {
            if (Schema::hasColumn('inv_stock_movements', $column)) {
                $sets[$column] = DB::raw("{$column} * {$per}");
            }
        }

        return $sets === [] ? 0 : DB::table('inv_stock_movements')->where('product_id', $productId)->update($sets);
    }

    /**
     * খরচের স্তর — পরিমাণ ×N, আর দর এমন যে **মূল্য হুবহু আগেরটাই**।
     *
     * ⓘ এক দরে না মিললে স্তরটা দুই সারিতে ভাগ হয় (মাথার ব্যাখ্যা দেখুন)।
     *
     * @return array{0: int, 1: int} কয়টা সারি ছোঁয়া হলো, কয়টা ভাগ হলো
     */
    private function layers(Product $product, string $per): array
    {
        if (! Schema::hasTable('inv_cost_layers')) {
            return [0, 0];
        }

        $touched = 0;
        $split = 0;

        foreach (DB::table('inv_cost_layers')->where('product_id', $product->id)->orderBy('id')->get() as $layer) {
            $value = bcmul((string) $layer->qty_remaining, (string) $layer->unit_cost, 4);
            $newRemaining = bcmul((string) $layer->qty_remaining, $per, 4);
            $newIn = bcmul((string) $layer->qty_in, $per, 4);
            $touched++;

            if (bccomp($newRemaining, '0', 4) === 0) {
                // নিঃশেষ স্তর — টাকা নেই, কেবল পরিমাণ আর দর
                DB::table('inv_cost_layers')->where('id', $layer->id)->update([
                    'qty_in' => $newIn,
                    'qty_remaining' => $newRemaining,
                    'unit_cost' => bcdiv((string) $layer->unit_cost, $per, 4),
                ]);

                continue;
            }

            $low = bcdiv($value, $newRemaining, 4);
            $residue = bcsub($value, bcmul($newRemaining, $low, 4), 4);

            // কয়টা একক এক ধাপ বেশি দরে বসবে — অবশিষ্ট ÷ ০.০০০১
            $higher = bcmul($residue, '10000', 0);

            if (bccomp($higher, '0', 4) <= 0 || bccomp($higher, $newIn, 4) > 0) {
                DB::table('inv_cost_layers')->where('id', $layer->id)->update([
                    'qty_in' => $newIn,
                    'qty_remaining' => $newRemaining,
                    'unit_cost' => $low,
                ]);

                continue;
            }

            DB::table('inv_cost_layers')->where('id', $layer->id)->update([
                'qty_in' => bcsub($newIn, $higher, 4),
                'qty_remaining' => bcsub($newRemaining, $higher, 4),
                'unit_cost' => $low,
            ]);

            /*
             * ⓘ বাকি এককগুলো এক ধাপ (০.০০০১) বেশি দরে, নতুন একটা সারিতে —
             * একই তারিখ, একই উৎস, তাই FIFO-র ক্রম বদলায় না।
             */
            DB::table('inv_cost_layers')->insert([
                'public_id' => (string) Str::uuid7(),
                'company_id' => $layer->company_id,
                'product_id' => $layer->product_id,
                'source_type' => $layer->source_type,
                'source_id' => $layer->source_id,
                'document_no' => $layer->document_no,
                'trx_date' => $layer->trx_date,
                'qty_in' => $higher,
                'qty_remaining' => $higher,
                'unit_cost' => bcadd($low, '0.0001', 4),
                'created_by' => $layer->created_by,
                'created_at' => $layer->created_at,
                'updated_at' => now(),
            ]);

            $split++;
            $touched++;
        }

        return [$touched, $split];
    }

    /**
     * স্তরের ব্যবহার — পরিমাণ ×N, টাকা (`amount`) অক্ষত, দর সেখান থেকেই।
     */
    private function uses(int $productId, string $per): int
    {
        if (! Schema::hasTable('inv_cost_layer_uses')) {
            return 0;
        }

        $touched = 0;

        foreach (DB::table('inv_cost_layer_uses')->where('product_id', $productId)->orderBy('id')->get() as $use) {
            $qty = bcmul((string) $use->qty, $per, 4);

            DB::table('inv_cost_layer_uses')->where('id', $use->id)->update([
                'qty' => $qty,
                'unit_cost' => bccomp($qty, '0', 4) === 0
                    ? bcdiv((string) $use->unit_cost, $per, 4)
                    : bcdiv((string) $use->amount, $qty, 4),
            ]);

            $touched++;
        }

        return $touched;
    }

    /**
     * কাগজের লাইন — পরিমাণ ×N, দর ÷N, আর টাকার ঘরগুলো অক্ষত।
     */
    private function lines(string $table, int $productId, string $per, string $qtyColumn, ?string $rateColumn): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'product_id')
            || ! Schema::hasColumn($table, $qtyColumn)) {
            return 0;
        }

        $hasRate = $rateColumn !== null && Schema::hasColumn($table, $rateColumn);
        $hasCost = Schema::hasColumn($table, 'unit_cost');
        $touched = 0;

        foreach (DB::table($table)->where('product_id', $productId)->orderBy('id')->get() as $line) {
            $qty = bcmul((string) $line->{$qtyColumn}, $per, 4);
            $sets = [$qtyColumn => $qty];

            if ($hasRate) {
                $sets[$rateColumn] = bcdiv((string) $line->{$rateColumn}, $per, 4);
            }

            if ($hasCost) {
                $sets['unit_cost'] = bcdiv((string) $line->unit_cost, $per, 4);
            }

            DB::table($table)->where('id', $line->id)->update($sets);
            $touched++;
        }

        return $touched;
    }

    /**
     * প্যাকের টেবিল নতুন base-এ: পুরনো base একটা প্যাক হয়ে যায় (১ কার্টন =
     * ২৪ পিস), আর বাকি প্যাকগুলোর factor-ও ঐ অনুপাতে বাড়ে।
     */
    private function packs(Product $product, Unit $from, Unit $to, string $per): void
    {
        if (! Schema::hasTable('inv_product_units')) {
            return;
        }

        foreach (ProductUnit::query()->where('product_id', $product->id)->get() as $pack) {
            if ($pack->unit_id === $from->id || $pack->unit_id === $to->id) {
                continue;
            }

            $pack->forceFill(['factor' => bcmul((string) $pack->factor, $per, 6)])->save();
        }

        /*
         * ⚠️ পুরনো base-এর সারিটা না থাকলেও বানাতে হয় — ২০ সেপ্টেম্বর ২০২৬,
         * পরীক্ষায় ধরা। ⛔ যে পণ্যের প্যাকের টেবিল কখনো ভরা হয়নি (লাইভের
         * "অপেক্ষায়" দুইটা ঠিক তাই), তার কার্টনটা নামানোর পর কোথাও থাকত না —
         * অর্থাৎ যে এককে এতদিন কেনা-বেচা হয়েছে, সেটাই হারিয়ে যেত।
         */
        ProductUnit::query()->updateOrCreate(
            ['product_id' => $product->id, 'unit_id' => $from->id],
            ['company_id' => $product->company_id, 'factor' => $per, 'per_qty' => $per,
                'per_unit_id' => $to->id, 'is_active' => true],
        );

        ProductUnit::query()->updateOrCreate(
            ['product_id' => $product->id, 'unit_id' => $to->id],
            ['company_id' => $product->company_id, 'factor' => '1', 'per_qty' => null,
                'per_unit_id' => null, 'is_active' => true],
        );
    }

    /**
     * এই পণ্যের মোট পরিমাণ আর মোট মূল্য — প্রমাণের দুইটা সংখ্যা।
     *
     * ⓘ মূল্য cost layer থেকে (`qty_remaining × unit_cost`), সারি ধরে যোগ —
     * SQL-এর SUM নয়, কারণ গুণটা প্রতিটা সারিতে চার ঘরে হওয়া চাই, ঠিক যেভাবে
     * খাতা পড়ে। rebase-এ এই সংখ্যাটা এক চুলও বদলানোর কথা নয়।
     *
     * @return array{qty: string, value: string}
     */
    private function totals(Product $product): array
    {
        $qty = (string) (DB::table('inv_stock_movements')
            ->where('product_id', $product->id)
            ->selectRaw('COALESCE(SUM(floor_change + free_change + unplaced_change + unplaced_free_change), 0) as q')
            ->value('q') ?? '0');

        $value = '0';

        foreach (DB::table('inv_cost_layers')->where('product_id', $product->id)->get() as $layer) {
            $value = bcadd($value, bcmul((string) $layer->qty_remaining, (string) $layer->unit_cost, 4), 4);
        }

        return ['qty' => bcadd($qty, '0', 4), 'value' => $value];
    }
}

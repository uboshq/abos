<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\MasterData\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
 * ── কী গুণ হয়, কী ভাগ ─────────────────────────────────────────────────
 * পরিমাণ ×N: মজুদ-চলাচলের প্রতিটা ঘর, cost layer-এর `qty_in` ও
 * `qty_remaining`, layer ব্যবহারের `qty`, আর কাগজের লাইনের `qty`।
 * দর ÷N: `unit_cost` আর লাইনের `rate`।
 *
 * ⭐ ফলে **মোট মূল্য বদলায় না** (qty × দর), কেবল একক বদলায় — আর সেটাই
 * এই কাজের একমাত্র পরীক্ষা: আগে-পরে মূল্য সমান, পরিমাণ ঠিক N গুণ।
 *
 * ⚠️ `entered_qty` / `entered_unit_id` ছোঁয়া হয় না: ওগুলো বলে মানুষ কী
 * লিখেছিলেন ("২ কার্টন"), আর সেটা আজও সত্যি।
 */
final class PackRebase
{
    /** পরিমাণের ঘর — টেবিল → কলাম। */
    private const QTY_COLUMNS = [
        'inv_stock_movements' => ['floor_change', 'reserved_change', 'hold_change', 'free_change',
            'free_reserved_change', 'unplaced_change', 'unplaced_free_change'],
        'inv_cost_layers' => ['qty_in', 'qty_remaining'],
        'inv_cost_layer_uses' => ['qty'],
        'sal_order_lines' => ['ordered_qty'],
        'sal_challan_lines' => ['delivered_qty'],
        'sal_challan_gift_lines' => ['qty'],
        'sal_invoice_lines' => ['qty'],
        'sal_return_lines' => ['qty'],
        'pur_order_lines' => ['ordered_qty'],
        'pur_receipt_lines' => ['received_qty'],
        'pur_bill_lines' => ['qty'],
        'pur_bill_gift_lines' => ['qty'],
        'pur_return_lines' => ['qty'],
        'inv_transfer_lines' => ['qty'],
    ];

    /** দরের ঘর — একই সারিতে, উল্টো দিকে। */
    private const RATE_COLUMNS = [
        'inv_cost_layers' => ['unit_cost'],
        'inv_cost_layer_uses' => ['unit_cost'],
        'sal_order_lines' => ['rate'],
        'sal_challan_lines' => ['rate'],
        'sal_invoice_lines' => ['rate'],
        'sal_return_lines' => ['rate'],
        'pur_order_lines' => ['rate'],
        'pur_receipt_lines' => ['rate'],
        'pur_bill_lines' => ['rate'],
        'pur_return_lines' => ['rate'],
        'inv_transfer_lines' => ['rate'],
    ];

    /**
     * @return array{product: string, from: string, to: string, per: string,
     *     rows: array<string, int>, qty_before: string, qty_after: string,
     *     value_before: string, value_after: string}
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

        DB::beginTransaction();

        try {
            foreach (self::QTY_COLUMNS as $table => $columns) {
                $rows[$table] = $this->scale($table, $product->id, $columns, $per, multiply: true);
            }

            foreach (self::RATE_COLUMNS as $table => $columns) {
                $this->scale($table, $product->id, $columns, $per, multiply: false);
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
        ];
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
            if ($pack->unit_id === $from->id) {
                // পুরনো base: ১ কার্টন = N নতুন base
                $pack->forceFill([
                    'factor' => $per,
                    'per_qty' => $per,
                    'per_unit_id' => $to->id,
                ])->save();

                continue;
            }

            if ($pack->unit_id === $to->id) {
                $pack->forceFill(['factor' => '1', 'per_qty' => null, 'per_unit_id' => null])->save();

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
     * একটা টেবিলের ঘরগুলো গুণ বা ভাগ — কয়টা সারি ছোঁয়া হলো, সেটা ফেরে।
     *
     * @param  list<string>  $columns
     */
    private function scale(string $table, int $productId, array $columns, string $per, bool $multiply): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'product_id')) {
            return 0;
        }

        $sets = [];

        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                $sets[$column] = DB::raw($multiply ? "{$column} * {$per}" : "{$column} / {$per}");
            }
        }

        if ($sets === []) {
            return 0;
        }

        return DB::table($table)->where('product_id', $productId)->update($sets);
    }

    /**
     * এই পণ্যের মোট পরিমাণ আর মোট মূল্য — প্রমাণের দুইটা সংখ্যা।
     *
     * ⓘ মূল্য cost layer থেকে (`qty_remaining × unit_cost`): ওটাই খাতায়
     * পণ্যটার দাম, আর rebase-এ ওটা এক চুলও বদলানোর কথা নয়।
     *
     * @return array{qty: string, value: string}
     */
    private function totals(Product $product): array
    {
        $qty = (string) (DB::table('inv_stock_movements')
            ->where('product_id', $product->id)
            ->selectRaw('COALESCE(SUM(floor_change + free_change + unplaced_change + unplaced_free_change), 0) as q')
            ->value('q') ?? '0');

        $value = (string) (DB::table('inv_cost_layers')
            ->where('product_id', $product->id)
            ->selectRaw('COALESCE(SUM(qty_remaining * unit_cost), 0) as v')
            ->value('v') ?? '0');

        return ['qty' => bcadd($qty, '0', 4), 'value' => bcadd($value, '0', 4)];
    }
}

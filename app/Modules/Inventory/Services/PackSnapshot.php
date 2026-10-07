<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * মজুদ আর কাগজের একটা আঙুলের ছাপ — প্যাকের ব্যাকফিলের আগে ও পরে।
 *
 * ── কেন ───────────────────────────────────────────────────────────────
 * [[PackBackfill]]-এর প্রতিশ্রুতি: মজুদের একটা সংখ্যাও, কোনো কাগজের
 * একটা লাইনও বদলায় না। ⚠️ প্রতিশ্রুতি মুখে বললে যথেষ্ট নয় — এই ছাপ
 * আগে নিয়ে রাখা হয়, পরে আবার নেওয়া হয়, আর দুইটা হুবহু মিলতে হয়।
 *
 * ⓘ প্রতিটা টেবিলের **প্রতিটা সারির প্রতিটা ঘর** ধরা হয় (কেবল
 * `updated_at` বাদ — সেটা কেউ ছুঁলেই বদলায়, অর্থ না বদলালেও)। কোনো
 * কলাম বেছে নেওয়া হয় না, যাতে কাল একটা নতুন কলাম যোগ হলেও ছাপ সেটা
 * আপনা থেকে ধরে।
 *
 * ── ⚠️ কোম্পানি ধরে নয়, ইচ্ছে করে ───────────────────────────────────
 * লাইন-টেবিলে `company_id` নেই (কোম্পানি জানে তার কাগজ)। আর প্রমাণটা
 * গোটা ডেটাবেসের — কোনো কোম্পানিতেই কিছু বদলায়নি। ⓘ ছাপ কেবল সংখ্যা আর
 * হ্যাশ ছাপে, কোনো সারির লেখা নয়, তাই এক কোম্পানির তথ্য অন্যের চোখে
 * পড়ে না।
 */
final class PackSnapshot
{
    /**
     * যে টেবিলগুলো ব্যাকফিল কখনো ছোঁয় না — মজুদ, খরচ, আর প্যাকে লেখা
     * যায় এমন প্রতিটা লাইন ([[HasEnteredPack]])।
     */
    public const TABLES = [
        'inv_stock_movements',
        'inv_cost_layers',
        'inv_cost_layer_uses',
        'sal_order_lines',
        'sal_challan_lines',
        'sal_challan_gift_lines',
        'sal_invoice_lines',
        'sal_return_lines',
        'pur_order_lines',
        'pur_receipt_lines',
        'pur_bill_lines',
        'pur_bill_gift_lines',
        'pur_return_lines',
        'inv_transfer_lines',
    ];

    /**
     * @return array<string, array{rows: int, md5: string}>
     */
    public function take(): array
    {
        $print = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = array_values(array_diff(Schema::getColumnListing($table), ['updated_at']));
            sort($columns);

            $hash = hash_init('md5');
            $rows = 0;

            foreach (DB::table($table)->select($columns)->lazyById(500) as $row) {
                $values = [];

                foreach ($columns as $column) {
                    $values[] = $column.'='.($row->{$column} ?? '∅');
                }

                hash_update($hash, implode('|', $values)."\n");
                $rows++;
            }

            $print[$table] = ['rows' => $rows, 'md5' => hash_final($hash)];
        }

        return $print;
    }

    /**
     * দুইটা ছাপ মেলানো — কোন টেবিলে অমিল, তার তালিকা।
     *
     * @param  array<string, array{rows: int, md5: string}>  $before
     * @param  array<string, array{rows: int, md5: string}>  $after
     * @return list<string>
     */
    public function differences(array $before, array $after): array
    {
        $changed = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $table) {
            if (($before[$table] ?? null) != ($after[$table] ?? null)) {
                $changed[] = $table;
            }
        }

        return $changed;
    }
}

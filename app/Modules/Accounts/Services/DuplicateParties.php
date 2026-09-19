<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * একই মানুষ দুইবার — গ্রাহক, সরবরাহকারী আর ব্যক্তির তালিকায়।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ফিন্যান্স মানচিত্রের §৩৩ "ডুপ্লিকেট খোঁজা ও মেরামত"। একই দোকান দুই
 * কোডে থাকলে তার বকেয়া দুই ভাগে পড়ে, আর কেউ পুরো অঙ্কটা দেখেন না।
 *
 * ⓘ মেলানো হয় দুইভাবে: একই মোবাইল (কেবল অঙ্ক, শেষ ১১টা, যাতে +৮৮০ আর
 * ০ দিয়ে শুরু একই গোনা হয়), আর একই নাম (ছোট হাতের, ফাঁকা ছাঁটা)। ⛔ জোড়া
 * লাগানো (merge) নেই: দুইটা খাতার লেনদেন এক করা ফেরানো যায় না, তাই
 * "মেরামত" মানে সারিটা খুলে ঠিক করা বা বন্ধ করা, হাতে।
 */
final class DuplicateParties
{
    /** তালিকা → [টেবিল, মোবাইলের কলাম] */
    public const KINDS = [
        'customer' => ['customers', 'phone'],
        'supplier' => ['suppliers', 'phone'],
        'person' => ['mdm_people', 'mobile'],
    ];

    /**
     * @return list<array{by: string, key: string, rows: list<object>}>
     */
    public function groups(string $kind): array
    {
        [$table, $phone] = self::KINDS[$kind];

        $rows = DB::table($table)
            ->where('company_id', CompanyContext::id())
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_bn', "{$phone} as phone", 'is_active']);

        $byPhone = [];
        $byName = [];

        foreach ($rows as $row) {
            $digits = preg_replace('/\D+/', '', (string) $row->phone) ?? '';

            if (strlen($digits) >= 10) {
                $byPhone[substr($digits, -11)][] = $row;
            }

            $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($row->name_en ?: $row->name_bn)) ?? ''));

            if ($name !== '') {
                $byName[$name][] = $row;
            }
        }

        $out = [];

        foreach (['phone' => $byPhone, 'name' => $byName] as $by => $buckets) {
            foreach ($buckets as $key => $members) {
                if (count($members) > 1) {
                    $out[] = ['by' => $by, 'key' => (string) $key, 'rows' => $members];
                }
            }
        }

        return $out;
    }

    /** @return array<string, int> প্রতিটা তালিকায় কয়টা দল */
    public function counts(): array
    {
        $out = [];

        foreach (array_keys(self::KINDS) as $kind) {
            $out[$kind] = count($this->groups($kind));
        }

        return $out;
    }
}

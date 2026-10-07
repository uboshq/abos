<?php

declare(strict_types=1);

namespace App\Modules\Sales\Dashboard;

use App\Core\Contracts\ContributesActivity;
use App\Core\Dashboard\Happening;
use App\Core\Support\Money;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;

/**
 * বিক্রয়ের ঘরে সদ্য যা হয়েছে।
 *
 * ── তিনটাই, আর কেন এই তিনটাই ────────────────────────────────────────
 * বিল কাটা (টাকা পাওনা হলো), আদায় (টাকা এল), ফেরত (টাকা ফেরত গেল)।
 * চালান ও অর্ডার ইচ্ছাকৃতভাবে বাদ: ওগুলোর প্রতিটার পেছনে একটা বিল
 * আসে, তাই দুইটাই দেখালে একই ঘটনা তালিকায় দুইবার বসত — আর চার সারির
 * একটা তালিকায় সেটা অর্ধেক জায়গা খেয়ে ফেলত।
 *
 * ── কেন খসড়া নয় ────────────────────────────────────────────────────
 * খসড়া "হয়েছে" নয়, "শুরু হয়েছে"। ওগুলোর জায়গা করণীয় তালিকায়, আর
 * সেখানে ওরা আছেও।
 */
final class SalesActivity implements ContributesActivity
{
    /** @return list<Happening> */
    public static function activity(int $limit): array
    {
        return [
            ...self::invoices($limit),
            ...self::collections($limit),
            ...self::returns($limit),
        ];
    }

    /** @return list<Happening> */
    private static function invoices(int $limit): array
    {
        return SalesInvoice::query()
            ->posted()
            ->with('customer')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (SalesInvoice $invoice) => new Happening(
                /*
                 * `updated_at`, `trx_date` নয়।
                 *
                 * তালিকাটা "কখন ঘটল" ধরে সাজানো, "কোন তারিখের কাগজ"
                 * ধরে নয়। পিছিয়ে-তারিখে একটা বিল বসালে সেটা আজকের
                 * কাজ — কিন্তু `trx_date` ধরলে সারিটা তিন দিন আগে
                 * চলে যেত আর তালিকায় কোনোদিন দেখা যেত না।
                 */
                when: $invoice->updated_at ?? $invoice->created_at,
                title: $invoice->document_no.' · '.Money::format((string) $invoice->total, 2),
                subtitle: $invoice->customer?->name() ?? '',
                icon: 'receipt',
                permission: 'sales.invoice.view',
                tone: 'money',
                sourceType: SalesInvoice::drillSourceType(),
                sourceId: $invoice->id,
            ))
            ->all();
    }

    /** @return list<Happening> */
    private static function collections(int $limit): array
    {
        return Collection::query()
            ->posted()
            ->with('customer')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Collection $collection) => new Happening(
                when: $collection->updated_at ?? $collection->created_at,
                title: $collection->document_no.' · '.__('sales::message.collected_amount', [
                    'amount' => Money::format((string) $collection->amount, 2),
                ]),
                subtitle: $collection->customer?->name() ?? '',
                icon: 'inbox',
                permission: 'sales.collection.view',

                /*
                 * টাকা আসা "ভালো", টাকা যাওয়া নয়।
                 *
                 * তালিকায় চোখ বুলিয়ে দুইটা আলাদা করতে পারা দরকার —
                 * সব সারি একই রঙে থাকলে দিনের গল্পটা পড়তে প্রতিটা
                 * লাইন পড়তে হয়।
                 */
                tone: 'good',
                sourceType: Collection::drillSourceType(),
                sourceId: $collection->id,
            ))
            ->all();
    }

    /** @return list<Happening> */
    private static function returns(int $limit): array
    {
        return SalesReturn::query()
            ->posted()
            ->with('customer')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (SalesReturn $return) => new Happening(
                when: $return->updated_at ?? $return->created_at,
                title: $return->document_no.' · '.__('sales::message.returned_amount', [
                    'amount' => Money::format((string) $return->total, 2),
                ]),
                subtitle: $return->customer?->name() ?? '',
                icon: 'swap',
                permission: 'sales.return.view',

                // ফেরত মানে বিক্রয় উল্টে যাওয়া — চোখে পড়া দরকার
                tone: 'warn',
                sourceType: SalesReturn::drillSourceType(),
                sourceId: $return->id,
            ))
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Services\BatchService;

/**
 * মাল ঢোকার সময় লটটা জন্মানো — চালান ও বিল দুইটার জন্যই।
 *
 * ── কেন ভাগ করা ─────────────────────────────────────────────────────
 * মাল ঢোকে দুই পথে: GRN থাকলে চালানে, না থাকলে সরাসরি বিলে। দুই
 * জায়গায় দুইবার লেখা হলে একদিন একটায় নিয়ম বদলাত আর অন্যটায় থেকে
 * যেত — আর তখন কোন পথে ঢোকা মালের লট সঠিক তা বলার উপায় থাকত না।
 */
trait BringsInLots
{
    /**
     * এই লাইনের লট — লট ধরা পণ্য না হলে কিছুই না।
     *
     * ── কেন লট ধরা না হলে নীরবে ছেড়ে দেওয়া হয় ───────────────────────
     * ডিপোর চাল, ডাল, সাবানে লট নেই আর কোনোদিন হবেও না। ওই পণ্যগুলোয়
     * লট নম্বর চাইলে প্রতিটা ক্রয়ে একটা বানানো নম্বর বসত, আর বানানো
     * লট রিকলের খাতায় একটা মিথ্যা সারি।
     *
     * উল্টোদিকে লট ধরা পণ্যে নম্বরটা **বাধ্যতামূলক** — `BatchService`
     * নিজেই আটকায়। ওটা ছাড়া মালটা ঢুকত "কোন লট জানা নেই" অবস্থায়,
     * আর পরে বিক্রি করতে গেলে ব্যবস্থা বলত লটে যথেষ্ট নেই। আজ ঠিক
     * সেটাই ঘটত, কারণ কোনো পথই লট বানাত না।
     *
     * @param  object  $line  চালানের বা বিলের একটা লাইন
     */
    private function lotFor(object $line, ?string $supplierRef = null): ?Batch
    {
        if (! ($line->product?->track_batch ?? false)) {
            return null;
        }

        return app(BatchService::class)->receive(
            product: $line->product,
            batchNo: (string) ($line->batch_no ?? ''),
            expiry: $line->expiry_date?->toDateString(),
            mrp: $line->mrp === null ? null : (string) $line->mrp,
            supplierRef: $supplierRef,
        );
    }
}

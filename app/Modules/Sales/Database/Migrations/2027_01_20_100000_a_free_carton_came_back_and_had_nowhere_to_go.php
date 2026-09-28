<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ফেরতের সারিতে ফ্রি পরিমাণ — মালিকের সিদ্ধান্ত, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ──────────────────────────────────────────────────────────
 * বিক্রয়ে ফ্রি আর উপহারের মাল **ফ্রি ভাণ্ডার** থেকে বেরোয়
 * (`delivery_challan:free` / `:gift`), কিন্তু ফেরতের সারিতে কেবল `qty`
 * ছিল — অর্থাৎ দামি মাল। ⚠️ ফ্রি কার্টন ফেরত এলে হয় নেওয়াই যেত না
 * ("যত বেচা তার বেশি"), নয় দামি মাল হয়ে তাকে উঠত আর গ্রাহকের পাওনা
 * কমত এমন মালের জন্য যার দাম তিনি কখনো দেননি।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * `free_qty` — ফ্রি ভাণ্ডারে ফেরে, শূন্য দামে; পাওনা আর খাতা ছোঁয় না।
 * ⓘ উপহারের মাল (অন্য পণ্য) বিলের সারিতে বাঁধা থাকে না — ঐ সারিতে
 * `sales_invoice_line_id` খালি আর `qty` শূন্য।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_return_lines', function (Blueprint $table): void {
            $table->decimal('free_qty', 18, 4)->default(0)->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('sal_return_lines', function (Blueprint $table): void {
            $table->dropColumn('free_qty');
        });
    }
};

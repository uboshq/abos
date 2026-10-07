<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "১ কার্টন ফ্রি" লেখা হলো, আর কাগজটা ফেরত এল "১২ পিস" নিয়ে।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * মালিকের নকশায় (৪ সেপ্টেম্বর ২০২৬) সরাসরি ক্রয়ের সারিতে **চারটা**
 * ঘর: `QTY. · UOM · FREE QTY · UOM`। অর্থাৎ ফ্রি পরিমাণের নিজের একক
 * আছে, আর সেটা কেনা পরিমাণের এককের সাথে এক না-ও হতে পারে — মিল
 * কার্টনে বেচে, ফ্রি দেয় পিসে।
 *
 * আজ `PurchaseBillService` ফ্রি পরিমাণ **লাইনের একই `unit_id`** দিয়ে
 * নামায়। ⓘ তাতে গুণটা ভুল হয় না, কিন্তু **যা লেখা হয়েছিল সেটা
 * হারায়**: কেউ "১ কার্টন ফ্রি" বাছলে খাতায় বসে `12`, আর বিলটা আবার
 * খুললে পর্দা দেখায় "১২ পিস ফ্রি"।
 *
 * ── কেন ঠিক এই দুইটা কলাম ───────────────────────────────────────────
 * ⭐ দামের পরিমাণে এই প্রশ্নটার উত্তর রিপো **আগেই** দিয়েছে, আর এটা
 * তার হুবহু নকল:
 *
 *   qty          base এককে বসে, প্রতিটা হিসাব ওটাই পড়ে
 *   entered_qty  যা টাইপ করা হয়েছিল           ⓘ কেবল চোখের জন্য
 *   entered_unit_id  কোন প্যাকে টাইপ করা হয়েছিল
 *
 * ⛔ ফ্রি-র জন্য আলাদা কোনো নিয়ম বানানো হয়নি, ইচ্ছাকৃতভাবে — একই
 * ফাইলে দুইটা নিয়ম থাকলে একদিন কেউ ভুলটাই নকল করত।
 *
 * ⚠️ `free_qty` **বদলায়নি, আর বদলাবেও না** — মজুদ, FEFO আর ফ্রি
 * ভাণ্ডারের প্রতিটা হিসাব ওটাই পড়ে। এই দুইটা কলাম কেবল **মনে রাখে**,
 * গোনে না।
 *
 * ⓘ `free_unit_id` না এলে সার্ভিস আগের মতোই লাইনের `unit_id` ধরে —
 * অর্থাৎ পুরনো প্রতিটা ডাক (API · ইমপোর্ট · সিডার) অবিকল আগের মতো চলে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pur_bill_lines', function (Blueprint $table): void {
            $table->decimal('entered_free_qty', 18, 4)->nullable()->after('entered_unit_id');

            $table->foreignId('free_unit_id')->nullable()->after('entered_free_qty')
                ->constrained('mdm_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pur_bill_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('free_unit_id');
            $table->dropColumn('entered_free_qty');
        });
    }
};

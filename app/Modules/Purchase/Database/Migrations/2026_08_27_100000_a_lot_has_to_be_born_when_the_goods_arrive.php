<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * লট মালের সাথে জন্মায় — চালানে ও বিলে।
 *
 * ── কী ভেঙেছিল ──────────────────────────────────────────────────────
 * `Batch::create` গোটা প্রকল্পে কোথাও ছিল না। যা ছিল তা কেবল লট
 * **খরচ** করার কোড: `BatchAllocator`, FEFO, মেয়াদ আটকানো, MRP সীমা,
 * `IssuedLots`, রিকল — আর `BatchController`, যেটা কেবল **বিদ্যমান** লট
 * শোধরায়। ক্রয়ের কোনো পথ `batch:` পাঠাত না।
 *
 * ফল: মেয়াদ আটকানোর মতো ব্যাচই তৈরি হত না, FEFO-র বাছার মতো লট থাকত
 * না, বিলে ছাপার লট থাকত না, আর রিকল খালি ফিরত। আটটা ইঞ্জিনের পাঁচটা
 * এমন খাবারের অপেক্ষায় বসে ছিল যা কেউ কখনো রাঁধেনি।
 *
 * পরীক্ষাগুলো পাশ করত কারণ প্রতিটা পরীক্ষা নিজে হাতে একটা `Batch`
 * বানিয়ে নিত — জিনিসটা না থাকলেও।
 *
 * ── কেন ঘরগুলো লাইনে, সরাসরি ব্যাচে নয় ───────────────────────────────
 * খসড়া অবস্থায় ব্যাচ তৈরি করলে কাগজটা কখনো নিশ্চিত না হলেও লটটা
 * থেকে যেত — আর তখন "এই লটে কত আছে" প্রশ্নের উত্তর হত শূন্য, অথচ লটটা
 * তালিকায় বসে থাকত। তাই লেখা থাকে লাইনে, আর লট জন্মায় নিশ্চিত করার
 * মুহূর্তে, মালের সাথে একসাথে।
 *
 * ── কেন MRP এখানেও ─────────────────────────────────────────────────
 * ছাপা দাম ব্যাচের, পণ্যের নয় (২১ আগস্টের মাইগ্রেশন)। প্রস্তুতকারক দুই
 * উৎপাদনের মাঝে দাম বদলে ছাপেন, তাই যে মানুষটা মাল বুঝে নিচ্ছেন তিনিই
 * প্যাকেটের গায়ে যা লেখা তা দেখছেন — পরে আর কেউ দেখবে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pur_receipt_lines', 'pur_bill_lines'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                // প্রস্তুতকারকের নিজের নম্বর — রিকলে এটাই বলা হবে
                $t->string('batch_no', 60)->nullable()->after('free_qty');

                // মেয়াদ নেই এমন লটও থাকে (কেবল খুঁজে বের করার জন্য)
                $t->date('expiry_date')->nullable()->after('batch_no');

                // প্যাকেটে ছাপা দাম — এর উপরে বেচা যায় না
                $t->decimal('mrp', 18, 4)->nullable()->after('expiry_date');
            });
        }
    }

    public function down(): void
    {
        foreach (['pur_receipt_lines', 'pur_bill_lines'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn(['batch_no', 'expiry_date', 'mrp']);
            });
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "কাকে দেওয়া হলো" ঘরটা পর্দায় ছিল, টেবিলে ছিল না।
 *
 * ── ⛔ কী ঘটত, ১৮ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
 * খরচের ফর্মে `payee_name` ঘরটা তিন দিন ধরে বসানো ছিল। ব্যবহারকারী নাম
 * লিখতেন, ভাউচার সেভ হত, পর্দায় কোনো ভুল দেখা যেত না — আর নামটা
 * **নীরবে হারিয়ে যেত**, কারণ `vouchers`-এ ওই নামের কোনো কলামই ছিল না।
 *
 * ⚠️ কলাম নেই মানে `$fillable`-এও নেই, তাই Eloquent চুপচাপ ফেলে দিত।
 * ⓘ কোনো ব্যতিক্রম নয়, লগে কিছু নয় — ছয় মাস পরে "কাকে দিয়েছিলাম"
 * প্রশ্নের উত্তর কেবল **নেই** হয়ে থাকত।
 *
 * ── ⭐ দুইটা কলাম, কারণ প্রশ্নটাও দুইটা ──────────────────────────────
 * মালিকের নির্দেশ: *"কাকে দেওয়া হলো ei line Paytype (vendor,
 * transporter/carrier, others …) … others hole কাকে দেওয়া হলো hate
 * likbe"*।
 *
 * অর্থাৎ **ধরনটা তালিকা থেকে** (`payee_type_id` → `mdm_party_types`,
 * যেখানে পরিবহনকারী · কুরিয়ার · হাম্মালি ঠিকাদার · সার্ভিস প্রোভাইডার
 * আগে থেকেই বসানো), আর **নামটা লেখা** (`payee_name`)।
 *
 * ⓘ `party_id`-র সাথে গুলিয়ে ফেলা যাবে না: ওটা খাতার পক্ষ — তাঁর নিজের
 * খতিয়ান আছে, বকেয়া জমে। ⚠️ এটা কেবল কাগজের নাম — রিকশাভাড়া বা
 * চা-নাস্তার দোকানির জন্য খতিয়ান খোলার কোনো মানে নেই, অথচ ছয় মাস পরে
 * বিলটা কার ছিল সেটা জানার দরকার হয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            /*
             * ⓘ `nullOnDelete` — ধরনটা মাস্টার থেকে মুছে ফেললে পুরনো
             * ভাউচারগুলো টিকে থাকে, কেবল ধরনটা খালি হয়। ⛔ ক্যাসকেড
             * দিলে একটা মাস্টার সারি মোছা মানে খাতার সারি মোছা হত।
             */
            $table->foreignId('payee_type_id')->nullable()->after('party_id')
                ->constrained('mdm_party_types')->nullOnDelete();

            $table->string('payee_name')->nullable()->after('payee_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payee_type_id');
            $table->dropColumn('payee_name');
        });
    }
};

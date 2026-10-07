<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কাউন্টারের ডিপোজিটের নিজের কোনো নিয়ম ছিল না।
 *
 * ── ⭐ মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"ডিপোজিট — এটা কাউন্টারের জন্য আলাদা নিয়ম, যেহেতু সরাসরি বিক্রয়
 * কাউন্টারে হয়। বাকিগুলো আলাদা হবে।"* আর ডিপোজিটটা হবে **হিসাবের আসল
 * রসিদ ভাউচার**।
 *
 * ⓘ ভাউচারের অনুমোদন এতদিন কাজের নাম হিসেবে ভাউচারের ধরনটাই পাঠাত
 * (`receipt`) — ফলে কাউন্টারের ডিপোজিট আর হিসাবের হাতে লেখা রসিদ একই
 * নিয়মে বাঁধা থাকত। ⚠️ মালিক কাউন্টারে সই চাইলে সব রসিদ আটকাত, আর
 * উল্টোটাও।
 *
 * ⭐ `origin` বলে ভাউচারটা কোথা থেকে এল। `counter` মানে সরাসরি বিক্রয়ের
 * কাউন্টার — তখন কাজের নাম `counter_deposit`, নিজের ছক। ⓘ খালি মানে
 * বাকি সব; পুরনো প্রতিটা সারি কিছু না বদলেই আগের নিয়মে চলে।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vouchers', 'origin')) {
            return;
        }

        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('origin', 16)->nullable()->after('against_id');
            $table->index(['company_id', 'origin', 'status']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vouchers', 'origin')) {
            return;
        }

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'origin', 'status']);
            $table->dropColumn('origin');
        });
    }
};

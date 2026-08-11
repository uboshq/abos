<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কারণ কোড নিজের খাত বলতে পারবে।
 *
 * ── কেন লাগল ────────────────────────────────────────────────────────
 * মাল তাক থেকে বেরিয়ে যায় বিক্রি ছাড়াও: অফিসে এক কার্টন বিস্কুট খাওয়া
 * হয়, কাউকে উপহার দেওয়া হয়, মালিক বাসার জন্য একটা পণ্য নেন। তিনটাতেই
 * মজুদ কমে **ক্রয়মূল্যে** — কেউ টাকা দেয়নি, তাই বিক্রয়ও নয়।
 *
 * আলাদা হয় কেবল বিপরীত খাতটা, আর সেটাই সবচেয়ে বেশি ভুল হয়:
 *
 *   আপ্যায়ন        → আপ্যায়ন খরচ (ব্যবসার খরচ, মুনাফা কমে)
 *   উপহার          → উপহার/প্রসার — **আলাদা খাত**, কারণ NBR সাধারণত
 *                    এটা খরচ হিসেবে বাদ দেয়, আর কর হিসাবের সময়
 *                    আলাদা করে বের করতে হয়
 *   মালিকের ব্যবহার → উত্তোলন (৩২০০) — **খরচই নয়**; মালিকের মূলধন কমে
 *
 * তৃতীয়টা খরচ লিখলে ব্যবসার মুনাফা কম দেখায় আর মালিকের মূলধনের হিসাব
 * ভুল থাকে — বছরশেষে কে কত নিল তা আর বলা যায় না।
 *
 * খাতটা কারণের গায়ে বসছে, কোডে নয়: কোন খরচের খাতে যাবে সেটা
 * প্রতিষ্ঠানভেদে আলাদা, আর কোম্পানি নিজেই নতুন কারণ যোগ করতে পারে
 * (নিয়ম ৭ — "কিসের তালিকা" সবসময় সারি, কখনো enum নয়)।
 *
 * nullable: পুরনো কারণগুলোর (গণনার পার্থক্য, নষ্ট) কোনো নির্দিষ্ট খাত
 * নেই — সেগুলো আগের মতোই মজুদ ঘাটতি ও উদ্বৃত্তে (৫১৬০) যাবে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdm_reason_codes', function (Blueprint $table) {
            $table->foreignId('account_id')
                ->nullable()
                ->after('context')
                ->constrained('accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mdm_reason_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });
    }
};

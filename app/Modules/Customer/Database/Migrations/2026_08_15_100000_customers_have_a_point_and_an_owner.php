<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * দোকানটা কোথায়, আর কে চালান।
 *
 * ── Point কেন, Area নয় ──────────────────────────────────────────────
 * মালিকের চাওয়া তালিকায় Point আর Area দুইটাই আছে, কিন্তু কলাম একটাই।
 * কারণ Area হলো Point-এর উপরের ধাপ, আর গাছটা (mdm_locations) ইতিমধ্যেই
 * সেই সম্পর্কটা ধরে রাখে: দেশ › বিভাগ › অঞ্চল › এরিয়া › টেরিটরি › পয়েন্ট।
 *
 * দুইটা কলাম রাখলে একদিন একটা পয়েন্ট অন্য এরিয়ায় সরত, আর গ্রাহকের সারিতে
 * পুরনো এরিয়াটাই লেখা থেকে যেত — দুইটা সংখ্যা একই কথা বলার কথা, অথচ
 * বলত না। একটাই বাঁধন, বাকিটা গাছ থেকে গোনা।
 *
 * ── কেন নির্দিষ্ট করে "পয়েন্ট" বলা হয়নি ─────────────────────────────
 * বাঁধনটা গাছের যেকোনো ধাপে যেতে পারে। বাস্তবে দোকান বসে পয়েন্টে, কিন্তু
 * কোনো ডিপো হয়তো টেরিটরি পর্যন্তই নামে, আর কেউ হয়তো রুট পর্যন্ত যায়।
 * ধাপটা কড়া করে বাঁধলে যে কোম্পানির পয়েন্ট নেই তার গ্রাহক কোথাও বসাতে
 * পারত না। (মনে রাখা: Region আর Territory ধাপ দুইটা বন্ধ করা যায়।)
 *
 * ── মালিকের নাম আলাদা কেন ───────────────────────────────────────────
 * দোকানের নাম "মায়ের দোয়া স্টোর", আর মালিক "রফিকুল ইসলাম"। ফোনে ধরতে
 * হলে দ্বিতীয়টা লাগে, আর দোকানের নামের ঘরে ওটা লিখে রাখলে চালানে ভুল
 * নাম ছাপা হত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('mdm_locations')
                ->nullOnDelete();

            $table->string('owner_name', 191)->nullable()->after('name_bn');

            // তালিকায় এলাকা ধরে ছাঁকা হয় — সেটাই সবচেয়ে চলতি প্রশ্ন
            $table->index(['company_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'location_id']);
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn('owner_name');
        });
    }
};

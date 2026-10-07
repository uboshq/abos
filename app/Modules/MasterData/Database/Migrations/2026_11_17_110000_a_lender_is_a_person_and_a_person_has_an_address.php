<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * পক্ষের তিনটা ঘর — সম্পর্ক, ঠিকানা, পরিচয়পত্র।
 *
 * ── ⭐ কেন এটা এখানে, Finance-এ নয় ───────────────────────────────────
 * নমুনার ধারের পর্দায় ঘরগুলো আছে: *"কার কাছ থেকে · সম্পর্ক · নাম ·
 * মোবাইল · ঠিকানা · NID / TIN"*। ⓘ কিন্তু মালিকের নিজের নির্দেশ ছিল
 * *"মাস্টার ডেটা → পক্ষ। একটাই পর্দা, ভূমিকা ধরে ছাঁকনি"* — তাই
 * ঘরগুলো মানুষটার সাথে থাকে, ধারের সারির সাথে নয়।
 *
 * ⛔ ধারের টেবিলে রাখলে একই করিম উদ্দিনের ঠিকানা তিনটা ধারে তিন
 * রকম হতে পারত, আর কোনটা সত্যি তা বলার উপায় থাকত না।
 *
 * ── ⚠️ তিনটাই nullable, আর সেটা ইচ্ছাকৃত ────────────────────────────
 * পুরনো সারিগুলোতে এই তথ্য নেই, আর থাকার কথাও নয়। ⓘ বাধ্যতামূলক করলে
 * পুরনো একজনের নাম বাছলেই ফর্ম আটকে যেত, অথচ টাকাটা ইতিমধ্যে হাতবদল
 * হয়ে গেছে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdm_people', function (Blueprint $table): void {
            /*
             * সম্পর্ক — পারিবারিক, বন্ধু, ব্যবসায়িক।
             *
             * ⓘ মুক্ত লেখা, তালিকা নয়: মালিকের নিজের উদাহরণ ছিল
             * *"করিম উদ্দিন — পারিবারিক"*, আর বাংলাদেশে সম্পর্কের
             * নামগুলো তালিকায় ধরে না (মামাতো ভাই, দুলাভাই, পাড়ার চাচা)।
             */
            $table->string('relationship', 60)->nullable()->after('mobile');

            $table->string('address', 191)->nullable()->after('relationship');

            /*
             * ⚠️ NID আর TIN এক ঘরেই — নমুনায় ওটা একটা ঘর (`NID / TIN`)।
             * ⓘ ব্যক্তির থাকে NID, প্রতিষ্ঠানের TIN, আর একই তালিকায়
             * দুইটাই বসে। ⛔ দুইটা আলাদা ঘর করলে প্রতিটা সারিতে একটা
             * চিরকাল খালি থাকত।
             */
            $table->string('nid_tin', 40)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('mdm_people', function (Blueprint $table): void {
            $table->dropColumn(['relationship', 'address', 'nid_tin']);
        });
    }
};

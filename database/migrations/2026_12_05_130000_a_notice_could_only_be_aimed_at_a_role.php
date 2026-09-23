<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * নোটিশ কেবল ভূমিকা ধরে লক্ষ্য করা যেত।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ৯ ও ১০ ────────────────
 * কোম্পানি · শাখা · বিভাগ · পদ · ভূমিকা · ব্যক্তি — ছয় স্তরে লক্ষ্য
 * করা যেতে হবে, আর *"Company A-এর Notice Company B-এর User দেখতে
 * পারবে না"*।
 *
 * ── ⛔ আগে যা ছিল ───────────────────────────────────────────────────
 * `notice_roles` — একটা নোটিশ আর একটা ভূমিকার নাম। ⚠️ ডিপোর জন্য
 * ওটুকু চলত, কিন্তু *"ঢাকা শাখার সবাইকে"* বা *"শুধু রহিম সাহেবকে"*
 * বলার কোনো পথ ছিল না।
 *
 * ── ⚠️ কেন `morphs()` নয়, একটা লেখা ─────────────────────────────────
 * ⓘ স্বাভাবিক উত্তর হতো polymorphic — `audience_type` + `audience_id`,
 * আর `audience_type`-এ মডেলের পুরো ক্লাস-নাম। ⛔ কিন্তু তখন কোরের
 * সারিতে `App\Modules\MasterData\Models\Department` লেখা থাকত, আর
 * **কোর কোনো মডিউলের নাম জানে না** ([[BoundariesTest]], §১৯.৭)।
 *
 * ⚠️ নিয়মটা ভাঙে ঠিক এভাবেই: একটা নাম, তারপর আরেকটা। ⓘ ছয় মাস পরে
 * মডিউলগুলো আর আলাদা থাকে না, অথচ কোনো মুহূর্তে কেউ সিদ্ধান্ত নেয়নি।
 *
 * ⭐ তাই একটা সাধারণ লেখা: `company:3` · `branch:7` · `role:Storekeeper`
 * · `user:42` · `department:5`। ⓘ কোর নিজের চারটা চেনে; বাকিগুলো যে
 * মডিউলের সম্পত্তি সে নিজে যোগ করে ([[NoticeAudience]])।
 *
 * ── ⓘ আর এতে খোঁজাটাও সস্তা ─────────────────────────────────────────
 * ব্যবহারকারীর চাবিগুলো একবার বানিয়ে একটাই `whereIn` — ⚠️ ছয় স্তরের
 * জন্য ছয়টা জোড় লাগত, আর প্রতিটা তালিকার পাতায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_audiences', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();

            /*
             * ⓘ `kind:id` — যেমন `branch:7`।
             *
             * ⚠️ ভূমিকার বেলায় id নয়, নামই বসে (`role:Storekeeper`)।
             * ⛔ কারণ ভূমিকার id কোম্পানিভেদে আলাদা হয় (Spatie teams),
             * আর নোটিশ এক কোম্পানি থেকে অন্যটায় নকল করা হলে id-টা
             * অন্য কারও ভূমিকায় গিয়ে পড়ত — নীরবে।
             */
            $table->string('match_key', 80);

            $table->timestamps();

            $table->unique(['notice_id', 'match_key'], 'nta_once');
            $table->index(['company_id', 'match_key'], 'nta_lookup');
        });

        /*
         * পুরনো সারিগুলো নতুন ঘরে — ডেটা হারানো যাবে না।
         *
         * ⚠️ `notice_roles` টেবিলটা **রাখা হচ্ছে**, মোছা হচ্ছে না। ⓘ
         * [[Notice]]-এর `scopeForRoles()` এখনো ওটা পড়ে, আর একই
         * মাইগ্রেশনে ঘর বানানো ও পুরনো পথ ভাঙা করলে ভাঙাটা ধরা পড়ত
         * ডিপ্লয়ের পরে।
         *
         * ⓘ পুরনো টেবিল তোলার কাজ চেকলিস্টের ধাপ ৩-এ, যখন বারটাও
         * নতুন পথে চলে আসবে।
         */
        DB::table('notice_roles')
            ->join('notices', 'notices.id', '=', 'notice_roles.notice_id')
            ->select([
                'notices.company_id',
                'notice_roles.notice_id',
                DB::raw("CONCAT('role:', notice_roles.role) as match_key"),
            ])
            ->orderBy('notice_roles.id')
            ->chunk(500, function ($rows) {
                $now = now();

                DB::table('notice_audiences')->insertOrIgnore(
                    collect($rows)->map(fn ($row) => [
                        /* ⓘ v7, v4 নয় — [[HasPublicId]] এই খাতায় সবসময় v7 বসায়,
                           আর সময়-ক্রমানুসারী বলে ইউনিক সূচকটা ক্রমে ভরে */
                        'public_id' => (string) \Illuminate\Support\Str::uuid7(),
                        'company_id' => $row->company_id,
                        'notice_id' => $row->notice_id,
                        'match_key' => $row->match_key,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_audiences');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * অফারটা থাকত বিক্রয়কর্মীর মনে।
 *
 * ── ⭐ মালিকের স্পেক, ২৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"Trade Promotion & Incentive Management"* — আলাদা মডিউল, আর অফারের
 * হিসাব কখনো বিক্রয়ের পর্দার ভিতরে লেখা যাবে না (ধারা ২২)।
 *
 * ── ⛔ আগে যা হত ────────────────────────────────────────────────────
 * ⓘ *"ঈদে ১০০ কার্টনে ৫ ফ্রি"* — কথাটা থাকত মুখে, হোয়াটসঅ্যাপে, বা
 * একটা কাগজে। ⚠️ ফল তিনটা, আর তিনটাই নীরব:
 *
 *   ⓵ একই ক্রেতা দুই বিক্রয়কর্মীর কাছে দুই রকম ছাড় পেতেন
 *   ⓶ অফার কবে শেষ তা কেউ জানত না, তাই চলতেই থাকত
 *   ⓷ মাস শেষে *"কত দিলাম"* প্রশ্নের কোনো উত্তর ছিল না
 *
 * ── ⚠️ কেন এই টেবিলে শর্ত ও সুবিধা নেই ──────────────────────────────
 * ⓘ একটা অফারের **অনেক** শর্ত ও **অনেক** সুবিধা থাকতে পারে — স্ল্যাব
 * মানেই তিন-চারটা সারি (§৭-ঙ)। ⛔ কলামে বসালে *"সর্বোচ্চ কয়টা স্ল্যাব"*
 * বলে একটা সীমা বানাতে হত, আর পঞ্চম স্ল্যাবের দিন টেবিলটা বদলাতে হত।
 *
 * ⓘ ওগুলো নিজের টেবিলে — আর ঠিক এই কারণেই এই সারিটা ছোট।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();

            /* ⓘ `PROM-2026-000001` — স্পেক §২১, নম্বরের সিরিজ থেকে */
            $table->string('code', 32);

            $table->string('name_en');
            $table->string('name_bn');
            $table->string('summary')->nullable();
            $table->text('terms')->nullable();

            $table->string('type', 32);
            $table->string('status', 16);

            /*
             * ⚠️ তারিখ ও সময় আলাদা, একসাথে নয়।
             *
             * ⓘ স্পেক §৬ ধাপ ১-এ চারটা ঘর: শুরুর তারিখ, শেষ তারিখ, শুরুর
             * সময়, শেষ সময়। ⛔ একটা `datetime`-এ মেশালে *"রোজ সকাল ১০টা
             * থেকে দুপুর ২টা"* বলা যেত না — অথচ ওটাই দুপুরের অফারের আকার।
             *
             * ⓘ সময় দুইটা `nullable`: না দিলে গোটা দিনটাই ধরা হয়।
             */
            $table->date('starts_on');
            $table->date('ends_on');

            /*
             * ⚠️ এই দুইটা ঘর **প্রথম দিনের শুরু আর শেষ দিনের শেষ**,
             * রোজকার সময় নয়।
             *
             * ⓘ স্পেকের §৬ ধাপ ১-এ Start Time / End Time হলো অফারের
             * শুরু আর শেষের মুহূর্ত। ⛔ *"রোজ সকাল ১০টা–দুপুর ২টা"*
             * সেটা নয় — ওটা §৬ ধাপ ৩-এর Time Based শর্ত, আর তার জায়গা
             * `promotion_conditions`।
             *
             * ⚠️ আর ঘর দুইটা **সত্যিই পড়া হয়** ([[Promotion::isLiveOn]]
             * ও `scopeLiveOn`)। ⛔ প্রথম খসড়ায় রাখা হয়েছিল অথচ কেউ পড়ত
             * না — তখন *"৩০ জুন সন্ধ্যা ৬টায় শেষ"* অফার রাত ১২টা পর্যন্ত
             * নীরবে চলত, আর পর্দায় সব ঠিক দেখাত। ⓘ যে ঘর দেখানো হয়
             * অথচ মানা হয় না, সেটা না থাকার চেয়ে খারাপ।
             *
             * ⓘ `null` মানে ঐ দিনটা পুরোটাই।
             */
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();

            /*
             * ⭐ অগ্রাধিকার — বড় সংখ্যা আগে (§৯)।
             *
             * ⚠️ একই বিলে দুইটা অফার খাটলে কে জেতে, সেটা এখান থেকেই ঠিক
             * হয়। ⓘ ডিফল্ট শূন্য, অর্থাৎ *"কোনো দাবি নেই"* — আর তখন
             * সিদ্ধান্তটা পরের নিয়মে গড়ায় (exclusive, তারপর সবচেয়ে
             * বেশি সুবিধা)।
             */
            $table->integer('priority')->default(0);

            /*
             * ⭐ একই বিলে একাধিক অফার খাটলে কী হবে — আর **অফারই বলে**।
             *
             * ── ⓘ মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর ২০২৬ ─────────────
             * *"ze kono ekta othoba sobkoti ba eker odhik — offer
             * ghosonar somoyei tik korbe"*।
             *
             * ── ⛔ আগে এখানে দুইটা ঘর ছিল, আর ওরা একসাথে মিথ্যা বলত ──
             * ⓘ `is_exclusive` (একা চলবে) আর `is_stackable` (সবার সাথে
             * চলবে) — দুইটাই `true` বসানো যেত। ⚠️ তখন অফারটা একই সাথে
             * *"একা"* আর *"সবার সাথে"*, আর কোনটা জিতবে তা নির্ভর করত
             * ইঞ্জিন কোন শর্তটা আগে পড়ে তার উপর।
             *
             * ⭐ একটা ঘরে তিনটা মান রাখলে ঐ স্ববিরোধী জোড়টা **লেখাই
             * যায় না** — বৈধতা যাচাই দিয়ে আটকাতে হয় না।
             */
            $table->string('combines', 8)->default('best');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
             * ⚠️ সূচকের নামগুলো হাতে দেওয়া, আর সেটা বাধ্য হয়ে।
             *
             * ⓘ Laravel নিজে নাম বানালে হত `promotions_company_id_code_unique`
             * — ৩৮ অক্ষর, ওটা চলত। ⛔ কিন্তু এই খাতায় আগে ৬৪ অক্ষরের সীমা
             * পেরোনো নাম `migrate:fresh` ভেঙেছে, **প্রত্যেকের** মেশিনে, আর
             * তখন খালি টেস্ট-লগটা দেখতে ধীর রানের মতোই লাগে। ⓘ তাই ছোট নাম
             * দেওয়াটাই এখানকার অভ্যাস।
             */
            $table->unique(['company_id', 'code'], 'prom_co_code_uq');

            /* ⓘ *"আজ কোন অফারগুলো চলছে"* — ইঞ্জিনের প্রথম প্রশ্ন */
            $table->index(['company_id', 'status', 'starts_on', 'ends_on'], 'prom_live_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};

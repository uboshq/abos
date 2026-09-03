<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ব্যাকআপের বইখাতা — চারটা টেবিল।
 *
 * ── আজ পর্যন্ত কী ছিল, আর কী ছিল না ───────────────────────────────────
 * ব্যাকআপ নেওয়ার কাজটা ৩ সেপ্টেম্বর ২০২৬-এও ভালোভাবেই হয়:
 * [[BackupService]] ডাম্প নেয়, gzip করে, **খুলে যাচাই করে**, পুরনোগুলো
 * মোছে, আর `deploy.sh` প্রতিটা deploy-এর আগে সেটা চালায়। মেপে দেখা
 * হয়েছে — একটা ডাম্প ফিরিয়ে এনে ১,৬৬৭ সারি হুবহু মিলেছে, খতিয়ানের
 * দুই পাশ ৮,৪০,০০০।
 *
 * ⚠️ **কিন্তু কোনো টেবিল নেই।** ইতিহাস, নীতি, গন্তব্য, যাচাইয়ের ফল —
 * সবই ফাইল আর একটা JSON লেজারে। তার ফল:
 *
 *   • ইতিহাস দেখতে ফোল্ডার খুলতে হয়
 *   • গন্তব্য একটাই, আর সেটা `.env`-এ (`ABOS_BACKUP_MIRROR`)
 *   • নীতি প্রতি কোম্পানিতে আলাদা করা যায় না
 *   • **অডিট নেই** — কে restore করল তা কোথাও লেখা থাকে না
 *
 * শেষেরটা সবচেয়ে গুরুতর: একটা restore মানে আজকের সব কাজ মুছে যাওয়া,
 * আর কে করল তা না জানার চেয়ে খারাপ কিছু নেই।
 *
 * ── আর যেটা আজ সত্যিই ভাঙা ────────────────────────────────────────────
 * লাইভে ৭৩টা ব্যাকআপ আছে, **সবগুলো একই ডিস্কে**। `ABOS_BACKUP_MIRROR`
 * খালি, তাই ইঞ্জিন নিজেই প্রতিটা রানে বলে: *"দ্বিতীয় কোনো গন্তব্য বলা
 * নেই — একই ডিস্ক নষ্ট হলে ব্যাকআপও হারাবে।"*
 *
 * ৩-২-১ নিয়মের তিনটা শর্তের একটাও আজ পূরণ হয় না। এই মাইগ্রেশনটা সেই
 * সমস্যার সমাধান নয় — সমাধানটা রাখার **জায়গা**।
 *
 * ── চারটা টেবিল কেন, একটা নয় ─────────────────────────────────────────
 *
 *   bak_destinations   কোথায় কপি যাবে — একটা সারি, একটা enum নয়
 *   bak_policies       কত ঘন ঘন, কী ধরনের, কতদিন রাখা
 *   bak_runs           প্রতিবার কী হলো, কোথায় গেল, কতটা গেল
 *   bak_verifications  ওই কপিটা সত্যিই ফেরে কি না
 *
 * শেষ দুইটা আলাদা, কারণ **"ব্যাকআপ নেওয়া হয়েছে" আর "ব্যাকআপ কাজ করে"
 * দুইটা আলাদা কথা**। একটা রানের একাধিক যাচাই থাকতে পারে (checksum,
 * খুলে দেখা, পূর্ণ test restore), আর সেগুলো ভিন্ন সময়ে চলতে পারে।
 * একই সারিতে রাখলে "শেষ কবে সত্যিকারের test restore হয়েছিল" প্রশ্নের
 * উত্তর দেওয়া যেত না।
 *
 * ── ⚠️ গন্তব্য একটা সারি, enum নয় — আর এটাই পুরো নকশার মেরুদণ্ড ──────
 * মালিকের ব্লুপ্রিন্টের শেষ লাইন: *"Google Drive/OneDrive/Dropbox-কে
 * backup-এর সমার্থক না ধরে Backup Destinations হিসেবে রাখা।"*
 *
 * আর ৩ সেপ্টেম্বর তিনি আরও একটা কথা বলেছেন যা এটাকে বাধ্যতামূলক করে:
 * **ABOS দুইভাবেই বিক্রি হবে** — আমাদের সার্ভারে, আর ক্রেতার নিজের
 * সার্ভারে। ক্রেতার ঘরে আমাদের কোনো মেশিন নেই, আমাদের Google অ্যাকাউন্টও
 * নেই। তাই ইঞ্জিন **কোনো মেশিনের নাম জানবে না** — সে জানবে গন্তব্যের
 * ধরন, আর বাকিটা আসবে একটা সেটিংস পর্দা থেকে।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * কোথায় কপি যাবে।
         *
         * ── `driver` + `config`, আর দুইটাই কেন দরকার ────────────────
         * `driver` বলে **কীভাবে** পাঠাতে হবে (`local` · `sftp` · `s3` …),
         * `config` বলে **কোথায়** — পথ, হোস্ট, বাকেট, চাবি। নতুন কোনো
         * সেবা যোগ করতে তাই কেবল একটা driver ক্লাস লাগে; টেবিল বা
         * মাইগ্রেশন ছুঁতে হয় না।
         *
         * ⚠️ `config` **এনক্রিপ্টেড কাস্টে** বসবে ([[BackupDestination]]
         * মডেল দেখুন)। কারণটা এই টেবিলের নিজের কাজেই: SFTP-র key আর
         * S3-এর secret এখানে থাকে, আর **এই ডাটাবেসটাই ব্যাকআপে যায়**।
         * সাদা চোখে রাখলে প্রতিটা ডাম্পের ভেতরে গ্রাহকের চাবি চলে যেত,
         * আর ডাম্পটা চুরি হলে গন্তব্যগুলোও সাথে যেত।
         */
        Schema::create('bak_destinations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 36)->nullable()->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('driver', 32);
            $table->text('config')->nullable();

            /*
             * কোন ভূমিকায় — ৩-২-১-১-০ নিয়মের ভাগগুলো।
             *
             * শুধু সাজানোর জন্য নয়: ড্যাশবোর্ড এটা দেখেই বলতে পারে
             * "অফসাইট কপি নেই" বা "অফলাইন কপি সাত দিনের পুরনো" —
             * অর্থাৎ **নিয়মটা মাপা যায়**, কেবল ঘোষিত থাকে না।
             */
            $table->string('kind', 16)->default('secondary');

            $table->boolean('is_active')->default(true);

            /*
             * স্বাস্থ্যের হিসাব — শেষ কবে সত্যিই পৌঁছানো গেছে।
             *
             * ⚠️ পেনড্রাইভ খুলে রাখাই তো উদ্দেশ্য, তাই "পাওয়া যাচ্ছে না"
             * নিজে থেকে ভুল নয়। ভুল হলো **কতদিন ধরে পাওয়া যাচ্ছে না** —
             * আর সেটা বলার জন্যই `last_ok_at` আলাদা করে রাখা।
             */
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_ok_at')->nullable();
            $table->string('last_error', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
        });

        /*
         * কত ঘন ঘন, কী নেওয়া হবে, কতদিন রাখা।
         *
         * একটা কোম্পানির একাধিক নীতি থাকতে পারে — যেমন "রোজ পূর্ণ, ৩০
         * দিন রাখা" আর "মাসে একবার, সাত বছর রাখা"। একটা সারিতে চাপালে
         * দ্বিতীয়টার জন্য জায়গা থাকত না।
         */
        Schema::create('bak_policies', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 36)->nullable()->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name', 120);

            /* `daily` · `hourly` · `weekly` · `monthly` — cron নয়, কারণ
               cron লেখা একটা কারিগরি দক্ষতা, আর এটা গ্রাহকের পর্দা। */
            $table->string('frequency', 16)->default('daily');
            $table->string('run_at', 5)->default('02:00');

            $table->string('backup_type', 16)->default('full');
            $table->string('scope', 16)->default('all');

            /* কোন কোন গন্তব্যে — id-র তালিকা। ⚠️ pivot টেবিল নয়:
               একটা নীতির গন্তব্য বদলানো মানে পুরো তালিকাটা নতুন করে
               লেখা, আর মাঝখানের অবস্থাগুলোর কোনো মানে নেই। */
            $table->json('destinations')->nullable();

            /*
             * কতদিন রাখা — daily/weekly/monthly/yearly, আর legal hold।
             *
             * `legal_hold` আলাদা ঘর নয়, এই JSON-এর ভেতরে: ওটা একটা
             * **নীতি**, একটা পতাকা নয় — "এই তারিখের আগেরগুলো কিছুতেই
             * মুছবে না, retention যাই বলুক"।
             */
            $table->json('retention')->nullable();

            $table->boolean('encrypt')->default(false);
            $table->boolean('verify')->default(true);
            $table->boolean('test_restore')->default(false);

            $table->string('notify_on', 16)->default('failure');
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
        });

        /*
         * প্রতিবার কী হলো।
         *
         * ── কেন `destinations_ok` আর `destinations_failed` দুইটাই ────
         * একটা ব্যাকআপ পাঁচ জায়গায় যেতে পারে, আর **তিনটায় গিয়ে দুইটায়
         * ব্যর্থ হওয়াটাই সবচেয়ে সাধারণ ফল** — পেনড্রাইভ খোলা, নেট বন্ধ।
         * একটা `status` কলামে ওটা ধরা যায় না: "সফল" বললে মিথ্যা,
         * "ব্যর্থ" বললেও মিথ্যা, আর দুইটাই মানুষকে ভুল কাজ করায়।
         */
        Schema::create('bak_runs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 36)->nullable()->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('bak_policies')->nullOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 16)->default('running');

            $table->string('backup_type', 16)->default('full');
            $table->string('scope', 16)->default('all');

            $table->string('file', 255)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();

            /* sha256 — যাচাইয়ের ভিত্তি, আর গন্তব্যে পৌঁছানোর পর
               মিলিয়ে দেখার একমাত্র উপায়। */
            $table->char('checksum', 64)->nullable();

            $table->json('destinations_ok')->nullable();
            $table->json('destinations_failed')->nullable();
            $table->text('error')->nullable();

            /* `schedule` · `manual` · `deploy` — কে ডেকেছিল।
               deploy-এর ব্যাকআপ আর রাতের ব্যাকআপ এক জিনিস নয়, আর
               তদন্তের সময় প্রথম প্রশ্নটাই এটা। */
            $table->string('triggered_by', 16)->default('schedule');
            $table->foreignId('user_id')->nullable()->constrained('users');

            $table->timestamps();

            $table->index(['company_id', 'started_at']);
            $table->index(['company_id', 'status']);
        });

        /*
         * ওই কপিটা সত্যিই ফেরে কি না।
         *
         * ── কেন আলাদা টেবিল ─────────────────────────────────────────
         * "ব্যাকআপ নেওয়া হয়েছে" আর "ব্যাকআপ কাজ করে" — দুইটা আলাদা
         * কথা, আর দ্বিতীয়টা প্রমাণ করতে হয়, ধরে নেওয়া যায় না।
         *
         * একটা রানের তিন ধরনের যাচাই হতে পারে, আর তিনটা ভিন্ন সময়ে:
         *
         *   checksum       সাথে সাথে, সস্তা
         *   integrity      gzip খুলে দেখা, সস্তা
         *   test_restore   সত্যিই একটা ডাটাবেসে ফিরিয়ে আনা, দামি
         *
         * ⓘ তৃতীয়টা আজও হয় — [[BackupService::verify()]] প্রতিটা রানে
         * ফিরিয়ে এনে টেবিল গোনে। যা নেই তা হলো **ফলটা লিখে রাখা**,
         * আর সেটা না থাকলে "শেষ কবে সত্যিকারের restore পরীক্ষা হয়েছিল"
         * প্রশ্নের উত্তর কারও কাছে নেই।
         */
        Schema::create('bak_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('bak_runs')->cascadeOnDelete();

            $table->string('kind', 16);
            $table->string('status', 16);

            /* কী পাওয়া গেল — কয়টা টেবিল, কয়টা সারি, কোন হ্যাশ।
               ⚠️ "সফল" লেখা একটা সারি প্রমাণ নয়; সংখ্যাটাই প্রমাণ। */
            $table->json('detail')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('verified_at');

            $table->index(['run_id', 'kind']);
        });
    }

    public function down(): void
    {
        // ⚠️ ক্রমটা উল্টো — সন্তান আগে, নাহলে foreign key আটকে দেয়
        Schema::dropIfExists('bak_verifications');
        Schema::dropIfExists('bak_runs');
        Schema::dropIfExists('bak_policies');
        Schema::dropIfExists('bak_destinations');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * অফলাইন সিঙ্কের বইখাতা — চারটা টেবিল, চারটা আলাদা প্রশ্নের উত্তর।
 *
 * ── কী ছিল না ─────────────────────────────────────────────────────────
 * ২ সেপ্টেম্বর ২০২৬ পর্যন্ত ABOS-এ `api` রুট শূন্য, টোকেন শূন্য, আর
 * সিঙ্কের কোনো ধারণাই নেই। সেলসম্যান দোকানে গিয়ে নেটওয়ার্কের বাইরে
 * চলে গেলে অর্ডারটা লেখার কোনো জায়গা নেই — কাগজে লিখে ফিরে এসে আবার
 * টাইপ করা ছাড়া।
 *
 * মালিকের সিদ্ধান্ত (২ সেপ্টেম্বর): **নেট না থাকলে শুধু অর্ডার।** চালান,
 * বিল, আদায় বা POS নয় — ওগুলোয় নম্বর ইস্যু হয়, স্টক কমে, খতিয়ানে
 * দাখিলা বসে, আর তিনটার একটাও অফলাইনে সৎভাবে করা যায় না।
 *
 * ── চারটা টেবিল কেন, একটা নয় ─────────────────────────────────────────
 *
 *   sync_devices    কোন হ্যান্ডসেট, কার, কোন কোম্পানির
 *   sync_states     ওই হ্যান্ডসেট কোন মডিউল কতদূর পর্যন্ত নামিয়েছে
 *   sync_changes    কোন বদলটা এসেছে, আর তার সাথে কী হয়েছে
 *   sync_conflicts  যেটা নিয়ে ব্যবস্থাটা নিজে সিদ্ধান্ত নিতে পারেনি
 *
 * একটা টেবিলে মেশালে "এই ডিভাইস কতদূর পেয়েছে" প্রশ্নের উত্তর দিতে
 * লক্ষ সারির লগ স্ক্যান করতে হত, আর ওই প্রশ্নটা প্রতিটা সিঙ্কে একবার
 * করে ওঠে।
 *
 * ── কেন `sync_changes`-ই আসল পাহারা ───────────────────────────────────
 * মোবাইল নেটওয়ার্কে একই অনুরোধ দুইবার পৌঁছানো **নিয়ম, ব্যতিক্রম নয়** —
 * উত্তরটা পথে হারালে ফোন আবার পাঠায়, আর ফোনের দিক থেকে দুইটা চেষ্টা
 * দেখতে হুবহু এক।
 *
 * পাহারা না থাকলে একই অর্ডার দুইবার বসত, আর **কোনো যাচাই লাল হত না**:
 * দুইটা অর্ডারই বৈধ, দুইটাতেই সব ঘর ভরা। ভুলটা ধরা পড়ত মাস শেষে, যখন
 * কেউ একটা সংখ্যা মেলাতে গিয়ে আটকে যেতেন — ঠিক সেই আকারের বাগ যেটা
 * `posted_documents` প্রহরী-টেবিল খতিয়ানের জন্য ঠেকায়।
 *
 * তাই একই সমাধান, একই আকারে: **ডিভাইস প্রতিটা চেষ্টায় একই `change_id`
 * পাঠায়** (Dart দিকে `SyncEngine._newChangeId()` একবার তৈরি করে সারিতে
 * বসিয়ে রাখে), আর এখানে `(device_id, change_id)` unique। দ্বিতীয়বার
 * এলে সারিটা বসে না, আর ফোনকে `DUPLICATE` বলা হয় — যা তার কাছে
 * `APPLIED`-এর সমান, অর্থাৎ কিউ থেকে মুছে ফেলার সংকেত।
 *
 * ── কেন `change_id` কেবল ডিভাইসের ভেতরে unique ────────────────────────
 * চাবিটা ফোনে তৈরি হয়: `local-<মাইক্রোসেকেন্ড>-<ক্রম>`। দুইটা আলাদা
 * ফোন একই মাইক্রোসেকেন্ডে প্রথম বদলটা সারিতে ফেললে **দুইটাই
 * `local-<একই>-0`** — বিশ্বজুড়ে unique ধরলে দ্বিতীয় ফোনের আসল
 * অর্ডারটা `DUPLICATE` বলে চুপচাপ ফেলে দেওয়া হত।
 *
 * ডিভাইস ধরে unique করলে সেই সম্ভাবনাটাই থাকে না, আর যা ঠেকানোর কথা
 * সেটা ঠিকই ঠেকে: **একই ফোনের একই বদল দুইবার**।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * হ্যান্ডসেটটা কে।
         *
         * ── কেন হার্ডওয়্যার আইডি নয় ─────────────────────────────────
         * চাবিটা ফোন নিজে তৈরি করে (Random.secure, ১৬ বাইট) আর
         * keystore-এ রাখে। হার্ডওয়্যারের আইডি ব্যবহারিকভাবেও পাওয়া যায়
         * না (Android ১০ থেকে বন্ধ), আর নীতিগতভাবেও ভুল: ওটা ফোনের
         * সাথে থেকে যায়, ব্যবহারকারী বদলালেও।
         *
         * ── ব্যবহারকারী বদলাতে পারে, ডিভাইস একই থাকে ─────────────────
         * একটা ফোন কোম্পানির, ব্যক্তির নয় — সেলসম্যান বদলালে ফোনটা
         * পরের জনের হাতে যায়। তাই `user_id` **বদলায়**, প্রতিটা লগইনে
         * নতুন করে বসে, আর `device_id` থেকে যায়। ওয়াটারমার্কও থেকে
         * যায়, যা ঠিক: ক্যাটালগ তো একই কোম্পানির।
         *
         * কোম্পানি বদলে গেলে ব্যাপারটা আলাদা — তখন আগের কোম্পানির
         * ক্যাটালগ ফোনে থেকে যাওয়া মানে টেন্যান্টের দেয়াল ফুটো। ওটা
         * ফোনের দিকে সামলানো হয় (`ReferenceCache.clearAll()` সাইন-আউটে)
         * আর সার্ভারের দিকে এই কলামটা দিয়ে: কোম্পানি বদলালে
         * ওয়াটারমার্ক শূন্য থেকে শুরু হয়।
         */
        Schema::create('sync_devices', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->string('device_id', 64)->unique();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * শেষ কবে কথা হয়েছে, আর কোন সংস্করণ থেকে।
             *
             * দুইটাই সহায়তার জন্য: "আমার অর্ডার যাচ্ছে না" ফোন এলে
             * প্রথম প্রশ্ন দুইটাই — ফোনটা কি আদৌ সার্ভারে পৌঁছাচ্ছে,
             * আর সে কি এত পুরনো বিল্ড যে চুক্তিটাই আলাদা।
             */
            $table->string('app_version', 32)->nullable();
            $table->string('platform', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'last_seen_at']);
        });

        /*
         * ওয়াটারমার্ক — এই ডিভাইস এই মডিউলের কতদূর পর্যন্ত নামিয়েছে।
         *
         * ── কেন সার্ভার মনে রাখে, ফোন নয় ────────────────────────────
         * ফোন `since` পাঠালে দুইটা জিনিস ভাঙত। এক, ফোনের ঘড়ি ভুল হতে
         * পারে — আর ভুল ঘড়ির `since` মানে হয় একই ডেটা বারবার, নয়
         * **চুপচাপ বাদ পড়া রেকর্ড**। দুই, উত্তরটা পথে হারালে ফোন জানে
         * না সে ওটা পেয়েছিল কি না, তাই `since` এগোবে কি না বলতে পারে
         * না।
         *
         * সার্ভার মনে রাখলে `GET .../pull` **প্যারামিটারহীন** হয়ে যায়,
         * আর সেটাই তাকে নিরাপদে পুনরাবৃত্তিযোগ্য করে: পরপর দুইবার
         * ডাকলে হুবহু একই ব্যাচ ফেরে। ওয়াটারমার্ক এগোয় কেবল ফোন যখন
         * আলাদা করে বলে "পুরোটা পেয়েছি" (`pull-complete`)।
         */
        Schema::create('sync_states', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 64);
            $table->string('module', 32);

            /*
             * NULL মানে "এই ডিভাইস এই মডিউলের কিছুই পায়নি" — প্রথম
             * সিঙ্কে পুরো ক্যাটালগ যাবে। শূন্য তারিখ বসালে সেটাই বোঝাত,
             * কিন্তু "কখনো হয়নি" আর "১৯৭০ সালে হয়েছিল" এক জিনিস নয়,
             * আর সহায়তার সময় পার্থক্যটা কাজে লাগে।
             */
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->unique(['device_id', 'module']);
        });

        /*
         * প্রতিটা পুশ করা বদল, আর তার পরিণতি।
         *
         * ── কেন প্রত্যাখ্যাত বদলও রাখা হয় ───────────────────────────
         * শুধু সফলগুলো রাখলে টেবিলটা ছোট থাকত। কিন্তু তখন "আমার
         * অর্ডারটা কোথায় গেল" প্রশ্নের উত্তর সার্ভারের দিকে থাকত না —
         * থাকত কেবল ওই ফোনটায়, আর ফোনটা তখন সেলসম্যানের পকেটে।
         *
         * ফোনের দিকেও একই নিয়ম: `SyncEngine.rejectedItems` প্রত্যাখ্যাত
         * সারি মুছে ফেলে না, কারণসহ ধরে রাখে। **দুই দিকেই একই সিদ্ধান্ত**,
         * আর সেটা ইচ্ছাকৃত: যে বদলটা ঘটেনি সেটাও একটা ঘটনা।
         */
        Schema::create('sync_changes', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 64);

            /*
             * ফোনে তৈরি চাবি — দেখুন উপরের ক্লাস-মন্তব্য। unique-টা
             * `(device_id, change_id)` জোড়ায়, একা `change_id`-তে নয়।
             */
            $table->string('change_id', 64);

            $table->string('module', 32);
            $table->string('entity_type', 64);

            /*
             * CREATE-এ ফাঁকা (সার্ভার এখনো সারিটা বানায়নি), UPDATE-এ
             * ভরা। বসানোর পর `applied_entity_id`-তে আসল আইডিটা লেখা হয়,
             * যাতে পরে "এই বদলটা কোন অর্ডার হলো" জিজ্ঞেস করা যায়।
             */
            $table->string('entity_id', 64)->nullable();
            $table->string('operation', 16);

            /*
             * যা এসেছে, হুবহু — ব্যাখ্যা করার আগে।
             *
             * ── কেন কাঁচা JSON, কলামে ভেঙে নয় ───────────────────────
             * সারিটা প্রমাণ। কলামে ভাঙলে যে ঘরগুলো আমরা চিনি কেবল
             * সেগুলোই থাকত, আর একটা পুরনো বিল্ড যদি এমন কিছু পাঠায় যা
             * আজকের কোড চেনে না, সেটা নীরবে হারিয়ে যেত — অথচ ঠিক
             * ওই ক্ষেত্রেই প্রমাণটা সবচেয়ে বেশি দরকার।
             */
            $table->longText('payload_json');

            $table->unsignedInteger('client_version')->default(1);

            /*
             * APPLIED · DUPLICATE · CONFLICT · REJECTED — ফোনের
             * `reasonIfRejected()` ঠিক এই চারটাই চেনে, আর প্রথম দুইটাকে
             * "কাজ শেষ" ধরে।
             */
            $table->string('status', 16);

            /*
             * কেন হয়নি — **বাংলায়, মানুষের জন্য**।
             *
             * এটা লগ নয়, UI: ফোনের অ্যাপ ইংরেজি framework-noise গিলে
             * ফেলে আর বাংলা বার্তাগুলো সেলসম্যানকে দেখায়। "credit limit
             * exceeded" লিখলে তিনি কিছুই বুঝতেন না।
             */
            $table->text('message')->nullable();
            $table->string('applied_entity_id', 64)->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('received_at');

            $table->timestamps();

            $table->unique(['device_id', 'change_id']);
            $table->index(['company_id', 'module', 'status']);
        });

        /*
         * যা নিয়ে ব্যবস্থাটা নিজে সিদ্ধান্ত নেয়নি।
         *
         * ── কেন "শেষ লেখাই জেতে" যথেষ্ট নয় ──────────────────────────
         * সবচেয়ে সহজ নিয়ম হলো নতুনটা পুরনোটাকে চাপা দেবে। ওটা
         * সেটিংসে চলে, **ব্যবসার নথিতে নয়**: অফিসে কেউ অর্ডারের পরিমাণ
         * কমিয়েছেন, আর মাঠে সেলসম্যানের ফোনে পুরনো পরিমাণটা বসে আছে।
         * শেষ লেখাটা জিতলে অফিসের সিদ্ধান্তটা **নীরবে মুছে যেত**, আর
         * কেউ জানত না।
         *
         * তাই সংঘর্ষ একটা সারি হয়ে বসে, আর মানুষ দেখে সিদ্ধান্ত নেন।
         * দুইটা রূপই রাখা হয় — ফোনেরটা আর সার্ভারেরটা — কারণ একটা
         * ছাড়া অন্যটা দেখে সিদ্ধান্ত নেওয়া যায় না।
         *
         * ⚠️ দুইটা একসাথে থাকার মানে এই সারিটা **দুই পাশের যোগফলের
         * চেয়ে বেশি গোপন**। তাই দেখার দরজায় অডিট-স্তরের অনুমতি।
         */
        Schema::create('sync_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 64);
            $table->string('module', 32);
            $table->string('entity_type', 64);
            $table->string('entity_id', 64)->nullable();

            $table->text('reason')->nullable();
            $table->longText('client_payload_json')->nullable();
            $table->longText('server_snapshot_json')->nullable();

            /*
             * PENDING_MANUAL_RESOLUTION · RESOLVED —
             * `AUTO_RESOLVED_LAST_WRITE_WINS` ইচ্ছে করে **নেই**, কারণ
             * উপরে লেখা কারণে এই ব্যবস্থাটা নিজে থেকে জেতায় না। ফোনের
             * `SyncConflict.status` কাঁচা স্ট্রিং রাখে, তাই পরে একটা
             * তৃতীয় অবস্থা যোগ করলে অ্যাপ ভাঙবে না।
             */
            $table->string('status', 32)->default('PENDING_MANUAL_RESOLUTION');

            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        /*
         * বিপরীত ক্রমে — `sync_conflicts` আর `sync_changes` দুইটাই
         * `companies` ও `users`-এর দিকে তাকায়, তাই ওগুলো আগে যাবে।
         * CI-তে `migrate:rollback --step=5` চলে, তাই এই ক্রমটা সত্যিই
         * পরীক্ষিত হয়, অনুমান নয়।
         */
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_changes');
        Schema::dropIfExists('sync_states');
        Schema::dropIfExists('sync_devices');
    }
};

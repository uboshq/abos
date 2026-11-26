<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ABOS-এর ভিতরে মেইল (মালিক, ২৫–২৬ নভেম্বর ২০২৬, নমুনা v4 "ok") — মেইল থাকে মেইল সার্ভারে, ABOS কেবল দেখায় আর পাঠায়।
 *
 * - `mail_accounts` — একটা মেইলবক্স: কোম্পানির (নিজের বা দলের) বা কারো ব্যক্তিগত (Gmail, Hotmail, Yahoo, যেকোনো IMAP)।
 *   ⛔ পাসওয়ার্ড আর টোকেন তালাবদ্ধ (Laravel `encrypted` cast); মেইলের লেখা কোথাও জমা থাকে না।
 *   ⭐ ব্যক্তিগত মেইল অনুমতি সাপেক্ষে — `status` pending → active (অনুমোদনকারী), মেয়াদ শেষে expired; ব্যাংকের মতো।
 * - `mail_account_members` — দলের মেইলবক্স কে কে দেখেন, কে পাঠাতে পারেন।
 * - `mail_links` — কোন মেইল কোন কাগজ বা গ্রাহকের পাতায় রাখা; মেইলটা চেনা যায় `message_id` দিয়ে।
 * - `mail_sends` — ABOS থেকে পাঠানো প্রতিটা মেইলের খাতা (কে, কোন ঠিকানা থেকে, কাকে, কোন কাগজ); কাগজের পাতায় "মেইলে পাঠানো"।
 * - `mail_templates` — কাজ ধরে টেমপ্লেট (আদায়, বিক্রয়, ডেলিভারি, ক্রয়, HR, উৎসব, অফার, কাগজ, ছোট উত্তর)।
 * - `mail_profiles` — কর্মীর সই: নিজের ছবি, নতুন মেইল আর উত্তরের নকশা, লিংক, যাদের ছবি সবসময় দেখাবেন।
 * - `mail_festivals` — উৎসব ও দিবস (কোম্পানি নিজে যোগ/লুকায়; চাঁদের তারিখ প্রতি বছর বসে)।
 * - `mail_addresses` — নিজের ডোমেইনের ঠিকানা (প্রশাসন): তৈরি, বন্ধ, মোছা — সার্ভারে cPanel দিয়ে, খাতা এখানে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // ⓘ ব্যক্তিগত বা নিজের মেইলবক্সের মালিক; দলের মেইলবক্সে খালি (সদস্য তালিকা আলাদা)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 12);                      // own | shared | personal
            $table->string('provider', 12);                  // company | gmail | outlook | yahoo | imap
            $table->string('label', 80)->nullable();         // "বিক্রয়"
            $table->string('colour', 9)->nullable();         // তালিকায় মেইলবক্সের রং
            $table->string('email', 191);
            $table->string('imap_host', 191)->nullable();
            $table->unsignedSmallInteger('imap_port')->nullable();
            $table->string('imap_secure', 8)->nullable();    // ssl | tls | none
            $table->string('smtp_host', 191)->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_secure', 8)->nullable();
            $table->string('username', 191)->nullable();
            $table->text('secret')->nullable();              // encrypted — পাসওয়ার্ড / অ্যাপ-পাসওয়ার্ড
            $table->text('oauth')->nullable();               // encrypted JSON — access/refresh token, expiry
            $table->string('sender_name', 120)->nullable();  // দলের মেইলবক্সে পাঠানো মেইল কার নামে ("Univer বিক্রয়")
            $table->boolean('sender_with_person')->default(false); // "তানভীর · Univer বিক্রয়"
            $table->string('status', 12)->default('active'); // active | pending | rejected | expired | off
            $table->string('reason', 500)->nullable();       // ব্যক্তিগত মেইল কেন দরকার
            $table->date('expires_on')->nullable();          // ব্যক্তিগত মেইলের মেয়াদ
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('checked_at')->nullable();     // শেষবার সফল সংযোগ
            $table->string('last_error', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'email', 'user_id'], 'mail_accounts_company_email_user');
            $table->index(['company_id', 'status'], 'mail_accounts_company_status');
        });

        Schema::create('mail_account_members', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('mail_account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_send')->default(true);
            $table->timestamps();

            $table->unique(['mail_account_id', 'user_id'], 'mail_members_account_user');
        });

        Schema::create('mail_links', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $table->string('message_id', 255);               // মেইলের নিজের Message-ID — ফোল্ডার বদলালেও একই
            $table->string('subject', 255)->nullable();
            $table->string('from_email', 191)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('linkable_type', 64);
            $table->unsignedBigInteger('linkable_id');
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mail_account_id', 'message_id', 'linkable_type', 'linkable_id'], 'mail_links_unique');
            $table->index(['linkable_type', 'linkable_id'], 'mail_links_paper');
        });

        Schema::create('mail_sends', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_account_id')->nullable()->constrained('mail_accounts')->nullOnDelete();
            $table->string('from_email', 191);
            $table->string('to', 1000);
            $table->string('cc', 1000)->nullable();
            $table->string('subject', 255);
            $table->string('message_id', 255)->nullable();
            $table->string('paper_type', 64)->nullable();    // কাগজ থেকে পাঠালে — বিল, চালান, খতিয়ান…
            $table->unsignedBigInteger('paper_id')->nullable();
            $table->string('paper_no', 64)->nullable();
            $table->foreignId('template_id')->nullable();
            $table->unsignedSmallInteger('files')->default(0);
            $table->boolean('auto')->default(false);         // নিজে থেকে পাঠানো (স্মরণ, উৎসব)
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['paper_type', 'paper_id'], 'mail_sends_paper');
            $table->index(['company_id', 'sent_at'], 'mail_sends_company_sent');
        });

        // ⭐ টেমপ্লেট — কোম্পানির (user_id খালি; মেইল-ব্যবস্থাপক বানান) বা কর্মীর নিজের
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category', 20);                  // collect | sales | delivery | purchase | hr | festival | offer | papers | reply
            $table->string('key', 40)->nullable();           // sales_bill | receipt | statement | due_reminder | purchase_order | festival key …
            $table->string('name', 120);
            $table->string('locale', 5)->default('bn');      // bn | en | both
            $table->string('format', 10)->default('designed'); // designed | plain
            $table->string('colour', 9)->nullable();         // মাথার রং
            $table->string('subject', 255);
            $table->text('body');                            // {গ্রাহকের নাম}, {বাকি টাকা} … ঘরসহ
            $table->string('button', 60)->nullable();        // "খতিয়ান দেখুন"
            $table->boolean('attach_paper')->default(false);
            $table->json('auto')->nullable();                // নিজে থেকে পাঠানো: {"on":false,"rule":"due_30","day":"mon","at":"10:00"}
            $table->boolean('active')->default(true);
            $table->unsignedInteger('used')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'category'], 'mail_templates_company_category');
        });

        // ⭐ কর্মীর সই — নিজের ছবি, নতুন মেইল আর উত্তরের নকশা আলাদা, লিংক
        Schema::create('mail_profiles', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('photo_path', 255)->nullable();
            $table->unsignedTinyInteger('style_new')->default(1);
            $table->unsignedTinyInteger('style_reply')->default(10);
            $table->boolean('festival')->default(true);      // উৎসবের দিনে সইয়ের পাশে শুভেচ্ছা
            $table->string('title', 120)->nullable();        // পদবি — না থাকলে HR থেকে
            $table->string('phone', 40)->nullable();
            $table->json('links')->nullable();               // facebook, linkedin, youtube, whatsapp, website
            $table->json('trusted')->nullable();             // যাদের মেইলের ছবি সবসময় দেখাবেন
            $table->text('custom')->nullable();              // "নিজে বানান" — নিজের সই (নকশা 21)
            $table->timestamps();

            $table->unique(['company_id', 'user_id'], 'mail_profiles_company_user');
        });

        // ⭐ পরে পাঠান — লেখা তালাবদ্ধ থাকে কেবল পাঠানো পর্যন্ত, পাঠানো হলেই সারিটা মোছে (মেইলের লেখা ABOS-এ জমা থাকে না)
        Schema::create('mail_outbox', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->longText('payload');                     // encrypted — প্রাপক, বিষয়, লেখা, সই, কাগজের নাম
            $table->timestamp('send_at');
            $table->string('last_error', 500)->nullable();
            $table->unsignedTinyInteger('tries')->default(0);
            $table->timestamps();

            $table->index(['send_at'], 'mail_outbox_send_at');
        });

        Schema::create('mail_festivals', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('name', 80);
            $table->string('greeting', 120);
            $table->string('line', 200);
            $table->string('when', 60);                      // "শাওয়াল 1", "26 মার্চ"
            $table->date('on_date')->nullable();             // এ বছরের তারিখ — চাঁদের দিন হাতে বসে
            $table->json('colours');                         // [from, to, accent]
            $table->string('mark', 16)->nullable();          // 🌙 🪔 …
            $table->boolean('quiet')->default(false);        // জাতীয় দিবস — শুধু শ্রদ্ধা, অফার নয়
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'key'], 'mail_festivals_company_key');
        });

        Schema::create('mail_addresses', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('email', 191);
            $table->string('domain', 120);
            $table->string('kind', 10);                      // person | shared | system
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('display_name', 120)->nullable();
            $table->unsignedInteger('quota_mb')->default(5120);
            $table->string('status', 10)->default('active'); // active | paused | deleting | deleted
            $table->string('pause_mode', 10)->nullable();    // forward | bounce
            $table->string('forward_to', 191)->nullable();
            $table->string('pause_reason', 200)->nullable();
            $table->date('resume_on')->nullable();
            $table->date('delete_on')->nullable();           // মোছার দিন — তার আগে ফেরানো যায়
            $table->string('handed_to', 191)->nullable();    // মোছার আগে মেইল কাকে দেওয়া হলো
            $table->json('auto_reply')->nullable();          // স্বয়ংক্রিয় উত্তর: {on, subject, body, start, stop}
            $table->foreignId('mail_account_id')->nullable()->constrained('mail_accounts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'email'], 'mail_addresses_company_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_addresses');
        Schema::dropIfExists('mail_festivals');
        Schema::dropIfExists('mail_outbox');
        Schema::dropIfExists('mail_profiles');
        Schema::dropIfExists('mail_templates');
        Schema::dropIfExists('mail_sends');
        Schema::dropIfExists('mail_links');
        Schema::dropIfExists('mail_account_members');
        Schema::dropIfExists('mail_accounts');
    }
};

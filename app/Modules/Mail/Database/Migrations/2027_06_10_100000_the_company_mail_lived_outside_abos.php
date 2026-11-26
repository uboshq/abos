<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ABOS-এর ভিতরে মেইল (মালিক, ২৫–২৬ নভেম্বর ২০২৬, নমুনা v3 "ok") — মেইল থাকে মেইল সার্ভারে, ABOS কেবল দেখায় আর পাঠায়।
 *
 * - `mail_accounts` — একটা মেইলবক্স: কোম্পানির (নিজের বা দলের) বা কারো ব্যক্তিগত (Gmail, Hotmail, Yahoo, যেকোনো IMAP)।
 *   ⛔ পাসওয়ার্ড আর টোকেন তালাবদ্ধ (Laravel `encrypted` cast); মেইলের লেখা কোথাও জমা থাকে না।
 *   ⭐ ব্যক্তিগত মেইল অনুমতি সাপেক্ষে — `status` pending → active (অনুমোদনকারী), মেয়াদ শেষে expired; ব্যাংকের মতো।
 * - `mail_account_members` — দলের মেইলবক্স কে কে দেখেন (মালিকের উত্তর "ক": দেওয়া সবাই দেখেন)।
 * - `mail_links` — কোন মেইল কোন কাগজের (গ্রাহক, বিল, ক্রয় আদেশ…) সাথে বাঁধা; মেইলটা চেনা যায় `message_id` দিয়ে।
 * - `mail_sent_papers` — কোন কাগজ কবে কাকে মেইলে গেল (বিলের পাতায় দেখায়)।
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
            $table->string('label', 80)->nullable();         // "বিক্রয় দল"
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
            $table->text('signature')->nullable();
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

        Schema::create('mail_sent_papers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_account_id')->nullable()->constrained('mail_accounts')->nullOnDelete();
            $table->string('paper_type', 64);
            $table->unsignedBigInteger('paper_id');
            $table->string('to', 500);
            $table->string('subject', 255);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['paper_type', 'paper_id'], 'mail_sent_papers_paper');
        });

        // ⭐ টেমপ্লেট — কোম্পানির (user_id খালি; মালিক/মেইল-ব্যবস্থাপক বানান) বা কর্মীর নিজের; `key` দিয়ে কাগজ চেনে (sales_bill …)
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 40)->nullable();           // sales_bill | receipt | statement | due_reminder | purchase_order | reply
            $table->string('name', 120);
            $table->string('locale', 5)->default('bn');      // bn | en | both
            $table->string('subject', 255);
            $table->text('body');                            // {নাম}, {বিল}, {টাকা} … ঘরসহ
            $table->boolean('attach_paper')->default(true);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'key'], 'mail_templates_company_key');
        });

        // ⭐ কর্মীর স্বাক্ষর — নিজের ছবি, পছন্দের ধরন (১–১০), নতুন মেইল আর উত্তরের জন্য আলাদা
        Schema::create('mail_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('photo_path', 255)->nullable();
            $table->unsignedTinyInteger('style_new')->default(10);
            $table->unsignedTinyInteger('style_reply')->default(8);
            $table->string('title', 120)->nullable();        // পদবি — না থাকলে প্রোফাইল থেকে
            $table->string('phone', 40)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id'], 'mail_profiles_company_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_profiles');
        Schema::dropIfExists('mail_templates');
        Schema::dropIfExists('mail_sent_papers');
        Schema::dropIfExists('mail_links');
        Schema::dropIfExists('mail_account_members');
        Schema::dropIfExists('mail_accounts');
    }
};

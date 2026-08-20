<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * গ্রাহক পোর্টাল ও জমার দাবি।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ডিলার নিজের বকেয়া জানতে ফোন করেন। টাকা জমা দিয়ে আবার ফোন করেন —
 * "স্লিপটা পাঠালাম, দেখে নিয়েন"। ডিপোর কেউ একজন হোয়াটসঅ্যাপে ছবিটা
 * দেখে, খাতায় বসায়, বা ভুলে যায়।
 *
 * ভুলে গেলে ডিলারের খাতায় টাকাটা বসে না, পরের বিলে বকেয়া বেশি দেখায়,
 * আর তর্কটা শুরু হয় দুই সপ্তাহ পরে — যখন কারো হাতে আর স্লিপটা নেই।
 *
 * ── দুইটা জিনিস, আর দুইটাই দরকার ────────────────────────────────────
 * এক, ডিলার নিজে ঢুকে নিজের খাতা দেখতে পারবেন। তাতে ফোনটাই লাগে না।
 *
 * দুই, জমার দাবি: "এই তারিখে, এই ব্যাংকে, এই রেফারেন্সে, এত টাকা
 * দিয়েছি"। দাবিটা নিজে থেকে খাতায় বসে না — ডিপো যাচাই করে গ্রহণ
 * করলে তবেই আদায় হয়। কিন্তু দাবিটা **লেখা থাকে**, তারিখসহ, আর সেটাই
 * পুরো পার্থক্য: হোয়াটসঅ্যাপের ছবি হারিয়ে যায়, সারি হারায় না।
 *
 * ── কেন দাবি সরাসরি আদায় নয় ─────────────────────────────────────────
 * ডিলারের কথায় খাতায় টাকা বসানো যায় না। ব্যাংকে টাকাটা সত্যিই এসেছে
 * কি না সেটা ডিপো দেখে, আর ওই যাচাইটাই আদায় ব্যবস্থার ভিত্তি। দাবি
 * সরাসরি বসলে যে কেউ বসে বসে নিজের বকেয়া শূন্য করে ফেলতে পারতেন।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            /*
             * পোর্টালের প্রবেশপথ।
             *
             * আলাদা টেবিল নয়, কারণ যিনি ঢোকেন তিনিই গ্রাহক — মাঝখানে
             * একটা টেবিল বসালে "কোন লগইন কোন ডিলারের" প্রশ্নটা একটা
             * জোড়ের উপর নির্ভর করত, আর ওই জোড়ে একদিন ভুল হত। এখানে
             * ভুল হওয়ার জায়গাই নেই: সারিটাই ডিলার।
             */
            $table->string('portal_password', 255)->nullable()->after('email');
            $table->boolean('portal_enabled')->default(false)->after('portal_password');
            $table->timestamp('portal_last_login_at')->nullable()->after('portal_enabled');
            $table->rememberToken();
        });

        Schema::create('sal_deposit_claims', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->date('claimed_on');
            $table->decimal('amount', 18, 4);

            /* কীভাবে দিয়েছেন — ব্যাংক, বিকাশ, নগদ। */
            $table->string('method', 16);

            /*
             * রেফারেন্স — ব্যাংকের স্লিপ নম্বর, বা বিকাশের TrxID।
             *
             * এটাই যাচাইয়ের একমাত্র সুতো। এটা ছাড়া দাবিটা কেবল একটা
             * কথা, আর ডিপো ব্যাংকের কাগজে খুঁজে বের করার কোনো উপায়
             * পেত না।
             */
            $table->string('reference', 64)->nullable();

            /* কোন হিসাবে গেছে বলে ডিলার বলছেন। */
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->string('status', 16)->default('pending');
            $table->string('note', 500)->nullable();

            /* গ্রহণ করলে যে আদায়টা তৈরি হয়েছে। */
            $table->foreignId('collection_id')->nullable()->constrained('sal_collections')->nullOnDelete();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
             * একই ডিলার একই রেফারেন্স দুইবার দাবি করতে পারেন না।
             *
             * না আটকালে একটাই জমা দুইবার দাবি করা যেত, আর ডিপোর দুইজন
             * দুইটা দেখে দুইবার গ্রহণ করলে বকেয়া দ্বিগুণ কমে যেত।
             * রেফারেন্স খালি থাকলে নিয়মটা খাটে না — MySQL-এ NULL
             * একে অপরের সমান নয় — আর সেটাই ঠিক: রেফারেন্সবিহীন নগদ
             * জমা সত্যিই একাধিকবার হতে পারে।
             */
            $table->unique(['company_id', 'customer_id', 'reference'], 'sal_claim_reference_once');
            $table->index(['company_id', 'status', 'claimed_on']);
            $table->index(['company_id', 'customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_deposit_claims');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'portal_password', 'portal_enabled', 'portal_last_login_at', 'remember_token',
            ]);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কাউন্টারে টাকা মানে কেবল নগদ নয়।
 *
 * ── আজ যা ঘটে ────────────────────────────────────────────────────────
 * `PosService` কেবল `cashAccount()` চেনে — অর্থাৎ কাউন্টারে বিকাশ,
 * নগদ, রকেট বা কার্ডে টাকা নেওয়ার কোনো পথ নেই। বাংলাদেশে ২০২৬ সালে
 * এটা রোজকার ঘটনা, দিনে বহুবার।
 *
 * ফল: দোকানি বিকাশে টাকা নেন, ব্যবস্থায় লেখেন "নগদ"। দিনশেষে ড্রয়ারে
 * টাকা কম, খাতায় বেশি — আর গণনায় ঘাটতি দেখায় এমন টাকার জন্য যেটা
 * আসলে বিকাশের অ্যাকাউন্টে নিরাপদে বসে আছে।
 *
 * ── কেন enum নয়, সারি ─────────────────────────────────────────────────
 * "কী কী উপায়ে টাকা নেওয়া যায়" — এটা একটা **তালিকা**, আর প্রতিটা
 * কোম্পানির তালিকা আলাদা। কারও বিকাশ আছে নগদ নেই, কারও দুইটা কার্ড
 * মেশিন দুই ব্যাংকের। কোডে enum লিখলে নতুন একটা উপায় যোগ করতে
 * ডেভেলপার লাগত — অথচ এটা সেটিংসের কাজ (মালিকের স্থায়ী নিয়ম)।
 *
 * ── প্রতিটা উপায় একটা খাত বলে ───────────────────────────────────────
 * এটাই পুরো কাজটার কেন্দ্র। "বিকাশ" সারিটা বলে দেয় টাকাটা কোন খাতে
 * বসবে; POS তখন আর অনুমান করে না। খাত ছাড়া সারিটা অর্থহীন, তাই
 * `account_id` বাধ্যতামূলক।
 *
 * নমুনা হিসেবে "কারণ কোড নিজের খাত বলতে পারে" মাইগ্রেশনটাই — সেখানে
 * একই কাজ আগে করা হয়েছে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_bn', 120);

            /*
             * টাকাটা কোন খাতে বসবে।
             *
             * `restrictOnDelete` — খাতটা মুছে ফেললে উপায়টা খাতহীন হয়ে
             * পড়ত, আর পরের বিক্রয়ে POS কোথায় বসাবে তা বলতে পারত না।
             * খাত মুছতে হলে আগে উপায়টা সরাতে হবে, আর সেটাই ঠিক ক্রম।
             */
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            /*
             * লেনদেনের নম্বর লাগে কি না।
             *
             * বিকাশ/নগদে TrxID থাকে আর সেটা ছাড়া পরে মেলানো যায় না;
             * কার্ডে থাকে শেষ চার অঙ্ক; নগদে কিছুই থাকে না। প্রতিটা
             * উপায়ে জোর করে নম্বর চাইলে নগদ বিক্রয়ে মানুষ `0` বসাত —
             * আর বানানো নম্বর কোনো নম্বর না থাকার চেয়ে খারাপ।
             */
            $table->boolean('needs_reference')->default(false);

            /*
             * ফি — কার্ডে ১.৫%, বিকাশ মার্চেন্টে ১.৪%।
             *
             * এখন কেবল রাখা হয়, কাটা হয় না: ফি কাটার হিসাব (কে বহন
             * করে, কখন বসে) আলাদা কাজ। কিন্তু ঘরটা এখনই, কারণ পরে
             * যোগ করলে পুরনো সারিগুলোর ফি অজানা থেকে যেত।
             */
            $table->decimal('fee_percent', 8, 4)->default(0);

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->uuid('public_id')->unique();
            $table->timestamps();
            $table->softDeletes();

            // কোডটা কোম্পানির ভেতরে অনন্য — দুইটা "BKASH" থাকলে কোনটা
            // কোনটা তা বলার উপায় থাকত না
            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_payment_methods');
    }
};

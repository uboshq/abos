<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ডেলিভারির খরচের কোনো ঘর ছিল না।
 *
 * ── কোন প্রশ্নের উত্তর নেই ───────────────────────────────────────────
 * ডিপো জ্বালানি কেনে, চালকের বেতন দেয়, গাড়ি সারায়। সব মিলিয়ে মাসে
 * হয়তো লাখখানেক। খাতায় ওগুলো বসে "জ্বালানি ও পরিবহন", "বেতন",
 * "মেরামত" — খাত ধরে ঠিকই আছে।
 *
 * কিন্তু মালিকের প্রশ্নটা অন্য: **"নেত্রকোনার রুটে মাসে কত খরচ হয়, আর
 * ওখান থেকে কত মার্জিন আসে?"** — আজ ওই প্রশ্নের কোনো উত্তর নেই, কারণ
 * খরচটা কোন রুটের সেটা কোথাও লেখা হয় না।
 *
 * ৪% মার্জিনের ব্যবসায় এটা ছোট প্রশ্ন নয়: একটা রুটের মার্জিন যদি তার
 * খরচের চেয়ে কম হয়, তবে ওই রুট **চালানোই লোকসান** — অথচ মোট হিসাবে
 * ব্যবসাটা লাভজনক দেখায়, আর কেউ ধরতে পারে না কোনটা টানছে আর কোনটা
 * ডোবাচ্ছে।
 *
 * ── কেন খাতের বদলে আলাদা মাত্রা ──────────────────────────────────────
 * "নেত্রকোনার জ্বালানি" নামে আলাদা খাত খোলা যেত, কিন্তু তাতে চারটা রুট
 * আর দশটা খরচের খাত মিলে চল্লিশটা খাত হত — আর নতুন রুট এলে আরও দশটা।
 * চার্টটা তখন আর পড়ার মতো থাকত না।
 *
 * মাত্রাটা আলাদা রাখলে খাত থাকে দশটাই, আর যেকোনো খাতের যেকোনো সারিতে
 * রুটের নাম বসানো যায়। "জ্বালানি কত" আর "নেত্রকোনায় কত" — দুইটা
 * প্রশ্নেরই উত্তর একই সারিগুলো থেকে বেরোয়।
 *
 * ── সারি, enum নয় ────────────────────────────────────────────────────
 * রুট বদলায়, নতুন এলাকা যোগ হয়, পুরনোটা বন্ধ হয়। enum লিখলে প্রতিটা
 * বদলে একটা রিলিজ লাগত, আর ততদিন কেউ "অন্যান্য" লিখে কাজ চালাত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_cost_centers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_bn', 120);

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->uuid('public_id')->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        Schema::table('ledger_entries', function (Blueprint $table): void {
            /*
             * খতিয়ানের সারিতে, ডকুমেন্টের মাথায় নয়।
             *
             * একটা ভাউচারে দুই রুটের খরচ থাকতে পারে — "নেত্রকোনায় ২,০০০
             * আর কেন্দুয়ায় ১,৫০০ জ্বালানি"। মাথায় রাখলে ওটা লিখতে দুইটা
             * ভাউচার লাগত, আর মানুষ তখন একটাতেই লিখে দিতেন।
             *
             * `nullOnDelete` — কেন্দ্রটা মুছলে সারিটা কেন্দ্রহীন হয়,
             * উধাও হয় না। খতিয়ানের কোনো সারি কোনো কারণেই হারাতে পারে না।
             */
            $table->foreignId('cost_center_id')->nullable()->after('party_id')
                ->constrained('mdm_cost_centers')->nullOnDelete();

            $table->index(['company_id', 'cost_center_id', 'trx_date'], 'ledger_cost_center_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('ledger_cost_center_idx');
            $table->dropConstrainedForeignId('cost_center_id');
        });

        Schema::dropIfExists('mdm_cost_centers');
    }
};

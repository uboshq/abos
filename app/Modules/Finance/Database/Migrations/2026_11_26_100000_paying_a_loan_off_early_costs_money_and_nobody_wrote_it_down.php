<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * মাঝপথে ঋণ শোধ করলে টাকা লাগে, আর সেটা কোথাও লেখা ছিল না।
 *
 * ── ⓘ মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"majpothe setelment korle ze extra charge ase ta soho korbe"* —
 * বাংলাদেশে মেয়াদি ঋণ আগে শোধ করলে ব্যাংক early settlement fee নেয়।
 *
 * ── ⭐ দুই ছাঁদ, কারণ ব্যাংকভেদে দুই রকম ─────────────────────────────
 * কেউ বকেয়ার শতাংশ নেয়, কেউ থোক টাকা। ⓘ তাই একটা ঘর সংখ্যা রাখে আর
 * আরেকটা বলে সংখ্যাটা কীসের — শতাংশ না টাকা।
 *
 * ── ⚠️ শতাংশটা কীসের উপর, সেটা এখানে ঠিক করা হয়নি ───────────────────
 * ⛔ বকেয়া আসলের উপর, নাকি বাকি থাকা সুদের উপর — দুই ব্যাংকে দুই রকম,
 * আর ভুলটা টাকার। ⓘ তাই ভিত্তিটাও একটা ঘর (`early_charge_basis`), আর
 * মালিকের উত্তর এলে কেবল ডিফল্টটা বসবে — কোনো কোড বদলাতে হবে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_bank_facilities', function (Blueprint $table) {
            /* কত — শতাংশ হলে ০–১০০, থোক হলে টাকার অঙ্ক */
            $table->decimal('early_charge', 18, 4)->nullable()->after('charges');

            /* সংখ্যাটা কীসের: `percent` না `flat` */
            $table->string('early_charge_kind', 12)->nullable()->after('early_charge');

            /*
             * শতাংশটা কার উপর: `principal` (বকেয়া আসল) না `interest`
             * (বাকি থাকা সুদ)। ⓘ থোক হলে এই ঘরটার কোনো মানে নেই।
             */
            $table->string('early_charge_basis', 12)->nullable()->after('early_charge_kind');
        });
    }

    public function down(): void
    {
        Schema::table('fin_bank_facilities', function (Blueprint $table) {
            $table->dropColumn(['early_charge', 'early_charge_kind', 'early_charge_basis']);
        });
    }
};

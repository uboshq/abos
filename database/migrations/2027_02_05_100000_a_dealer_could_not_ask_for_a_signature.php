<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ পোর্টালের গ্রাহক সই চাইতে পারতেন না — ৩ অক্টোবর ২০২৬ (abos-2c, সমন্বয়কের অনুমোদিত নকশা "ক")।
 *
 * ── কী ঘটত ─────────────────────────────────────────────────────────────
 * কোম্পানিতে DO-র (বা আদেশের) সইয়ের ছক থাকলে গ্রাহক পোর্টাল থেকে জমা দিলেই ৫০০: `approvals.requested_by`
 * কেবল কর্মী (`users`) নিত, অথচ গ্রাহক কর্মী নন — SQL 1048, "requested_by cannot be null"।
 *
 * ── কী বদলাল ──────────────────────────────────────────────────────────
 * অনুরোধকারী এখন দুই রকমের, আর **ঠিক একজন**: কর্মী (`requested_by`) বা পোর্টালের গ্রাহক
 * (`requested_by_customer_id`)। ⓘ নিয়মটা ইঞ্জিনে আর মডেলে বসানো ([[Approval]]-এর saving)।
 * ⚠️ CHECK বসানো যায়নি: MySQL cascade/set-null FK-র কলাম CHECK-এ নিতে দেয় না (ত্রুটি 3823), আর দুইটা
 * কলামেরই FK আছে — তাই পাহারাটা কোডে, আর দাবি [[ADealerAsksForASignatureInTheirOwnNameTest]]-এ।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table): void {
            $table->unsignedBigInteger('requested_by')->nullable()->change();
            $table->foreignId('requested_by_customer_id')->nullable()->after('requested_by')
                ->constrained('customers', 'id', 'approvals_req_customer_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table): void {
            $table->dropForeign('approvals_req_customer_fk');
            $table->dropColumn('requested_by_customer_id');
        });
    }
};

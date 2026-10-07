<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * উদ্ধৃতি বদলাতে গেলে আগের দরটা মুছে যেত।
 *
 * ── ⛔ কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * ডিলারের হাতে যাওয়া দর বদলাতে হলে কাগজটা "আবার খসড়ায়" ফিরত — একই সারি, একই নম্বর। ⚠️ নতুন দর বসার
 * সাথে সাথে পুরনো দরটা হারাত: ডিলার যদি বলতেন *"আপনারা তো আগে ৯৫ টাকা বলেছিলেন"*, দেখানোর মতো কিছু থাকত না।
 *
 * ── ⭐ আন্তর্জাতিক নিয়ম (মালিক, ৪ অক্টোবর ২০২৬: *"অবশ্যই ইন্টারন্যাশনাল স্ট্যান্ডার্ড"*) ─────────────────
 * পাঠানো উদ্ধৃতি বদলায় না — নতুন **সংস্করণ** হয়। একই মূল নম্বর, শেষে সংস্করণ (`QTN-0007-R1`, `-R2` …);
 * আগেরটা "নতুন সংস্করণে বদলেছে" অবস্থায় কেবল পড়ার জন্য থেকে যায়, আর পুরো ইতিহাস পাশাপাশি দেখা যায়।
 *
 * ── ⓘ তিনটা ঘর আর একটা পাহারা ───────────────────────────────────────────
 *   `root_quotation_id`  মূল উদ্ধৃতি — মূলটার নিজের ঘর খালি (সে নিজেই মূল)
 *   `revision_no`        ০ = মূল, ১, ২ … সংস্করণ
 *   `revised_from_id`    ঠিক আগের সংস্করণ
 *   `superseded_at/by`   কখন, কে নতুন সংস্করণ করলেন — পুরনোটার গায়ে
 * ⛔ (মূল, সংস্করণ) ইউনিক — দুই ক্লিকে একই "R1" দুইবার জন্মাতে পারে না; সেবার তালা ফসকালেও ডাটাবেস আটকায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_quotations', function (Blueprint $table): void {
            $table->foreignId('root_quotation_id')->nullable()->after('document_no')
                ->constrained('sal_quotations', 'id', 'sal_quotations_root_fk')->nullOnDelete();
            $table->unsignedSmallInteger('revision_no')->default(0)->after('root_quotation_id');
            $table->foreignId('revised_from_id')->nullable()->after('revision_no')
                ->constrained('sal_quotations', 'id', 'sal_quotations_revised_from_fk')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable()->after('converted_by');
            $table->foreignId('superseded_by')->nullable()->after('superseded_at')
                ->constrained('users', 'id', 'sal_quotations_superseded_by_fk')->nullOnDelete();

            $table->unique(['root_quotation_id', 'revision_no'], 'sal_quotations_root_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sal_quotations', function (Blueprint $table): void {
            $table->dropForeign('sal_quotations_root_fk');
            $table->dropForeign('sal_quotations_revised_from_fk');
            $table->dropForeign('sal_quotations_superseded_by_fk');
            $table->dropUnique('sal_quotations_root_revision_unique');
            $table->dropColumn(['root_quotation_id', 'revision_no', 'revised_from_id', 'superseded_at', 'superseded_by']);
        });
    }
};

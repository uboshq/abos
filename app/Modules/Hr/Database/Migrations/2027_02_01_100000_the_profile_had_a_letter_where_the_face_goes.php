<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * প্রোফাইলের গোল ঘরে মুখ নয়, নামের আদ্যক্ষর ছিল — মালিক, ২ অক্টোবর ২০২৬: "employee prf pic upload ki kore dibe"।
 *
 * ── কেন ফাইলের পথ নয়, সংযুক্তির id ────────────────────────────────────
 * পণ্যের ছবির মতোই (`inv_products.primary_image_id`): ফাইলটা [[AttachmentEngine]]-এর খাতায় থাকে —
 * কোম্পানির ফোল্ডারে, uuid নামে, sha256 আর সংস্করণসহ — আর দেখানো হয় `attachment.download` দিয়ে,
 * যেটা আগে কর্মীর `view` নীতি জিজ্ঞেস করে। ⛔ একটা `photo_path` থাকলে কেউ না কেউ একদিন সেটা
 * `asset()`-এ বসাত, আর তখন ঠিকানা অনুমান করেই অন্য কোম্পানির লোক ছবি দেখতেন।
 *
 * ⓘ nullable — পুরনো কর্মীদের ছবি নেই; না থাকলে আদ্যক্ষর, যেমন এতদিন।
 * ⓘ সংযুক্তি মুছলে ঘরটা খালি হয় (SET NULL) — কর্মীর সারি নয়।
 * ⚠️ ইনডেক্সের নাম ছোট, হাতে দেওয়া — ৬৪ অক্ষরের বেশি হলে MySQL প্রতিটা সেশন ভেঙে দেয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->foreignId('photo_attachment_id')->nullable()->after('emergency_mobile');

            $table->foreign('photo_attachment_id', 'hr_emp_photo_fk')
                ->references('id')->on('attachments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropForeign('hr_emp_photo_fk');
            $table->dropColumn('photo_attachment_id');
        });
    }
};

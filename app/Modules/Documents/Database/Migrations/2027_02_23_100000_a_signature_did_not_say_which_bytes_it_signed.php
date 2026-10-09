<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একটা সই বলত না সে ঠিক কোন বাইটে পড়েছে — ডকুমেন্টের চতুর্থ ধাপ (৯ অক্টোবর ২০২৬)।
 *
 * ⭐ পরিকল্পনা §১১ (সই), §১৪-এর শেয়ার আর §১৫ (সম্পর্ক)।
 *
 * ── ⓘ তিনটা বদল ───────────────────────────────────────────────────────
 * `dms_signatures` — প্রতিটা সই: কে, কবে, কোন স্তরে, **কোন ভার্সনের কোন SHA-256-এ**। সই দেওয়া হয়
 *   ABOS-এর সইয়ের ইনবক্সে ([[ApprovalEngine]]); শেষ সই পড়লে প্রতিটা সই এখানে বসে। ⛔ নতুন ভার্সন
 *   উঠলে পুরনো সই সেই পুরনো হ্যাশেরই থাকে — নতুনটায় আবার সই লাগে।
 * `dms_document_permissions`-এ `expires_at` আর `via_share` — শেয়ার মানে মেয়াদসহ অধিকার; একই খাতা,
 *   একই দেয়াল ([[DocumentAccess]]), তাই শেয়ার কখনো অন্য পথে কাগজ খোলে না।
 * `dms_document_links` — কাগজটা কার সাথে জোড়া: গ্রাহক, সরবরাহকারী, ক্রয়াদেশ, বিল, কর্মী। ⓘ অন্য
 *   মডিউলের ক্লাস নয়, ABOS-এর ড্রিলের নাম আর id ([[DrillResolver]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dms_signatures', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('dms_documents')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('dms_document_versions')->cascadeOnDelete();
            $table->char('file_hash', 64);
            $table->foreignId('approval_id')->constrained('approvals')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('level');
            $table->timestamp('signed_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['approval_id', 'user_id', 'level'], 'dms_signature_once');
            $table->index(['company_id', 'document_id']);
        });

        Schema::table('dms_document_permissions', function (Blueprint $table) {
            $table->boolean('via_share')->default(false)->after('can_edit');
            $table->timestamp('expires_at')->nullable()->after('via_share');
        });

        Schema::create('dms_document_links', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('dms_documents')->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'source_type', 'source_id'], 'dms_link_once');
            $table->index(['company_id', 'source_type', 'source_id'], 'dms_link_record');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dms_document_links');

        Schema::table('dms_document_permissions', function (Blueprint $table) {
            $table->dropColumn(['via_share', 'expires_at']);
        });

        Schema::dropIfExists('dms_signatures');
    }
};

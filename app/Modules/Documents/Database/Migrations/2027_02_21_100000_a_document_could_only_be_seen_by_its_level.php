<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একটা কাগজ কেবল তার গোপনীয়তার ধাপ দিয়ে দেখা যেত — ডকুমেন্টের দ্বিতীয় ধাপ (৯ অক্টোবর ২০২৬)।
 *
 * ⭐ পরিকল্পনা §১৩ (কাগজ-ধরে অনুমতি), §১৬ (খোঁজ), §১৯ (রিসাইকেল বিন), §২০ (প্রশাসন),
 * §২১ (তথ্যের গড়ন), §২২ (অবস্থা)।
 *
 * ── ⓘ পাঁচটা টেবিল ─────────────────────────────────────────────────────
 * `dms_document_types` আর `dms_categories` — কোম্পানির নিজের ধরন আর ফোল্ডার, প্রশাসনের
 *   পর্দা থেকে। ⓘ মালিকের ন'টা ফোল্ডার আর এগারোটা ধরন কোডেই থাকে ([[DocumentCatalog]]);
 *   এই টেবিলে কেবল যা কোম্পানি নিজে যোগ করে — তাই নতুন কোম্পানিতে সারি বসানোর আলাদা পথ লাগে না।
 * `dms_metadata_fields` আর `dms_document_metadata` — বাড়তি ঘর (যেমন "লাইসেন্স নম্বর"), ধরন ধরে।
 * `dms_document_permissions` — একটা কাগজে একজন মানুষ বা একটা ভূমিকাকে দেখা/নামানো/ছাপা/
 *   শেয়ার/বদলের অধিকার।
 *
 * ⓘ ট্যাগের তালিকা (`dms_tags`) — প্রশাসনের ঠিক করা নামগুলো, তোলার ফর্মে পরামর্শ হিসেবে।
 *
 * ── ⓘ রিসাইকেল বিন কেন নতুন টেবিল নয় ─────────────────────────────────
 * কাগজ আগে থেকেই নরম-মোছা (`deleted_at`, `deleted_by`)। ⭐ বিন মানে ঐ সারিগুলোর পর্দা;
 * কেবল মোছার আগের অবস্থাটা মনে রাখার ঘর লাগে, যাতে ফেরালে ঠিক আগের জায়গায় ফেরে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dms_document_types', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 24);
            $table->string('name_en', 120);
            $table->string('name_bn', 120);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('dms_categories', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 24);
            $table->string('name_en', 120);
            $table->string('name_bn', 120);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('dms_tags', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 40);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('dms_metadata_fields', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name_en', 120);
            $table->string('name_bn', 120);

            // ⓘ text, number, date — ঘরের ধরন
            $table->string('kind', 12)->default('text');

            // ⓘ কোন ধরনের কাগজে — ফাঁকা মানে সব কাগজে
            $table->string('doc_type', 24)->nullable();

            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('dms_document_metadata', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('dms_documents')->cascadeOnDelete();
            $table->foreignId('field_id')->constrained('dms_metadata_fields')->cascadeOnDelete();
            $table->string('value', 500)->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'field_id']);
        });

        Schema::create('dms_document_permissions', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('dms_documents')->cascadeOnDelete();

            // ⓘ কাকে — একজন মানুষ (`user`) বা একটা ভূমিকা (`role`)
            $table->string('grantee_type', 8);
            $table->unsignedBigInteger('grantee_id');

            // ⭐ পাঁচটা অধিকার (§১৩) — দেখা সবসময় চালু, বাকিগুলো বেছে
            $table->boolean('can_view')->default(true);
            $table->boolean('can_download')->default(false);
            $table->boolean('can_print')->default(false);
            $table->boolean('can_share')->default(false);
            $table->boolean('can_edit')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'grantee_type', 'grantee_id'], 'dms_doc_perm_grantee_unique');
            $table->index(['company_id', 'grantee_type', 'grantee_id'], 'dms_doc_perm_company_grantee_index');
        });

        Schema::table('dms_documents', function (Blueprint $table) {
            // ⓘ মোছার আগের অবস্থা — বিন থেকে ফেরালে ঠিক সেখানেই
            $table->string('deleted_from_status', 24)->nullable()->after('archived_from_status');

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'department_id']);
            $table->index(['company_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('dms_documents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status']);
            $table->dropIndex(['company_id', 'department_id']);
            $table->dropIndex(['company_id', 'deleted_at']);
            $table->dropColumn('deleted_from_status');
        });

        Schema::dropIfExists('dms_document_permissions');
        Schema::dropIfExists('dms_document_metadata');
        Schema::dropIfExists('dms_metadata_fields');
        Schema::dropIfExists('dms_tags');
        Schema::dropIfExists('dms_categories');
        Schema::dropIfExists('dms_document_types');
    }
};

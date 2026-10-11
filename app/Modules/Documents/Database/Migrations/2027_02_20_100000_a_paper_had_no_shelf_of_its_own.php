<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একটা কাগজের নিজের তাক ছিল না — ডকুমেন্ট ম্যানেজমেন্টের প্রথম ধাপ (৮ অক্টোবর ২০২৬)।
 *
 * ⭐ মালিকের পরিকল্পনা: `docs/ডকুমেন্ট-ম্যানেজমেন্ট-পরিকল্পনা.md` — §১ (জমার ভিত),
 * §৪ (সেন্টার), §৫ (বিস্তারিত), §৬ (আপলোড), §৯ (ভার্সন), §১২-র মেয়াদের তারিখ, §১৩ (দেয়াল)।
 *
 * ── ⓘ দুইটা টেবিল, তৃতীয়টা নয় ───────────────────────────────────────
 * `dms_documents` — কাগজটা কী (নাম, ধরন, ফোল্ডার, বিভাগ, মালিক, তারিখ, মেয়াদ,
 * গোপনীয়তা, ট্যাগ, বিবরণ)। `dms_document_versions` — প্রতিটা ফাইল একটা ভার্সন,
 * v1.0 → v1.1 → v2.0।
 *
 * ⛔ ফাইলের জন্য নতুন টেবিল নেই: ফাইল বসে ABOS-এর পুরনো `attachments`-এ, একই
 * ইঞ্জিন দিয়ে ([[AttachmentEngine]]) — পরিকল্পনার দ্বিতীয় বাঁধন *"যা আছে তা আবার
 * বানানো নয়"*। ভার্সনের সারি কেবল বলে কোন সংযুক্তিটা কোন ভার্সন।
 *
 * ── ⓘ কেন `dms_`, `doc_` নয় ───────────────────────────────────────────
 * `doc_shares` আর `doc_deliveries` কোরের — "কাগজ কোথায় গেল" ([[PaperTrail]])।
 * ⚠️ একই আগে বসালে মনে হত দুইটা এক পরিবারের, অথচ ওগুলো বিক্রির বিল, এগুলো তাকের কাগজ।
 *
 * ── ⓘ ফোল্ডার আর ধরন কেন টেবিল নয় ──────────────────────────────────
 * মালিকের ন'টা ফোল্ডার (§৪) আর ধরনগুলো আজ স্থির তালিকা ([[DocumentCatalog]]);
 * নিজের ফোল্ডার বা ধরন বানানোর পর্দা প্রশাসনের (§২০) — সেদিন তালিকাটা টেবিলে
 * সরবে। ⚠️ পর্দা ছাড়া টেবিল বানালে প্রতিটা নতুন কোম্পানিতে সারি বসানোর আরেকটা
 * পথ লাগত, আর কেউ সেটা বদলাতেই পারতেন না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dms_documents', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /*
             * ⓘ শাখা — কাগজটা কোন শাখার দেয়ালের ভিতরে ([[ScopedToUserBranch]])।
             * ফাঁকা মানে গোটা কোম্পানির (যেমন ট্রেড লাইসেন্স) — যিনি সব শাখা দেখেন কেবল তিনিই।
             */
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // ⓘ নিজের ফাঁকহীন নম্বর — নম্বর সিরিজ ইঞ্জিন থেকে (DOC-0001)
            $table->string('document_no', 40)->nullable();

            $table->string('name', 191);
            $table->string('doc_type', 24);
            $table->string('folder', 24);

            $table->foreignId('department_id')->nullable()->constrained('mdm_departments')->nullOnDelete();

            // ⓘ মালিক — কাগজটার দায় যাঁর; যিনি তুললেন (`created_by`) তিনি নাও হতে পারেন
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('document_date')->nullable();

            // ⭐ মেয়াদ (§১২) — সেন্টারের "৭/৩০/৯০ দিনের মধ্যে" ছাঁকনি এটাই পড়ে
            $table->date('expiry_date')->nullable();

            // ⭐ গোপনীয়তা (§১৪) — public, internal, confidential, highly_confidential, restricted
            $table->string('confidentiality', 24)->default('internal');

            // ⓘ ট্যাগ কমা দিয়ে আলাদা লেখা — খোঁজ LIKE দিয়ে; ট্যাগের নিজের টেবিল প্রশাসনের দিনে (§২০)
            $table->string('tags', 500)->nullable();
            $table->text('description')->nullable();

            // ⓘ অবস্থা (§২২) — আজ draft আর archived; অনুমোদন (§১০) এলে বাকিগুলো
            $table->string('status', 24)->default('draft');

            // ⓘ আর্কাইভ নরম: সারি থাকে, ফাইল থাকে, কেবল সেন্টার থেকে সরে
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archived_from_status', 24)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'folder']);
            $table->index(['company_id', 'expiry_date']);
            $table->index(['company_id', 'archived_at']);
            $table->index(['company_id', 'owner_id']);
        });

        Schema::create('dms_document_versions', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->foreignId('document_id')->constrained('dms_documents')->cascadeOnDelete();

            // ⭐ v{major}.{minor} — ছোট বদলে minor বাড়ে, বড় বদলে major বাড়ে আর minor শূন্য
            $table->unsignedSmallInteger('major');
            $table->unsignedSmallInteger('minor');

            /*
             * ⛔ ফাইলটা — `attachments`-এর সারি। ⚠️ restrict: যে সংযুক্তির উপর একটা ভার্সন
             * দাঁড়িয়ে, সেটা মুছে ভার্সনকে অনাথ করা যায় না।
             */
            $table->foreignId('attachment_id')->constrained('attachments')->restrictOnDelete();

            // ⓘ পুরনো ভার্সন ফেরানো মানে নতুন ভার্সন — কোনটা থেকে, সেটা এখানে
            $table->foreignId('restored_from_id')->nullable()->constrained('dms_document_versions')->nullOnDelete();

            $table->string('comment', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // ⛔ ভার্সন কখনো বদলায় না — তাই updated_at নেই
            $table->timestamp('created_at')->nullable();

            $table->unique(['document_id', 'major', 'minor']);
            $table->index(['company_id', 'document_id']);
        });

        /*
         * ⓘ চলতি ভার্সন — কাগজের সারিতে, যাতে তালিকা প্রতি সারিতে ভার্সন খুঁজতে না যায়।
         * ⚠️ দুই টেবিলই তৈরি হওয়ার পরে, কারণ দুইজন দুইজনকে চেনে।
         */
        Schema::table('dms_documents', function (Blueprint $table) {
            $table->foreignId('current_version_id')->nullable()->after('status')
                ->constrained('dms_document_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dms_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_version_id');
        });

        Schema::dropIfExists('dms_document_versions');
        Schema::dropIfExists('dms_documents');
    }
};

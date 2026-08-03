<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval engine ও Attachment engine-এর টেবিল (সেকশন ২.২, ২১, ২২)।
 *
 * দুটোই polymorphic — approvable_type/approvable_id আর source_module/
 * source_entity_id। কারণ একই: নতুন মডিউল এলে নতুন টেবিল যেন না লাগে।
 * সেকশন ১৯.৭ এটাকে নিষিদ্ধ করে রেখেছে ঠিক এই কারণেই।
 */
return new class extends Migration
{
    public function up(): void
    {
        // কোন ডকুমেন্টে কয় স্তরের অনুমোদন লাগবে — Control Panel থেকে সাজানো
        Schema::create('approval_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('module', 64);
            $table->string('action', 64);          // create · update · delete · reprint · discount · withdrawal
            $table->string('document_type', 64)->nullable();

            // সীমার নিচে অনুমোদন লাগবে না — "৫০০ টাকার ডিসকাউন্টে মালিকের
            // অনুমোদন" বাস্তবে কেউ মানে না, আর না মানলে পুরো ব্যবস্থাই অকেজো হয়
            $table->decimal('threshold_amount', 18, 4)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'module', 'action', 'document_type'], 'approval_flow_scope');
        });

        Schema::create('approval_flow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_flow_id')->constrained('approval_flows')->cascadeOnDelete();

            $table->unsignedInteger('level');       // ১, ২, ৩ — ক্রমানুসারে
            $table->string('approver_type', 16);    // role · user
            $table->unsignedBigInteger('approver_id');

            // একই স্তরে দুইজন থাকলে যেকোনো একজনই যথেষ্ট, নাকি দুজনেই লাগবে
            $table->boolean('requires_all')->default(false);

            $table->timestamps();

            $table->unique(['approval_flow_id', 'level', 'approver_type', 'approver_id'], 'approval_step_unique');
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('approvable_type', 64);
            $table->unsignedBigInteger('approvable_id');

            $table->string('module', 64);
            $table->string('action', 64);
            $table->decimal('amount', 18, 4)->nullable();

            $table->string('status', 16)->default('pending');   // pending · approved · rejected · cancelled
            $table->unsignedInteger('current_level')->default(1);

            // কী বদলাতে চাওয়া হয়েছে — অনুমোদনের আগে পরিবর্তনটা প্রয়োগ হবে না,
            // তাই প্রস্তাবিত মানটা কোথাও রাখতে হয়
            $table->json('payload')->nullable();

            $table->string('requested_reason', 500)->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('requested_at');

            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status', 'module'], 'approval_queue');
            $table->index(['approvable_type', 'approvable_id'], 'approval_target');
        });

        // প্রতিটা স্তরের সিদ্ধান্ত আলাদা রো — কে কখন কী বলল, তার ইতিহাস।
        // শুধু চূড়ান্ত অবস্থা রাখলে "তিন নম্বর স্তরে আটকে ছিল কেন" প্রশ্নের
        // উত্তর কখনো পাওয়া যায় না।
        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_id')->constrained('approvals')->cascadeOnDelete();

            $table->unsignedInteger('level');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision', 16);          // approved · rejected
            $table->string('remarks', 500)->nullable();
            $table->timestamp('decided_at');

            $table->timestamps();

            $table->index(['approval_id', 'level']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('source_module', 64);
            $table->string('source_entity', 64);
            $table->unsignedBigInteger('source_entity_id');

            // ব্যবহারকারীর দেওয়া নাম শুধু দেখানোর জন্য; ডিস্কে ফাইল বসে
            // নতুন তৈরি করা নামে (UUID) — নাহলে "../../.env" জাতীয় নাম দিয়ে
            // ফাইল সিস্টেমের বাইরে লেখা যায়।
            $table->string('original_name', 255);
            $table->string('stored_path', 500);
            $table->string('mime_type', 128);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64)->nullable();

            // একই কাগজের নতুন সংস্করণ — আগেরটা মুছে যায় না
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('replaces_id')->nullable()->constrained('attachments')->nullOnDelete();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'source_module', 'source_entity', 'source_entity_id'], 'attachment_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('approval_flow_steps');
        Schema::dropIfExists('approval_flows');
    }
};

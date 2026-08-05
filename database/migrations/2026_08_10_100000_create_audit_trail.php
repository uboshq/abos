<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * অডিট — কে, কখন, কী বদলাল।
 *
 * ── কেন দুইটা টেবিল ─────────────────────────────────────────────────
 * উপরেরটা ঘটনা: "রফিক ৩টা ৪০ মিনিটে চালান DC-০০১২ সম্পাদনা করেছেন"।
 * নিচেরটা বিস্তারিত: "পরিমাণ ১০ → ১৫, দর ১২০ → ১২৫"।
 *
 * এক টেবিলে JSON হিসেবে রাখা যেত, আর তাতে লেখা সহজ হত। কিন্তু তখন
 * "দর কে কবে বদলেছে" প্রশ্নটার উত্তর দিতে প্রতিটা সারির JSON খুলে দেখতে
 * হত — আর ঠিক ওই প্রশ্নটাই মালিক সবচেয়ে বেশি করেন।
 *
 * ── কেন সারিগুলো কখনো বদলায় না ─────────────────────────────────────
 * অডিট বদলানো গেলে অডিট বলে কিছু থাকে না। মডেলেই সেভ ও ডিলিট আটকানো,
 * আর কারণটা সেখানে লেখা।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_trails', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            /*
             * কে করেছে।
             *
             * nullable, কারণ সিডার ও কনসোল কমান্ডেরও পরিবর্তন লগ হয় —
             * আর "কেউ না" লেখাটা ওই ক্ষেত্রে সত্যি। ব্যবহারকারী মুছে
             * গেলেও (nullOnDelete) সারিটা থাকে: কাজটা তো হয়েছিল।
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // created · updated · deleted · restored · confirmed · cancelled · approved · rejected
            $table->string('action', 24);

            $table->string('auditable_type', 191);
            $table->unsignedBigInteger('auditable_id');

            /*
             * ডকুমেন্ট নম্বর ও নাম — ঘটনার সময়ের কপি।
             *
             * রেকর্ডটা পরে বদলে গেলে বা মুছে গেলেও তালিকায় "কোনটা"
             * তা পড়া যায়। শুধু id রাখলে মুছে যাওয়া রেকর্ডের সারিটা
             * একটা সংখ্যা হয়ে থাকত।
             */
            $table->string('document_no', 64)->nullable();
            $table->string('label', 191)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // সম্পাদনার কারণ — ঐচ্ছিক, কিন্তু কিছু কাজে বাধ্যতামূলক
            $table->string('reason', 500)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'auditable_type', 'auditable_id'], 'audit_record_index');
            $table->index(['company_id', 'user_id', 'created_at']);
            $table->index(['company_id', 'action']);
        });

        Schema::create('audit_field_changes', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('audit_trail_id')->constrained('audit_trails')->cascadeOnDelete();

            $table->string('field', 120);

            /*
             * মান দুইটা টেক্সট, তাদের নিজের ধরনে নয়।
             *
             * এক টেবিলে সংখ্যা, তারিখ, লেখা ও পতাকা সবই আসে। আলাদা
             * কলাম রাখলে প্রতিটা সারিতে চারটা কলামের তিনটা খালি থাকত,
             * আর নতুন কোনো ধরন এলে টেবিল বদলাতে হত।
             */
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'field']);
            $table->index('audit_trail_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_field_changes');
        Schema::dropIfExists('audit_trails');
    }
};

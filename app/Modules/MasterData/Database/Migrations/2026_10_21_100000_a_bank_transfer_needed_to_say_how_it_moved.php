<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একটা ব্যাংক-জমা কীভাবে এল তা বলা দরকার ছিল — ONLINE, NPSB, BEFTN, RTGS।
 *
 * "ব্যাংক ট্রান্সফার" method বললে কেবল অর্ধেক কথা: টাকাটা কোন মাধ্যমে
 * এসেছে সেটা মাস শেষে ব্যাংকের কাগজের সাথে মেলানোর সময় লাগে। মাধ্যমটা
 * সারি, enum নয় — নতুন একটা চ্যানেল (যেমন নতুন কোনো instant-pay) এলে
 * কোম্পানি নিজে সেটিংস থেকে যোগ করবে, রিলিজ ছাড়াই।
 *
 * ── `applies_to` কেন এখনই, আজ দরকার না হলেও ─────────────────────────
 * আজকের মাধ্যমগুলো কেবল ব্যাংকের। কিন্তু MFS-এরও নিজের মাধ্যম আছে
 * (Send Money · Payment · Cash Out)। কলামটা পরে যোগ করা মানে সব সারি
 * আবার ছোঁয়া — তাই এখনই বসছে, `applies_to='bank'` দিয়ে।
 *
 * ⭐ মানগুলো payment method-এর `kind`-এর সাথেই এক শব্দভাণ্ডার (cash ·
 * bank · mfs · cheque) — দুই কলামে দুই বানান থাকলে একদিন 'bank' আর
 * 'BANK' মিলত না, আর কোথাও কিছু লাল হত না।
 *
 * ⚠️ **`applies_to` খালি মানে "সব ধরনে চলে"**, "কোথাও নয়" নয়। ভবিষ্যতে
 * কেউ নগদ বা কার্ডের জন্য একটা মাধ্যম যোগ করলে ধরন না বসিয়েও সেটা যেন
 * সব জায়গায় কাজ করে — সিদ্ধান্তটা এখানে লেখা রইল, যাতে ছয় মাস পরে
 * দুইজন দুই রকম না ধরেন।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_transfer_modes', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 60);
            $table->string('name_bn', 60)->nullable();

            // কোন payment-kind-এর সাথে যায় — খালি হলে সব ধরনে (উপরে কারণ)
            $table->string('applies_to', 8)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_transfer_modes');
    }
};

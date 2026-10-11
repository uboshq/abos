<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ পোর্টাল থেকে গ্রাহকের অনুরোধ — তথ্য বদল আর কাগজ (ট্রেড লাইসেন্স / NID / BIN); গ্রাহক পোর্টাল, ধাপ ১, ১১ অক্টোবর ২০২৬।
 *
 * ── কেন একটা আলাদা টেবিল ───────────────────────────────────────────────
 * মালিকের নিয়ম: গ্রাহক নিজের মূল রেকর্ড নিজে বদলান না — বদলটা অফিসে **অনুরোধ** হিসেবে যায়, অফিস দেখে নিজের
 * পর্দায় বসায়। ⓘ অনুরোধটা তাই একটা আলাদা সারি: কী চাওয়া হলো (`asked`), তখন কী ছিল (`was`), আর অফিস কী বলল।
 * ⓘ কাগজের ফাইলটা সংযুক্তির খাতায় ([[AttachmentEngine]]), এই সারির সাথে বাঁধা।
 *
 * ⓘ কেবল নতুন টেবিল — পুরনো কোনো সারি ছোঁয়া হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_profile_requests')) {
            return;
        }

        Schema::create('customer_profile_requests', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // contact = তথ্য বদল, paper = কাগজ; কাগজের ধরন আলাদা ঘরে
            $table->string('kind', 16);
            $table->string('paper_kind', 24)->nullable();

            // কী চাওয়া হলো, আর তখন রেকর্ডে কী ছিল — অফিস দুইটা পাশাপাশি দেখে
            $table->json('asked')->nullable();
            $table->json('was')->nullable();
            $table->string('note', 500)->nullable();

            $table->string('status', 16)->default('submitted');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->timestamps();

            // ⓘ অফিসের তালিকা "অপেক্ষমাণ" ধরে খোলে; গ্রাহকের পাতা নিজেরগুলো নতুন আগে
            $table->index(['company_id', 'status', 'created_at'], 'cpr_company_status');
            $table->index(['customer_id', 'created_at'], 'cpr_customer_latest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profile_requests');
    }
};

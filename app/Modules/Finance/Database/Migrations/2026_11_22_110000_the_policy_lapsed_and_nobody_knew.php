<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * পলিসিটা মেয়াদ পেরিয়ে গেল, আর কেউ জানল না।
 *
 * ── কী ছিল না ────────────────────────────────────────────────────────
 * ট্রাক, গুদাম, মাল, মানুষ — এগুলোর বীমা কোথাও লেখা থাকত না। প্রিমিয়াম
 * দেওয়া হলে সেটা একটা সাধারণ খরচের ভাউচার হয়ে হারাত, আর নবায়নের তারিখ
 * থাকত কেবল এজেন্টের ফোনে। ⛔ মেয়াদ পেরোনো গুদামে আগুন লাগলে কেউ
 * বীমার টাকা পেত না — আর সেটা জানা যেত আগুনের পরে।
 *
 * ── দুইটা টেবিল কেন ──────────────────────────────────────────────────
 * পলিসিটা একটা, প্রিমিয়াম প্রতি বছর নতুন। ⚠️ এক সারিতে রাখলে নবায়নে
 * গত বছরের প্রিমিয়াম মুছে যেত — আর "এই ট্রাকের বীমায় পাঁচ বছরে কত
 * গেছে" প্রশ্নের উত্তর থাকত না।
 *
 * ⓘ প্রিমিয়ামের সারি টাকা নাড়ে না: "টাকা দিন" বোতাম পরিশোধ ভাউচার খোলে,
 * আর ভাউচার পোস্ট হলে সারিটা নিজে থেকে "দেওয়া হয়েছে" হয়
 * ([[App\Core\Contracts\SettledByAVoucher]]) — মূলধনের সারির মতোই।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_insurance_policies', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // বীমা কোম্পানি — প্রতিষ্ঠানের তালিকা থেকে (kind = insurance)
            $table->foreignId('institution_id')->constrained('fin_institutions')->restrictOnDelete();

            $table->string('policy_no', 60);

            // vehicle · warehouse · goods · people · other
            $table->string('covers', 16);
            // কী — "ট্রাক ঢাকা মেট্রো ট ১১-১২৩৪", "নেত্রকোনা গুদাম"
            $table->string('subject', 200);

            /* ⚠️ ১৮,৪ — বাকি সব টাকার ঘরের মতোই (২১ সেপ্টেম্বর ২০২৬) */
            $table->decimal('sum_insured', 18, 4)->default(0);
            $table->decimal('premium', 18, 4)->default(0);

            $table->date('starts_on');
            $table->date('ends_on');

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * ⓘ একই কোম্পানির একই পলিসি নম্বর দুইবার নয়।
             *
             * ⛔ নামটা হাতে দেওয়া, আর সেটা ইচ্ছাকৃত: নিজে থেকে তৈরি নামটা
             * (`fin_insurance_policies_company_id_institution_id_policy_no_unique`)
             * ৬৫ অক্ষর, আর MySQL ৬৪-এর বেশি নেয় না — ২০ সেপ্টেম্বর ২০২৬-এ
             * এটাই প্রতিটা `migrate:fresh` মাঝপথে ফেলে দিচ্ছিল, আর
             * RefreshDatabase পরের পরীক্ষায় আবার শূন্য থেকে শুরু করত।
             * ⚠️ ভুলটা নীরব ছিল না, কিন্তু কারণটা ছিল অন্য সেশনের পর্দায়।
             */
            $table->unique(['company_id', 'institution_id', 'policy_no'], 'fin_policy_no_unique');
            $table->index(['company_id', 'is_active', 'ends_on']);
        });

        Schema::create('fin_insurance_premiums', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('fin_insurance_policies')->cascadeOnDelete();

            // এই প্রিমিয়াম কোন মেয়াদের
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('amount', 18, 4);

            // draft · posted — পোস্ট হয় পরিশোধ ভাউচারের সাথে
            $table->string('status', 16)->default('draft');
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_insurance_premiums');
        Schema::dropIfExists('fin_insurance_policies');
    }
};

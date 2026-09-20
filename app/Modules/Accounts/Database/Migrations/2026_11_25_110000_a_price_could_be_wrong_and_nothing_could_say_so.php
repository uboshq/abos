<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * দাম ভুল হতে পারত, আর সেটা বলার কোনো কাগজ ছিল না।
 *
 * ── কী ছিল ──────────────────────────────────────────────────────────
 * ভুল শোধরানোর একমাত্র পথ ছিল **ফেরতের কাগজ** — বিক্রয় ফেরত বা ক্রয়
 * ফেরত। ⛔ কিন্তু ফেরত মানে **মাল নড়ে**: স্টক ফিরে আসে, স্তরের দাম
 * হিসাব হয়। ⚠️ অথচ যে ভুলগুলো আসলে ঘটে, তার বেশিরভাগে মাল নড়ে না —
 *
 *   · বিল হয়ে যাওয়ার পর দাম কমানোর কথা হলো
 *   · মাল ঠিকই গেছে, কিন্তু কিছুটা নষ্ট — টাকা ছাড় দেওয়া হলো
 *   · সরবরাহকারী বেশি দাম বসিয়েছে, মাল ফেরত যাচ্ছে না
 *   · গুনতিতে কম এসেছে, কিন্তু কাগজে পুরোটা
 *
 * ⛔ এগুলোর জন্য ফেরতের কাগজ কাটলে স্টক **মিথ্যা** বলত: গুদামে যে মাল
 * নেই সেটা ফিরে এসেছে বলে দেখাত। ⓘ তাই মানুষ হয় জাবেদা ভাউচার কাটতেন
 * (আর তখন গ্রাহকের কাছে দেখানোর মতো কোনো কাগজ থাকত না), নয়তো কিছুই
 * করতেন না।
 *
 * ── মানচিত্র §৭: "ডেবিট ও ক্রেডিট নোট" ──────────────────────────────
 * ⭐ একটাই টেবিল, দুইটা দিক:
 *   · **ক্রেডিট নোট** — গ্রাহককে দেওয়া হয়; আমরা তাঁর কাছে যা পাই, কমে
 *   · **ডেবিট নোট** — সরবরাহকারীকে দেওয়া হয়; আমরা যা দেব, কমে
 *
 * ⓘ দুইটা আলাদা টেবিল নয়, কারণ কাঠামো হুবহু এক আর হিসাবের দিকটা কেবল
 * উল্টো — দুইটা রাখলে একদিন একটায় ভ্যাটের ঘর যোগ হত, অন্যটায় না।
 *
 * ⛔ মাল নড়ে না, আর সেটাই এই কাগজের সংজ্ঞা। মাল ফিরলে ওটা ফেরতের কাগজ,
 * নোট নয় — দুইটার সীমানা এখানেই।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_notes', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('document_no', 64);
            $table->date('trx_date');

            /* `credit` — গ্রাহককে; `debit` — সরবরাহকারীকে */
            $table->string('direction', 8);

            /*
             * কাকে দেওয়া হলো।
             *
             * ⓘ `party_type` ধরনটা মুক্ত লেখা, কারণ কোর কোনো মডিউলের নাম
             * জানে না — ঠিক খতিয়ানের সারির মতোই ([[ledger_entries]])।
             */
            $table->string('party_type', 32);
            $table->unsignedBigInteger('party_id');

            /*
             * ⭐ কোন কাগজটা শোধরানো হচ্ছে — ঐচ্ছিক, কিন্তু প্রায় সবসময় থাকে।
             *
             * ⚠️ FK নয়, ইচ্ছাকৃতভাবে: আজ এটা বিক্রয় বিল, কাল ক্রয় বিল, পরশু
             * হয়তো অন্য কিছু। ⓘ `source_type`/`source_id` জোড়াটা পুরো
             * ব্যবস্থায় একই ভাষা বলে, আর ড্রিল সেটাই বোঝে (নিয়ম ১)।
             */
            $table->string('against_type', 64)->nullable();
            $table->unsignedBigInteger('against_id')->nullable();
            $table->string('against_no', 64)->nullable();

            /*
             * ⚠️ টাকা তিন ঘরে, আর তিনটাই আলাদা করে রাখা হয়।
             *
             * ⓘ `total` কষে নেওয়া যেত, কিন্তু রাখা হয় — কারণ ভ্যাটের হার
             * বদলালে পুরনো কাগজের যোগফল বদলে যেত, আর ছাপা কাগজের সাথে
             * পর্দার সংখ্যা মিলত না।
             */
            $table->decimal('amount', 18, 4);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4);

            /* কেন — বাছাই তালিকা থেকে, আর নিচে মানুষের নিজের ভাষায় */
            $table->string('reason', 32);
            $table->string('narration', 500)->nullable();

            $table->string('status', 16)->default('draft');

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            /* ⚠️ নাম ছোট — জেনারেট করা নাম ৬৪ অক্ষর ছাড়ালে migrate ভাঙে */
            $table->unique(['company_id', 'document_no'], 'acc_note_no_unique');
            $table->index(['company_id', 'direction', 'status'], 'acc_note_state');
            $table->index(['company_id', 'party_type', 'party_id'], 'acc_note_party');
            $table->index(['against_type', 'against_id'], 'acc_note_against');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_notes');
    }
};

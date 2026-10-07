<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * রান্নাঘরের টিকিট — রেস্টুরেন্টের ধাপ ৪।
 *
 * ── কী ছিল না ────────────────────────────────────────────────────────
 * ধাপ ১–৩-এর পর কাউন্টার জানে কয় প্লেট বানানো যাবে, আর বিক্রির সাথে
 * উপকরণও কমে। কিন্তু **রান্নাঘর কিছুই জানে না**। কেউ একটা কাগজ নিয়ে
 * দৌড়ায়, নয়তো চেঁচিয়ে বলে — আর ব্যস্ত সময়ে দুইটাই হারায়।
 *
 * ── কেন `source_type`/`source_id`, `sales_invoice_id` নয় ─────────────
 * টিকিট আজ আসে বিক্রয় চালান থেকে। কাল আসবে টেবিলের অর্ডার থেকে, বা
 * ফোনের অর্ডার থেকে। কলামের নামে `sales_invoice` লিখে রাখলে দ্বিতীয়
 * উৎসটা এলে হয় একটা nullable কলাম বাড়ত, নয় টিকিট দুই টেবিলে ভাগ হত।
 *
 * স্টক মুভমেন্টে এই একই ধরনটা আগে থেকেই আছে, আর একই কারণে।
 *
 * ── কেন তিনটা সময়ের ঘর, একটা `status` নয় ────────────────────────────
 * অবস্থা জানলে "এখন কী" বলা যায়। সময় জানলে **কতক্ষণ** বলা যায় — আর
 * রান্নাঘরে ওটাই আসল প্রশ্ন: কোন টিকিটটা সবচেয়ে বেশিক্ষণ বসে আছে,
 * আর রাঁধতে গড়ে কত লাগছে। শুধু `status` রাখলে ওই দুইটার একটাও পরে
 * বের করা যেত না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            /* কোন কাগজ থেকে এল — নাম নয়, ধরন ও নম্বর */
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');

            /*
             * কাগজের নম্বরটা এখানেও লেখা থাকে।
             *
             * রাঁধুনি ওটাই ডেকে বলেন ("INV-0042 রেডি")। জোড়া লাগিয়ে
             * আনা যেত, কিন্তু ব্যস্ত সময়ে প্রতি দশ সেকেন্ডে পাতাটা
             * আবার আসে — আর টিকিটের সাথে নম্বরটা বসিয়ে রাখলে ওই
             * জোড়াটা প্রতিবার লাগাতে হয় না।
             */
            $table->string('document_no', 64);

            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();
            $table->decimal('qty', 18, 4);

            $table->string('state', 16)->default('placed');

            $table->timestamp('placed_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();

            $table->string('note', 191)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->uuid('public_id')->unique();

            /*
             * রান্নাঘরের পর্দা সবসময় একটাই প্রশ্ন করে: এই কোম্পানির
             * যেগুলো এখনো দেওয়া হয়নি, পুরনোটা আগে।
             */
            $table->index(['company_id', 'state', 'placed_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_kitchen_tickets');
    }
};

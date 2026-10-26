<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ এক টাকায় বহু বিল — কোন বিলে কত বসল (মালিকের নকশা: "খোলা বিল ও ক্রেডিট", প্রতিটা বিলে "এই রসিদে কত"; cb-ma-4, ২৫ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে রসিদ একটা বিলের বিপরীতে (`vouchers.against_type/against_id`, Accounts-Finance অডিট ম১) — ৫১,২০০ টাকার চার বিল একটা রসিদে মেটানো
 * যেত না, অথচ আন্তর্জাতিক নিয়মে (SAP F-28, Oracle cash application) এক টাকা বহু বিলে ভাগ হয়।
 * ⓘ এক কাগজের বহু সারি, তাই আলাদা টেবিল ([[VoucherBillShare]]-এর একই ছাঁচ: কোম্পানি ভাউচারের মাধ্যমে, নিজের প্রকাশ্য নাম, নিরীক্ষায়)।
 * ⓘ বিলের বাকি কেবল পাকা ভাউচারের সারি গোনে ([[SalesInvoice::paidByReceiptVouchers()]]) — খসড়া বা বাতিলের সারি টাকা নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_voucher_allocations', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            // ⓘ কোন কাগজ — ড্রিলের নাম আর তার আইডি (রসিদের পুরনো "বিপরীতে"-র একই জোড়া)
            $table->string('against_type', 60);
            $table->unsignedBigInteger('against_id');
            $table->decimal('amount', 18, 4);
            $table->timestamps();

            $table->index(['against_type', 'against_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_voucher_allocations');
    }
};

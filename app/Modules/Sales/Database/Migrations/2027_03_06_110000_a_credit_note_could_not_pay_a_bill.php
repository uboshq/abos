<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * খোলা ক্রেডিট বিলে প্রয়োগ — দফা ৩ (মালিকের নকশা ১০০, ২১ অক্টোবর ২০২৬; fe-র D1)।
 *
 * ⓘ গ্রাহকের ক্রেডিট নোট বা আগের আদায়ের অপ্রয়োগকৃত অংশ খাতায় আগেই Cr হয়ে আছে — বিলে বসানো মানে কেবল "কোন বিল শোধ হলো"
 * লেখা, নতুন কোনো দাখিলা নয়। আগে এর কোনো ঘর ছিল না: পাওনা ঠিক থাকত, অথচ বিলটা চিরকাল "বাকি" দেখাত।
 *
 * ⓘ সারির ধরন (`kind`):
 *   · credit_note — `source_id` = acc_notes (গ্রাহকের ক্রেডিট নোট), `target_invoice_id` = যে বিলে বসল
 *   · credit      — `source_id` = sal_collections (আগের আদায়), `target_invoice_id` = যে বিলে বসল
 *   · debit_note  — এই আদায়ের টাকায় শোধ হওয়া ডেবিট নোট: `source_id` = acc_notes, `target_invoice_id` ফাঁকা
 * ⓘ কেবল পাকা আদায়ের সারি গোনা হয় ([[SalesInvoice::collectedAmount()]]); বাতিল হলে নিজে থেকেই আর গোনা হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sal_collection_applications')) {
            return;
        }

        Schema::create('sal_collection_applications', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('collection_id')->constrained('sal_collections')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('target_invoice_id')->nullable()->constrained('sal_invoices')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->timestamps();

            $table->index(['kind', 'source_id']);
            $table->index('target_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_collection_applications');
    }
};

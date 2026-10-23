<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ করণীয় — আইনের দায়, শেষ তারিখসহ (টাকা দেওয়ার মিল-তালিকা #৩; fe, ২২ অক্টোবর ২০২৬)।
 *
 * ⓘ সরবরাহকারীকে দেওয়ার সময় কাটা AIT আর VDS খাতায় সরকারের দায় হয়ে বসত (২১২১, ২১২২), কিন্তু "কবে কোষাগারে জমা দিতে হবে" আর
 * "সরবরাহকারীকে মূসক-৬.৬ দিতে হবে" কেউ মনে করাত না। এই টেবিল সেই দায়গুলো রাখে — কোন কাগজ থেকে এল, কী করতে হবে, কত, কবের মধ্যে,
 * কে কবে করলেন, কোন চালান নম্বরে।
 *
 * ⓘ শুরু থেকেই সাধারণ (fe): এক কাগজের এক দায় এক সারি — `source_type` + `source_id` (যেকোনো কাগজ) আর `kind` (কী করতে হবে) আর
 * `subject` (AIT না VDS; না লাগলে ফাঁকা)। আজকের দুই ধরন: `kosagar_deposit`, `mushak_6_6_issue`; পরে a6-র `mushak_6_6_receive`।
 *
 * ⛔ (company, source_type, source_id, kind, subject) অনন্য — একই কাগজ দুবার পোস্ট হলেও দায় দুবার বসে না।
 * ⓘ `cancelled_at`: কাগজ বাতিল বা চেক ফেরত হলে খোলা দায় বাতিল; করা হয়ে যাওয়া দায় থেকে যায় (জমা সত্যিই হয়েছে)।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compliance_obligations')) {
            return;
        }

        Schema::create('compliance_obligations', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('source_no', 64)->nullable();
            $table->string('kind', 32);
            $table->string('subject', 16)->default('');
            $table->decimal('amount', 18, 2)->default(0);
            $table->date('due_on');
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ref_no', 64)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'source_type', 'source_id', 'kind', 'subject'], 'compliance_once');
            $table->index(['company_id', 'done_at', 'cancelled_at', 'due_on'], 'compliance_open');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_obligations');
    }
};

<?php

declare(strict_types=1);

use App\Core\Support\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * গুদাম থেকে গুদামে স্টক স্থানান্তর।
 *
 * ── কেন দুইটা সময় আলাদা করে রাখা ────────────────────────────────────
 * রওনা আর পৌঁছানো এক মুহূর্ত নয়। মাঝখানের সময়টুকু কাগজে না থাকলে মাল
 * হারালে কেউ বলতে পারত না কোথায় হারাল — উৎসে গোনা হয়েছিল, গন্তব্যে
 * পৌঁছায়নি, আর দুই গুদামের কেউই দায় নিত না।
 *
 * ── কেন কোনো টাকার ঘর নেই ───────────────────────────────────────────
 * গুদাম বদলালে মালের মূল্য বদলায় না। দর বসালে কেউ ভাবত ওটা দিয়ে কোনো
 * হিসাব হচ্ছে, আর একদিন কেউ ওটা বদলে দিত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_transfers', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);

            $table->foreignId('from_warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();

            $table->date('trx_date');

            // কখন ট্রাকে উঠল, আর কখন পৌঁছাল
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();

            // draft → confirmed (রওনা) → closed (পৌঁছেছে)
            $table->string('status', 32)->default(DocumentStatus::DRAFT);
            $table->text('narration')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'trx_date']);
            $table->index('from_warehouse_id');
            $table->index('to_warehouse_id');
        });

        Schema::create('inv_transfer_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('stock_transfer_id')->constrained('inv_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            $table->decimal('qty', 18, 4);
            $table->unsignedSmallInteger('line_no');
            $table->timestamps();

            $table->index(['stock_transfer_id', 'line_no']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_transfer_lines');
        Schema::dropIfExists('inv_transfers');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * সরবরাহকারী।
 *
 * গ্রাহকের টেবিলের আয়না, তিনটা পার্থক্য সহ:
 *
 *   credit_limit আছে, কিন্তু সেটা তথ্য — সরবরাহকারী আমাদের কত বাকিতে
 *   দেবে তা তারা ঠিক করে, আমরা নয়। তাই ছাড়িয়ে গেলে ক্রয় আটকানো হয় না,
 *   শুধু দেখানো হয়।
 *
 *   BIN ও TIN আছে, কারণ ক্রয়ে উৎসে ভ্যাট কাটতে হলে ওগুলো লাগে — আর
 *   বিলের সময় খুঁজতে গেলে পাওয়া যায় না।
 *
 *   payment_term_id আছে: সরবরাহকারীর সাথে শর্ত আগে থেকে ঠিক থাকে
 *   ("৩০ দিন"), আর প্রতিটা ক্রয়ে সেটা আবার বলার মানে নেই।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 191);
            $table->string('name_bn', 191)->nullable();

            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('address_en', 500)->nullable();
            $table->string('address_bn', 500)->nullable();

            // যোগাযোগের মানুষ — বড় সরবরাহকারীতে প্রতিষ্ঠানের নম্বরে
            // ফোন করে কাজ হয় না
            $table->string('contact_person', 120)->nullable();
            $table->string('contact_phone', 32)->nullable();

            // ধরন মাস্টার তালিকা থেকে, মুক্ত লেখা নয়
            $table->foreignId('party_type_id')->nullable()
                ->constrained('mdm_party_types')->nullOnDelete();

            $table->foreignId('payment_term_id')->nullable()
                ->constrained('mdm_payment_terms')->nullOnDelete();

            // উৎসে ভ্যাট কাটতে লাগে
            $table->string('bin', 32)->nullable();
            $table->string('tin', 32)->nullable();

            /*
             * তারা আমাদের কত বাকিতে দেবে — তথ্য, নিয়ম নয়।
             *
             * গ্রাহকের সীমা ছাড়ালে বিল আটকানো যায়, কারণ সেটা আমাদের
             * সিদ্ধান্ত। সরবরাহকারীর সীমা তাদের সিদ্ধান্ত, আর সেটা
             * ছাড়িয়ে গেলে আমাদের সিস্টেম আটকানোর কেউ নয় — শুধু
             * ক্রয়কারীকে জানানো দরকার।
             */
            $table->decimal('credit_limit', 18, 4)->default(0);
            $table->unsignedSmallInteger('credit_days')->default(0);

            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->date('opening_date')->nullable();

            $table->string('status', 16)->default('confirmed');
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'name_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};

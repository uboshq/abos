<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একই ক্রেতার হাতে কুপনটা দুইবার খাটল — স্পেক §৭-ঞ।
 *
 * ── ⭐ কুপন কী ───────────────────────────────────────────────────────
 * ⓘ অফারটা সাধারণ অফারের মতোই (শর্ত, সুবিধা, মেয়াদ), কেবল একটা কোড
 * ছাড়া বিলে বসে না। ⛔ কোডটাই চাবি — তাই কোডের নিজের গোনা লাগে:
 * মোট কতবার, একজন ক্রেতা কতবার, আর কার জন্য।
 *
 * ── ⚠️ কেন দুইটা টেবিল, একটা নয় ─────────────────────────────────────
 * ⓘ `used_count` একা থাকলে বিল বাতিলে সংখ্যাটা কমানো যেত, কিন্তু
 * **কোন বিলটা** কমাল তা বলা যেত না। ⛔ তখন একই বাতিল দুইবার চাপলে
 * দুইবার কমত, আর কুপনটা নিজের সীমার বেশি খাটত — নীরবে।
 *
 * ⭐ তাই প্রতিটা ব্যবহারের নিজের সারি (`promotion_coupon_redemptions`),
 * আর ফেরত মানে সারিতে `reversed_at` — একবারই বসে।
 *
 * ⚠️ সূচকের নাম হাতে দেওয়া, ৬৪ অক্ষরের নিচে — লম্বা নাম সবার
 * `migrate:fresh` ভাঙে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_coupons', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            /* ⛔ অফার মোছা যায় না যতক্ষণ তার কুপন আছে — ইতিহাস হারাত */
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();

            /*
             * ⓘ ক্রেতা যা টাইপ করেন — সবসময় বড় হাতের, ফাঁকা ছাড়া।
             * ⚠️ অনন্যতা কোম্পানি-প্রতি: দুই কোম্পানির একই কোড থাকতেই পারে।
             */
            $table->string('code', 40);

            /* ⛔ মোট কতবার — খালি রাখা যায় না; "অসীম" নীরবে ধরে নেওয়া হয় না */
            $table->unsignedInteger('max_uses')->default(1);

            /* ⓘ একজন ক্রেতা কতবার — খালি মানে মোট সীমাটাই একমাত্র সীমা */
            $table->unsignedInteger('max_uses_per_customer')->nullable();

            /* ⓘ কেবল একজন ক্রেতার জন্য হলে */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            /*
             * ⭐ তালার নিচে গোনা সংখ্যা — `redeem()` সারিটা `lockForUpdate`
             * করে তবেই বাড়ায়। ⓘ ফেরতের সারিগুলো থেকে সবসময় আবার গোনা যায়।
             */
            $table->unsignedInteger('used_count')->default(0);

            /* ⓘ অফারের মেয়াদের ভিতরেই — `CouponDesk::issue()` যাচাই করে */
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'pcp_co_code_uq');
            $table->index(['company_id', 'promotion_id'], 'pcp_prom_ix');
        });

        Schema::create('promotion_coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->foreignId('coupon_id')
                ->constrained('promotion_coupons', 'id', 'pcr_coupon_fk')
                ->restrictOnDelete();

            /* ⓘ বিলে যে সুবিধাটা বসল — ছাদের গোনা ওখান থেকেই */
            $table->foreignId('promotion_application_id')
                ->constrained('promotion_applications', 'id', 'pcr_app_fk')
                ->restrictOnDelete();

            $table->foreignId('customer_id')->nullable()
                ->constrained('customers', 'id', 'pcr_cust_fk')
                ->nullOnDelete();

            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');

            $table->timestamp('redeemed_at');

            /* ⭐ ফেরত একবারই — এই ঘর ভরা থাকলে সারিটা আর গোনা হয় না */
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()
                ->constrained('users', 'id', 'pcr_rev_by_fk')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'source_type', 'source_id'], 'pcr_src_ix');
            $table->index(['coupon_id', 'customer_id'], 'pcr_coupon_cust_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_coupon_redemptions');
        Schema::dropIfExists('promotion_coupons');
    }
};

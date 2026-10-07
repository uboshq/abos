<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * প্রতিটা বিক্রয়ের কাগজ নিজের পথটা নিজের গায়ে লিখে রাখে।
 *
 * ── ⛔ কেন গ্রাহকের ঘর থেকে join করে পড়া যথেষ্ট নয় ────────────────────
 * গ্রাহকের পথ বদলায় — আজ খুচরা দোকান, ছয় মাস পরে ডিলার। join করে
 * পড়লে সেদিন থেকে তাঁর **গত বছরের প্রতিটা বিক্রিও** "ডিলার" হয়ে যেত,
 * আর পথ-ভিত্তিক রিপোর্টের পুরনো মাসগুলো নীরবে বদলে যেত। ⭐ কাগজে লেখা
 * পথটা বিক্রির মুহূর্তের সত্য; পরে কেউ গ্রাহক বদলালে ইতিহাস বদলায় না।
 *
 * ⓘ ঘরটা কেউ হাতে বসায় না — [[CarriesTheSalesChannel]] কাগজ তৈরির সময়
 * বসায়। পুরনো কাগজগুলো খালি থাকে: তখন পথ বলে কিছু ছিলই না, আর আজকের
 * গ্রাহক-পথ থেকে পিছনে বসালে ঠিক উপরের ভুলটাই করা হত।
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['sal_orders', 'sal_challans', 'sal_invoices', 'sal_returns'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->foreignId('channel_id')->nullable()->after('customer_id')
                    ->constrained('mdm_sales_channels')->nullOnDelete();

                // পথ ধরে মাসের বিক্রয় — রিপোর্টের একমাত্র প্রশ্ন; নাম ছোট, ৬৪-র নিচে
                $table->index(['company_id', 'channel_id'], $name.'_channel_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name.'_channel_idx');
                $table->dropConstrainedForeignId('channel_id');
            });
        }
    }
};

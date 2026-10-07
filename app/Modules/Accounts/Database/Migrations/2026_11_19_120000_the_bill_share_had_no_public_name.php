<?php

declare(strict_types=1);

use App\Modules\Accounts\Models\VoucherBillShare;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * খরচের বিল-ভাগের সারি — বাইরের নাম (`public_id`), ১৯ সেপ্টেম্বর ২০২৬।
 *
 * ⛔ টেবিলটা ১৭ তারিখে বানানো হয়েছিল
 * (`2026_11_17_100000_the_expense_form_had_three_fields…`) আর `publicId()`
 * বাদ পড়েছিল। ⓘ `PublicIdTest` লাল হয়ে ধরল — abos-f9 খবর দিল। ⚠️ ঐ
 * মাইগ্রেশন লাইভে চলে গেছে, তাই সেটা বদলানো যায় না; নতুন মাইগ্রেশন।
 *
 * ⓘ পুরনো সারিগুলোও নাম পায়, নাহলে সেগুলোর দিকে কোনো লিংক যেত না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('acc_voucher_bill_shares', 'public_id')) {
            Schema::table('acc_voucher_bill_shares', function (Blueprint $table) {
                $table->publicId();
            });
        }

        VoucherBillShare::query()->withoutGlobalScopes()->whereNull('public_id')->eachById(
            fn (VoucherBillShare $row) => $row->forceFill(['public_id' => (string) Str::uuid()])->saveQuietly(),
        );
    }

    public function down(): void
    {
        Schema::table('acc_voucher_bill_shares', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};

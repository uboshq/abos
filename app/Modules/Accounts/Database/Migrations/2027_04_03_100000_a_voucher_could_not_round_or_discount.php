<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ রসিদ আর পরিশোধ ভাউচারে কম-বেশি — ছাড় বা রাউন্ডিং, কাগজে একটাই (মালিকের নকশা ১০০, দফা ৪; cb-ma-5, ২৫ অক্টোবর ২০২৬)।
 *
 * ⓘ আদায়ের একই দুই ঘর (`difference_kind`, `difference_amount`) — মালিকের সইয়ের ছাপ আর খাতার সারি এখান থেকেই
 * ([[\App\Modules\Accounts\Services\VoucherApproval::differenceFingerprint()]], [[\App\Modules\Accounts\Services\WithheldTax::difference()]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->string('difference_kind', 10)->nullable()->after('vds_amount');
            $table->decimal('difference_amount', 18, 4)->default(0)->after('difference_kind');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropColumn(['difference_kind', 'difference_amount']);
        });
    }
};

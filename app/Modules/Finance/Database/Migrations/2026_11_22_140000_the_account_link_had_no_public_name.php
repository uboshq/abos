<?php

declare(strict_types=1);

use App\Modules\Finance\Models\InstitutionAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * খাত-প্রতিষ্ঠানের জোড়ার সারিটার বাইরের কোনো নাম ছিল না।
 *
 * ⚠️ `fin_institution_accounts` বসানোর সময় `publicId()` দিইনি — ধরে
 * নিয়েছিলাম জোড়ার সারি "ব্যবসার নথি" নয়। ⓘ কিন্তু নিয়মটা তা বলে না:
 * [[Tests\Feature\Architecture\PublicIdTest]] ফ্রেমওয়ার্কের টেবিল ছাড়া
 * **প্রতিটা** টেবিলে বাইরের কী চায়, আর কারণটা ন্যায্য — যেদিন মোবাইল
 * অ্যাপ বা API সারিটা চাইবে, সেদিন ক্রমিক আইডি পাঠানো মানে অন্য
 * কোম্পানির সারি অনুমান করার পথ খুলে দেওয়া।
 *
 * ⓘ আলাদা মাইগ্রেশন, আগেরটা বদলে নয়: টেবিলটা ইতিমধ্যে লাইভে বসে গেছে
 * (ddb3d1c7), আর বসে যাওয়া মাইগ্রেশন বদলালে যে সার্ভারে সেটা চলে গেছে
 * সেখানে আর কিছুই ঘটত না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fin_institution_accounts', 'public_id')) {
            Schema::table('fin_institution_accounts', function (Blueprint $table) {
                $table->publicId();
            });
        }

        InstitutionAccount::query()->withoutGlobalScopes()->whereNull('public_id')->eachById(
            fn (InstitutionAccount $row) => $row->forceFill(['public_id' => (string) Str::uuid()])->saveQuietly(),
        );
    }

    public function down(): void
    {
        Schema::table('fin_institution_accounts', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};

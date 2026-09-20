<?php

declare(strict_types=1);

use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * বীমার প্রিমিয়াম "বিবিধ খরচে" লুকিয়ে থাকত — চলমান কোম্পানিগুলোতেও খাত।
 *
 * ⓘ `StandardChart`-এ সারি যোগ করলে কেবল নতুন কোম্পানি পায়; চলমানগুলো পায়
 * "মানসম্মত ছক বসান" চাপলে। তাই এই মাইগ্রেশন — কারণটা পুরোটা
 * `fuel_and_hire_were_the_same_expense`-এ লেখা।
 *
 * ⚠️ পুরনো দাখিলা ছোঁয়া হয় না: বিবিধ খরচে যা বসেছে তা ওখানেই থাকে।
 * খাত বদলানো মানে গত বছরের লাভ-লোকসান বদলানো।
 */
return new class extends Migration
{
    private const CODE = '5221';

    private const PARENT = '5200';

    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $exists = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('code', self::CODE)
                ->exists();

            if ($exists) {
                continue;
            }

            $parentId = DB::table('accounts')->where('company_id', $companyId)->where('code', self::PARENT)->value('id')
                ?? DB::table('accounts')->where('company_id', $companyId)->where('code', '5000')->value('id');

            // ⓘ ছক এখনো বসেনি এমন কোম্পানি — ছক বসানোর সময় খাতটা নিজেই আসবে
            if ($parentId === null) {
                continue;
            }

            DB::table('accounts')->insert([
                'public_id' => (string) Str::uuid(),
                'company_id' => $companyId,
                'code' => self::CODE,
                'name_en' => 'Insurance Premium',
                'name_bn' => 'বীমা প্রিমিয়াম',
                'type' => Account::EXPENSE,
                'parent_id' => $parentId,
                'is_group' => false,
                'nature' => Account::defaultNatureFor(Account::EXPENSE),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * ⚠️ খাতটা মোছা হয় না — ততক্ষণে প্রিমিয়াম বসে গেছে থাকতে পারে।
     * মাইগ্রেশন ফেরানো মানে কোডটা ফেরানো, খাতাটা নয়।
     */
    public function down(): void {}
};

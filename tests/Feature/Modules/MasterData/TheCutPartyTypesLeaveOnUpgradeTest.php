<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * আপগ্রেডের পথটা মাপা — যেটা আমাদের কোনো টেস্টই আগে মাপেনি।
 *
 * নতুন ইনস্টল `installDefaults` চালায়, তাই তার তালিকা সবসময় ঠিক। কিন্তু
 * একটা চলমান কোম্পানি আপগ্রেড করলে সে কেবল মাইগ্রেশনগুলো পায় — seed নয়।
 * মালিকের কেটে দেওয়া RENTAL/BROKER/DEALER পুরনো মাইগ্রেশন ও seed থেকে সেই
 * কোম্পানিতে বসে ছিল, আর নতুন মুছে-ফেলা মাইগ্রেশনটাই সেগুলো তোলে।
 *
 * ⚠️ RefreshDatabase খালি DB-তে মাইগ্রেশন চালায় (তখন কোনো কোম্পানি নেই),
 * তাই মাইগ্রেশনটা এখানে নিজে থেকে কিছু করে না — আমরা পুরনো অবস্থাটা হাতে
 * বানিয়ে তবে up() ডাকি। এভাবেই "আপগ্রেড অন্ধ" ফাঁকটা একবার হলেও ঢাকা পড়ে।
 */
class TheCutPartyTypesLeaveOnUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** ★ অব্যবহৃত কাটা-ধরন যায়, ব্যবহৃত (সরবরাহকারী বা গ্রাহক) রয়ে যায়। */
    public function test_the_migration_drops_the_unused_cut_types_but_keeps_the_used_ones(): void
    {
        // আপগ্রেডের আগের অবস্থা — installDefaults এগুলো আর বসায় না, তাই হাতে
        $rental = $this->seedOldType('RENTAL', 'Rental Vehicle', 'ভাড়ার গাড়ি', 'supplier');
        $broker = $this->seedOldType('BROKER', 'Broker', 'দালাল', 'supplier');
        $dealer = $this->seedOldType('DEALER', 'Dealer', 'ডিলার', 'customer');

        // RENTAL একজন সরবরাহকারীর গায়ে, DEALER একজন গ্রাহকের গায়ে — BROKER খালি
        Supplier::query()->create([
            'code' => 'S-UPG', 'name_en' => 'Rented Lorry Co', 'name_bn' => 'ভাড়া লরি',
            'party_type_id' => $rental,
        ]);
        Customer::query()->create([
            'code' => 'C-UPG', 'name_en' => 'Old Dealer Shop', 'name_bn' => 'পুরনো ডিলার',
            'party_type_id' => $dealer,
        ]);

        // মাইগ্রেশন ফাইলটা একটা অবজেক্ট return করে; require করে সরাসরি up() ডাকি
        $migration = require base_path(
            'app/Modules/MasterData/Database/Migrations/'
            .'2026_10_25_100000_the_rented_lorry_the_broker_and_the_dealer_were_cut.php'
        );
        $migration->up();

        // অব্যবহৃত BROKER সত্যিই মুছেছে (hard delete)
        $this->assertNull(
            PartyType::query()->find($broker),
            'অব্যবহৃত BROKER মুছে যাওয়ার কথা ছিল।'
        );

        // ব্যবহৃত দুইটা রয়ে গেছে — সরবরাহকারী-দিক ও গ্রাহক-দিক দুইটাই পরীক্ষিত
        $this->assertNotNull(
            PartyType::query()->find($rental),
            'সরবরাহকারী-ব্যবহৃত RENTAL রয়ে যাওয়ার কথা।'
        );
        $this->assertNotNull(
            PartyType::query()->find($dealer),
            'গ্রাহক-ব্যবহৃত DEALER রয়ে যাওয়ার কথা।'
        );
    }

    /** পুরনো ধরন হাতে বসিয়ে তার id ফেরায়। */
    private function seedOldType(string $code, string $en, string $bn, string $appliesTo): int
    {
        return (int) DB::table('mdm_party_types')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'code' => $code,
            'name_en' => $en,
            'name_bn' => $bn,
            'applies_to' => $appliesTo,
            'is_default' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ একই নামের দোকান সম্পাদনায় প্রতিবার "এই নামে আগে থেকেই একটা পক্ষ আছে" — মালিক, ১০ অক্টোবর ২০২৬।
 *
 * TRADE DEPOT-এ "Maa Enterprise" নামে চারটা আলাদা দোকান। CUS-0118-এর বাকির সীমা বদলাতে গেলে নাম না ছুঁয়েও
 * নকলের সতর্কতা আসত, আর টিক না দিয়ে সংরক্ষণ হত না। ⓘ নকল খোঁজা নতুন নাম বা নতুন ফোন বসানোর মুহূর্তে।
 */
class EditingASameNamedShopNeverAsksAgainTest extends TestCase
{
    use RefreshDatabase;

    private Location $point;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $company = Company::query()->findOrFail($owner->current_company_id);
        CompanyContext::set($company->id, $owner->current_branch_id ?? $company->defaultBranch()?->id);
        $this->actingAs($owner);

        $this->point = Location::query()->where('company_id', $company->id)->where('level', Location::POINT)->firstOrFail();
    }

    private function shop(string $name, string $phone, bool $allow = false): Customer
    {
        return app(CustomerService::class)->create(array_filter([
            'name_en' => $name,
            'owner_name' => 'Razib',
            'phone' => $phone,
            'address_en' => 'Shambhuganj',
            'location_id' => $this->point->id,
            'credit_limit' => 0,
            'credit_days' => 0,
            'allow_duplicate' => $allow ?: null,
        ], fn ($v) => $v !== null));
    }

    public function test_editing_one_of_two_same_named_shops_without_touching_the_name_saves(): void
    {
        $this->shop('M/S. Maa Enterprise', '01916085552');
        $second = $this->shop('M/S. Maa Enterprise', '01946072224', allow: true);

        $saved = app(CustomerService::class)->update($second, ['credit_days' => 15, 'name_en' => 'M/S. Maa Enterprise']);

        $this->assertSame(15, (int) $saved->fresh()->credit_days, 'নাম না বদলেও সম্পাদনা নকল বলে আটকাল।');
    }

    public function test_renaming_a_shop_into_an_existing_name_still_asks(): void
    {
        $this->shop('M/S. Maa Enterprise', '01916085552');
        $other = $this->shop('Rahim Store', '01811000222');

        $this->expectException(ValidationException::class);
        app(CustomerService::class)->update($other, ['name_en' => 'M/S. Maa Enterprise']);
    }
}

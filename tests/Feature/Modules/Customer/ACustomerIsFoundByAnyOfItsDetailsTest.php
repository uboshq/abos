<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গ্রাহক খোঁজা — মালিক, ১ অক্টোবর ২০২৬: *"কোড, নাম, পয়েন্ট, এরিয়া, ঠিকানা, মালিকের নাম, মোবাইল —
 * egulo zekono titei hobe"*। ⓘ আগে কেবল নাম, কোড আর ফোন মিলত।
 */
final class ACustomerIsFoundByAnyOfItsDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_detail_finds_the_customer_and_a_stranger_finds_nothing(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $area = Location::query()->create(['company_id' => $company->id, 'code' => 'AR-QX', 'name_en' => 'Qwarea Zone', 'level' => Location::AREA, 'is_active' => true]);
        $point = Location::query()->create(['company_id' => $company->id, 'parent_id' => $area->id, 'code' => 'PT-QX', 'name_en' => 'Qwpoint Bazar', 'level' => Location::POINT, 'is_active' => true]);

        $customer = Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'location_id' => $point->id,
            'code' => 'CUS-QX91', 'name_en' => 'M/S Qwname Enterprise', 'owner_name' => 'Qwowner Rahman',
            'phone' => '01799887766', 'address_en' => 'Qwaddress Road', 'status' => 'active', 'is_active' => true,
        ]);

        foreach (['CUS-QX91', 'Qwname', 'Qwpoint', 'Qwarea', 'Qwaddress', 'Qwowner', '99887766'] as $term) {
            $this->assertContains($customer->id, Customer::query()->search($term)->pluck('id')->all(),
                "'{$term}' লিখে খুঁজলে গ্রাহক আসে না — মালিক বলেছেন যেকোনো ঘর দিয়েই খোঁজা যাবে।");
        }

        $this->assertNotContains($customer->id, Customer::query()->search('Zzznothing')->pluck('id')->all(),
            'অমিল লেখাতেও গ্রাহক এলো — খোঁজা তখন আর ছাঁকে না।');

        /*
         * ⭐ মালিক, একই দিন: *"নিষ্ক্রিয়রাও দেখাও filter hoy na"* — ছাঁকনির প্যানেলের কোনো ঘর
         * বদলালেই তালিকা বদলায় ([[ui.toolbar]]-এর ধারকে `data-action="submit-form"`)।
         */
        $html = $this->get(route('customer.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<div id="toolbar-filters"\s+data-action="submit-form"/', $html,
            'ছাঁকনির টিক দিলে তালিকা বদলায় না — "খুঁজুন" না চাপা পর্যন্ত কিছুই হয় না।');
    }
}

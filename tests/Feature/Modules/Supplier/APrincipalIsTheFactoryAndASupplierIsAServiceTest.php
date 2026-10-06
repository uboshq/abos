<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রিন্সিপাল আর সরবরাহকারী — মালিক, ৬ অক্টোবর ২০২৬: *"সাপ্লায়ারের নাম ভেন্ডর নিয়ে সমস্যা; ডিপো ব্যবসা, তাই প্রিন্সিপাল …
 * প্রিন্সিপাল শুধু ম্যানুফ্যাকচারারের জন্য রাখো, ডিপোর জন্য; আর সার্ভিস প্রোভাইডারে সাপ্লায়ার নিয়ে যাও"*।
 *
 * দাবি:
 *  · নতুন কোম্পানির ধরনের তালিকায় VENDOR = "Principal / প্রিন্সিপাল", আর GENERAL = "Supplier / সরবরাহকারী"।
 *  · প্রিন্সিপাল মূল সরবরাহকারীর তালিকায়, সাধারণ সরবরাহকারী সেবাদাতার তালিকায় — কেউ দুই জায়গায় নয়।
 *  · নতুন সরবরাহকারীর ফর্মে কেবল প্রিন্সিপাল, সেবাদাতার ফর্মে সাধারণ সরবরাহকারী (প্রিন্সিপাল নয়)।
 */
final class APrincipalIsTheFactoryAndASupplierIsAServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app()->setLocale('bn');
    }

    public function test_a_principal_sits_in_the_main_list_and_a_plain_supplier_with_the_services(): void
    {
        $principal = PartyType::query()->where('code', Supplier::VENDOR_CODE)->firstOrFail();
        $general = PartyType::query()->where('code', 'GENERAL')->first();

        $this->assertSame(['Principal', 'প্রিন্সিপাল'], [$principal->name_en, $principal->name_bn], '⛔ VENDOR এখনো "Vendor" নামে।');
        $this->assertNotNull($general, '⛔ সাধারণ সরবরাহকারীর ধরন নেই।');
        $this->assertSame(['Supplier', 'সরবরাহকারী', PartyType::SUPPLIER], [$general->name_en, $general->name_bn, $general->applies_to]);

        $factory = Supplier::query()->create(['code' => 'PRN-T1', 'name_en' => 'Factory One', 'party_type_id' => $principal->id, 'is_active' => true]);
        $shop = Supplier::query()->create(['code' => 'GEN-T1', 'name_en' => 'Packet Shop', 'party_type_id' => $general->id, 'is_active' => true]);

        $this->assertTrue(Supplier::query()->onlySuppliers()->whereKey($factory->id)->exists());
        $this->assertFalse(Supplier::query()->onlyServiceProviders()->whereKey($factory->id)->exists());
        $this->assertTrue(Supplier::query()->onlyServiceProviders()->whereKey($shop->id)->exists(), '⛔ সাধারণ সরবরাহকারী সেবাদাতার তালিকায় নেই।');
        $this->assertFalse(Supplier::query()->onlySuppliers()->whereKey($shop->id)->exists(), '⛔ সাধারণ সরবরাহকারী প্রিন্সিপালের তালিকায়।');

        $main = $this->get(route('supplier.create'))->assertOk()->viewData('partyTypes')->pluck('code')->all();
        $this->assertSame([Supplier::VENDOR_CODE], $main, '⛔ নতুন সরবরাহকারীর ফর্মে প্রিন্সিপাল ছাড়া অন্য ধরন।');

        $services = $this->get(route('supplier.create', ['kind' => 'service']))->assertOk()->viewData('partyTypes')->pluck('code')->all();
        $this->assertContains('GENERAL', $services, '⛔ সেবাদাতার ফর্মে সাধারণ সরবরাহকারী নেই।');
        $this->assertNotContains(Supplier::VENDOR_CODE, $services);
    }
}

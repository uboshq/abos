<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সেবাদাতার নিজের নম্বর — মালিক, ২ অক্টোবর ২০২৬: *"suppliyer iD - SUP001, সেবাদাতা - ID= SPD-001"*।
 *
 * ⓘ একই পর্দা, একই সেবা ([[SupplierService::seriesFor()]]): ধরন সার্ভিস/পরিবহন হলে SPD, পণ্যের সরবরাহকারী বা ধরন
 * ফাঁকা হলে SUP। হাতে কোড দিলে সেটাই থাকে।
 */
final class AServiceProviderGetsItsOwnNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_service_provider_is_numbered_spd_and_a_supplier_sup(): void
    {
        $service = app(SupplierService::class)->create(['name_en' => 'ZQ Hira Auto', 'party_type_id' => $this->type('SERVICE')]);
        $transport = app(SupplierService::class)->create(['name_en' => 'ZQ Karim Transport', 'party_type_id' => $this->type('TRANSPORT')]);
        $vendor = app(SupplierService::class)->create(['name_en' => 'ZQ Rice Mill', 'party_type_id' => $this->type('VENDOR')]);
        $plain = app(SupplierService::class)->create(['name_en' => 'ZQ No Type']);

        $this->assertStringStartsWith('SPD', $service->code, '⛔ সেবাদাতা SPD নম্বর পায়নি — '.$service->code);
        $this->assertStringStartsWith('SPD', $transport->code, '⛔ পরিবহনকারী (সেবাদাতা) SPD নম্বর পায়নি।');
        $this->assertStringStartsWith('SUP', $vendor->code, '⛔ পণ্যের সরবরাহকারী SUP নম্বর পায়নি।');
        $this->assertStringStartsWith('SUP', $plain->code, '⛔ ধরন ছাড়া সরবরাহকারী SUP নম্বর পায়নি।');

        // ⓘ দুই সিরিজ আলাদা গোনে — দ্বিতীয় সেবাদাতা পরের SPD
        $this->assertNotSame($service->code, $transport->code);
        $this->assertSame((int) preg_replace('/\D/', '', $service->code) + 1, (int) preg_replace('/\D/', '', $transport->code));
    }

    public function test_a_code_typed_by_hand_is_kept(): void
    {
        $kept = app(SupplierService::class)->create(['name_en' => 'ZQ Old Garage', 'code' => 'OLD-77', 'party_type_id' => $this->type('SERVICE')]);

        $this->assertSame('OLD-77', $kept->code);
    }

    private function type(string $code): int
    {
        return (int) PartyType::query()->where('code', $code)->firstOrFail()->id;
    }
}

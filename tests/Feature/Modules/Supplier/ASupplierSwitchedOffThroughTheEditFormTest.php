<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ সম্পাদনার চাবিতেই সরবরাহকারী বন্ধ-চালু করা যেত (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[SupplierService::update()]])।
 *
 * ⚠️ বন্ধ করার বোতাম `supplier.delete` চায়, কিন্তু সম্পাদনার অনুরোধ `is_active` নিত আর সেবা সেটা বসাত — তাই কেবল
 * `supplier.update` হাতে থাকলেই `is_active=0` পাঠিয়ে সরবরাহকারী বন্ধ হত। ⭐ এখন কারণসহ ফেরত; নাম বদল আগের মতোই চলে।
 */
final class ASupplierSwitchedOffThroughTheEditFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_edit_key_alone_cannot_switch_a_supplier_off(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $clerk->givePermissionTo(['supplier.view', 'supplier.update']);

        $supplier = Supplier::query()->where('is_active', true)->orderBy('id')->firstOrFail();

        $this->actingAs($clerk)->put(route('supplier.update', $supplier), [
            'name_en' => $supplier->name_en, 'is_active' => '0',
        ])->assertSessionHasErrors('is_active');
        $this->assertTrue((bool) $supplier->fresh()->is_active, '⛔ সম্পাদনার চাবিতে সরবরাহকারী বন্ধ হয়ে গেল');

        $this->put(route('supplier.update', $supplier), ['name_en' => 'Renamed Mills'])->assertSessionHasNoErrors();
        $this->assertSame('Renamed Mills', $supplier->fresh()->name_en, 'সাধারণ সম্পাদনা আটকে গেল');
    }
}

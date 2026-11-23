<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\AreaBinding;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ বিক্রয়ের কৃতিত্ব দায়িত্বে থাকা মানুষের, বিল যিনি করলেন তাঁর নয় (মালিক, ২৩ নভেম্বর ২০২৬)।
 *
 * ⓘ পর্দার দিক: বিলের পাতায় "দায়িত্বে" আর "বিল করেছেন" দুই আলাদা নাম; এলাকার তালিকায় আজ চালু
 * বাঁধনের মানুষ; আর "দায়িত্ব বদলান" লিংক কেবল মাঠকর্মীর পাতার চাবি যাঁর আছে তাঁর।
 */
final class SalesCreditShowsTheResponsiblePersonTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_the_bill_names_the_responsible_person_and_the_biller_apart(): void
    {
        $this->actingAs($this->owner);

        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => '100']],
        );

        $sr = $this->person('Rafiq Responsible', ['sales.invoice.view']);

        // ⓘ কাগজে বসানো দায়িত্ব — বিল করেছেন মালিক, দায়িত্বে অন্য জন
        DB::table('sal_invoices')->where('id', $invoice->id)->update(['sales_person_id' => $sr->id]);

        $this->assertNotSame($sr->id, (int) $invoice->fresh()->created_by, '⛔ পরীক্ষাটা দুই আলাদা মানুষ চায়।');

        $this->get(route('sales.invoice.show', $invoice))
            ->assertOk()
            ->assertSeeInOrder([__('sales::field.responsible'), 'Rafiq Responsible'])
            ->assertSeeInOrder([__('sales::field.billed_by'), $this->owner->name])
            ->assertDontSee(__('sales::field.responsible_as_of_now'));
    }

    public function test_the_location_list_shows_who_is_bound_there_today(): void
    {
        $area = Location::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'code' => 'RSP-A1', 'name_en' => 'Responsibility Area', 'level' => Location::AREA, 'is_active' => true,
        ]);

        $sr = $this->person('Selim Bound', ['customer.view']);
        $gone = $this->person('Gone Ended', ['customer.view']);

        AreaBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $sr->id, 'location_id' => $area->id,
            'starts_on' => now()->subDays(3)->toDateString()]);
        // ⓘ শেষ হয়ে যাওয়া বাঁধন — আজকের দায়িত্বে পড়ে না
        AreaBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $gone->id, 'location_id' => $area->id,
            'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->subDays(4)->toDateString()]);

        $this->actingAs($this->owner)
            ->get(route('master_data.location.index', ['q' => 'RSP-A1']))
            ->assertOk()
            ->assertSee(__('master_data::field.responsibility'))
            ->assertSee('Selim Bound')
            ->assertDontSee('Gone Ended');

        $this->get(route('master_data.location.show', $area))
            ->assertOk()
            ->assertSee('Selim Bound')
            ->assertSee('data-change-responsibility', false);
    }

    public function test_the_change_link_is_hidden_without_the_field_force_key(): void
    {
        $area = Location::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'code' => 'RSP-A2', 'name_en' => 'Viewer Area', 'level' => Location::AREA, 'is_active' => true,
        ]);

        $viewer = $this->person('Only Viewer', ['master_data.view']);

        $this->actingAs($viewer)
            ->get(route('master_data.location.show', $area))
            ->assertOk()
            ->assertSee(__('master_data::field.responsibility'))
            ->assertDontSee('data-change-responsibility', false)
            ->assertDontSee(__('master_data::action.change_responsibility'));
    }

    /** @param list<string> $keys */
    private function person(string $name, array $keys): User
    {
        $u = User::factory()->create(['name' => $name, 'current_company_id' => $this->company->id, 'is_active' => true]);
        $u->companies()->attach($this->company->id, ['is_active' => true]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $u->givePermissionTo($keys);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }
}

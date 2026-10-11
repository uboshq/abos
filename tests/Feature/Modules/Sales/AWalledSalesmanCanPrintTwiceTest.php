<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Services\PrintQueue;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ দেয়ালের ভিতরের বিক্রয়কর্মী একটা অর্ডার, DO, গেট পাস বা রসিদ দ্বিতীয়বার ছাপলে ৫০০ পেতেন (PR #17 রিভিউ ⛔২, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ ছাপার গোনা [[PrintQueue::queue()]] `firstOrCreate` দিয়ে আগের সারি খোঁজে। [[PrintJob::applyDealerWall()]] কেবল বিল আর
 * চালানের সারি চেনে, তাই দেয়ালের ভিতরে নতুন কাগজগুলোর আগের সারি "নেই" — আবার বসাতে গিয়ে অনন্য চাবিতে ধাক্কা।
 */
final class AWalledSalesmanCanPrintTwiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_second_print_finds_the_first_row_behind_the_wall(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        app(SettingsService::class)->set(DealerScope::SWITCH, true);

        $salesman = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $salesman->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, function () use ($salesman) {
            $salesman->assignRole('salesman');
            Role::findByName('salesman', 'web')->givePermissionTo(Permission::findOrCreate(DealerScope::OWN, 'web'));
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($salesman->fresh());
        $this->assertTrue(app(DealerScope::class)->walled(), 'ⓘ বিক্রয়কর্মী দেয়ালে নেই — দাবিটা কিছু মাপছে না।');

        $queue = app(PrintQueue::class);

        foreach ([PrintJob::ORDER, PrintJob::DELIVERY_ORDER, PrintJob::GATE_PASS, PrintJob::CHALLAN_GATEPASS, PrintJob::RECEIPT] as $type) {
            $first = $queue->queue($type, 4242, 'a4', 'X-1');
            $queue->printed($first);

            $second = $queue->queue($type, 4242, 'a4', 'X-1');

            $this->assertSame($first->id, $second->id, "⛔ {$type}: দ্বিতীয় ছাপায় আগের সারি পাওয়া যায়নি।");
            $this->assertTrue($second->isReprint(), "⛔ {$type}: দ্বিতীয় ছাপা DUPLICATE গোনে না।");
        }
    }
}

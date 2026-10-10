<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseRequisition;
use App\Modules\Purchase\Services\PurchaseRequisitionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * চাহিদা যিনি করলেন তিনিই মঞ্জুর করতেন, আর দুটো মঞ্জুর একসাথে বাজেট পার হত — পুরো-ERP অডিট, ১০ অক্টোবর ২০২৬, ক্রয় ⚠️২।
 *
 * ⭐ সুইচ `purchase.requisition_maker_checker` (ডিফল্টে বন্ধ, fe): বন্ধে আজকের মতো, চালুতে নিজের চাহিদা নিজে নয় — একই মানুষ,
 * সুইচ বন্ধ তারপর চালু। অন্য কেউ পারেন; মালিক একা করলে আটকায় না (ভাউচারের নিয়মের মতো)।
 * ⭐ মঞ্জুর এক লেনদেনে: চাহিদার সারি আর খরচ-কেন্দ্রের সারি তালায়, তারপর বাজেটের খোঁজ।
 */
final class TheRequesterApprovedTheirOwnRequisitionTest extends TestCase
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

    public function test_the_same_person_may_approve_their_own_only_while_the_switch_is_off(): void
    {
        $clerk = $this->clerk();
        $other = $this->clerk();

        // ── সুইচ বন্ধ (ডিফল্ট): আজকের মতো, নিজেরটা নিজে ──
        $this->actingAs($clerk);
        $mine = $this->requisition();
        $this->service()->approve($mine);
        $this->assertSame(DocumentStatus::CONFIRMED, $mine->fresh()->status, 'সুইচ বন্ধে আচরণ বদলে গেল।');

        // ── সুইচ চালু: একই মানুষ, নিজেরটা নয় ──
        app(SettingsService::class)->set('purchase.requisition_maker_checker', true);
        $next = $this->requisition();
        $this->assertFalse($clerk->can('approve', $next->fresh()), '⛔ মঞ্জুরের বোতাম নিজের চাহিদায় দেখায়।');

        try {
            $this->service()->approve($next);
            $this->fail('⛔ সুইচ চালু, তবু নিজের চাহিদা নিজে মঞ্জুর হলো।');
        } catch (ValidationException) {
            $this->assertSame(DocumentStatus::DRAFT, $next->fresh()->status);
        }

        // ⓘ অন্য কেউ পারেন — নিয়মটা হাত আলাদা করা, মঞ্জুর বন্ধ করা নয়
        $this->actingAs($other);
        $this->assertTrue($other->can('approve', $next->fresh()));
        $this->service()->approve($next);
        $this->assertSame(DocumentStatus::CONFIRMED, $next->fresh()->status);

        // ⓘ মালিক একা করলে আটকায় না
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $owners = $this->requisition();
        $this->service()->approve($owners);
        $this->assertSame(DocumentStatus::CONFIRMED, $owners->fresh()->status, 'মালিকের নিজের চাহিদা আটকে গেল।');
    }

    public function test_the_budget_is_counted_under_the_cost_centres_lock(): void
    {
        $centre = CostCenter::query()->create(['code' => 'CC-LOCK', 'name_en' => 'Lock centre', 'name_bn' => 'তালার কেন্দ্র', 'is_active' => true]);
        $paper = $this->requisition($centre);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->service()->approve($paper);

        $lockAt = null;
        $askAt = null;
        foreach ($queries as $i => $sql) {
            if ($lockAt === null && str_contains($sql, 'from `acc_cost_centers`') && str_contains($sql, 'for update')) {
                $lockAt = $i;
            }
            if ($askAt === null && str_contains($sql, 'fin_budgets')) {
                $askAt = $i;
            }
        }

        $this->assertNotNull($askAt, 'দৃশ্যটাই বানানো যায়নি — বাজেটের খোঁজ পাওয়া গেল না।');
        $this->assertNotNull($lockAt, '⛔ খরচ-কেন্দ্রে তালা নেই — দুটো মঞ্জুর একসাথে বাজেট পার হতে পারে।');
        $this->assertLessThan($askAt, $lockAt, '⛔ বাজেটের খোঁজ তালার আগে।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function service(): PurchaseRequisitionService
    {
        return app(PurchaseRequisitionService::class);
    }

    private function clerk(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        foreach (['purchase.requisition.view', 'purchase.requisition.create', 'purchase.requisition.approve'] as $key) {
            CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function requisition(?CostCenter $centre = null): PurchaseRequisition
    {
        return $this->service()->create(
            ['trx_date' => now()->toDateString(), 'cost_center_id' => $centre?->id],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '2', 'estimated_rate' => '10']],
        );
    }
}

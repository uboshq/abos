<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * নির্ধারিত ফাইল মালিকের হেডারের শেষ বাছা শাখায় আটকায় না (ec, রিপোর্ট লাইন-নিরীক্ষা ⚠️৮, ১৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে: মালিক হেডারে একটা শাখা বেছে রাখলে (`view_all_branches` = না) পরদিনের "গোটা কোম্পানি" ফাইলে কেবল একটা শাখার সংখ্যা।
 *
 * দাবি: দুই শাখায় দুই দোকানের বিক্রি — মালিকের ফাইলে দুইটাই; আর মালিকের হেডারের বাছাই রানের পরও অক্ষত।
 */
final class TheScheduleFollowedTheOwnersHeaderBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shared_file_covers_every_branch_whatever_the_header_says(): void
    {
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $sell = function (Branch $branch, string $code) use ($company, $owner, $a) {
            CompanyContext::set($company->id, $a->id);
            $this->actingAs($owner);
            $shop = Customer::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => $code,
                'name_en' => 'Shop '.$code, 'status' => DocumentStatus::CONFIRMED, 'is_active' => true, 'credit_limit' => '1000000']);
            $done = app(DirectSaleService::class)->complete(
                ['customer_id' => $shop->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
                [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '3', 'rate' => '10', 'free_qty' => '0']],
            );
            $done['invoice']->forceFill(['branch_id' => $branch->id])->saveQuietly();
        };
        $sell($a, 'SHOP-IN-A');
        $sell($b, 'SHOP-IN-B');

        CompanyContext::set($company->id, null);
        $this->actingAs($owner->fresh());
        $schedule = app(ScheduleService::class)->create([
            'report_key' => 'sales.by_customer', 'frequency' => 'daily', 'format' => 'csv',
            'filters' => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()],
        ]);
        Auth::logout();
        CompanyContext::clear();

        // ⓘ মালিক হেডারে কেবল শাখা A বেছে রেখে গেছেন
        $owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $a->id])->save();
        app(DataScope::class)->forget();

        $run = app(ScheduledReportRunner::class)->runOne($schedule->fresh());
        $text = (string) Storage::disk('local')->get((string) $run->file_path);

        $this->assertStringContainsString('SHOP-IN-A', $text, 'শাখা A-র দোকানই নেই — দাবি অন্ধ।');
        $this->assertStringContainsString('SHOP-IN-B', $text, '⛔ মালিকের হেডারের বাছাইয়ে গোটা কোম্পানির ফাইল এক শাখায় আটকাল।');
        $this->assertFalse((bool) $owner->fresh()->view_all_branches, '⛔ রান মালিকের হেডারের বাছাই বদলে দিল।');
    }
}

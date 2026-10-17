<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\ReportRun;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * গুদামে সীমিত প্রাপক নিজের গুদামের ফাইলই পান (ec, রিপোর্ট লাইন-নিরীক্ষা ⛔২, ১৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে: নিজের ফাইল পেতেন কেবল ডিলারের দেয়ালের, শাখায় সীমিত আর কাগজের রিপোর্টের প্রাপক। গুদামে সীমিত ভাণ্ডারী থাকতেন
 * ভাগের ফাইলে — মালিকের চোখে বানানো, যেখানে গুদামের দেয়াল কিছুই ছাঁকে না। তিনি রোজ সব গুদামের সারি পেতেন।
 *
 * দাবি: যে রানে তিনি নামাতে পারেন, তার প্রতিটার ফাইলে কেবল তাঁর গুদাম; আর তাঁর নিজের একটা রান আছে।
 */
final class AWarehouseLimitedRecipientGotEveryWarehouseTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_warehouse_limited_recipient_gets_only_their_warehouse(): void
    {
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($owner);

        $mine = Warehouse::query()->create(['company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => 'WH-MINE', 'name_en' => 'Mine', 'name_bn' => 'আমার', 'is_active' => true]);
        Warehouse::query()->create(['company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => 'WH-OTHER', 'name_en' => 'Other', 'name_bn' => 'অন্য', 'is_active' => true]);

        // ⓘ গুদামের দেয়াল বসানো একটা রিপোর্ট — মজুদের রিপোর্টগুলো যেমন বসায়
        app(ReportEngine::class)->register(new ReportDefinition(
            key: 'ec_warehouse_probe',
            title: 'Warehouse probe',
            query: fn (array $f) => DB::table('inv_warehouses')->where('company_id', $f['company_id'])
                ->select('code')->orderBy('code')->tap(ReportEngine::warehouseWall($f, 'id')),
            columns: [['key' => 'code', 'label' => 'Code', 'type' => ReportColumn::TEXT]],
            filters: [],
            permission: 'inventory.report',
        ));

        $keeper = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true, 'locale' => 'en']);
        $keeper->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'user_id' => $keeper->id, 'scope_type' => UserDataScope::WAREHOUSE, 'scope_id' => $mine->id,
        ]);
        CompanyContext::forCompany($company->id, fn () => $keeper->givePermissionTo(Permission::findOrCreate('inventory.report', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        $schedule = app(ScheduleService::class)->create([
            'report_key' => 'ec_warehouse_probe', 'frequency' => 'daily', 'format' => 'csv', 'recipients' => [$keeper->id],
        ]);
        Auth::logout();
        app(DataScope::class)->forget();

        app(ScheduledReportRunner::class)->runOne($schedule->fresh());

        $runs = ReportRun::query()->withoutGlobalScopes()->where('report_schedule_id', $schedule->id)->get();
        $his = $runs->filter(fn (ReportRun $run) => in_array((int) $keeper->id, array_map('intval', (array) $run->recipients), true));

        $this->assertNotEmpty($his, 'ভাণ্ডারী কোনো ফাইলই পাননি — দাবি অন্ধ।');
        $this->assertNotNull($runs->first(fn (ReportRun $run) => array_map('intval', (array) $run->recipients) === [(int) $keeper->id]),
            '⛔ গুদামে সীমিত প্রাপকের নিজের ফাইল হয়নি।');

        foreach ($his as $run) {
            $text = (string) Storage::disk('local')->get((string) $run->file_path);
            $this->assertStringContainsString('WH-MINE', $text, 'নিজের গুদামই ফাইলে নেই — দাবি অন্ধ।');
            $this->assertStringNotContainsString('WH-OTHER', $text, '⛔ এক গুদামের ভাণ্ডারীর ফাইলে অন্য গুদাম।');
        }

        $shared = $runs->first(fn (ReportRun $run) => in_array((int) $owner->id, array_map('intval', (array) $run->recipients), true));
        $this->assertStringContainsString('WH-OTHER', (string) Storage::disk('local')->get((string) $shared->file_path),
            'মালিকের ফাইলে সব গুদাম নেই — দাবি অন্ধ।');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ReportRun;
use App\Models\User;
use App\Models\UserDataScope;
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
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * নির্ধারিত রিপোর্ট — প্রাপক নিজের শাখায়, আর সূচি বদলান কেবল যিনি বানিয়েছেন বা super_admin (অডিট ⛔৬, ৬ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে: ফাইল চলত বানানো মানুষের (মালিকের) নাগালে, প্রাপক বাছা হত কেবল চাবি দেখে — শাখা A-র মানুষ রোজ সব শাখার সংখ্যা
 * পেতেন। আর সূচির চাবি থাকলেই যে কেউ মালিকের সূচি বদলে নিজেকে প্রাপক করতে পারতেন।
 *
 * দাবি:
 *  - শাখা A-তে সীমিত প্রাপক নিজের আলাদা ফাইল পান — তাতে A-র দোকান আছে, B-র নেই; আর ভাগের (মালিকের চোখের) ফাইলে তিনি নেই।
 *  - একই মানুষ মালিকের সূচি বদলাতে বা বন্ধ করতে পারেন না; মালিক পারেন (দাবিটা সত্যিই তাকায়)।
 */
final class AScheduledReportKeepsEachRecipientInTheirBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_limited_recipient_gets_only_their_branch_and_cannot_rewrite_the_owners_schedule(): void
    {
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        // ── দুই শাখায় দুই দোকানে বিক্রি ──
        // ⓘ দুই বিক্রিই শাখা A-র গুদাম থেকে (ডেমোর নেত্রকোনা গুদাম খালি), তারপর দ্বিতীয় বিলটা শাখা B-র — রিপোর্ট বিলের শাখা পড়ে
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

        // ── প্রাপক: শাখা A-তে সীমিত, বিক্রয়ের রিপোর্ট আর সূচির চাবিসহ ──
        $clerk = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true, 'locale' => 'en']);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $a->id,
        ]);
        CompanyContext::forCompany($company->id, function () use ($clerk) {
            foreach (['sales.report', 'system_admin.reports.schedule'] as $key) {
                $clerk->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        CompanyContext::set($company->id, null);
        $this->actingAs($owner->fresh());
        $schedule = app(ScheduleService::class)->create([
            'report_key' => 'sales.by_customer', 'frequency' => 'daily', 'format' => 'csv',
            'filters' => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()],
            'recipients' => [$clerk->id],
        ]);
        Auth::logout();
        app(DataScope::class)->forget();

        app(ScheduledReportRunner::class)->runOne($schedule->fresh());

        $runs = ReportRun::query()->withoutGlobalScopes()->where('report_schedule_id', $schedule->id)->get();
        $own = $runs->first(fn (ReportRun $run) => array_map('intval', (array) $run->recipients) === [(int) $clerk->id]);
        $this->assertNotNull($own, '⛔ শাখা-সীমিত প্রাপকের নিজের ফাইল হয়নি।');
        $text = (string) Storage::disk('local')->get((string) $own->file_path);
        $this->assertStringContainsString('SHOP-IN-A', $text, 'নিজের শাখার দোকানই ফাইলে নেই — দাবি অন্ধ।');
        $this->assertStringNotContainsString('SHOP-IN-B', $text, '⛔ শাখা A-র প্রাপকের ফাইলে শাখা B-র দোকান।');
        foreach ($runs as $run) {
            if ($run->id !== $own->id) {
                $this->assertNotContains((int) $clerk->id, array_map('intval', (array) $run->recipients),
                    '⛔ শাখা-সীমিত প্রাপক ভাগের (মালিকের চোখের) ফাইলও নামাতে পারেন।');
            }
        }

        // ── সূচি বদলানো ──
        CompanyContext::set($company->id, null);
        $this->actingAs($clerk->fresh());
        foreach (['update' => fn () => app(ScheduleService::class)->update($schedule->fresh(), [
            'report_key' => 'sales.by_customer', 'frequency' => 'daily', 'format' => 'csv', 'filters' => [], 'recipients' => [$clerk->id],
        ]), 'deactivate' => fn () => app(ScheduleService::class)->deactivate($schedule->fresh())] as $what => $try) {
            try {
                $try();
                $this->fail("⛔ অন্যের সূচি {$what} করা গেল।");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('schedule', $e->errors());
            }
        }
        $this->assertTrue((bool) $schedule->fresh()->is_active);

        $this->actingAs($owner->fresh());
        app(ScheduleService::class)->deactivate($schedule->fresh());
        $this->assertFalse((bool) $schedule->fresh()->is_active, 'মালিক নিজের সূচি বন্ধ করতে পারেন না — দাবি অন্ধ।');
    }
}

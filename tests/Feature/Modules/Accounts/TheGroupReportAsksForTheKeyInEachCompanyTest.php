<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * গ্রুপের খাতা — প্রতিটা কোম্পানির সংখ্যা কেবল সেই কোম্পানিতে চাবি থাকলে (অডিট ⛔১০, ৬ অক্টোবর ২০২৬)।
 *
 * ⛔ দরজা কেবল চলতি কোম্পানির `accounts.report.group` দেখত, আর তালিকায় আসত সদস্য সব কোম্পানির আয়-ব্যয়-লাভ।
 * দাবি, একই মানুষ দুইবার: A-তে চাবি, B-তে কেবল সদস্য → B-র নাম পাতায় নেই; B-তেও চাবি দিলে → B আসে (দাবিটা সত্যিই তাকায়)।
 */
final class TheGroupReportAsksForTheKeyInEachCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_company_without_the_key_stays_out_of_the_group_report(): void
    {
        $this->seed(DemoSeeder::class);
        $depot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mart = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($depot->id, $depot->defaultBranch()?->id);

        $user = User::factory()->create(['current_company_id' => $depot->id, 'current_branch_id' => $depot->defaultBranch()?->id, 'is_active' => true]);
        $user->companies()->attach($depot->id, ['is_active' => true]);
        $user->companies()->attach($mart->id, ['is_active' => true]);
        $this->grantIn($user, $depot);

        $page = (string) $this->actingAs($user->fresh())->get(route('accounts.group_report'))->assertOk()->getContent();
        $this->assertStringContainsString((string) $depot->name_en, $page, 'নিজের কোম্পানিই পাতায় নেই — দাবি অন্ধ।');
        $this->assertStringNotContainsString((string) $mart->name_en, $page, '⛔ চাবি ছাড়া কোম্পানির সংখ্যা গ্রুপের খাতায় এল।');

        // ⭐ B-তেও চাবি — এবার আসে
        $this->grantIn($user, $mart);
        CompanyContext::set($depot->id, $depot->defaultBranch()?->id);
        $page = (string) $this->actingAs($user->fresh())->get(route('accounts.group_report'))->assertOk()->getContent();
        $this->assertStringContainsString((string) $mart->name_en, $page, 'B-তে চাবি দেওয়ার পরেও B আসেনি — দাবি অন্ধ।');
    }

    private function grantIn(User $user, Company $company): void
    {
        foreach (['accounts.report.group', 'accounts.view'] as $key) {
            CompanyContext::forCompany($company->id, function () use ($user, $key) {
                $user->unsetRelation('permissions');
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            });
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

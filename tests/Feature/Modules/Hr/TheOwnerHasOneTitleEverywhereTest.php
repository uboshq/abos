<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\MasterData\Models\Designation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * মালিকের পদবি সব কোম্পানিতে একটাই, আর বদলানো যায় — মালিকের সিদ্ধান্ত, ২ অক্টোবর ২০২৬।
 *
 * ⭐ দুই কোম্পানিতে মালিকের কর্মী-সারি দুই আলাদা পদবিতে বাঁধা থাকলেও প্রোফাইলে একই লেখা — গোটা ব্যবস্থার
 * সেটিং `group.owner_title_bn|en` ([[Ownership::groupOwnerTitle()]])। ⭐ সেটিং একবার বদলালে দুই কোম্পানিতেই নতুনটা।
 * ⛔ fail-closed: `ABOS_OWNER_EMAILS` খালি হলে তিনি মালিক নন — তখন যার যার সারির পদবিই।
 */
final class TheOwnerHasOneTitleEverywhereTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_title_in_every_company_and_one_change_reaches_them_all(): void
    {
        app()->setLocale('bn');
        Permission::findOrCreate('hr.employee.view', 'web');

        $owner = User::factory()->create(['email' => 'Owner@Group.test']);

        $pages = [];

        foreach ([['ADI', 'Managing Director'], ['AVA', 'Proprietor']] as [$code, $designation]) {
            $company = Company::create(['code' => $code, 'name_en' => $code.' Co']);
            $branch = Branch::query()->create(['company_id' => $company->id, 'code' => $code.'B', 'name_en' => 'Main', 'is_active' => true]);
            $owner->companies()->attach($company->id);
            CompanyContext::set($company->id, $branch->id);
            CompanyContext::forCompany($company->id, fn () => $owner->givePermissionTo('hr.employee.view'));

            $row = Designation::query()->create(['company_id' => $company->id, 'code' => $code.'-D', 'name_en' => $designation, 'name_bn' => $designation, 'is_active' => true]);
            $employee = Employee::query()->create([
                'company_id' => $company->id, 'branch_id' => $branch->id, 'code' => $code.'-1', 'name_en' => 'The Owner',
                'joining_date' => '2024-01-01', 'designation_id' => $row->id, 'user_id' => $owner->id,
            ]);

            $pages[$code] = [$company, $branch, route('hr.employee.show', $employee), $designation];
        }

        $look = function (string $code) use ($owner, $pages): string {
            [$company, $branch, $url] = $pages[$code];
            CompanyContext::set($company->id, $branch->id);
            $owner->forceFill(['current_company_id' => $company->id])->save();

            return (string) $this->actingAs($owner->fresh())->get($url)->assertOk()->getContent();
        };

        // ⛔ তালিকা খালি — মালিক নন, সারির পদবিই
        config(['abos.owner_emails' => []]);
        foreach ($pages as $code => [, , , $designation]) {
            $this->assertStringContainsString(e($designation), $look($code), "{$code}: তালিকা খালি, তবু সারির পদবি দেখায়নি — fail-closed ভাঙা।");
        }

        // ⭐ তালিকায় আছেন — ডিফল্ট পদবি দুই কোম্পানিতেই, সারির পদবি কোথাও নয়
        config(['abos.owner_emails' => ['owner@group.test']]);
        foreach ($pages as $code => [, , , $designation]) {
            $html = $look($code);
            $this->assertStringContainsString('গ্রুপ চেয়ারম্যান ও সিইও', $html, "{$code}: ডিফল্ট মালিক-পদবি নেই।");
            $this->assertStringNotContainsString(e($designation), $html, "⛔ {$code}: সারির পদবি (\"{$designation}\") এখনো দেখাচ্ছে।");
        }

        // ⭐ একবার বদলানো — দুই কোম্পানিতেই নতুনটা
        app(SettingsService::class)->set('group.owner_title_bn', 'চেয়ারম্যান');
        app(SettingsService::class)->flush();
        foreach (array_keys($pages) as $code) {
            $html = $look($code);
            $this->assertStringContainsString('চেয়ারম্যান', $html, "{$code}: বদলানো পদবি পৌঁছায়নি।");
            $this->assertStringNotContainsString('গ্রুপ চেয়ারম্যান ও সিইও', $html, "⛔ {$code}: পুরনো পদবি রয়ে গেছে — বদলটা এক কোম্পানিতেই আটকে।");
        }
    }

    public function test_only_a_super_admin_may_change_the_title(): void
    {
        $settings = app(SettingsService::class);
        $clerk = User::factory()->create();

        $this->assertFalse($settings->mayChange('group.owner_title_bn', $clerk), '⛔ সাধারণ ব্যবহারকারী মালিকের পদবি বদলাতে পারেন।');
        $this->assertTrue($settings->isProductWide('group.owner_title_bn'), '⛔ পদবি কোম্পানি-ভিত্তিক — এক কোম্পানিতে বদলালে অন্যটায় বদলাবে না।');
        $this->assertTrue($settings->isProductWide('group.owner_title_en'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\AuthorityService;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalLimit;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⛔ শাখার সইয়ের সীমা হেডারে যে শাখাই বাছা থাক, খাটে — Approval লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[AuthorityService::allows()]])।
 *
 * ⓘ ব্যবস্থাপকের সীমা: সব শাখায় ১০ লাখ, নেত্রকোনায় ১ লাখ। হেডারে ময়মনসিংহ বাছা থাকলে নেত্রকোনার ৫ লাখের কাগজটা তাঁর দেখার
 * দেয়ালে "নেই" হত, শাখা null, আর ১০ লাখের সাধারণ সীমায় সই পেরোত — "সব শাখা" বাছলে একই সই থামত।
 */
final class ABranchLimitHoldsWhateverTheHeaderShowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_papers_branch_limit_holds_with_another_branch_in_the_header(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $home = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $far = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($company->id, $far->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        // ⓘ নেত্রকোনার ৫ লাখের খসড়া
        $voucher = app(VoucherService::class)->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'শাখার বড় খরচ',
            'branch_id' => $far->id], [
                ['account_id' => (int) Account::query()->where('code', '5202')->value('id'), 'debit' => '500000'],
                ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '500000'],
            ]);
        $this->assertSame($far->id, (int) $voucher->branch_id, 'দৃশ্যটাই বানানো যায়নি — ভাউচার নেত্রকোনার নয়');

        $role = Role::query()->create(['name' => 'Branch Signer', 'guard_name' => 'web', 'company_id' => $company->id]);
        ApprovalLimit::query()->create(['company_id' => $company->id, 'role_id' => $role->id, 'max_amount' => '1000000']);
        ApprovalLimit::query()->create(['company_id' => $company->id, 'role_id' => $role->id, 'branch_id' => $far->id, 'max_amount' => '100000']);

        $manager = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => $home->id, 'is_active' => true]);
        $manager->forceFill(['view_all_branches' => false])->save();
        $manager->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $manager->assignRole($role));
        $manager = $manager->fresh();

        $approval = (new Approval)->forceFill(['company_id' => $company->id, 'approvable_type' => $voucher->getMorphClass(), 'approvable_id' => $voucher->id,
            'module' => 'accounts', 'action' => 'voucher', 'amount' => '500000']);

        // ⓘ হেডারে ময়মনসিংহ — কাগজটা তাঁর দেখার দেয়ালের বাইরে
        $this->actingAs($manager);
        CompanyContext::set($company->id, $home->id);
        app(DataScope::class)->forget();
        $this->assertNull(Voucher::query()->find($voucher->id), 'দৃশ্যটাই বানানো যায়নি — হেডারের শাখা কাগজটা লুকায় না');

        $this->assertFalse(app(AuthorityService::class)->allows($manager, $approval),
            '⛔ হেডারে অন্য শাখা বাছলেই নেত্রকোনার ১ লাখের সীমা পেরিয়ে ৫ লাখে সই');

        // ⓘ নিজের সীমার ভিতরে আগের মতোই
        $small = (clone $approval)->forceFill(['amount' => '90000']);
        $this->assertTrue(app(AuthorityService::class)->allows($manager, $small));
    }
}

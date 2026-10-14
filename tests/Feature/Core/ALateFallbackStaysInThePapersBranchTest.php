<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalSla;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⛔ দেরির কাগজ কেবল তার শাখার ব্যবস্থাপকের হাতে — যে ব্যবস্থাপকই প্রশ্ন করুন — Approval লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬
 * ([[ApprovalSla::fallbackRecipients()]])।
 *
 * ⓘ কাগজটা প্রশ্নকারীর হেডারের শাখার দেয়ালে পড়া হত। ময়মনসিংহের ব্যবস্থাপক নিজে প্রশ্ন করলে নেত্রকোনার কাগজ "নেই", শাখা null —
 * আর তখন সব ব্যবস্থাপক, তিনিও, নেত্রকোনার দেরির কাগজে সই দিতে পারতেন। ঘণ্টার কমান্ড (কেউ লগইন নয়) ঠিক মানুষদেরই খবর দিত।
 */
final class ALateFallbackStaysInThePapersBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_fallback_managers_are_those_of_the_papers_branch_whoever_asks(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($company->id, $ntk->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $voucher = app(VoucherService::class)->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'নেত্রকোনার খরচ',
            'branch_id' => $ntk->id], [
                ['account_id' => (int) Account::query()->where('code', '5202')->value('id'), 'debit' => '1000'],
                ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '1000'],
            ]);

        $role = Role::query()->firstOrCreate(['name' => ApprovalSla::FALLBACK_ROLE, 'guard_name' => 'web', 'company_id' => $company->id]);
        $manager = function (Branch $branch) use ($company, $role): User {
            $user = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => $branch->id, 'is_active' => true]);
            $user->companies()->attach($company->id, ['is_active' => true]);
            CompanyContext::forCompany($company->id, fn () => $user->assignRole($role));
            UserDataScope::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'user_id' => $user->id,
                'scope_type' => UserDataScope::BRANCH, 'scope_id' => $branch->id]);

            return $user->fresh();
        };
        $here = $manager($mms);
        $there = $manager($ntk);
        app(DataScope::class)->forget();

        $approval = (new Approval)->forceFill(['company_id' => $company->id, 'approvable_type' => $voucher->getMorphClass(),
            'approvable_id' => $voucher->id, 'module' => 'accounts', 'action' => 'voucher', 'amount' => '1000']);

        // ⓘ ময়মনসিংহের ব্যবস্থাপক নিজে প্রশ্ন করছেন — নেত্রকোনার কাগজ তাঁর দেয়ালের বাইরে
        $this->actingAs($here);
        CompanyContext::set($company->id, $mms->id);
        app(DataScope::class)->forget();
        $this->assertNull(Voucher::query()->find($voucher->id), 'দৃশ্যটাই বানানো যায়নি — কাগজটা তাঁর দেয়ালের ভিতরে');

        $chosen = app(ApprovalSla::class)->fallbackRecipients($approval);
        $this->assertNotContains($here->id, $chosen, '⛔ ময়মনসিংহের ব্যবস্থাপক নেত্রকোনার দেরির কাগজে সই পেলেন');
        $this->assertContains($there->id, $chosen, 'নেত্রকোনার ব্যবস্থাপকই বাদ পড়লেন');
    }
}

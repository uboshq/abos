<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\BalanceSheetService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * স্থিতিপত্র "সব শাখা"-তেও নাগালের ভেতরে — অডিট ⛔১১ (৬ অক্টোবর ২০২৬)।
 *
 * ⛔ "সব শাখা" মানে আগে গোটা কোম্পানি, তাই একটা শাখায় সীমিত মানুষও অন্য শাখার নগদ, মূলধন আর চলতি লাভ দেখতেন।
 * দাবি: শাখা B-তে নগদ ৯,৯৯৯ বসে → A-তে সীমিত মানুষের "সব শাখা"-র স্থিতিপত্র নড়ে না; সীমাহীন মালিকের নড়ে ঠিক ৯,৯৯৯
 * (দাবিটা সত্যিই তাকায়)।
 */
final class TheBalanceSheetStaysInsideTheBranchesYouMaySeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_branches_means_the_branches_in_your_reach(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $clerk = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => null, 'is_active' => true]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $a->id,
        ]);

        $assets = fn (User $who) => $this->assetsAs($company, $who);
        $clerkBefore = $assets($clerk);
        $ownerBefore = $assets($owner);

        // ── শাখা B-তে নগদ ৯,৯৯৯ (মূলধন থেকে) ──
        CompanyContext::set($company->id, $b->id);
        $this->actingAs($owner);
        app(PostingEngine::class)->post(sourceType: 'test:bs-branch', sourceId: 1, trxDate: now(), branchId: $b->id, lines: [
            ['account_id' => app(CashTillService::class)->ensurePrimaryTill()->account_id, 'debit' => '9999'],
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => '9999'],
        ]);

        $this->assertSame(0, bccomp($assets($clerk), $clerkBefore, 4), '⛔ A-তে সীমিত মানুষের "সব শাখা"-র স্থিতিপত্রে শাখা B-র টাকা এল।');
        $this->assertSame(0, bccomp(bcsub($assets($owner), $ownerBefore, 4), '9999', 4), 'মালিকের স্থিতিপত্রে B-র টাকা আসেনি — দাবি অন্ধ।');
    }

    /** "সব শাখা" বাছা অবস্থায় মোট সম্পদ */
    private function assetsAs(Company $company, User $who): string
    {
        $who->forceFill(['current_branch_id' => null, 'current_company_id' => $company->id])->save();
        CompanyContext::set($company->id, null);
        $this->actingAs($who->fresh());
        app(DataScope::class)->forget();

        $sheet = app(BalanceSheetService::class)->build();

        return (string) $sheet['totals']['assets'];
    }
}

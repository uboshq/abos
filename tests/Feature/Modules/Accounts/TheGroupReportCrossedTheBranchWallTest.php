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
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\GroupLedgerService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দলগত রিপোর্ট শাখায় সীমিত মানুষকে গোটা কোম্পানির যোগফল দেখাত — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (হিসাব, ঠিক ৪)।
 *
 * দাবি: শাখা B-তে আয় ৭,৭৭৭ বসে → A-তে সীমিত মানুষের দলগত আয় নড়ে না; সীমাহীন মালিকের নড়ে ঠিক ৭,৭৭৭ (দাবিটা সত্যিই তাকায়)।
 * ⓘ সীমিত মানুষের নিজের শাখা A-র আয় তবু আসে — দেয়াল, অন্ধত্ব নয়।
 */
final class TheGroupReportCrossedTheBranchWallTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_limited_person_sees_only_their_branches_in_each_company(): void
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

        $income = function (User $who) use ($company): string {
            CompanyContext::set($company->id, null);
            app(DataScope::class)->forget();
            $row = collect(app(GroupLedgerService::class)->build($who->fresh())['companies'])->firstWhere('id', $company->id);

            return (string) $row['income'];
        };

        $clerkBefore = $income($clerk);
        $ownerBefore = $income($owner);

        $this->actingAs($owner);
        CompanyContext::set($company->id, null);
        $cash = app(CashTillService::class)->ensurePrimaryTill()->account_id;

        foreach ([[$b, '7777', 1], [$a, '1111', 2]] as [$branch, $amount, $id]) {
            app(PostingEngine::class)->post(sourceType: 'test:group-branch', sourceId: $id, trxDate: now(), branchId: $branch->id, lines: [
                ['account_id' => $cash, 'debit' => $amount],
                ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => $amount],
            ]);
        }

        $this->assertSame(0, bccomp(bcsub($income($clerk), $clerkBefore, 4), '1111', 4), '⛔ A-তে সীমিত মানুষের দলগত আয়ে শাখা B-র টাকা এল (বা A-র টাকা এল না)।');
        $this->assertSame(0, bccomp(bcsub($income($owner), $ownerBefore, 4), '8888', 4), 'মালিকের দলগত আয়ে দুই শাখার টাকা নেই — দাবি অন্ধ।');
    }
}

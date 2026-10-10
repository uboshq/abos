<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * বছরশেষের সমাপনী কাগজ শাখার দেয়াল মানত না — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (হিসাব, ঠিক ৩)।
 *
 * ⛔ আগে: শাখা A-তে সীমিত মানুষ সমাপনীর পাতা আর ছাপায় শাখা B-র আয়ের সারিও দেখতেন।
 * ⭐ এখন: দেখার শাখার নিয়মে ([[YearEndService::closingPaper()]]) — A-র মানুষ কেবল A; মালিক সব (দাবিটা সত্যিই তাকায়)।
 */
final class TheClosingPaperCrossedTheBranchWallTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_limited_reader_sees_only_their_branch_on_the_closing_paper(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $a = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $b = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $year = FinancialYear::query()->where('is_current', true)->firstOrFail();

        CompanyContext::set($company->id, null);
        $this->actingAs($owner);
        $cash = app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $date = $year->starts_on->copy()->addMonths(3)->toDateString();

        foreach ([[$a, '1111', 1], [$b, '7777', 2]] as [$branch, $amount, $id]) {
            app(PostingEngine::class)->post(sourceType: 'test_sale', sourceId: $id, trxDate: $date, branchId: $branch->id, lines: [
                ['account_id' => $cash, 'debit' => $amount],
                ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => $amount],
            ]);
        }

        app(YearEndService::class)->close($year);

        $clerk = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => null, 'is_active' => true]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $clerk->givePermissionTo(Permission::findOrCreate('accounts.report.final', 'web'));
        UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $a->id,
        ]);

        $branchesOn = function (User $who) use ($year): array {
            $this->actingAs($who->fresh());
            app(DataScope::class)->forget();

            return collect(app(YearEndService::class)->closingPaper($year))
                ->flatMap(fn (array $paper) => array_column($paper['lines'], 'branch'))
                ->unique()->filter()->sort()->values()->all();
        };

        $this->assertNotContains($b->name(), $branchesOn($clerk), '⛔ A-তে সীমিত মানুষ সমাপনীর কাগজে শাখা B-র সারি দেখলেন।');
        $this->assertContains($a->name(), $branchesOn($clerk));
        $this->assertContains($b->name(), $branchesOn($owner), 'মালিকের কাগজে B নেই — দাবি অন্ধ।');

        // ⓘ পাতা আর ছাপা একই তথ্যে — পাতাতেও B-র অঙ্ক নেই
        $this->actingAs($clerk->fresh());
        app(DataScope::class)->forget();
        $this->get(route('accounts.year_end.closing', $year))->assertOk()->assertDontSee('7,777');
    }
}

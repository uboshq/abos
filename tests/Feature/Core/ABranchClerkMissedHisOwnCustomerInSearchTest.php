<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Search\SearchEngine;
use App\Core\Engines\Search\SearchHit;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⚠️ শাখায় সীমিত কর্মী নিজের গ্রাহককেও খোঁজায় পেতেন না — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬ ([[SearchEngine::matchesIn()]])।
 *
 * ⓘ প্রতি উৎসে LIMIT ৫ বসত পলিসির আগে, ক্রম ছাড়া: ডাটাবেজের প্রথম ৫টা "Alpha Store" অন্য শাখার হলে সবই বাদ, ফল শূন্য।
 * নিজের গ্রাহকটা মাঝখানে বসানো — আগে ৬টা, পরে ৬টা অন্য শাখার — যাতে কেবল ক্রম উল্টালে নয়, বেশি এনে ছাঁকলে তবেই পাওয়া যায়।
 */
final class ABranchClerkMissedHisOwnCustomerInSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_limited_clerk_finds_his_own_customer_among_many_from_other_branches(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $mine = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $theirs = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        $make = fn (string $code, Branch $branch) => Customer::query()->create(['company_id' => $company->id, 'code' => $code,
            'name_en' => 'Alpha Store '.$code, 'branch_id' => $branch->id, 'is_active' => true]);

        foreach (range(1, 6) as $i) {
            $make('ALF-B'.$i, $theirs);
        }
        $make('ALF-MINE', $mine);
        foreach (range(1, 6) as $i) {
            $make('ALF-A'.$i, $theirs);
        }

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $clerk->givePermissionTo(['customer.view']);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();

        $found = array_map(
            fn (SearchHit $hit) => $hit->documentNo,
            array_values(array_filter(app(SearchEngine::class)->search('Alpha Store', $clerk), fn (SearchHit $hit) => $hit->slug === 'customer')),
        );

        $this->assertContains('ALF-MINE', $found, '⚠️ নিজের শাখার "Alpha Store" খোঁজায় এল না — অন্য শাখার ৫টা সারি জায়গা খেয়ে বাদ পড়ল।');
        $this->assertSame(['ALF-MINE'], $found, '⛔ অন্য শাখার গ্রাহক খোঁজায় এল।');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Imports\CustomerLimitImporter;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ সীমার ফাইল শাখার দেয়াল মানত না (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[CustomerLimitImporter]])।
 *
 * ⚠️ ইমপোর্ট কেবল `customer.update` চাবি দেখত, তারপর কোড ধরে গোটা কোম্পানিতে খুঁজত। ময়মনসিংহে সীমিত কর্মী নেত্রকোনার
 * গ্রাহকের কোড আর ০ লিখে তাঁর বাকি বন্ধ করে দিতেন (কমাতে সই লাগে না) — পর্দায় যে গ্রাহক তিনি খুলতেই পারেন না।
 */
final class ALimitFileReachedAnotherBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_clerk_cannot_set_another_branchs_limit_by_file(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $mine = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $theirs = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $clerk->givePermissionTo(['customer.view', 'customer.update']);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();

        $make = fn (string $code, Branch $branch) => Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'code' => $code, 'name_en' => $code,
            'credit_limit' => '50000', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $theirShop = $make('LIM-THEIRS', $theirs);
        $ourShop = $make('LIM-OURS', $mine);

        $this->actingAs($clerk);
        $importer = app(CustomerLimitImporter::class);

        $this->assertNotSame([], $importer->check(['code' => 'LIM-THEIRS', 'credit_limit' => '0']), '⛔ অন্য শাখার গ্রাহক যাচাইয়ে সবুজ');
        $importer->import(['code' => 'LIM-THEIRS', 'credit_limit' => '0']);
        $this->assertSame(0, bccomp('50000', (string) $theirShop->fresh()->credit_limit, 4), '⛔ ফাইলে অন্য শাখার গ্রাহকের সীমা বদলে গেল');

        // ⓘ নিজের শাখার গ্রাহক — আগের মতোই
        $importer->import(['code' => 'LIM-OURS', 'credit_limit' => '0']);
        $this->assertSame(0, bccomp('0', (string) $ourShop->fresh()->credit_limit, 4), 'নিজের শাখার গ্রাহকের সীমা কমল না');
    }
}

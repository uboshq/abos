<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Sync\SyncService;
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
 * ⛔ অন্য শাখায় সরানো দোকান এক-শাখার ফোনে থেকে যেত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[SyncService::movedKey()]])।
 *
 * ⚠️ জলচিহ্নের টানা কেবল নিজের শাখার বদল পাঠায় — চলে যাওয়া দোকানের কোনো খবর যেত না, আর ময়মনসিংহের ফোনে নেত্রকোনায় সরানো
 * দোকান আর তার বকেয়া রয়ে যেত। ⭐ এখন শাখা-বদল দেখার চাবি বদলায় — ফোন ক্যাশ মুছে গোড়া থেকে টানে।
 */
final class AShopMovedBranchAndStayedOnThePhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_a_customer_to_another_branch_changes_the_branch_phones_view(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $mine = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $theirs = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mine->id]);
        app(DataScope::class)->forget();

        $this->actingAs($owner);
        $shop = Customer::acrossDealers()->orderBy('id')->firstOrFail();
        $shop->update(['branch_id' => $mine->id]);

        $before = app(SyncService::class)->viewKey($clerk);

        $shop->fresh()->update(['branch_id' => $theirs->id]);

        $this->assertNotSame($before, app(SyncService::class)->viewKey($clerk),
            '⛔ দোকান অন্য শাখায় গেল, অথচ এক-শাখার ফোনের দেখার চাবি একই — দোকান ফোনে থেকে যাবে');
    }
}

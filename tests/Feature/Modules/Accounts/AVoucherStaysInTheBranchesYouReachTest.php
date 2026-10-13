<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⛔ ভাউচারের শাখা নিজের নাগালের — Accounts লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ ফর্মের নিয়ম কেবল দেখত শাখাটা কোম্পানির কি না। শাখা-সীমিত একজন অন্য শাখার নামে খরচ বসাতে পারতেন — টাকা ঐ শাখার খাতায়,
 * অথচ তিনি নিজে আর ভাউচারটা দেখতে পেতেন না।
 */
final class AVoucherStaysInTheBranchesYouReachTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_bound_clerk_cannot_write_into_another_branch(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $home = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        $clerk = User::factory()->create(['current_company_id' => $company->id, 'current_branch_id' => $home->id, 'is_active' => true]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $clerk->givePermissionTo(array_map(
            fn (string $k) => Permission::findOrCreate($k, 'web'),
            ['accounts.view', 'accounts.voucher.view', 'accounts.voucher.create', 'accounts.voucher.update'],
        )));
        UserDataScope::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $home->id]);
        app(DataScope::class)->forget();

        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();
        $form = fn (int $branch) => ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'শাখার খরচ',
            'save_as_draft' => '1', 'branch_id' => (string) $branch,
            'lines' => [['account_id' => $a->id, 'debit' => '300', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '300']]];

        $before = Voucher::acrossBranches()->count();

        $this->actingAs($clerk)->from(route('accounts.voucher.list'))
            ->post(route('accounts.voucher.store', 'journal'), $form((int) $other->id))
            ->assertSessionHasErrors(['branch_id' => __('accounts::asset.branch_out_of_reach')]);

        $this->assertSame($before, Voucher::acrossBranches()->count(), '⛔ নাগালের বাইরের শাখার নামে ভাউচার বসল');

        // ⓘ নিজের শাখায় আগের মতোই
        $this->actingAs($clerk)->post(route('accounts.voucher.store', 'journal'), $form((int) $home->id))->assertSessionHasNoErrors();
    }
}

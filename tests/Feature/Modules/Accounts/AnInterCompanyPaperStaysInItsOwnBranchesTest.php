<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\InterCompanyTransfer;
use App\Modules\Accounts\Services\InterCompanyService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ আন্তঃকোম্পানি লেনদেনের শাখা কেবল নিজের কোম্পানির — অডিট স১৪, ১০ অক্টোবর ২০২৬।
 *
 * ⓘ [[InterCompanyService::record()]] অনুরোধের `branch_id` সোজা দেওয়ার ভাউচারে বসাত: অন্য কোম্পানির শাখার নম্বর দিলে নিজের
 * খাতার সারি এমন শাখায় পড়ত যা এই কোম্পানির কোনো শাখা-প্রতিবেদনে আসে না।
 */
final class AnInterCompanyPaperStaysInItsOwnBranchesTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->beta = Company::query()->where('code', 'FMART')->firstOrFail();

        CompanyContext::forCompany((int) $this->alpha->id,
            fn () => $this->putMoneyIn($this->money($this->alpha), '20000', now()->toDateString()));

        CompanyContext::set((int) $this->alpha->id);
        $this->actingAs($this->owner);
    }

    public function test_another_companys_branch_is_refused_and_nothing_is_written(): void
    {
        $theirs = Branch::query()->withoutGlobalScopes()->where('company_id', $this->beta->id)->firstOrFail();

        try {
            $this->record((int) $theirs->id);
            $this->fail('⛔ অন্য কোম্পানির শাখার নামে নিজের খাতায় লেনদেন বসল');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.inter_company_branch_unknown'), $e->errors()['branch_id'][0] ?? null);
        }

        $this->assertSame(0, InterCompanyTransfer::query()->withoutGlobalScopes()->count());
    }

    public function test_an_own_branch_still_goes_through(): void
    {
        $ours = Branch::query()->withoutGlobalScopes()->where('company_id', $this->alpha->id)->firstOrFail();

        $this->assertInstanceOf(InterCompanyTransfer::class, $this->record((int) $ours->id));
    }

    private function record(int $branchId): InterCompanyTransfer
    {
        return app(InterCompanyService::class)->record($this->owner, [
            'counter_company_id' => $this->beta->id,
            'trx_date' => now()->toDateString(),
            'amount' => '5000.0000',
            'purpose' => 'ভাড়ার টাকা',
            'from_account_id' => $this->money($this->alpha)->id,
            'to_account_id' => $this->money($this->beta)->id,
            'branch_id' => $branchId,
        ]);
    }

    private function money(Company $company): Account
    {
        return CompanyContext::forCompany((int) $company->id, fn () => Account::query()
            ->whereIn('money_kind', Account::MONEY_KINDS)
            ->where('is_group', false)
            ->orderBy('code')
            ->firstOrFail());
    }
}

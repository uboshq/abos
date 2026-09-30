<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "টাকা কোথায়" পিকারে অন্য শাখার টিলের নগদ দেখাত — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কেন ─────────────────────────────────────────────────────────────
 * সাত শাখা সাত ব্যবসা; প্রত্যেকের টিল আলাদা। শাখা বাছলেও রসিদ, পরিশোধ আর সরাসরি বিক্রির
 * টাকার-খাত পিকারে সব শাখার টিলের খাত আসত — ভুল শাখার ড্রয়ারে টাকা বসানো এক ক্লিক দূরে।
 *
 * ── ⭐ নিয়ম ────────────────────────────────────────────────────────────
 * এক শাখা বাছলে সেই শাখার টিলের খাত; অন্য শাখার বা শাখাহীন টিলের খাত নয়। ব্যাংক আর MFS
 * কোম্পানির — সব জায়গায়। "সব শাখা"-য় সবগুলো।
 */
final class AMoneyPickerShowedAnotherBranchsTillTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $mymensingh;

    private Branch $netrakona;

    /** @var array<string, int> টিলের খাত */
    private array $account = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->mymensingh = $this->branch('MMS');
        $this->netrakona = $this->branch('NTK');
        CompanyContext::set($this->company->id, $this->mymensingh->id);
        $this->actingAs($this->owner);

        foreach (['mms' => $this->mymensingh->id, 'ntk' => $this->netrakona->id, 'none' => null] as $where => $branch) {
            $till = app(CashTillService::class)->create(['code' => 'MT'.strtoupper($where), 'name_en' => 'Money till '.$where]);
            CashTill::query()->whereKey($till->id)->update(['branch_id' => $branch]);
            $this->account[$where] = (int) $till->account_id;
        }

        /*
         * ⓘ ডেমোতে ব্যাংকের খাত নেই — একটা নগদ খাতের ভাই বানিয়ে তাকে ব্যাংক করা, একই মায়ের
         * নিচে (টাকার মায়েরা পিকারের তালিকায় আছে)।
         */
        $bank = Account::query()->ofMoneyKind(Account::BANK)->active()->first();

        if ($bank === null) {
            $sibling = Account::query()->ofMoneyKind(Account::CASH)->postable()->firstOrFail();
            $bank = $sibling->replicate(['public_id']);
            $bank->forceFill(['code' => $sibling->code.'-BNK', 'name_en' => 'Probe bank', 'money_kind' => Account::BANK])->save();
        }

        $this->account['bank'] = (int) $bank->id;
    }

    public function test_each_money_picker_shows_the_picked_branchs_tills_and_every_bank(): void
    {
        $screens = [
            'ক্রয়ের পরিশোধ' => [route('purchase.payment.create'), 'accounts'],
            'সরাসরি বিক্রি' => [route('sales.direct.create'), 'moneyAccounts'],
            'আদায়ের রসিদ (ভাউচার)' => [route('accounts.voucher.create', 'receipt'), 'moneyAccounts'],
        ];

        $expect = ['mms' => ['mms', 'bank'], 'ntk' => ['ntk', 'bank'], 'all' => ['mms', 'ntk', 'none', 'bank']];

        foreach (['mms' => $this->mymensingh->id, 'ntk' => $this->netrakona->id, 'all' => 'all'] as $pick => $branch) {
            $this->choose($branch);

            foreach ($screens as $name => [$url, $key]) {
                $ids = collect($this->actingAs($this->owner)->get($url)->assertOk()->viewData($key))
                    ->map(fn ($a) => (int) (is_array($a) ? $a['id'] : $a->id))->all();

                foreach (['mms', 'ntk', 'none', 'bank'] as $which) {
                    $this->assertSame(
                        in_array($which, $expect[$pick], true),
                        in_array($this->account[$which], $ids, true),
                        sprintf('⛔ %s — "%s" বেছে %s-এর খাত ভুল জায়গায়।', $name, $pick, $which),
                    );
                }
            }
        }
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())
            ->post(route('branch.switch'), ['branch_id' => (string) $branch])
            ->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}

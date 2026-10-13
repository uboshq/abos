<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * লাভের কাগজ বাতিল হলেও পাওনার হিসাব আগের মতোই থাকত (Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
 *
 * ⭐ দাবিগুলো:
 *   · মূলধনে নেওয়ার ভাউচার বাতিল — লাভটা আবার পাওনা, তোলা বা আবার মূলধনে নেওয়া যায়।
 *   · যে ঘোষণার ভাগ থেকে টাকা তোলা হয়ে গেছে, সেটা Accounts থেকে বাতিল হয় না।
 *   · অপেক্ষার ঘোষণার খসড়া বাতিল — ভাগগুলোও বাতিল, সীমা আর আটকে থাকে না।
 *   · লাভ থেকে আসা মূলধনের খসড়া সারি হাতে মোছা যায় না।
 *   · লাভের ভাগ তোলা হাতে লেখা ভাউচারে মেটে না — কেবল নিজের পথে।
 */
final class TheProfitOutlivedItsCancelledPaperTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    private Person $big;

    private Person $small;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->switchCompany((int) $this->company->id);
        $this->be($this->owner);

        $this->big = $this->contribute('PC-BIG', '700000');
        $this->small = $this->contribute('PC-SML', '300000');
    }

    public function test_a_cancelled_capitalise_voucher_makes_the_profit_owed_again(): void
    {
        $this->declare('100000');
        $entries = app(ProfitDistribution::class)->capitalise(['trx_date' => now()->toDateString(), 'entry_type' => CapitalEntry::CONTRIBUTION]);
        $this->assertSame(0, bccomp(app(ProfitDistribution::class)->outstandingFor((int) $this->big->id), '0', 4), 'দৃশ্যটাই বানানো যায়নি');

        app(VoucherService::class)->cancel(Voucher::acrossBranches()->findOrFail($entries[0]->voucher_id), 'ভুল');

        $this->assertSame(0, bccomp(app(ProfitDistribution::class)->outstandingFor((int) $this->big->id), '70000', 4),
            '⛔ মূলধনে নেওয়া বাতিল, তবু লাভটা আর পাওনা নয় — ২১৯০-এ আছে অথচ তোলা বা আবার নেওয়া যায় না');
    }

    public function test_a_declaration_whose_share_was_drawn_is_not_cancelled_from_accounts(): void
    {
        $this->declare('100000');
        $this->take($this->small, '10000');
        $share = ProfitShare::query()->where('person_id', $this->small->id)->sole();

        try {
            app(VoucherService::class)->cancel(Voucher::acrossBranches()->findOrFail($share->voucher_id), 'ভুল');
            $this->fail('⛔ তোলা হয়ে যাওয়া লাভের ঘোষণা বাতিল হল — ২১৯০ ঋণাত্মক');
        } catch (ValidationException) {
        }

        $this->assertSame(ProfitShare::POSTED, $share->fresh()->status);
    }

    public function test_a_cancelled_waiting_declaration_frees_its_shares(): void
    {
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'finance', 'action' => 'profit', 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => User::query()->where('email', 'accounts@abos.test')->value('id')]);
        $this->app->forgetInstance(ApprovalEngine::class);

        $this->declare('60000');
        $share = ProfitShare::query()->firstOrFail();
        $this->assertSame(ProfitShare::DRAFT, $share->status, 'দৃশ্যটাই বানানো যায়নি — ঘোষণা সইয়ের অপেক্ষায় নেই');

        app(VoucherService::class)->cancel(Voucher::acrossBranches()->findOrFail($share->voucher_id), 'ভুল');

        $this->assertSame(0, ProfitShare::query()->where('status', ProfitShare::DRAFT)->count(), '⛔ বাতিল ঘোষণার ভাগ খসড়াই থাকল — সীমা চিরকাল আটকে');
    }

    public function test_a_capital_row_born_of_profit_is_not_deleted_by_hand(): void
    {
        $row = CapitalEntry::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id, 'document_no' => 'PCAP-T', 'person_id' => $this->big->id,
            'contributor_type' => CapitalEntry::PARTNER, 'entry_type' => CapitalEntry::CONTRIBUTION, 'in_kind' => CapitalEntry::PROFIT,
            'trx_date' => now()->toDateString(), 'amount' => '5000', 'status' => CapitalEntry::DRAFT,
        ]);

        try {
            app(CapitalService::class)->discard($row);
            $this->fail('⛔ লাভ থেকে আসা মূলধনের সারি হাতে মুছল — শেষ সই তবু ভাউচার বসাত, আর একই লাভ আবার পাওনা হত');
        } catch (ValidationException) {
        }

        $this->assertNotNull(CapitalEntry::query()->find($row->id));
    }

    public function test_a_profit_share_withdrawal_is_not_settled_by_a_hand_written_voucher(): void
    {
        $profit = app(WithdrawalService::class)->request(['person_id' => $this->big->id, 'kind' => Withdrawal::PROFIT_SHARE, 'in_kind' => 'cash',
            'trx_date' => now()->toDateString(), 'amount' => '1000', 'reason' => 'test']);
        $cash = app(WithdrawalService::class)->request(['person_id' => $this->big->id, 'kind' => Withdrawal::DRAWING, 'in_kind' => 'cash',
            'trx_date' => now()->toDateString(), 'amount' => '1000', 'reason' => 'test']);

        $this->assertFalse($profit->fresh()->settlementTerms()['open'], '⛔ লাভের ভাগ হাতে লেখা ভাউচারে মিটতে পারে — ঘোষিত লাভ আর ২১৯০ এড়িয়ে');
        $this->assertTrue($cash->fresh()->settlementTerms()['open'], 'সাধারণ উত্তোলন আগের মতোই');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function declare(string $profit): void
    {
        app(ProfitDistribution::class)->declare(['trx_date' => now()->subDays(3)->toDateString(), 'profit' => $profit]);
    }

    private function take(Person $person, string $amount): void
    {
        $withdrawal = app(WithdrawalService::class)->request(['person_id' => $person->id, 'kind' => Withdrawal::PROFIT_SHARE, 'in_kind' => 'cash',
            'trx_date' => now()->toDateString(), 'amount' => $amount, 'reason' => 'test']);
        $till = Account::query()->money()->where('is_group', false)->firstOrFail();
        $this->putMoneyIn($till, $amount, now()->toDateString());
        app(WithdrawalService::class)->post($withdrawal->fresh(), $till);
    }

    private function contribute(string $code, string $amount): Person
    {
        $person = Person::query()->create(['code' => $code, 'name_en' => $code, 'name_bn' => $code, 'is_active' => true]);
        CapitalEntry::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id, 'document_no' => 'CAP-'.$code, 'person_id' => $person->id,
            'contributor_type' => CapitalEntry::PARTNER, 'entry_type' => CapitalEntry::CONTRIBUTION, 'in_kind' => CapitalEntry::CASH,
            'trx_date' => now()->subMonth()->toDateString(), 'amount' => $amount, 'status' => CapitalEntry::POSTED,
        ]);

        return $person;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Hr\Models\Employee;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\Concerns\ReadsTheRefusal;
use Tests\TestCase;

/**
 * ⭐ খরচের লাইন থেকে জাবেদা — খরচের ভাউচার ১০০%, ভাগ C (fe অনুমোদিত §১/§২, ২৪ অক্টোবর ২০২৬; [[ExpenseLedger]])।
 *
 * দাবি, আসল দরজায় (accounts.voucher.store আর .simulate):
 *   ক) নগদে বিদ্যুৎ — Dr খরচ, খরচ-কেন্দ্রসহ খাতায় (⛔ আগে কেন্দ্র মাথায় থাকত, খাতায় নামত না); বিবরণ "পক্ষ · বিল · লাইন"
 *   খ) বাকিতে ভাড়া, AIT আর VDS কেটে — Cr দেনা নিট, Cr ২১২১, Cr ২১২২; টাকার খাত বসে না
 *   গ) কয়েক লাইন — সমন্বয়যোগ্য ভ্যাট আলাদা 1139-এ, অসমন্বয়যোগ্য খরচের সাথে
 *   ঘ) কর্মী দিলেন — Cr 1131, পক্ষ কর্মী; টাকার খাত বসে না
 *   প্রতিটায় ডেবিট = ক্রেডিট, আর simulate-এর সারি = পোস্ট করা সারি
 *   ঙ) বাকিতে খরচ গ্রাহকের নামে — ফেরে (fe-র সিদ্ধান্ত ১)
 *   চ) পুরনো এক-লাইনের খরচ — আগের মতোই, কোনো ব্যবসার লাইন ছাড়া
 *   ছ) 1139 নিজের মানে না ধরলে — ফেরে
 */
final class AnExpensePostsFromItsLinesTest extends TestCase
{
    use PutsMoneyInTheTill;
    use ReadsTheRefusal;
    use RefreshDatabase;

    private Company $company;

    private Branch $a;

    private CashTill $till;

    private CostCenter $plant;

    private CostCenter $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['current_branch_id' => $this->a->id])->save();
        CompanyContext::set($this->company->id, $this->a->id);
        $this->actingAs($owner->fresh());
        app(StandardChart::class)->install();

        $this->till = app(CashTillService::class)->create(['name_en' => 'Expense box', 'holder_id' => $owner->id]);
        CashTill::query()->withoutGlobalScopes()->whereKey($this->till->id)->update(['branch_id' => $this->a->id]);
        $this->putMoneyIn($this->till->account, '200000');

        $this->plant = CostCenter::query()->create(['code' => 'CC-PLANT', 'name_en' => 'Plant', 'name_bn' => 'কারখানা', 'is_active' => true]);
        $this->office = CostCenter::query()->create(['code' => 'CC-OFFICE', 'name_en' => 'Office', 'name_bn' => 'অফিস', 'is_active' => true]);
    }

    /** ক — নগদে বিদ্যুৎ */
    public function test_cash_electricity_lands_with_its_cost_centre_and_its_own_narration(): void
    {
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $payload = $this->payload([
            'instrument' => 'cash', 'from_account_id' => $this->till->account_id,
            'party_type' => 'supplier', 'party_id' => $supplier->id, 'bill_no' => 'MBS-4471',
            'expense_lines' => [['expense_account_id' => $this->head(StandardChart::FUEL), 'cost_center_id' => $this->plant->id, 'amount' => '12450', 'description' => 'অক্টোবরের বিদ্যুৎ']],
        ]);

        $preview = $this->simulate($payload);
        $voucher = $this->postExpense($payload);
        $rows = $this->ledger($voucher);

        $this->assertBalanced($rows, 'ক');
        $expense = $rows->firstWhere('account_id', $this->head(StandardChart::FUEL));
        $this->assertSame((int) $this->plant->id, (int) $expense->cost_center_id, '⛔ ক: খরচ-কেন্দ্র খাতার সারিতে নামেনি — বাজেটের "আসল" শূন্য দেখাত');
        $party = app(PartyRegistry::class)->labelsOf([['supplier', (int) $supplier->id]])['supplier:'.$supplier->id];
        $this->assertSame($party.' · MBS-4471 · অক্টোবরের বিদ্যুৎ', $expense->narration, '⛔ ক: খাতার বিবরণ "পক্ষ · বিল · লাইন" নয়');
        $this->assertStringNotContainsString((string) $voucher->document_no, (string) $expense->narration, '⛔ ক: বিবরণে নিজের কাগজের নম্বর');
        $this->assertSame($this->shape($rows), $preview, '⛔ ক: simulate যা দেখাল, পোস্ট তা লিখল না');
    }

    /** খ — বাকিতে ভাড়া, AIT আর VDS কেটে */
    public function test_credit_rent_with_ait_and_vds_owes_the_supplier_the_net_and_moves_no_money(): void
    {
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $payable = $this->head(StandardChart::PAYABLE);
        $payload = $this->payload([
            'instrument' => Voucher::ON_CREDIT, 'from_account_id' => $payable, 'due_date' => now()->addDays(30)->toDateString(),
            'party_type' => 'supplier', 'party_id' => $supplier->id, 'bill_no' => 'RNT-10', 'ait_amount' => '1500', 'vds_amount' => '4500',
            'expense_lines' => [['expense_account_id' => $this->head(StandardChart::RENT), 'cost_center_id' => $this->office->id, 'amount' => '30000']],
        ]);

        $preview = $this->simulate($payload);
        $voucher = $this->postExpense($payload);
        $rows = $this->ledger($voucher);

        $this->assertBalanced($rows, 'খ');
        $this->assertSame('24000.0000', $this->creditOn($rows, $payable), '⛔ খ: দেনা নিট (৩০,০০০ − ১,৫০০ − ৪,৫০০) নয়');
        $this->assertSame((int) $supplier->id, (int) $rows->firstWhere('account_id', $payable)->party_id, '⛔ খ: দেনা সরবরাহকারীর নামে বসেনি');
        $this->assertSame('1500.0000', $this->creditOn($rows, $this->head(StandardChart::TAX_DEDUCTED_AT_SOURCE)), '⛔ খ: AIT ২১২১-এ নয়');
        $this->assertSame('4500.0000', $this->creditOn($rows, $this->head(StandardChart::VDS_PAYABLE)), '⛔ খ: VDS ২১২২-এ নয়');
        $this->assertNull($voucher->money_account_id, '⛔ খ: বাকিতে খরচে টাকার খাত বসল — টাকা তো নড়েনি (fe-র সিদ্ধান্ত ১)');
        $this->assertSame($this->shape($rows), $preview, '⛔ খ: simulate যা দেখাল, পোস্ট তা লিখল না');
    }

    /** গ — কয়েক লাইন, দুই রকম ভ্যাট */
    public function test_several_lines_split_recoverable_vat_to_1139_and_fold_the_rest_into_the_expense(): void
    {
        $payload = $this->payload([
            'instrument' => 'cash', 'from_account_id' => $this->till->account_id,
            'expense_lines' => [
                ['expense_account_id' => $this->head(StandardChart::FUEL), 'cost_center_id' => $this->plant->id, 'amount' => '1000', 'vat_amount' => '150', 'vat_recoverable' => '1'],
                ['expense_account_id' => $this->head(StandardChart::RENT), 'cost_center_id' => $this->office->id, 'amount' => '2000', 'vat_amount' => '300', 'vat_recoverable' => '0'],
            ],
        ]);

        $preview = $this->simulate($payload);
        $voucher = $this->postExpense($payload);
        $rows = $this->ledger($voucher);

        $this->assertBalanced($rows, 'গ');
        $this->assertSame('1000.0000', $this->debitOn($rows, $this->head(StandardChart::FUEL)), '⛔ গ: সমন্বয়যোগ্য ভ্যাট খরচে মিশল');
        $this->assertSame('150.0000', $this->debitOn($rows, $this->head(StandardChart::INPUT_VAT_RECOVERABLE)), '⛔ গ: সমন্বয়যোগ্য ভ্যাট 1139-এ আলাদা বসেনি');
        $this->assertSame('2300.0000', $this->debitOn($rows, $this->head(StandardChart::RENT)), '⛔ গ: অসমন্বয়যোগ্য ভ্যাট খরচের সাথে বসেনি');
        $this->assertSame((int) $this->office->id, (int) $rows->firstWhere('account_id', $this->head(StandardChart::RENT))->cost_center_id,
            '⛔ গ: দ্বিতীয় লাইনের খরচ-কেন্দ্র খাতায় নামেনি');
        $this->assertSame(2, Voucher::query()->findOrFail($voucher->id)->expenseLines()->count(), '⛔ গ: ব্যবসার লাইন রাখা হয়নি');
        $this->assertSame($this->shape($rows), $preview, '⛔ গ: simulate যা দেখাল, পোস্ট তা লিখল না');
    }

    /** ঘ — কর্মী দিলেন */
    public function test_an_employee_paid_expense_is_owed_to_the_employee_on_1131_and_moves_no_money(): void
    {
        $employee = Employee::query()->create(['branch_id' => $this->a->id, 'code' => 'EMP-EXP1', 'name_en' => 'Paying Employee', 'joining_date' => '2026-01-01']);
        $payload = $this->payload([
            'instrument' => Voucher::BY_EMPLOYEE, 'employee_id' => $employee->id,
            'expense_lines' => [['expense_account_id' => $this->head(StandardChart::FUEL), 'amount' => '500']],
        ]);

        $preview = $this->simulate($payload);
        $voucher = $this->postExpense($payload);
        $rows = $this->ledger($voucher);

        $this->assertBalanced($rows, 'ঘ');
        $advance = $rows->firstWhere('account_id', $this->head(StandardChart::EMPLOYEE_ADVANCE));
        $this->assertNotNull($advance, '⛔ ঘ: কর্মী দিলেন, অথচ 1131-এ কিছু বসেনি');
        $this->assertSame(['employee', (int) $employee->id, '500.0000'], [(string) $advance->party_type, (int) $advance->party_id, bcadd((string) $advance->credit, '0', 4)],
            '⛔ ঘ: কর্মীর পাওনা তাঁর নামে ক্রেডিট নয়');
        $this->assertNull($voucher->money_account_id, '⛔ ঘ: কর্মী দিলেন, অথচ টাকার খাত বসল (fe-র সিদ্ধান্ত ১)');
        $this->assertSame((int) $employee->id, (int) $voucher->employee_id, '⛔ ঘ: কোন কর্মী দিলেন, মাথায় রইল না');
        $this->assertSame($this->shape($rows), $preview, '⛔ ঘ: simulate যা দেখাল, পোস্ট তা লিখল না');
    }

    /** ঙ — বাকিতে খরচ গ্রাহকের নামে নয় */
    public function test_credit_to_a_customer_is_refused(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $before = Voucher::query()->count();

        $this->post(route('accounts.voucher.store', 'expense'), $this->payload([
            'instrument' => Voucher::ON_CREDIT, 'from_account_id' => $this->head(StandardChart::PAYABLE), 'due_date' => now()->addDays(7)->toDateString(),
            'party_type' => 'customer', 'party_id' => $customer->id,
            'expense_lines' => [['expense_account_id' => $this->head(StandardChart::FUEL), 'amount' => '800']],
        ]));

        $said = $this->refusalOn('party');
        $this->assertContains($said, [trans('accounts::validation.credit_party_supplier_or_employee', [], 'bn'), trans('accounts::validation.credit_party_supplier_or_employee', [], 'en')],
            '⛔ ঙ: গ্রাহকের নামে বাকিতে খরচ ফিরল না, বা অন্য কারণে ফিরল: '.json_encode($this->refusalMessages(), JSON_UNESCAPED_UNICODE));
        $this->assertSame($before, Voucher::query()->count(), '⛔ ঙ: ফেরানো খরচ লেখা রইল');
    }

    /** চ — পুরনো এক-লাইনের খরচ */
    public function test_an_old_single_line_expense_posts_exactly_as_before(): void
    {
        $voucher = $this->postExpense([
            'type' => Voucher::EXPENSE, 'trx_date' => now()->toDateString(), 'narration' => 'পুরনো পথ',
            'amount' => '700', 'to_account_id' => $this->head(StandardChart::FUEL), 'from_account_id' => $this->till->account_id,
        ]);
        $rows = $this->ledger($voucher);

        $this->assertSame([[$this->head(StandardChart::FUEL), '700.0000', '0.0000'], [(int) $this->till->account_id, '0.0000', '700.0000']],
            $rows->map(fn ($r) => [(int) $r->account_id, bcadd((string) $r->debit, '0', 4), bcadd((string) $r->credit, '0', 4)])->values()->all(),
            '⛔ চ: পুরনো এক-লাইনের খরচ আগের দুই সারিতে বসে না — পুরনো অ্যাপ আর পুরনো খসড়া ভাঙত');
        $this->assertSame(0, $voucher->expenseLines()->count(), '⛔ চ: পুরনো পথে ব্যবসার লাইন বানানো হলো');
    }

    /** ছ — 1139 নিজের মানে না ধরলে */
    public function test_a_1139_that_lost_its_meaning_is_refused(): void
    {
        DB::table('accounts')->where('company_id', $this->company->id)->where('code', StandardChart::INPUT_VAT_RECOVERABLE)->update(['type' => Account::LIABILITY]);
        $before = Voucher::query()->count();

        $this->post(route('accounts.voucher.store', 'expense'), $this->payload([
            'instrument' => 'cash', 'from_account_id' => $this->till->account_id,
            'expense_lines' => [['expense_account_id' => $this->head(StandardChart::FUEL), 'amount' => '1000', 'vat_amount' => '150', 'vat_recoverable' => '1']],
        ]));

        $vat = StandardChart::find(StandardChart::INPUT_VAT_RECOVERABLE);
        $expected = array_map(fn (string $l) => trans('accounts::validation.account_lost_its_meaning', ['code' => StandardChart::INPUT_VAT_RECOVERABLE, 'account' => $vat->label()], $l), ['bn', 'en']);
        $this->assertContains($this->refusalOn('expense_lines.0.vat_recoverable'), $expected,
            '⛔ ছ: মানে-হারানো 1139-এ ভ্যাট বসতে গেল, বা অন্য কারণে ফিরল: '.json_encode($this->refusalMessages(), JSON_UNESCAPED_UNICODE));
        $this->assertSame($before, Voucher::query()->count(), '⛔ ছ: ফেরানো খরচ লেখা রইল');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function payload(array $extra): array
    {
        return ['type' => Voucher::EXPENSE, 'trx_date' => now()->toDateString(), 'narration' => 'খরচের লাইনের দাবি', ...$extra];
    }

    private function postExpense(array $payload): Voucher
    {
        $this->post(route('accounts.voucher.store', 'expense'), $payload);
        $this->assertSame([], $this->refusalMessages(), '⛔ জমা ফিরল: '.json_encode($this->refusalMessages(), JSON_UNESCAPED_UNICODE));

        $voucher = Voucher::acrossBranches()->latest('id')->firstOrFail();
        $this->assertTrue($voucher->isPosted(), '⛔ খরচ পাকা হয়নি (অবস্থা '.$voucher->status.')');

        return $voucher;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function simulate(array $payload): array
    {
        $response = $this->postJson(route('accounts.voucher.simulate'), $payload);
        $this->assertSame(200, $response->status(), '⛔ খরচের simulate খোলে না (অবস্থা '.$response->status().') — পোস্টের আগে খাতা দেখা যায় না');
        $json = $response->json();
        $this->assertSame([], $json['missing'] ?? [], '⛔ simulate বলে ঘর বাকি: '.json_encode($json['missing'] ?? []));
        $this->assertNotEmpty($json['rows'] ?? [], '⛔ খরচের simulate কিছু দেখায় না: '.($json['message'] ?? ''));

        return array_map(fn (array $r) => [(string) $r['account'], (string) $r['debit'], (string) $r['credit']], $json['rows']);
    }

    /** @return \Illuminate\Support\Collection<int, LedgerEntry> */
    private function ledger(Voucher $voucher)
    {
        return LedgerEntry::query()->withoutGlobalScopes()
            ->where('source_type', Voucher::SOURCE_TYPES[Voucher::EXPENSE])->where('source_id', $voucher->id)->orderBy('id')->get();
    }

    /** @return list<array{0: string, 1: string, 2: string}> — simulate-এর একই আকার */
    private function shape($rows): array
    {
        $accounts = Account::query()->whereKey($rows->pluck('account_id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            $accounts[$r->account_id]->code.' '.$accounts[$r->account_id]->name(),
            bccomp((string) $r->debit, '0', 4) > 0 ? Money::format((string) $r->debit) : '',
            bccomp((string) $r->credit, '0', 4) > 0 ? Money::format((string) $r->credit) : '',
        ])->values()->all();
    }

    private function assertBalanced($rows, string $case): void
    {
        $debit = $rows->reduce(fn ($s, $r) => bcadd($s, (string) $r->debit, 4), '0');
        $credit = $rows->reduce(fn ($s, $r) => bcadd($s, (string) $r->credit, 4), '0');
        $this->assertSame(0, bccomp($debit, $credit, 4), "⛔ {$case}: খাতায় ডেবিট {$debit} ≠ ক্রেডিট {$credit}");
    }

    private function debitOn($rows, int $account): string
    {
        return $rows->where('account_id', $account)->reduce(fn ($s, $r) => bcadd($s, (string) $r->debit, 4), '0.0000');
    }

    private function creditOn($rows, int $account): string
    {
        return $rows->where('account_id', $account)->reduce(fn ($s, $r) => bcadd($s, (string) $r->credit, 4), '0.0000');
    }

    private function head(string $code): int
    {
        return (int) (StandardChart::find($code)?->id ?? $this->fail("দৃশ্যটাই ঠিক নয় — মান ছকে {$code} নেই"));
    }
}

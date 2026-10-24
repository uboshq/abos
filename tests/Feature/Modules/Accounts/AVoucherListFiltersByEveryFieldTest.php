<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ListSummary;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ ভাউচারের তালিকা প্রতিটা ঘরে ছাঁকে — তারিখ, পক্ষ, খাত, পদ্ধতি, অবস্থা, টাকার পরিসর, কে বানালেন, শাখা (মালিকের প্রশ্ন, ২৪ অক্টোবর
 * ২০২৬: রসিদের তালিকার "+ Filter" কী করে — কেবল তারিখ খুলত; fe: international standard-এর ভাউচার তালিকা)।
 *
 * দাবি:
 *   ১. প্রতিটা ছাঁকনি তালিকা আর মোট দুটোই ঠিক ছাঁকে — ছয়টা রসিদ, প্রতিটা আলাদা এক ঘরে
 *   ২. "অবস্থা" তালিকার মোটের দলের সাথে হুবহু এক ([[ListSummary::of()]] আর [[ListSummary::onlyGroup()]] একই সই-সেবা)
 *   ৩. share-লিংক (টুলবারের) একই ফল দেয়, আর বাছা ছাঁকনি চিপে নামসহ ওঠে
 *   ৪. হেডারে এক শাখা বাছা থাকলে সেটাই শাখার শুরুর মান
 *   ৫. ভুল বা অ্যারে মান ৫০০ নয়, আর নীরবে বাদ
 *   ৬. পরিশোধ আর জাবেদার তালিকাতেও একই ছাঁকনি
 */
final class AVoucherListFiltersByEveryFieldTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Account $cash;

    private Account $bank;

    /** @var array<string, int>  নাম => ভাউচার id */
    private array $v = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->cash = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $this->bank = Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? app(AccountService::class)->create(['code' => StandardChart::BANK.'-01', 'name_en' => 'Filter bank',
                'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id')]);

        // ⓘ ডেমোর আগের রসিদ, পরিশোধ আর জাবেদা সরিয়ে রাখা (স্থানান্তরের তালিকায়) — তালিকায় কেবল এই দাবির ছয়টা; মোছা নয়, সারির বাঁধন থাকে
        DB::table('vouchers')->where('company_id', $this->company->id)
            ->whereIn('type', [Voucher::RECEIPT, Voucher::PAYMENT, Voucher::JOURNAL])->update(['type' => Voucher::CONTRA]);

        [$one, $two] = Customer::query()->orderBy('id')->limit(2)->get()->all();
        [$a, $b] = $this->twoBranches();
        $clerk = User::factory()->create(['is_active' => true]);

        $this->v = [
            'base' => $this->receipt(['party_id' => $one->id, 'amount' => '1000', 'trx_date' => '2026-10-05', 'branch_id' => $a->id]),
            'party' => $this->receipt(['party_id' => $two->id, 'amount' => '1100', 'trx_date' => '2026-10-05', 'branch_id' => $a->id]),
            'bank' => $this->receipt(['party_id' => $one->id, 'amount' => '1200', 'trx_date' => '2026-10-05', 'branch_id' => $a->id, 'instrument' => 'transfer'], $this->bank),
            'early' => $this->receipt(['party_id' => $one->id, 'amount' => '1300', 'trx_date' => '2026-09-20', 'branch_id' => $a->id]),
            'big' => $this->receipt(['party_id' => $one->id, 'amount' => '90000', 'trx_date' => '2026-10-05', 'branch_id' => $a->id]),
            'other' => $this->receipt(['party_id' => $one->id, 'amount' => '1400', 'trx_date' => '2026-10-05', 'branch_id' => $b->id, 'created_by' => $clerk->id, 'status' => DocumentStatus::DRAFT]),
        ];
        $this->party = ['one' => 'customer:'.$one->id, 'two' => 'customer:'.$two->id];
        $this->branches = [$a->id, $b->id];
        $this->clerk = $clerk->id;
    }

    /** @var array<string, string> */
    private array $party = [];

    /** @var list<int> */
    private array $branches = [];

    private int $clerk = 0;

    public function test_the_ground_is_there(): void
    {
        $this->assertCount(6, $this->ids([]), 'দৃশ্যটাই ঠিক নয় — ছাঁকনি ছাড়া তালিকায় ছয়টা রসিদ নেই');
    }

    /** ১ — প্রতিটা ছাঁকনি তালিকা আর মোট দুটোই */
    public function test_every_filter_narrows_the_list_and_its_total(): void
    {
        foreach ([
            'তারিখ থেকে' => [['from' => '2026-10-01'], ['base', 'party', 'bank', 'big', 'other']],
            'তারিখ পর্যন্ত' => [['to' => '2026-09-30'], ['early']],
            'পক্ষ' => [['party' => $this->party['two']], ['party']],
            'খাত' => [['account' => (string) $this->bank->id], ['bank']],
            'পদ্ধতি' => [['method' => 'transfer'], ['bank']],
            'অবস্থা' => [['state' => ListSummary::DRAFT], ['other']],
            'টাকা থেকে' => [['min' => '50,000'], ['big']],
            'টাকা পর্যন্ত' => [['max' => '1100'], ['base', 'party']],
            'কে বানালেন' => [['by' => (string) $this->clerk], ['other']],
            'শাখা' => [['branch_id' => (string) $this->branches[1]], ['other']],
        ] as $what => [$query, $expected]) {
            $want = $this->names($expected);

            $this->assertEqualsCanonicalizing($want, $this->ids($query), "⛔ {$what}: তালিকা ঠিক ছাঁকে না");
            $this->assertSame(0, bccomp($this->grand($query), $this->countedSum($want), 2),
                "⛔ {$what}: মোট ছাঁকনি মানে না — {$this->grand($query)}, হওয়ার কথা {$this->countedSum($want)}");
        }
    }

    /** ২ — অবস্থা = মোটের দল */
    public function test_the_state_filter_is_the_summarys_own_group(): void
    {
        DB::table('vouchers')->where('id', $this->v['early'])->update(['status' => DocumentStatus::CANCELLED]);
        $base = Voucher::query()->ofType(Voucher::RECEIPT);
        $summary = ListSummary::of($base, Voucher::listSummary());

        foreach (ListSummary::GROUPS as $group) {
            if (! isset($summary[$group])) {
                continue;
            }

            $this->assertCount($summary[$group]['count'], $this->ids(['state' => $group]),
                "⛔ অবস্থা '{$group}': ছাঁকা তালিকা আর মোটের দল দুই সংখ্যা বলে");
        }

        $this->assertSame([$this->v['early']], $this->ids(['state' => ListSummary::CANCELLED]), '⛔ বাতিলের ছাঁকনিতে বাতিল রসিদটা নেই');
    }

    /** ৩ — share-লিংক একই ফল, চিপে নাম */
    public function test_the_share_link_shows_the_same_list_and_the_chips_say_what_is_chosen(): void
    {
        $query = ['party' => $this->party['one'], 'method' => 'cash', 'from' => '2026-10-01'];
        $html = (string) $this->get(route('accounts.voucher.index', ['type' => Voucher::RECEIPT, ...$query]))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/href="mailto:\?body=([^"]+)"/', $html, $m), 'দৃশ্যটাই ঠিক নয় — share-লিংক নেই');
        $shared = urldecode(html_entity_decode($m[1]));
        $response = $this->get($shared)->assertOk();
        $this->assertEqualsCanonicalizing($this->ids($query), $response->viewData('vouchers')->getCollection()->modelKeys(),
            '⛔ share-লিংক অন্য তালিকা দেখায়: '.$shared);

        // ⓘ পরীক্ষা ইংরেজি পাঠায় (Accept-Language en) — দুই ভাষাই মানা
        $chip = fn (string $l) => e(trans('money.method.legend', [], $l).': '.trans('money.method.cash', [], $l));
        $this->assertTrue(str_contains($html, $chip('bn')) || str_contains($html, $chip('en')), '⛔ পদ্ধতির চিপে নাম আর মান নেই');
        // ⓘ প্রতিটা চিপের ✕ — নিজের ছাঁকনিটা ছাড়া বাকি সব রেখে
        preg_match_all('/data-facet\s+href="([^"]+)"/', $html, $facets);
        $removes = array_map(fn (string $h) => html_entity_decode($h), $facets[1]);
        $this->assertNotEmpty(array_filter($removes, fn (string $h) => ! str_contains($h, 'method=') && str_contains($h, 'party=')),
            '⛔ পদ্ধতির চিপে ✕ নেই, বা ✕ বাকি ছাঁকনিও মুছে দেয়: '.json_encode($removes));
    }

    /** ৪ — হেডারের শাখাই শুরুর মান */
    public function test_the_header_branch_is_the_starting_branch(): void
    {
        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $this->branches[1]])->save();
        CompanyContext::set($this->company->id, $this->branches[1]);

        $response = $this->actingAs($this->owner->fresh())->get(route('accounts.voucher.index', ['type' => Voucher::RECEIPT]))->assertOk();

        $this->assertSame((string) $this->branches[1], $response->viewData('filters')['branch_id'] ?? null, '⛔ হেডারে শাখা বাছা, অথচ ছাঁকনির শাখা ফাঁকা');
        $this->assertSame([$this->v['other']], $response->viewData('vouchers')->getCollection()->modelKeys(), '⛔ হেডারের শাখার বাইরের রসিদ তালিকায়');
    }

    /** ৫ — ভুল মান নীরবে বাদ */
    public function test_a_wrong_or_array_value_is_dropped_not_a_crash(): void
    {
        foreach (['party[]=x', 'min=abc', 'state=everything', 'method=bitcoin', 'account=1%20or%201', 'from=yesterday', 'branch_id[]=1'] as $bad) {
            $response = $this->get(route('accounts.voucher.index', ['type' => Voucher::RECEIPT]).'?'.$bad);

            $this->assertSame(200, $response->getStatusCode(), "⛔ ?{$bad}: পাতা ভাঙল");
            $this->assertCount(6, $response->viewData('vouchers')->getCollection(), "⛔ ?{$bad}: অচেনা মান তালিকা ছাঁকল");
        }
    }

    /** ৬ — পরিশোধ আর জাবেদাতেও */
    public function test_the_payment_and_journal_lists_take_the_same_filters(): void
    {
        DB::table('vouchers')->where('id', $this->v['bank'])->update(['type' => Voucher::PAYMENT]);
        DB::table('vouchers')->where('id', $this->v['big'])->update(['type' => Voucher::JOURNAL]);

        $this->assertSame([$this->v['bank']], $this->ids(['method' => 'transfer'], Voucher::PAYMENT), '⛔ পরিশোধের তালিকা পদ্ধতিতে ছাঁকে না');
        $this->assertSame([$this->v['big']], $this->ids(['min' => '50000'], Voucher::JOURNAL), '⛔ জাবেদার তালিকা টাকার পরিসরে ছাঁকে না');

        $html = (string) $this->get(route('accounts.voucher.index', ['type' => Voucher::PAYMENT]))->assertOk()->getContent();
        foreach (['party', 'account', 'method', 'state', 'by', 'min', 'max'] as $key) {
            $this->assertStringContainsString('data-filter="'.$key.'"', $html, "⛔ পরিশোধের তালিকায় '{$key}'-এর ছাঁকনি নেই");
        }

        // ⛔ পরিশোধে পক্ষের নাম "পক্ষ" — "কাকে দেওয়া হল" ফর্মে খাতের ঘরের লেখা, এক লেখার দুই মানে হত
        $label = $this->get(route('accounts.voucher.index', ['type' => Voucher::PAYMENT]))->viewData('filterLabels')['party'] ?? null;
        $this->assertContains($label, [trans('accounts::field.party', [], 'bn'), trans('accounts::field.party', [], 'en')],
            '⛔ পরিশোধের তালিকায় পক্ষের ছাঁকনির নাম "পক্ষ" নয়: '.$label);
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** @return list<int> */
    private function ids(array $query, string $type = Voucher::RECEIPT): array
    {
        return $this->get(route('accounts.voucher.index', ['type' => $type, ...$query]))->assertOk()
            ->viewData('vouchers')->getCollection()->modelKeys();
    }

    private function grand(array $query): string
    {
        $grand = $this->get(route('accounts.voucher.index', ['type' => Voucher::RECEIPT, ...$query]))->assertOk()->viewData('grand');

        return (string) ($grand['amount'] ?? '0');
    }

    /** @param list<int> $ids */
    private function countedSum(array $ids): string
    {
        return (string) Voucher::query()->whereKey($ids)->get()->reduce(fn (string $s, Voucher $v) => bcadd($s, $v->countedAmount(), 4), '0');
    }

    /** @return list<int> */
    private function names(array $names): array
    {
        return array_map(fn (string $n) => $this->v[$n], $names);
    }

    /** @return array{0: Branch, 1: Branch} */
    private function twoBranches(): array
    {
        $branches = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->limit(2)->get()->all();
        $this->assertCount(2, $branches, 'দৃশ্যটাই ঠিক নয় — ডেমোতে দুইটা শাখা নেই');

        return $branches;
    }

    /**
     * ⓘ একটা পাকা রসিদ, এক ঘরে আলাদা — সেবা দিয়ে সারিসহ লেখা, তারপর মাথার ঘরগুলো সরাসরি (দাবিটা ছাঁকনির, পোস্টের নয়)।
     */
    private function receipt(array $head, ?Account $money = null): int
    {
        $money ??= $this->cash;
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $head['trx_date'], 'narration' => 'ছাঁকনির দাবি'],
            [
                ['account_id' => $money->id, 'debit' => $head['amount']],
                ['account_id' => Account::query()->where('code', StandardChart::RECEIVABLE)->value('id'), 'credit' => $head['amount']],
            ],
        );

        DB::table('vouchers')->where('id', $voucher->id)->update([
            'type' => Voucher::RECEIPT,
            'party_type' => 'customer',
            'party_id' => $head['party_id'],
            'amount' => $head['amount'],
            'instrument' => $head['instrument'] ?? 'cash',
            'branch_id' => $head['branch_id'],
            'created_by' => $head['created_by'] ?? $this->owner->id,
            'status' => $head['status'] ?? DocumentStatus::CONFIRMED,
        ]);

        return (int) $voucher->id;
    }
}

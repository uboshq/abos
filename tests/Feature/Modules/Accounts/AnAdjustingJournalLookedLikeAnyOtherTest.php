<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\DocumentFingerprint;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\AdjustingReversals;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * মাসশেষের সমন্বয় জাবেদা দেখতে আর সব জাবেদার মতো, খোলা জেরের নম্বর ছিল যার-তার — ভাউচারের পরিকল্পনা, অংশ ৩ঘ (৭ অক্টোবর ২০২৬)।
 *
 * ⭐ সমন্বয়: জাবেদার পর্দার টিক → `is_adjusting`, নাম "সমন্বয় জাবেদা", তালিকায় দাগ আর ছাঁকনি; কেবল জাবেদায়। ছাপ (সই) আজকের
 *   ভাউচারে অবিকল। "উল্টো দাখিলার তারিখ" এলে নিজে একবার উল্টায় ([[AdjustingReversals]])।
 * ⭐ খোলা জের: প্রতিটা দাখিলা OB সিরিজের নম্বর পায় ([[OpeningBalanceService::SERIES]]), পক্ষ/খাতের কোড বিবরণে।
 */
final class AnAdjustingJournalLookedLikeAnyOtherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_the_screen_marks_names_lists_and_filters_an_adjusting_journal(): void
    {
        $this->post(route('accounts.voucher.store', 'journal'), $this->journal(['is_adjusting' => '1']))->assertSessionHasNoErrors();
        $adjusting = Voucher::query()->latest('id')->firstOrFail();
        $this->post(route('accounts.voucher.store', 'journal'), $this->journal(['is_adjusting' => '0']))->assertSessionHasNoErrors();
        $plain = Voucher::query()->latest('id')->firstOrFail();

        $this->assertTrue($adjusting->is_adjusting, '⛔ টিক দেওয়া জাবেদায় দাগ বসেনি।');
        $this->assertFalse($plain->is_adjusting);
        $this->assertSame(__('accounts::voucher.adjusting_journal'), $adjusting->typeLabel());

        $this->get(route('accounts.voucher.show', $adjusting))->assertOk()->assertSee(__('accounts::voucher.adjusting_journal'));

        $list = $this->get(route('accounts.voucher.index', 'journal'))->assertOk();
        // ⓘ দাগটা নিজেই — ছাঁকনির `data-adjusting-filter` নয়
        $this->assertMatchesRegularExpression('/data-adjusting(?![-\w])/', $list->getContent(), '⛔ তালিকায় সমন্বয় দাগ নেই।');
        $list->assertSee($plain->document_no);

        $only = $this->get(route('accounts.voucher.index', ['journal', 'adjusting' => 1]))->assertOk();
        $only->assertSee($adjusting->document_no)->assertDontSee($plain->document_no);

        // ⓘ টিক তুলে নিলে সম্পাদনায় দাগ সত্যিই ওঠে
        $this->put(route('accounts.voucher.update', $adjusting), $this->journal(['is_adjusting' => '0']))
            ->assertSessionHasNoErrors()->assertRedirect(route('accounts.voucher.show', $adjusting));
        $this->assertFalse($adjusting->fresh()->is_adjusting, '⛔ টিক তুলে নিলেও দাগ রয়ে গেল।');
    }

    public function test_only_a_journal_can_carry_the_mark(): void
    {
        $cash = Account::query()->postable()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();
        $receipt = app(VoucherService::class)->create(
            ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'আদায়', 'is_adjusting' => true],
            [['account_id' => $cash->id, 'debit' => '100', 'credit' => '0'], ['account_id' => StandardChart::find(StandardChart::RENT_INCOME)->id, 'debit' => '0', 'credit' => '100']],
        );

        $this->assertFalse($receipt->fresh()->is_adjusting, '⛔ আদায়ে সমন্বয় দাগ বসল।');
    }

    public function test_the_fingerprint_of_todays_vouchers_does_not_move(): void
    {
        $this->post(route('accounts.voucher.store', 'journal'), $this->journal())->assertSessionHasNoErrors();
        $voucher = Voucher::query()->latest('id')->firstOrFail();

        // ⓘ দাগ নেই, উল্টোর আসল নেই — নতুন দুই ঘর ছাপের বাইরে, তাই মাইগ্রেশনের আগের ছাপ (সই) অবিকল
        $this->assertContains('is_adjusting', $voucher->fingerprintIgnores(), '⛔ দাগহীন ভাউচারের ছাপে নতুন ঘর — আজকের সই বাতিল হতো।');
        $this->assertContains('reversal_of_id', $voucher->fingerprintIgnores(), '⛔ উল্টো-নয় ভাউচারের ছাপে নতুন ঘর।');

        // ⓘ দাগ বসালে কাগজ বদলায় — সই চায় (ছাপ ডাটাবেজের সারি থেকে, [[DocumentFingerprint::asStored()]])
        $before = app(DocumentFingerprint::class)->of($voucher);
        DB::table('vouchers')->where('id', $voucher->id)->update(['is_adjusting' => true]);
        $marked = $voucher->fresh();
        $this->assertNotContains('is_adjusting', $marked->fingerprintIgnores());
        $this->assertNotSame($before, app(DocumentFingerprint::class)->of($marked), '⛔ সমন্বয় দাগ বসলেও ছাপ একই।');
    }

    public function test_every_opening_takes_an_ob_number_and_keeps_its_code_in_the_narration(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $party = app(OpeningBalanceService::class)->forReceivable(Customer::drillSourceType(), (int) $customer->id, (string) $customer->code, '500', null);
        $stock = app(OpeningBalanceService::class)->forInventory(sourceId: 9001, documentNo: 'OPENING', amount: '700');
        $account = app(AccountService::class)->create([
            'code' => StandardChart::BANK.'-77', 'name_en' => 'Opening bank',
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            'opening_balance' => '900',
        ]);

        $numbers = [];

        foreach ([$party, $stock, LedgerEntry::query()->where('source_type', OpeningBalanceService::ACCOUNT_SOURCE.OpeningBalanceService::SOURCE_SUFFIX)
            ->where('source_id', $account->id)->get()->all()] as $rows) {
            $this->assertNotEmpty($rows);
            $no = collect($rows)->pluck('document_no')->unique()->sole();
            $this->assertStringStartsWith('OB', (string) $no, '⛔ খোলা জের OB সিরিজে নয় — '.$no);
            $numbers[] = $no;
        }

        $this->assertCount(3, array_unique($numbers), '⛔ দুই খোলা দাখিলা একই নম্বর পেল।');
        $this->assertStringContainsString((string) $customer->code, (string) $party[0]->narration, 'পক্ষের কোড বিবরণে নেই।');
        $this->assertStringContainsString($account->code, (string) LedgerEntry::query()->where('document_no', $numbers[2])->value('narration'));
    }

    public function test_an_adjusting_journal_reverses_itself_once_on_its_date(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $receivable = $this->leaf(StandardChart::RECEIVABLE);
        $income = StandardChart::find(StandardChart::RENT_INCOME);

        $due = $this->posted(true, now()->subDays(3), now()->subDay(), $receivable, $income, $customer);
        $plain = $this->posted(false, now()->subDays(3), now()->subDay(), $receivable, $income, $customer);
        $later = $this->posted(true, now()->subDays(3), now()->addDays(5), $receivable, $income, $customer);

        $this->assertSame(1, app(AdjustingReversals::class)->run()['reversed']);
        $this->assertSame(0, app(AdjustingReversals::class)->run()['reversed'], '⛔ একই সমন্বয় দুইবার উল্টাল।');

        $reversal = Voucher::query()->where('reversal_of_id', $due->id)->sole();
        $this->assertTrue($reversal->isPosted());
        $this->assertTrue($reversal->is_adjusting);
        $this->assertSame($due->reverse_on->toDateString(), $reversal->trx_date->toDateString(), 'উল্টো জাবেদা তার তারিখে নয়।');
        $this->assertSame(
            $due->lines->map(fn ($l) => [(int) $l->account_id, (float) $l->credit, (float) $l->debit, $l->party_type, (int) $l->party_id])->sort()->values()->all(),
            $reversal->lines->map(fn ($l) => [(int) $l->account_id, (float) $l->debit, (float) $l->credit, $l->party_type, (int) $l->party_id])->sort()->values()->all(),
            '⛔ উল্টো জাবেদার সারি আসলটার হুবহু উল্টো নয়।',
        );
        $this->assertTrue(DB::table('audit_trails')->where('action', 'adjusting_reversed')->where('auditable_id', $due->id)->exists(), 'নিরীক্ষায় দাগ নেই।');

        $this->assertFalse(Voucher::query()->where('reversal_of_id', $plain->id)->exists(), '⛔ সাধারণ জাবেদা নিজে উল্টাল।');
        $this->assertFalse(Voucher::query()->where('reversal_of_id', $later->id)->exists(), '⛔ তারিখ আসার আগেই উল্টাল।');

        // ⓘ একবারই — সরাসরি দ্বিতীয় ডাকেও
        $this->assertNull(app(AdjustingReversals::class)->reverse((int) $due->id));

        // ⓘ শিডিউলারের কমান্ড — সব কোম্পানি ঘুরে; তারিখ এলে পরেরটাও
        $this->artisan('abos:adjusting-reverse', ['--date' => now()->addDays(5)->toDateString()])->assertSuccessful();
        CompanyContext::set(Company::query()->where('code', 'TDEPOT')->value('id'), null);
        $this->assertTrue(Voucher::acrossBranches()->where('reversal_of_id', $later->id)->exists(), '⛔ কমান্ড তারিখ-আসা সমন্বয় উল্টায়নি।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function posted(bool $adjusting, Carbon $on, Carbon $reverseOn, Account $dr, Account $cr, Customer $customer): Voucher
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $on->toDateString(), 'reverse_on' => $reverseOn->toDateString(),
                'narration' => 'মাসশেষের বকেয়া আয়', 'is_adjusting' => $adjusting],
            [['account_id' => $dr->id, 'debit' => '300', 'credit' => '0', 'party_type' => 'customer', 'party_id' => $customer->id],
                ['account_id' => $cr->id, 'debit' => '0', 'credit' => '300']],
        );
        app(VoucherService::class)->post($voucher);

        return $voucher->fresh(['lines']);
    }

    /** @return array<string, mixed> */
    private function journal(array $extra = []): array
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        return [
            'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'মাসশেষের সমন্বয়', 'save_as_draft' => '1',
            'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '100']],
            ...$extra,
        ];
    }

    private function leaf(string $code): Account
    {
        $root = StandardChart::find($code);

        return $root->is_group
            ? Account::query()->postable()->whereKey($root->selfAndDescendants()->pluck('id'))->orderBy('code')->firstOrFail()
            : $root;
    }
}

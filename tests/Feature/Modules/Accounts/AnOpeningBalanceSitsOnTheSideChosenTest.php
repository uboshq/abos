<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Imports\OpeningBalanceImporter;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ খোলা জের বসে বাছা পাশে — অঙ্ক ধনাত্মক, পাশে Dr বা Cr (a5-এর ধরা; সমন্বয়ক, ১৩ অক্টোবর ২০২৬)।
 *
 * ⓘ আগে খাতের ফর্মে কেবল একটা সংখ্যা: ধনাত্মক = খাতের স্বাভাবিক দিক, ঋণাত্মক = উল্টো। নিয়মটা কোথাও লেখা ছিল না — দায়ের
 * খাতে "Cr ৫০০০" ভেবে −৫০০০ লিখলে সেটা Dr হয়ে বসত। এখন ট্রায়াল ব্যালেন্সের মতো অঙ্ক আর পাশ; না বাছলে স্বাভাবিক দিক।
 * উল্টো দিক বৈধ — যেমন ব্যাংকের ওভারড্রাফট, এখনকার মতো ব্যাংকের খাতেই Cr (মালিকের উত্তরের অপেক্ষা)।
 */
final class AnOpeningBalanceSitsOnTheSideChosenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_a_liability_opened_on_cr_sits_on_cr_and_on_dr_sits_on_dr(): void
    {
        $this->assertSame(0, bccomp($this->open('2390', 'credit'), '-5000', 4), '⛔ দায়ের খাতে Cr ৫০০০ বাছা হল, অথচ Cr-এ বসেনি');
        $this->assertSame(0, bccomp($this->open('2391', 'debit'), '5000', 4), '⛔ দায়ের খাতে Dr বাছা হল, অথচ Dr-এ বসেনি');
        $this->assertSame(0, bccomp($this->open('2392', null), '-5000', 4), '⛔ পাশ না বাছলে দায়ের স্বাভাবিক দিক (Cr) নয়');

        $this->get(route('accounts.coa.show', Account::query()->where('code', '2390')->firstOrFail()))
            ->assertOk()
            ->assertSee('(Cr) 5,000.00', false);
    }

    public function test_a_bank_can_open_overdrawn_on_cr_and_a_negative_amount_is_refused(): void
    {
        $this->assertSame(0, bccomp($this->open('1102-OD', 'credit', StandardChart::find(StandardChart::BANK)->id), '-5000', 4),
            '⛔ ব্যাংকের ওভারড্রাফট Cr-এ বসেনি');

        $this->from(route('accounts.coa.create'))->post(route('accounts.coa.store'), [
            'code' => '2393', 'name_en' => 'Negative', 'name_bn' => 'ঋণাত্মক', 'parent_id' => $this->liabilityParent(),
            'opening_balance' => '-5000', 'opening_date' => now()->toDateString(),
        ])->assertSessionHasErrors('opening_balance');

        $this->assertNull(Account::query()->where('code', '2393')->first(), '⛔ ঋণাত্মক অঙ্কে খাত তৈরি হল — পাশটা আবার চিহ্নে লুকাল');
    }

    public function test_the_import_takes_an_optional_side(): void
    {
        $bank = Account::query()->create([
            'code' => '1102-IMP', 'name_en' => 'Import bank', 'name_bn' => 'আমদানির ব্যাংক', 'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK, 'is_active' => true,
        ]);
        $importer = app(OpeningBalanceImporter::class);

        $this->assertNotSame([], $importer->check(['account_code' => '1102-IMP', 'opening_balance' => '700', 'side' => 'maybe']), '⛔ অচেনা পাশ মানা হল');

        $row = ['account_code' => '1102-IMP', 'opening_balance' => '700', 'side' => 'Cr'];
        $this->assertSame([], $importer->check($row));
        $importer->import($row);

        $this->assertSame(0, bccomp($this->openingOf($bank), '-700', 4), '⛔ আমদানিতে Cr বলা জের Cr-এ বসেনি');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** পর্দা দিয়ে খাত খোলা, ৫০০০ জেরে — ফেরে খাতায় বসা নিট Dr (Cr হলে ঋণাত্মক) */
    private function open(string $code, ?string $side, ?int $parent = null): string
    {
        $this->post(route('accounts.coa.store'), array_filter([
            'code' => $code, 'name_en' => 'Opening '.$code, 'name_bn' => 'খোলা '.$code, 'parent_id' => $parent ?? $this->liabilityParent(),
            'opening_balance' => '5000', 'opening_side' => $side, 'opening_date' => now()->toDateString(),
        ], fn ($v) => $v !== null))->assertSessionHasNoErrors()->assertRedirect();

        return $this->openingOf(Account::query()->where('code', $code)->firstOrFail());
    }

    private function openingOf(Account $account): string
    {
        return (string) LedgerEntry::query()->where('account_id', $account->id)
            ->where('source_type', OpeningBalanceService::ACCOUNT_SOURCE.OpeningBalanceService::SOURCE_SUFFIX)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as n')->value('n');
    }

    private function liabilityParent(): int
    {
        return (int) StandardChart::find(StandardChart::SALARY_PAYABLE)->parent_id;
    }
}

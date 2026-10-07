<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\YearEndService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * বছর বন্ধ হলো, অথচ তার কোনো কাগজ নেই — ভাউচারের আন্তর্জাতিক পরিকল্পনা, অংশ ৩ঙ (৭ অক্টোবর ২০২৬)।
 *
 * ⭐ সমাপনীর দাখিলা নিজের নম্বর পায় — "YC-<বছর>" ([[YearEndService::closingNumber()]]), নিজের পাতা আর ছাপা, বছরশেষের তালিকা
 * আর খাতার যেকোনো সারি থেকে খোলে ([[YearClosing]])। বছর আবার খুললে উল্টো দাখিলা একই নম্বর বহন করে; আবার বন্ধ করলে "/২"।
 * ⓘ দাখিলার হিসাব একটুও বদলায়নি — কেবল নম্বর আর কাগজ।
 */
final class TheYearClosedWithoutAPaperTest extends TestCase
{
    use RefreshDatabase;

    private FinancialYear $year;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->year = FinancialYear::query()->where('is_current', true)->firstOrFail();
    }

    public function test_the_closing_entry_carries_its_own_number_and_its_reversal_carries_the_same(): void
    {
        $this->trade('50000', '30000');
        app(YearEndService::class)->close($this->year);

        $no = 'YC-'.$this->year->name;
        $this->assertSame([$no], $this->numbers(YearEndService::CLOSE_SOURCE), '⛔ সমাপনী নিজের YC নম্বরে নয়।');

        app(YearEndService::class)->reopen($this->year->fresh(), User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->assertSame([$no], $this->numbers(YearEndService::CLOSE_REVERSAL), '⛔ উল্টো দাখিলা সমাপনীর নম্বর চেনে না।');

        // ⓘ আবার বন্ধ — পরের পালা
        app(YearEndService::class)->close($this->year->fresh());
        $this->assertSame([$no, $no.'/2'], $this->numbers(YearEndService::CLOSE_SOURCE), '⛔ দ্বিতীয় সমাপনী আলাদা নম্বর পেল না।');
        $this->assertSame($no.'/2', app(YearEndService::class)->closingNumberOf($this->year));

        // ⓘ কাগজে তিনটা দাখিলা, নিজের নিজের নম্বরে — বন্ধ, উল্টো, আবার বন্ধ
        $this->assertSame(
            [['close', $no], ['reversal', $no], ['close', $no.'/2']],
            array_map(fn (array $p) => [$p['kind'], $p['document_no']], app(YearEndService::class)->closingPaper($this->year)),
            '⛔ সমাপনীর কাগজে দাখিলাগুলো মিশে গেল।',
        );
    }

    public function test_the_paper_shows_and_prints_every_entry_and_the_list_and_ledger_open_it(): void
    {
        $this->trade('50000', '30000');
        app(YearEndService::class)->close($this->year);
        $no = 'YC-'.$this->year->name;

        $page = $this->get(route('accounts.year_end.closing', $this->year))->assertOk();
        $page->assertSee($no)->assertSee(__('accounts::voucher.closing_voucher'))
            ->assertSee(StandardChart::find(StandardChart::SALES)->code)
            ->assertSee(StandardChart::find(StandardChart::RETAINED_EARNINGS)->code)
            ->assertSee('data-closing-paper="close"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'data-closing-paper='), 'বন্ধ বছরে কেবল একটা দাখিলা।');

        // ⓘ দুই দিক সমান — কাগজের মোট
        $paper = app(YearEndService::class)->closingPaper($this->year)[0];
        $this->assertSame(0, bccomp($paper['debit'], $paper['credit'], 4));
        $this->assertSame(0, bccomp($paper['debit'], '50000', 4), '⛔ কাগজের মোট আয়ের সমান নয়।');

        $print = $this->get(route('accounts.year_end.closing.print', $this->year))->assertOk();
        $this->assertSame('application/pdf', $print->headers->get('Content-Type'));

        $this->get(route('accounts.year_end.index'))->assertOk()->assertSee('data-closing-link', false)->assertSee($no);

        // ⓘ খাতার যেকোনো সারি থেকে — বন্ধ আর উল্টো দুটোই
        $drill = app(DrillResolver::class)->describe(YearEndService::CLOSE_SOURCE, $this->year->id);
        $this->assertSame($no, $drill['document_no']);
        $this->assertSame(['accounts.year_end.closing', ['year' => $this->year->id]], $drill['route']);
        $this->assertNotNull(app(DrillResolver::class)->resolve(YearEndService::CLOSE_REVERSAL, $this->year->id));

        // ⓘ বছর খুললে উল্টোটাও একই পাতায়
        app(YearEndService::class)->reopen($this->year->fresh(), User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->get(route('accounts.year_end.closing', $this->year))->assertOk()->assertSee('data-closing-paper="reversal"', false);
    }

    public function test_a_year_never_closed_has_no_paper_and_the_paper_needs_the_key(): void
    {
        $this->get(route('accounts.year_end.closing', $this->year))->assertNotFound();

        $this->trade('50000', '30000');
        app(YearEndService::class)->close($this->year);

        $clerk = User::factory()->create();
        $clerk->companies()->attach($this->company, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();
        $clerk->givePermissionTo(Permission::findOrCreate('accounts.report', 'web'));

        $this->actingAs($clerk)->get(route('accounts.year_end.closing', $this->year))->assertForbidden();
        $this->actingAs($clerk)->get(route('accounts.year_end.closing.print', $this->year))->assertForbidden();
    }

    /** @return list<string> */
    private function numbers(string $source): array
    {
        return LedgerEntry::query()->where('source_type', $source)->where('source_id', $this->year->id)
            ->orderBy('id')->pluck('document_no')->unique()->values()->map(fn ($n) => (string) $n)->all();
    }

    private function trade(string $income, string $expense): void
    {
        $cash = app(CashTillService::class)->ensurePrimaryTill()->account;
        $date = $this->year->starts_on->copy()->addMonths(3)->toDateString();

        app(PostingEngine::class)->post(sourceType: 'test_sale', sourceId: 1, trxDate: $date, lines: [
            ['account_id' => $cash->id, 'debit' => $income],
            ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => $income],
        ]);
        app(PostingEngine::class)->post(sourceType: 'test_cost', sourceId: 1, trxDate: $date, lines: [
            ['account_id' => StandardChart::find(StandardChart::DISCOUNT_GIVEN)->id, 'debit' => $expense],
            ['account_id' => $cash->id, 'credit' => $expense],
        ]);
    }
}

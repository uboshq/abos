<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\DayBookPaper;
use App\Modules\Accounts\Services\DayBookService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ দৈনিক খতিয়ান — মালিকের অনুমোদিত নমুনা (১৬ নভেম্বর ২০২৬, "ok"; ১৮ নভেম্বর: "এখনই করে লাইভে দাও")।
 *
 * দাবিগুলো: (১) প্রতিটা এন্ট্রি ঠিক জার্নালে — বিক্রয়, নগদ, ব্যাংক, সাধারণ; (২) এন্ট্রি নম্বর জার্নাল ধরে, পোস্টের ক্রমে, অর্থবছরে;
 * (৩) জার্নালের যোগ আর দিনের যোগ খাতার সাথে হুবহু, ডেবিট = ক্রেডিট; (৪) অন্য দিন আর অন্য শাখা আসে না; (৫) পার্টির নামের পাশে
 * পয়েন্ট আর শাখা; (৬) কাগজ ভাগে কোনো এন্ট্রি দুই পাতায় ভাঙে না, পাতার জের পরের পাতার শুরুর জের, আর ছাপা PDF-এর পাতা = ভাগের পাতা;
 * (৭) চাবি ছাড়া দরজা বন্ধ; (৮) দুই ভাষায় কাঁচা চাবি নেই।
 */
final class TheDayBookIsAJournalOnPaperTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-16';

    private Company $company;

    private Branch $a;

    private Branch $b;

    private User $owner;

    private Account $cash;

    private Account $bank;

    private Customer $dealer;

    private int $source = 9100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Carbon::setTestNow(self::DAY.' 21:00:00');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($this->company->id, $this->a->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['locale' => 'en'])->save();
        $this->actingAs($this->owner->fresh());
        app()->setLocale('en');

        app(StandardChart::class)->install();
        $this->cash = app(AccountService::class)->create(['name_en' => 'Day book cash', 'parent_id' => StandardChart::find(StandardChart::CASH_IN_HAND)->id, 'held_by' => $this->owner->id]);
        $this->bank = app(AccountService::class)->create(['name_en' => 'Day book bank', 'parent_id' => StandardChart::find(StandardChart::BANK)->id]);
        $this->dealer = Customer::query()->orderBy('id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_entry_sits_in_its_journal_with_all_its_lines_and_the_totals_agree(): void
    {
        $this->theDay();

        $book = app(DayBookService::class)->build(self::DAY);
        $by = collect($book['journals'])->keyBy('key');

        $this->assertSame(['sales', 'cash', 'bank', 'journal'], array_column($book['journals'], 'key'),
            '⛔ জার্নালগুলো নমুনার ক্রমে নেই (বিক্রয় → নগদ → ব্যাংক → সাধারণ)');
        $this->assertSame(['T-9101', 'T-9104'], array_column($by['sales']['entries'], 'document_no'), '⛔ বিক্রির বিল বিক্রয় জার্নালে নেই');
        $this->assertSame(['T-9102'], array_column($by['cash']['entries'], 'document_no'), '⛔ নগদের রসিদ নগদ জার্নালে নেই');
        $this->assertSame(['T-9103'], array_column($by['bank']['entries'], 'document_no'), '⛔ ব্যাংকের রসিদ ব্যাংক জার্নালে নেই');
        $this->assertSame(['T-9105'], array_column($by['journal']['entries'], 'document_no'), '⛔ জাবেদা সাধারণ জার্নালে নেই');

        // ⓘ বিক্রির এন্ট্রির চার লাইন একসাথে, ডেবিট আগে
        $sale = $by['sales']['entries'][0];
        $this->assertCount(4, $sale['lines'], '⛔ এন্ট্রির সব লাইন একসাথে নেই');
        $this->assertSame(['100000.0000', '80000.0000', '0.0000', '0.0000'], array_column($sale['lines'], 'debit'), '⛔ ডেবিটগুলো আগে নয়');

        $this->assertSame(0, bccomp($by['sales']['debit'], '230000', 2), '⛔ বিক্রয় জার্নালের যোগ ভুল');
        $this->assertSame(0, bccomp($book['debit'], '310000', 2), '⛔ দিনের ডেবিটের যোগ খাতার সাথে মেলে না');
        $this->assertSame(0, bccomp($book['debit'], $book['credit'], 2), '⛔ দিনের ডেবিট আর ক্রেডিট মেলে না');
        $this->assertSame(5, $book['entries']);
    }

    public function test_entry_numbers_run_per_journal_in_posting_order_and_ignore_the_branch_filter(): void
    {
        // ⓘ আগের দিনে একটা বিক্রি — বছরের প্রথম বিক্রয় এন্ট্রি; আজকেরটা দ্বিতীয়
        $this->book('sales_invoice', '2026-11-10', $this->saleLines('50000', '40000'));
        $this->theDay();

        $all = collect(app(DayBookService::class)->build(self::DAY)['journals'])->keyBy('key');
        $this->assertSame(['SAL/2026/00002', 'SAL/2026/00003'], array_column($all['sales']['entries'], 'number'),
            '⛔ বিক্রয়ের এন্ট্রি নম্বর বছরের ক্রমে নয়');
        $this->assertSame(['CSH/2026/00001'], array_column($all['cash']['entries'], 'number'), '⛔ নগদ জার্নালের নম্বর নিজের ক্রমে নয়');

        // ⓘ শাখা বাছলে নম্বর বদলায় না — কেবল অন্য শাখার এন্ট্রি বাদ পড়ে
        $onlyB = collect(app(DayBookService::class)->build(self::DAY, (int) $this->b->id)['journals'])->keyBy('key');
        $this->assertSame(['SAL/2026/00003'], array_column($onlyB['sales']['entries'], 'number'),
            '⛔ শাখা বাছলে অন্য শাখার এন্ট্রি এল, বা নম্বর বদলে গেল');
        $this->assertFalse($onlyB->has('cash'), '⛔ অন্য শাখার নগদ এন্ট্রি এই শাখার খতিয়ানে এল');

        // ⓘ অন্য দিনের এন্ট্রি আসে না
        $this->assertSame(0, app(DayBookService::class)->build('2026-11-15')['entries'], '⛔ অন্য দিনের এন্ট্রি এল');
    }

    public function test_the_party_reads_name_point_and_branch(): void
    {
        $this->theDay();
        $sale = collect(app(DayBookService::class)->build(self::DAY)['journals'])->firstWhere('key', 'sales')['entries'][0];

        $this->assertStringStartsWith((string) $this->dealer->name(), (string) $sale['party'], '⛔ পার্টির নাম নেই');
        $this->assertStringContainsString(' · ', (string) $sale['party'], '⛔ পার্টির নামের পাশে পয়েন্ট/শাখা নেই');
    }

    public function test_the_paper_never_splits_an_entry_and_carries_the_totals_from_page_to_page(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->book('sales_invoice', self::DAY, $this->saleLines('1000', '800'));
        }

        $book = app(DayBookService::class)->build(self::DAY);
        $pages = DayBookPaper::pages($book);
        $this->assertGreaterThan(2, count($pages), 'দৃশ্যটাই ঠিক নয় — ৩০টা এন্ট্রিতে কাগজ দুই পাতার বেশি হওয়ার কথা');

        $seen = 0;
        $running = '0';

        foreach ($pages as $n => $page) {
            if ($n > 0) {
                $this->assertSame($pages[$n - 1]['carry_out'], $page['carry_in'], "⛔ পাতা {$n}-এর শেষের জের পরের পাতার শুরুর জের নয়");
                $this->assertSame(0, bccomp($page['carry_in']['debit'], $running, 2), "⛔ পাতা {$n} পর্যন্ত জের এন্ট্রির যোগ নয়");
            }

            $units = $n > 0 ? 1 : 0;

            foreach ($page['rows'] as $row) {
                $units += $row['units'];

                if ($row['kind'] === 'entry') {
                    $seen++;
                    $running = bcadd($running, $row['entry']['debit'], 4);
                }
            }

            $this->assertLessThanOrEqual($n === 0 ? DayBookPaper::FIRST_PAGE : DayBookPaper::OTHER_PAGES, $units + ($page['carry_out'] ? 1 : 0),
                "⛔ পাতা {$n} বাজেটের চেয়ে বেশি ভরা");
        }

        $this->assertSame($book['entries'], $seen, '⛔ কোনো এন্ট্রি কাগজে বাদ পড়ল বা দুইবার এল');
        $this->assertNull(end($pages)['carry_out'], '⛔ শেষ পাতায় "পরের পৃষ্ঠায় জের"');

        // ⓘ ছাপা PDF-এর পাতা = ভাগের পাতা — mPDF নিজে কোনো বাড়তি পাতা খোলেনি
        $response = $this->get(route('accounts.daybook', ['date' => self::DAY, 'export' => 'pdf']))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame(count($pages), preg_match_all('#/Type\s*/Page[^s]#', (string) $response->getContent()),
            '⛔ ছাপা কাগজের পাতা ভাগের পাতার সমান নয় — একটা পাতা উপচে পড়েছে, জেরের সারি ভুল পাতায়');
    }

    public function test_the_screen_shows_the_day_in_both_languages_without_raw_keys(): void
    {
        $this->theDay();

        foreach (['en' => 'Sales journal', 'bn' => 'বিক্রয় জার্নাল'] as $locale => $heading) {
            $this->owner->forceFill(['locale' => $locale])->save();
            $this->actingAs($this->owner->fresh());
            app()->setLocale($locale);

            $html = (string) $this->get(route('accounts.daybook', ['date' => self::DAY]))->assertOk()->getContent();

            $this->assertStringContainsString($heading, $html, "⛔ ({$locale}) জার্নালের নাম নেই");
            $this->assertStringContainsString('SAL/2026/00001', $html, "⛔ ({$locale}) এন্ট্রি নম্বর নেই");
            $this->assertStringContainsString('3,10,000.00', $html, "⛔ ({$locale}) দিনের যোগ লাখের ধাঁচে নেই");
            $this->assertSame(0, preg_match('/accounts::daybook\.[a-z_.]+/', $html), "⛔ ({$locale}) কাঁচা চাবি");
        }

        // ⓘ ফাঁকা দিন
        $this->get(route('accounts.daybook', ['date' => '2026-11-01']))->assertOk()->assertSee(__('accounts::daybook.empty'));
    }

    public function test_the_door_needs_the_report_key(): void
    {
        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['default_branch_id' => $this->a->id, 'is_active' => true]);
        $this->actingAs($clerk);
        $this->get(route('accounts.daybook'))->assertForbidden();

        $clerk->givePermissionTo(Permission::findOrCreate('accounts.report', 'web'));
        $this->actingAs($clerk->fresh());
        $this->get(route('accounts.daybook'))->assertOk();
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** দিনের পাঁচটা এন্ট্রি: দুইটা বিক্রি (একটা শাখা খ-তে), নগদ রসিদ, ব্যাংক রসিদ, জাবেদা */
    private function theDay(): void
    {
        $this->book('sales_invoice', self::DAY, $this->saleLines('100000', '80000'));              // T-9101
        $this->book('receipt_voucher', self::DAY, [                                                  // T-9102
            ['account_id' => $this->cash->id, 'debit' => '20000', 'branch_id' => $this->a->id],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => '20000',
                'party_type' => 'customer', 'party_id' => $this->dealer->id, 'branch_id' => $this->a->id],
        ]);
        $this->book('receipt_voucher', self::DAY, [                                                  // T-9103
            ['account_id' => $this->bank->id, 'debit' => '50000', 'branch_id' => $this->a->id],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => '50000',
                'party_type' => 'customer', 'party_id' => $this->dealer->id, 'branch_id' => $this->a->id],
        ]);
        $this->book('sales_invoice', self::DAY, $this->saleLines('30000', '20000', $this->b));       // T-9104
        $this->book('journal_voucher', self::DAY, [                                                  // T-9105
            ['account_id' => StandardChart::find(StandardChart::BANK_CHARGES)->id, 'debit' => '10000', 'branch_id' => $this->a->id],
            ['account_id' => StandardChart::find(StandardChart::INTEREST_PAYABLE)->id, 'credit' => '10000', 'branch_id' => $this->a->id],
        ]);
    }

    /** @return list<array<string, mixed>> বিক্রির চার লাইন: পাওনা/বিক্রি, খরচ/মজুদ */
    private function saleLines(string $amount, string $cost, ?Branch $branch = null): array
    {
        $branch ??= $this->a;

        return [
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => $amount,
                'party_type' => 'customer', 'party_id' => $this->dealer->id, 'branch_id' => $branch->id],
            ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => $amount, 'branch_id' => $branch->id],
            ['account_id' => StandardChart::find(StandardChart::COST_OF_GOODS_SOLD)->id, 'debit' => $cost, 'branch_id' => $branch->id],
            ['account_id' => StandardChart::find(StandardChart::INVENTORY)->id, 'credit' => $cost, 'branch_id' => $branch->id],
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function book(string $type, string $date, array $lines): int
    {
        $id = ++$this->source;
        app(PostingEngine::class)->post($type, $id, $date, $lines, 'T-'.$id, $this->a->id);

        return $id;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\ImportRunner;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\BankStatementService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * ব্যাংক এমন কিছু জানত যা আমাদের বইয়ে কোনোদিন ওঠেনি — মানচিত্র §৯।
 *
 * ── কী পাহারা দেওয়া হচ্ছে ───────────────────────────────────────────
 * মিলকরণের পর্দা এতদিন কেবল এক দিক দেখাত: আমাদের কোন সারি ব্যাংকে
 * ওঠেনি। ⚠️ কিন্তু মাস শেষে তফাত থেকে যাওয়ার আসল কারণ উল্টো দিকটা —
 * সার্ভিস চার্জ, সুদ, ফেরত আসা চেক, যেগুলো কেউ বইয়ে তোলেনি কারণ কেউ
 * জানতই না ঘটেছে।
 *
 * ⛔ আর এই ফাইলের সবচেয়ে জরুরি পরীক্ষাগুলো সুখের পথের নয়: ব্যাংকের
 * ফাইল নোংরা আসে, আর নোংরা ঘরেই ভুলগুলো লুকায়।
 */
final class TheBankKnewThingsTheBooksHadNeverHeardTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $head = Account::query()->where('code', StandardChart::BANK)->firstOrFail();

        $this->bank = Account::query()->create([
            'parent_id' => $head->id,
            'code' => '1102-01',
            'name_en' => 'City Bank Current',
            'name_bn' => 'সিটি ব্যাংক চলতি',
            'type' => $head->type,
            'nature' => $head->nature,
            'is_group' => false,
            'money_kind' => Account::BANK,
            'is_active' => true,
        ]);
    }

    /**
     * ⭐ ব্যাংকের ফাইল ওঠে, আর যা আমাদের বইয়ে নেই সেটাই পড়ে থাকে।
     */
    public function test_the_statement_loads_and_what_we_never_booked_stays_visible(): void
    {
        $result = app(ImportRunner::class)->run('bank_statement', $this->csv([
            ['1102-01', '03/09/2026', 'SERVICE CHARGE SEP', '', '230.00', '', '48,770.00'],
            ['1102-01', '05/09/2026', 'CHEQUE 4411 PAID', '4411', '5,000.00', '', '43,770.00'],
            ['1102-01', '30/09/2026', 'INTEREST CREDIT', '', '', '112.50', '43,882.50'],
        ]));

        $this->assertSame(3, $result['imported'], 'তিনটা সারির সবগুলো ওঠেনি: '.json_encode($result['failed']));
        $this->assertSame([], $result['failed']);

        $lines = BankStatementLine::query()->orderBy('trx_date')->get();

        $this->assertCount(3, $lines);

        // ⛔ ০৩/০৯ মানে ৩ সেপ্টেম্বর — কোনোদিনই ৯ মার্চ নয়
        $this->assertSame('2026-09-03', $lines->first()->trx_date->toDateString(),
            'দিন-মাস উল্টে গেছে — আমেরিকান ছকে পড়া হচ্ছে।');

        // ⭐ কমা সরিয়ে সংখ্যাটা সত্যিই সংখ্যা হয়েছে
        $this->assertSame('5000.0000', $lines->firstWhere('reference', '4411')->debit);

        $this->assertSame('112.5000', $lines->last()->credit);

        // ⭐ তিনটাই এখনো "ব্যাংক জানে, আমরা জানি না" — বইয়ে কিছুই বসেনি
        $this->assertCount(3, app(BankStatementService::class)
            ->unmatchedFor($this->bank, '2026-09-30'));

        // ⛔ আর ইমপোর্ট থেকে একটাও দাখিলা বইয়ে যায়নি
        $this->assertSame(0, Voucher::query()->count(),
            'স্টেটমেন্ট তোলা থেকে ভাউচার বসেছে — ব্যাংকের কথা আমাদের কথা হয়ে গেছে।');
    }

    /**
     * ⛔ একই ফাইল দুইবার তুললে সারি দ্বিগুণ হয় না।
     *
     * ⓘ মানুষ প্রায়ই আগের মাসসহ গোটা ফাইলটা আবার নামান — সেটা ভুল নয়,
     * তাই ভুল বলে ফেরানোও হয় না। কেবল নতুন সারিগুলো বসে।
     */
    public function test_the_same_file_twice_does_not_double_the_lines(): void
    {
        $rows = [
            ['1102-01', '03/09/2026', 'SERVICE CHARGE SEP', '', '230.00', '', ''],
            ['1102-01', '05/09/2026', 'ATM WITHDRAWAL', '', '2,000.00', '', ''],
        ];

        app(ImportRunner::class)->run('bank_statement', $this->csv($rows));
        app(ImportRunner::class)->run('bank_statement', $this->csv($rows));

        $this->assertSame(2, BankStatementLine::query()->count(),
            'একই ফাইল দুইবার তুলে সারি দ্বিগুণ হয়েছে।');
    }

    /**
     * ⭐ একই দিনে একই অঙ্কের দুইটা **সত্যিকারের** সারি দুইটাই থাকে।
     *
     * ⚠️ এটাই ছাপ বসানোর আসল ফাঁদ: দুইবার ৫০০ টাকা তোলা হতেই পারে, আর
     * ওটাকে "একই সারি" ধরে ফেললে ব্যাংকের হিসাব থেকে টাকা হারিয়ে যেত।
     */
    public function test_two_real_lines_on_one_day_are_two_lines(): void
    {
        app(ImportRunner::class)->run('bank_statement', $this->csv([
            ['1102-01', '07/09/2026', 'ATM WITHDRAWAL', '', '500.00', '', ''],
            ['1102-01', '07/09/2026', 'ATM WITHDRAWAL', '', '500.00', '', ''],
        ]));

        $this->assertSame(2, BankStatementLine::query()->count(),
            'একই দিনের দুইটা আসল লেনদেন এক হয়ে গেছে — ব্যাংকের হিসাব থেকে টাকা হারাল।');
    }

    /**
     * ⛔ নোংরা সারিগুলো ধরা পড়ে, আর বাকিগুলো তবু ওঠে।
     */
    public function test_the_dirty_lines_are_named_and_the_rest_still_load(): void
    {
        $result = app(ImportRunner::class)->run('bank_statement', $this->csv([
            ['1102-01', '03/09/2026', 'GOOD LINE', '', '100.00', '', ''],
            ['9999-99', '03/09/2026', 'NO SUCH ACCOUNT', '', '100.00', '', ''],
            ['1102-01', 'পহেলা সেপ্টেম্বর', 'BAD DATE', '', '100.00', '', ''],
            ['1102-01', '04/09/2026', 'TOTALS ROW', '', '', '', '48,770.00'],
            ['1102-01', '05/09/2026', 'BOTH SIDES', '', '100.00', '100.00', ''],
        ]));

        $this->assertSame(1, $result['imported'], 'ভালো সারিটাও আটকে গেছে।');
        $this->assertCount(4, $result['failed'], 'চারটা নোংরা সারির সবগুলো ধরা পড়েনি।');

        $said = implode(' · ', array_column($result['failed'], 'error'));

        // ⓘ ভুলটা কী, সেটা বলা হয় — "ইমপোর্ট ব্যর্থ" বলে থামা হয় না
        $this->assertStringContainsString('9999-99', $said);
        $this->assertStringContainsString('পহেলা সেপ্টেম্বর', $said);
    }

    /**
     * ⭐ বইয়ে ভাউচার বসালে ব্যাংকের সারিটা নিজে থেকেই মিলে যায়।
     *
     * ⚠️ দিকটা উল্টো, আর সেটাই সবচেয়ে সহজ ভুল: ব্যাংক টাকা **নিলে**
     * (ব্যাংকের ডেবিট) আমাদের খাতায় ব্যাংক খাতটা **ক্রেডিট** হয়।
     */
    public function test_once_we_book_it_the_bank_line_matches_itself(): void
    {
        app(ImportRunner::class)->run('bank_statement', $this->csv([
            ['1102-01', '03/09/2026', 'SERVICE CHARGE SEP', '', '230.00', '', ''],
        ]));

        $charge = Account::query()->where('code', StandardChart::BANK_CHARGES)->postable()->firstOrFail();
        $vouchers = app(VoucherService::class);

        $vouchers->post($vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => '2026-09-03',
             'narration' => 'ব্যাংক চার্জ', 'instrument_no' => 'SC-SEP'],
            [
                ['account_id' => $charge->id, 'debit' => '230', 'credit' => '0'],
                ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '230'],
            ],
        ));

        $matched = app(BankStatementService::class)->matchAgainstBooks($this->bank, '2026-09-30');

        $this->assertSame(1, $matched, 'ভাউচার বসানোর পরেও ব্যাংকের সারিটা মেলেনি।');

        $this->assertCount(0, app(BankStatementService::class)
            ->unmatchedFor($this->bank, '2026-09-30'));
    }

    /**
     * ⛔ দুইটা প্রার্থী থাকলে কোনোটাই মেলানো হয় না।
     *
     * ⓘ যন্ত্রের পক্ষে বলা অসম্ভব কোন সারি কোনটা, আর ভুল জোড়া বসলে সেটা
     * কেউ কোনোদিন খুঁজে পেত না। ⭐ তার চেয়ে মানুষটাকে দুইটাই দেখানো ভালো।
     */
    public function test_two_possible_matches_means_no_match(): void
    {
        app(ImportRunner::class)->run('bank_statement', $this->csv([
            ['1102-01', '07/09/2026', 'ATM WITHDRAWAL', '', '500.00', '', ''],
        ]));

        $charge = Account::query()->where('code', StandardChart::BANK_CHARGES)->postable()->firstOrFail();
        $vouchers = app(VoucherService::class);

        // ⓘ হুবহু একই অঙ্কের দুইটা সারি, একই দিনে
        foreach (['A', 'B'] as $n) {
            $vouchers->post($vouchers->create(
                ['type' => Voucher::JOURNAL, 'trx_date' => '2026-09-07',
                 'narration' => 'তোলা '.$n, 'instrument_no' => 'W-'.$n],
                [
                    ['account_id' => $charge->id, 'debit' => '500', 'credit' => '0'],
                    ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '500'],
                ],
            ));
        }

        $this->assertSame(0, app(BankStatementService::class)->matchAgainstBooks($this->bank, '2026-09-30'),
            'দুইটা প্রার্থী থাকা সত্ত্বেও একটা বেছে নেওয়া হয়েছে।');
    }

    /**
     * ⭐ হিসাবরক্ষকের নিজের দরজা — প্রশাসকের ইমপোর্ট পর্দায় যেতে হয় না।
     */
    public function test_the_accountant_can_load_it_from_the_reconciliation_screen(): void
    {
        $recon = app(\App\Modules\Accounts\Services\BankReconciliationService::class)->open([
            'bank_account_id' => $this->bank->id,
            'statement_date' => '2026-09-30',
            'statement_balance' => '43882.50',
        ]);

        $this->post(route('accounts.reconciliation.statement', $recon), [
            'file' => $this->csv([
                ['1102-01', '03/09/2026', 'SERVICE CHARGE SEP', '', '230.00', '', ''],
            ]),
        ])->assertRedirect();

        $this->assertSame(1, BankStatementLine::query()->count());

        // ⭐ আর পর্দায় সারিটা দেখা যায়, ব্যাংকের লেখা হুবহু
        $this->get(route('accounts.reconciliation.show', $recon))
            ->assertOk()
            ->assertSee('SERVICE CHARGE SEP');
    }

    /**
     * ব্যাংকের ছকের একটা ফাইল — শিরোনামসহ।
     *
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows): UploadedFile
    {
        $out = "account_code,trx_date,description,reference,debit,credit,balance\n";

        foreach ($rows as $row) {
            /* ⓘ কমাওয়ালা সংখ্যা উদ্ধৃতিতে — ব্যাংকের ফাইলেও ঠিক তাই */
            $out .= implode(',', array_map(
                fn ($cell) => str_contains($cell, ',') ? '"'.$cell.'"' : $cell,
                $row,
            ))."\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'stmt').'.csv';
        file_put_contents($path, $out);

        return new UploadedFile($path, 'statement.csv', 'text/csv', null, true);
    }
}

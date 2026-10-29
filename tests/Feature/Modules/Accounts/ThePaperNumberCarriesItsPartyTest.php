<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Reports\ChequeReports;
use App\Modules\Accounts\Services\AccountLedger;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherParties;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * কাগজের নম্বরের পাশে পক্ষ — "নাম · পয়েন্ট"।
 *
 * ⭐ মালিক, ১৯ অক্টোবর ২০২৬: *"অনেক জায়গায় তালিকায় শুধু ভাউচার নম্বর দেওয়া। সেখানে নম্বরের পাশে গ্রাহকের নাম আর পয়েন্টের নাম
 * দিলে সহজে বোঝা যায়।"*
 *
 * ⓘ আসল কাগজ, সেবা দিয়ে লেখা: পয়েন্টে বসা গ্রাহকের আদায়, সরবরাহকারীকে পরিশোধ, গ্রাহকের চেক, আর পক্ষ ছাড়া একটা জাবেদা।
 * প্রতিটা পর্দা/রিপোর্টে নম্বর আর পক্ষ **একই সারিতে**; সরবরাহকারীর বেলায় তাঁর নাম; পক্ষহীন জাবেদায় বাড়তি কিছু নয়।
 * ⚠️ নাম আসে গোটা পাতার একবারে — পঁচিশ সারিতেও গ্রাহকের টেবিলে প্রশ্ন দুইটার বেশি নয়।
 */
final class ThePaperNumberCarriesItsPartyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $customer;

    private Supplier $supplier;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        $this->till = app(CashTillService::class)->ensurePrimaryTill()->account;

        $point = Location::query()->where('level', Location::POINT)->first();
        $this->assertNotNull($point, '⛔ প্রস্তুতি — ডেমোতে কোনো পয়েন্ট নেই, "নাম · পয়েন্ট" দেখানোর মতো গ্রাহক বানানো গেল না।');

        // ⓘ দেখার শাখার গ্রাহক — অন্য শাখার পক্ষে ভাউচার লেখা যায় না
        $this->customer = Customer::query()->inViewedBranch()->firstOrFail();
        $this->customer->forceFill(['location_id' => $point->id])->save();
        $this->supplier = Supplier::query()->firstOrFail();
    }

    /** ⭐ আদায়ের নম্বরের পাশে "নাম · পয়েন্ট" — সব ভাউচারের তালিকায়, একই সারিতে */
    public function test_a_collection_shows_the_customer_and_the_point_beside_its_number(): void
    {
        $receipt = $this->receipt($this->customer, '1000');
        $label = $this->customerLabel();

        $this->assertStringContainsString(' · ', $label, '⛔ প্রস্তুতি — গ্রাহকের নামের পাশে পয়েন্ট আসেনি।');

        $html = $this->get(route('accounts.voucher.list', ['tab' => Voucher::RECEIPT]))->assertOk()->getContent();

        $this->assertStringContainsString(e($label), $this->rowOf($html, $receipt->document_no),
            '⛔ আদায়ের নম্বরের সারিতে গ্রাহকের নাম আর পয়েন্ট নেই।');
    }

    /** ⭐ সরবরাহকারীর পরিশোধে তাঁর নাম; ⛔ পক্ষহীন জাবেদায় বাড়তি কিছু নয় */
    public function test_a_supplier_payment_shows_the_supplier_and_a_journal_without_a_party_shows_nothing_more(): void
    {
        $this->receipt($this->customer, '1000');
        $payment = $this->payment('200');
        $journal = $this->journal('50');

        $supplierLabel = app(PartyRegistry::class)->labelsOf([['supplier', $this->supplier->id]])['supplier:'.$this->supplier->id];

        $html = $this->get(route('accounts.voucher.list', ['tab' => Voucher::PAYMENT]))->assertOk()->getContent();
        $this->assertStringContainsString(e($supplierLabel), $this->rowOf($html, $payment->document_no),
            '⛔ সরবরাহকারীর পরিশোধের সারিতে তাঁর নাম নেই।');

        $html = $this->get(route('accounts.voucher.list', ['tab' => Voucher::JOURNAL]))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-party', $this->rowOf($html, $journal->document_no),
            '⛔ পক্ষ ছাড়া জাবেদার পাশে কারও নাম বসেছে।');
    }

    /** ⭐ চেকের খাতায় চেক নম্বরের পাশে পক্ষ */
    public function test_the_cheque_book_shows_the_party_beside_the_cheque_number(): void
    {
        $cheque = $this->cheque();

        $html = $this->get(route('accounts.cheque.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e($this->customerLabel()), $this->rowOf($html, $cheque->cheque_no),
            '⛔ চেকের সারিতে পক্ষের নাম নেই।');
    }

    /**
     * ⭐ খাতের খাতা — ক্যাশের সারিতে পক্ষ নেই, তবু কাগজের গ্রাহক বসে; ওয়েব আর ফোন একই নাম।
     */
    public function test_the_cash_ledger_names_the_customer_on_the_web_and_on_the_phone(): void
    {
        $receipt = $this->receipt($this->customer, '1000');
        $label = $this->customerLabel();

        $html = $this->get(route('accounts.coa.show', $this->till))->assertOk()->getContent();
        $this->assertStringContainsString(e($label), $this->rowOf($html, $receipt->document_no),
            '⛔ ক্যাশের খাতায় আদায়ের সারিতে গ্রাহক নেই — টাকার সারির কাগজে ফিরে দেখা হয়নি।');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $phone = $this->getJson('/api/v1/accounts/chart/'.$this->till->public_id.'/ledger')->assertOk();

        $row = collect($phone->json('entries'))->firstWhere('document_no', $receipt->document_no);
        $this->assertNotNull($row, '⛔ প্রস্তুতি — ফোনের খাতায় আদায়ের সারি নেই।');
        $this->assertSame($label, $row['party'] ?? null, '⛔ ফোনের খাতার সারিতে পক্ষ ওয়েবের মতো নয়।');
    }

    /**
     * ⭐ রিপোর্ট — দৈনিক খতিয়ান, আদায়ের তালিকা, নগদ বই আর চেকের খাতায় "পক্ষ" কলাম; ফোনের রিপোর্টেও একই ঘর।
     */
    public function test_the_reports_carry_a_party_column_filled_from_the_paper(): void
    {
        $receipt = $this->receipt($this->customer, '1000');
        $journal = $this->journal('50');
        $cheque = $this->cheque();
        $label = $this->customerLabel();
        $today = ['from' => now()->toDateString(), 'to' => now()->toDateString()];
        $engine = app(ReportEngine::class);

        foreach (['accounts.day_book', 'accounts.inflow', 'accounts.cash_book'] as $key) {
            $rows = collect($engine->run($key, $today, perPage: 500)->rows);
            $mine = $rows->where('document_no', $receipt->document_no);

            $this->assertNotEmpty($mine, "⛔ প্রস্তুতি — {$key}-এ আদায়ের সারি নেই।");
            $this->assertSame([$label], $mine->pluck('party')->unique()->values()->all(),
                "⛔ {$key}: আদায়ের প্রতিটা সারিতে গ্রাহক নেই (টাকার সারিতেও থাকার কথা)।");

            foreach ($rows->where('document_no', $journal->document_no) as $row) {
                $this->assertSame('', $row['party'], "⛔ {$key}: পক্ষ ছাড়া জাবেদায় কারও নাম বসেছে।");
            }
        }

        $chequeRow = collect($engine->run(ChequeReports::KEY, $today, perPage: 500)->rows)->firstWhere('cheque_no', $cheque->cheque_no);
        $this->assertSame($label, $chequeRow['party'] ?? null, '⛔ চেকের খাতার রিপোর্টে পক্ষ নেই।');

        // ⓘ পক্ষের ঘর খোঁজার কলাম নয় — SQL-এ ঐ নামের ঘর নেই, খুঁজলে রিপোর্ট ভাঙত
        $this->assertNotContains('party', $engine->get('accounts.day_book')->searchableColumns());
        $engine->run('accounts.day_book', $today + ['q' => $receipt->document_no], perPage: 50);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $phone = $this->getJson('/api/v1/reports/accounts.day_book?'.http_build_query($today + ['perPage' => 200]))->assertOk();

        $this->assertContains('party', array_column($phone->json('columns'), 'key'), '⛔ ফোনের রিপোর্টে পক্ষের কলাম নেই।');
        $this->assertContains($label, array_column($phone->json('rows'), 'party'), '⛔ ফোনের রিপোর্টে আদায়ের সারিতে গ্রাহক নেই।');
    }

    /**
     * ⭐ পঁচিশটা আদায়, পঁচিশ সারি — গ্রাহকের নাম গোটা পাতার একবারে; ⛔ সারি প্রতি একটা প্রশ্ন নয় (N+1)।
     */
    public function test_twenty_five_collections_name_their_customers_in_a_bounded_number_of_queries(): void
    {
        $customers = Customer::query()->inViewedBranch()->limit(25)->get();
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });

        /*
         * ⓘ এক সারির পাতা আগে মাপা — পাতার বাকি অংশ (মেনু, নোটিশ) নিজেও গ্রাহকের টেবিল ছুঁতে পারে; দাবিটা নামের প্রশ্নের, তাই
         * পঁচিশ সারিতে প্রশ্ন এক সারির চেয়ে বাড়া চলে না, আর নামের প্রশ্ন নিজে দুইটার বেশি নয়।
         */
        $this->receipt($customers[0], '10');
        $sql = [];
        $this->get(route('accounts.voucher.list', ['tab' => Voucher::RECEIPT]))->assertOk();
        $one = $this->customerQueries($sql);

        for ($i = 1; $i < 25; $i++) {
            $this->receipt($customers[$i % $customers->count()], '10');
        }

        $sql = [];
        $page = $this->get(route('accounts.voucher.list', ['tab' => Voucher::RECEIPT]))->assertOk();
        $this->assertGreaterThanOrEqual(25, count($page->viewData('vouchers')->items()), '⛔ প্রস্তুতি — পাতায় পঁচিশটা আদায় নেই।');
        $this->assertLessThanOrEqual($one, $this->customerQueries($sql),
            "⛔ সব ভাউচারের তালিকা: এক সারিতে গ্রাহকের প্রশ্ন {$one}টা, পঁচিশে বেশি — নাম সারি ধরে আসছে:".PHP_EOL
            .implode(PHP_EOL, $this->customerSql($sql)));

        // ⓘ নামের প্রশ্ন নিজে — পাতার বাইরে, সরাসরি; গোটা পঁচিশটার জন্য দুইটার বেশি নয়
        $sql = [];
        app(VoucherParties::class)->of(Voucher::query()->where('type', Voucher::RECEIPT)->get());
        $this->assertLessThanOrEqual(2, $this->customerQueries($sql),
            '⛔ ভাউচারের পক্ষ সারি ধরে আসছে:'.PHP_EOL.implode(PHP_EOL, $this->customerSql($sql)));

        $sql = [];
        app(ReportEngine::class)->run('accounts.day_book', ['from' => now()->toDateString(), 'to' => now()->toDateString()], perPage: 500);
        $this->assertLessThanOrEqual(2, $this->customerQueries($sql),
            '⛔ দৈনিক খতিয়ান গ্রাহকের নাম সারি ধরে আনছে:'.PHP_EOL.implode(PHP_EOL, $this->customerSql($sql)));

        $sql = [];
        app(AccountLedger::class)->page($this->till, 1);
        $this->assertLessThanOrEqual(2, $this->customerQueries($sql),
            '⛔ ক্যাশের খাতা গ্রাহকের নাম সারি ধরে আনছে:'.PHP_EOL.implode(PHP_EOL, $this->customerSql($sql)));
    }

    /**
     * ⭐ পক্ষ কেবল পর্দার জন্য — তালিকার ফাইলে (CSV, JSON) নম্বরের ঘর কেবল নম্বর; পর্দার সারিতে তবু "নাম · পয়েন্ট"।
     *
     * ⓘ নম্বর ধরে খোঁজা আর মেলানো ফাইলেই হয় — ঘরে নাম জুড়লে সেটা ভাঙত ([[ListExport]], `data-party-beside`)।
     * ⓘ রিপোর্টের "পক্ষ" কলাম আলাদা কথা: সেটা নিজের কলাম, ফাইলে নামসহ যায় ([[ReportExport]] সারির মান পড়ে, Blade নয়)।
     */
    public function test_the_list_files_keep_the_number_alone_while_the_screen_shows_the_party(): void
    {
        $receipt = $this->receipt($this->customer, '1000');
        $cheque = $this->cheque();
        $expense = $this->expenseFor('রহিম মিস্ত্রি');
        $label = $this->customerLabel();

        // ⓘ পর্দা আগের মতো — নম্বরের সারিতে নাম আর পয়েন্ট
        $html = $this->get(route('accounts.voucher.list', ['tab' => Voucher::RECEIPT]))->assertOk()->getContent();
        $this->assertStringContainsString(e($label), $this->rowOf($html, $receipt->document_no), '⛔ পর্দার সারিতে পক্ষ নেই।');

        // সব ভাউচার — নম্বরের ঘর হুবহু নম্বর
        $json = $this->get(route('accounts.voucher.list', ['tab' => Voucher::RECEIPT, 'export' => 'json']))->assertOk()->json();
        $row = collect($json['rows'] ?? [])->first(fn (array $r) => str_contains((string) ($r['document_no'] ?? ''), $receipt->document_no));
        $this->assertNotNull($row, '⛔ প্রস্তুতি — ফাইলে আদায়ের সারি নেই।');
        $this->assertSame($receipt->document_no, $row['document_no'], '⛔ JSON-এ নম্বরের ঘরে পক্ষের নামও চলে গেছে।');

        $line = $this->csvLineWith($this->csvRows(route('accounts.voucher.list', ['tab' => Voucher::RECEIPT, 'export' => 'csv'])), $receipt->document_no);
        $this->assertContains($receipt->document_no, $line, '⛔ CSV-তে নম্বরের ঘর কেবল নম্বর নয়।');
        $this->assertStringNotContainsString($label, implode('|', $line), '⛔ CSV-তে নম্বরের সাথে পক্ষের নাম চলে গেছে।');

        // চেকের খাতা — নম্বরের ঘর নম্বর দিয়ে শুরু (নিচের দিকের লেখা আগের মতোই থাকে), পক্ষ নয়
        $html = $this->get(route('accounts.cheque.index'))->assertOk()->getContent();
        $this->assertStringContainsString(e($label), $this->rowOf($html, $cheque->cheque_no), '⛔ চেকের পর্দার সারিতে পক্ষ নেই।');

        $json = $this->get(route('accounts.cheque.index', ['export' => 'json']))->assertOk()->json();
        $row = collect($json['rows'] ?? [])->first(fn (array $r) => str_contains((string) ($r['cheque_no'] ?? ''), $cheque->cheque_no));
        $this->assertNotNull($row, '⛔ প্রস্তুতি — ফাইলে চেকের সারি নেই।');
        $this->assertStringStartsWith($cheque->cheque_no, $row['cheque_no']);
        $this->assertStringNotContainsString('·', $row['cheque_no'], '⛔ চেকের ফাইলে নম্বরের ঘরে পক্ষ চলে গেছে।');
        $this->assertStringNotContainsString($label, $row['cheque_no']);

        $line = $this->csvLineWith($this->csvRows(route('accounts.cheque.index', ['export' => 'csv'])), $cheque->cheque_no);
        $this->assertStringNotContainsString($label, implode('|', $line), '⛔ চেকের CSV-তে পক্ষের নাম চলে গেছে।');

        /*
         * খাতভিত্তিক খরচ — পর্দায় খরচের নম্বরের পাশে প্রাপক। ⓘ ফাইল ধরে পাতার **প্রথম** টেবিল (খাত ধরে যোগ), ভাউচারের সারি নয়
         * ([[ListExport::capture()]]) — তাই ফাইলে নম্বরের পাশের প্রাপক কোথাও নেই।
         */
        $html = $this->get(route('finance.expense.index'))->assertOk()->getContent();
        $this->assertStringContainsString('রহিম মিস্ত্রি', $this->rowOf($html, $expense->document_no), '⛔ খরচের পর্দার সারিতে প্রাপক নেই।');

        $file = (string) $this->get(route('finance.expense.index', ['export' => 'csv']))->assertOk()->getContent();
        $this->assertStringNotContainsString('· রহিম মিস্ত্রি', $file, '⛔ খরচের CSV-তে নম্বরের পাশের প্রাপক চলে গেছে।');

        // ⓘ রিপোর্টের পক্ষ-কলাম ফাইলে নামসহ — নিজের কলাম, নম্বরের ঘরের অংশ নয়
        $report = (string) $this->get(route('accounts.report.show', [
            'slug' => 'day-book', 'from' => now()->toDateString(), 'to' => now()->toDateString(), 'export' => 'csv',
        ]))->assertOk()->getContent();
        $this->assertStringContainsString($label, $report, '⛔ দৈনিক খতিয়ানের ফাইলে পক্ষের কলাম খালি।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function expenseFor(string $payee): Voucher
    {
        return app(VoucherService::class)->create(
            ['type' => Voucher::EXPENSE, 'trx_date' => now()->toDateString(), 'payee_name' => $payee, 'narration' => 'মেরামত'],
            [
                ['account_id' => StandardChart::find(StandardChart::ENTERTAINMENT)->id, 'debit' => '300'],
                ['account_id' => $this->till->id, 'credit' => '300'],
            ],
        );
    }

    /** @return list<list<string>> */
    private function csvRows(string $url): array
    {
        $body = ltrim((string) $this->get($url)->assertOk()->getContent(), "\xEF\xBB\xBF");

        return array_values(array_map(
            fn (string $line) => str_getcsv($line),
            array_filter(preg_split('/\r\n|\n/', $body) ?: [], fn (string $l) => $l !== ''),
        ));
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    private function csvLineWith(array $rows, string $needle): array
    {
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if (str_contains((string) $cell, $needle)) {
                    return $row;
                }
            }
        }

        $this->fail("⛔ প্রস্তুতি — CSV-তে '{$needle}' নেই।");
    }

    private function customerLabel(): string
    {
        return app(PartyRegistry::class)->labelsOf([['customer', $this->customer->id]])['customer:'.$this->customer->id];
    }

    private function receipt(Customer $customer, string $amount): Voucher
    {
        $service = app(VoucherService::class);

        return $service->post($service->create(
            ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(),
                'party_type' => 'customer', 'party_id' => $customer->id, 'narration' => 'আদায়'],
            [
                ['account_id' => $this->till->id, 'debit' => $amount],
                ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id,
                    'party_type' => 'customer', 'party_id' => $customer->id, 'credit' => $amount],
            ],
        ));
    }

    private function payment(string $amount): Voucher
    {
        $service = app(VoucherService::class);

        return $service->post($service->create(
            ['type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(),
                'party_type' => 'supplier', 'party_id' => $this->supplier->id, 'narration' => 'পরিশোধ'],
            [
                ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id,
                    'party_type' => 'supplier', 'party_id' => $this->supplier->id, 'debit' => $amount],
                ['account_id' => $this->till->id, 'credit' => $amount],
            ],
        ));
    }

    private function journal(string $amount): Voucher
    {
        $service = app(VoucherService::class);

        return $service->post($service->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'আপ্যায়ন'],
            [
                ['account_id' => StandardChart::find(StandardChart::ENTERTAINMENT)->id, 'debit' => $amount],
                ['account_id' => $this->till->id, 'credit' => $amount],
            ],
        ));
    }

    private function cheque(): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => Cheque::RECEIVED, 'cheque_no' => 'PN'.random_int(100000, 999999), 'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->toDateString(), 'amount' => '5000',
            'party_type' => 'customer', 'party_id' => $this->customer->id,
        ]);
    }

    /** পাতার যে টেবিল-সারিতে লেখাটা আছে, তার পুরো HTML */
    private function rowOf(string $html, string $needle): string
    {
        $at = strpos($html, $needle);
        $this->assertNotFalse($at, "⛔ প্রস্তুতি — পাতায় '{$needle}' নেই।");

        $start = strrpos(substr($html, 0, $at), '<tr');
        $end = strpos($html, '</tr>', $at);
        $this->assertNotFalse($start, "⛔ '{$needle}' কোনো টেবিল-সারিতে নেই।");

        return substr($html, (int) $start, ($end === false ? strlen($html) : $end) - (int) $start);
    }

    /** @param  list<string>  $sql */
    private function customerQueries(array $sql): int
    {
        return count($this->customerSql($sql));
    }

    /**
     * @param  list<string>  $sql
     * @return list<string>
     */
    private function customerSql(array $sql): array
    {
        $table = (new Customer)->getTable();

        return array_values(array_filter($sql, fn (string $q) => preg_match('/from\s+[`"]?'.preg_quote($table, '/').'[`"]?(\s|$)/i', $q) === 1));
    }
}

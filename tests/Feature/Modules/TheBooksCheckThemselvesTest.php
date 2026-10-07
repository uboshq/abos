<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Integrity\IntegrityRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Integrity\AccountsChecks;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Integrity\SalesChecks;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * খাতা নিজেই মেলে কি না — যাচাইটা সত্যিই ধরে কি না।
 *
 * ── কেন এই পরীক্ষাগুলোর ছক আলাদা ────────────────────────────────────
 * সাধারণ পরীক্ষা দেখায় কোডটা ঠিক কাজ করে। এখানে প্রমাণ করতে হয় উল্টোটা:
 * **খাতা ভাঙলে যাচাইটা ধরতে পারে**। তাই প্রতিটা পরীক্ষা আগে দেখে সব
 * সবুজ, তারপর হাতে একটা গরমিল বসিয়ে দেখে যাচাইটা লাল হয়।
 *
 * এই ধাপটা ছাড়া পরীক্ষাগুলো "সব সবুজ" দাবি করে পাশ করত — আর একটা
 * যাচাই **না চলা** আর তার **পাশ করা** পর্দায় দেখতে হুবহু এক।
 */
class TheBooksCheckThemselvesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
    }

    private function sell(string $rate = '100'): SalesInvoice
    {
        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '2', 'rate' => $rate]],
        );

        return app(SalesInvoiceService::class)->confirm($invoice);
    }

    // ── সুস্থ খাতা ──────────────────────────────────────────────────

    /**
     * স্বাভাবিক কাজের পর সব যাচাই মিলে যায়।
     *
     * এটাই ভিত্তি: যদি সুস্থ খাতাতেও কোনো যাচাই লাল হয়, তবে যাচাইটাই
     * ভুল, আর তখন পর্দাটা মানুষ পড়া বন্ধ করে দেবে।
     */
    public function test_a_healthy_set_of_books_passes_every_check(): void
    {
        $this->sell();

        foreach (app(IntegrityRegistry::class)->all() as $key => $check) {
            $findings = $check->run();

            $this->assertSame([], $findings,
                "সুস্থ খাতাতেও '{$key}' গরমিল বলছে: ".implode(' · ', array_map(
                    fn ($f) => $f->what.' — '.$f->detail, $findings,
                )));
        }
    }

    // ── প্রতিটা যাচাই তার নিজের ভাঙাটা ধরে ─────────────────────────

    /** রেওয়ামিল না মিললে ধরা পড়ে। */
    public function test_it_catches_a_ledger_that_does_not_balance(): void
    {
        $this->sell();

        $this->assertSame([], AccountsChecks::trialBalance()->run());

        // একটা সারির ডেবিট বাড়িয়ে দেওয়া — ঠিক যেভাবে হাতে চালানো
        // SQL বা আধেক লেখা ট্রানজেকশন খাতা ভাঙে
        LedgerEntry::query()->where('debit', '>', 0)->limit(1)
            ->update(['debit' => DB::raw('debit + 50')]);

        $findings = AccountsChecks::trialBalance()->run();

        $this->assertCount(1, $findings, 'রেওয়ামিলের গরমিলটা ধরা পড়েনি।');
        $this->assertStringContainsString('50', $findings[0]->detail);
    }

    /**
     * একটা কাগজ না মিললে ধরা পড়ে — যদিও মোট যোগফল মেলে।
     *
     * ── এটাই সবচেয়ে জরুরি পরীক্ষা ───────────────────────────────────
     * দুইটা ভাঙা দাখিলা একে অন্যকে ঢেকে দেয়: একটায় ৫০ বেশি ডেবিট,
     * আরেকটায় ৫০ বেশি ক্রেডিট। রেওয়ামিল নিখুঁত সবুজ, অথচ দুইটা
     * কাগজেরই অঙ্ক ভুল — আর গোটা খাতার যোগফল দেখে সেটা কোনোদিন ধরা
     * পড়ত না।
     */
    public function test_it_catches_two_broken_postings_that_cover_for_each_other(): void
    {
        $first = $this->sell('100');
        $second = $this->sell('200');

        LedgerEntry::query()
            ->where('source_type', SalesInvoice::drillSourceType())
            ->where('source_id', $first->id)
            ->where('debit', '>', 0)->limit(1)
            ->update(['debit' => DB::raw('debit + 50')]);

        LedgerEntry::query()
            ->where('source_type', SalesInvoice::drillSourceType())
            ->where('source_id', $second->id)
            ->where('credit', '>', 0)->limit(1)
            ->update(['credit' => DB::raw('credit + 50')]);

        // গোটা খাতা মেলে — এই লাইনটাই দেখায় কেন দ্বিতীয় যাচাইটা লাগে
        $this->assertSame([], AccountsChecks::trialBalance()->run(),
            'পরীক্ষার ছকটাই ভুল: এখানে রেওয়ামিলের মেলার কথা।');

        $findings = AccountsChecks::everyDocumentBalances()->run();

        $this->assertCount(2, $findings, 'একে অন্যকে ঢেকে দেওয়া দুইটা ভাঙা দাখিলা ধরা পড়েনি।');
    }

    /** বিলের মোট লাইনের সাথে না মিললে ধরা পড়ে। */
    public function test_it_catches_an_invoice_that_disagrees_with_its_lines(): void
    {
        $invoice = $this->sell();

        $this->assertSame([], SalesChecks::invoiceMatchesItsLines()->run());

        DB::table('sal_invoices')->where('id', $invoice->id)
            ->update(['total' => DB::raw('total + 25')]);

        $findings = SalesChecks::invoiceMatchesItsLines()->run();

        $this->assertCount(1, $findings, 'বিল আর তার লাইনের গরমিল ধরা পড়েনি।');
        $this->assertSame($invoice->document_no, $findings[0]->what);
        $this->assertTrue($findings[0]->isDrillable(), 'সারিটা থেকে বিলটায় যাওয়ার পথ নেই।');
    }

    /**
     * দাখিলাহীন নিশ্চিত বিল ধরা পড়ে।
     *
     * মাল বেরিয়ে গেছে, কাগজ ছাপা হয়েছে, অথচ আয় ও প্রাপ্য কোথাও বসেনি।
     * বিক্রয়ের তালিকায় বিলটা ঠিকঠাক দেখায়, আর রেওয়ামিলও মেলে — কারণ
     * কিছুই তো বসেনি।
     */
    public function test_it_catches_a_confirmed_invoice_with_no_posting(): void
    {
        $invoice = $this->sell();

        $this->assertSame([], SalesChecks::confirmedInvoicesReachedTheLedger()->run());

        LedgerEntry::query()
            ->where('source_type', SalesInvoice::drillSourceType())
            ->where('source_id', $invoice->id)
            ->delete();

        $findings = SalesChecks::confirmedInvoicesReachedTheLedger()->run();

        $this->assertCount(1, $findings, 'দাখিলাহীন বিলটা ধরা পড়েনি।');
        $this->assertSame($invoice->document_no, $findings[0]->what);
    }

    /** খাতহীন খতিয়ান সারি ধরা পড়ে। */
    public function test_it_catches_an_entry_whose_account_is_gone(): void
    {
        $this->sell();

        $this->assertSame([], AccountsChecks::everyEntryHasAnAccount()->run());

        LedgerEntry::query()->limit(1)->update(['account_id' => 999999]);

        $this->assertCount(1, AccountsChecks::everyEntryHasAnAccount()->run(),
            'যে খাতটা নেই, সেই সারিটা ধরা পড়েনি।');
    }

    // ── পর্দা ───────────────────────────────────────────────────────

    /** পর্দাটা খোলে, আর কী কী দেখা হয়েছে তা বলে। */
    public function test_the_screen_names_what_it_checked(): void
    {
        $this->sell();

        $this->get(route('accounts.integrity'))
            ->assertOk()
            ->assertSee(__('accounts::integrity.trial_balance'))
            ->assertSee(__('sales::integrity.invoice_total'))
            ->assertSee(__('accounts::message.check_passed'));
    }

    /**
     * ভাঙা থাকলে পর্দাটা কোন কাগজে, তা বলে।
     *
     * "৩টা বিলে গরমিল" বললে কাজ এগোয় না — কোন তিনটা, সেটাই তো প্রশ্ন।
     */
    public function test_the_screen_names_the_document_that_is_wrong(): void
    {
        $invoice = $this->sell();

        DB::table('sal_invoices')->where('id', $invoice->id)
            ->update(['total' => DB::raw('total + 25')]);

        $this->get(route('accounts.integrity'))
            ->assertOk()
            ->assertSee($invoice->document_no)
            ->assertSee(__('sales::integrity.invoice_total_broken'));
    }

    // ── অনুমতি ──────────────────────────────────────────────────────

    /**
     * প্রতিটা যাচাই নিজের চাবি ঘোষণা করে।
     *
     * ভাঙা কাগজের তালিকা মানে কোন বিলে কত গরমিল — সেটা সবার দেখার
     * জিনিস নয়। চাবি ছাড়া একটা যাচাই তৈরিই করা যায় না।
     */
    public function test_every_check_declares_a_permission(): void
    {
        $checks = app(IntegrityRegistry::class)->all();

        $this->assertNotSame([], $checks, 'কোনো মডিউলই কোনো যাচাই ঘোষণা করেনি।');

        foreach ($checks as $key => $check) {
            $this->assertNotSame('', trim($check->permission), "'{$key}'-এর কোনো চাবি নেই।");
            $this->assertStringContainsString('.', $key);
            $this->assertNotSame('', trim($check->whenBroken), "'{$key}' বলে না ভাঙলে কী হয়।");
        }
    }
}

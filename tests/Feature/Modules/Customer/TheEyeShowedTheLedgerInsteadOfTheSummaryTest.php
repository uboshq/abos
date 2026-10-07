<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * তালিকার 👁 খাতা খুলত, সারাংশ নয় — মালিকের নির্দেশ, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * *"👁 চাপলে এক নজরে: এই মাসের ও সারা জীবনের বিল, বকেয়া, কয়টা বিল বাকি
 * ও তাতে কয়টা পণ্য, শেষ কেনা, শেষ জমা, অবশিষ্ট সীমা। খাতা কেবল বকেয়ার
 * লিংকে।"*
 *
 * ── ⓘ হাতে গোনা অঙ্ক ─────────────────────────────────────────────────
 * মিনিকেট চাল ৩,৫৫০ দরে, ভ্যাট ও ছাড় নেই। নতুন গ্রাহক, সীমা ১,০০,০০০।
 *   ক  গত মাস (মাস শুরুর ৫ দিন আগে)  ১০ বস্তা = ৩৫,৫০০  জমা ০
 *   খ  গত মাস (মাস শুরুর ৩ দিন আগে)   ২ বস্তা =  ৭,১০০  জমা ৭,১০০ (পুরো শোধ)
 *   আদায় ১ গত মাস (মাস শুরুর ২ দিন আগে) ১০,০০০ → বিল ক-এর বিপরীতে
 *   গ  আজ                             ৪ বস্তা = ১৪,২০০  জমা ০
 *   আদায় ২ আজ                        ৫,০০০ → বিল গ-এর বিপরীতে
 *
 *   এই মাসে  ১টা বিল, ১৪,২০০.০০
 *   সব মিলিয়ে ৩টা বিল, ৩৫,৫০০ + ৭,১০০ + ১৪,২০০ = ৫৬,৮০০.০০
 *   বকেয়া   ৫৬,৮০০ − ৭,১০০ − ১০,০০০ − ৫,০০০ = ৩৪,৭০০.০০
 *   বকেয়া বিল ২টা (ক: ২৫,৫০০, গ: ৯,২০০; খ শোধ), তাতে পণ্যের সারি ১ + ১ = ২
 *   শেষ কেনা আজ, ১৪,২০০.০০ · শেষ জমা আজ, ৫,০০০.০০ (আদায় ২ — খ-এর
 *            ৭,১০০ বড় কিন্তু পুরনো, তাই "শেষ" নয়)
 *   অবশিষ্ট সীমা ১,০০,০০০ − ৩৪,৭০০ − আটকে থাকা ০ = ৬৫,৩০০.০০
 */
final class TheEyeShowedTheLedgerInsteadOfTheSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $dealer;

    private Warehouse $warehouse;

    private Product $rice;

    private SalesInvoice $billA;

    private SalesInvoice $billC;

    private Collection $lastCollection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->set('customer.zero_limit_blocks', false);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->rice = Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail();

        // ⓘ নতুন গ্রাহক শূন্য সীমায় জন্মায় — তারপর সীমা সরাসরি
        $dealer = app(CustomerService::class)->create([
            'name_en' => 'Glance Traders',
            'name_bn' => 'এক নজর ট্রেডার্স',
            'credit_limit' => '0',
            'credit_days' => 30,
        ]);
        $dealer->forceFill(['credit_limit' => '100000'])->save();
        $this->dealer = $dealer->fresh();

        $monthStart = Carbon::today()->startOfMonth();

        $this->billA = $this->sell(10, '0', $monthStart->copy()->subDays(5)->toDateString());
        $this->sell(2, '7100', $monthStart->copy()->subDays(3)->toDateString());
        $this->collect($this->billA, '10000', $monthStart->copy()->subDays(2)->toDateString());
        $this->billC = $this->sell(4, '0', Carbon::today()->toDateString());
        $this->lastCollection = $this->collect($this->billC, '5000', Carbon::today()->toDateString());
    }

    // ── ১ · প্রতিটা অঙ্ক হাতের গোনায় ─────────────────────────────────────

    public function test_the_summary_shows_every_figure_as_hand_counted(): void
    {
        $html = $this->get(route('customer.summary', $this->dealer))->assertOk()->getContent();

        $today = DateFormat::format(Carbon::today()->toDateString());

        $expected = [
            'month-count' => __('customer::summary.invoice_count', ['count' => 1]),
            'month-amount' => '14,200.00',
            'lifetime-count' => __('customer::summary.invoice_count', ['count' => 3]),
            'lifetime-amount' => '56,800.00',
            'outstanding' => '34,700.00',
            'pending-count' => '2',
            'pending-items' => '2',
            'last-purchase-date' => $today,
            'last-purchase-amount' => '14,200.00',
            'last-payment-date' => $today,
            'last-payment-amount' => '5,000.00',
            'available-credit' => '65,300.00',
            'credit-limit' => '1,00,000.00',
        ];

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $this->figureOn($html, $key), "⛔ সারাংশের '{$key}' হাতের গোনা নয়।");
        }

        // ⓘ অঙ্কগুলো তাদের উৎসে যায় — শেষ কেনা বিলে, শেষ জমা আদায়ে
        $this->assertSame(route('sales.invoice.show', $this->billC), $this->hrefOf($html, 'last-purchase-amount'));
        $this->assertSame(route('sales.collection.show', $this->lastCollection), $this->hrefOf($html, 'last-payment-amount'));
    }

    /** ⛔ বকেয়া খাতার সাথে এক — তালিকার ও খাতার পাতার একই উৎস। */
    public function test_the_outstanding_equals_the_ledger_and_is_the_only_door_to_it(): void
    {
        $ledgerNet = (string) DB::table('ledger_entries')
            ->where('company_id', $this->company->id)
            ->where('party_type', Customer::drillSourceType())
            ->where('party_id', $this->dealer->id)
            ->selectRaw('SUM(debit) - SUM(credit) AS net')
            ->value('net');

        $this->assertSame(0, bccomp('34700', $ledgerNet, 4), 'দৃশ্যটাই বানানো যায়নি — খাতার বকেয়া ৩৪,৭০০ নয়।');

        $html = $this->get(route('customer.summary', $this->dealer))->assertOk()->getContent();

        $this->assertSame(route('customer.show', $this->dealer).'#transactions', $this->hrefOf($html, 'outstanding'),
            '⛔ বকেয়ার অঙ্কটা খাতায় নিয়ে যায় না।');

        // ⛔ খাতার টেবিল এখানে নয় — না সেকশন, না কোনো দাখিলার নম্বর
        $this->assertStringNotContainsString('id="transactions"', $html, '⛔ সারাংশে খাতার টেবিল আঁকা হয়েছে।');
        $this->assertStringNotContainsString((string) $this->billA->document_no, $html, '⛔ সারাংশে খাতার সারি এসেছে।');
        $this->assertStringNotContainsString((string) $this->lastCollection->document_no, $html, '⛔ সারাংশে খাতার সারি এসেছে।');
    }

    /** ⛔ অবশিষ্ট সীমা কাউন্টারের পর্দার হুবহু — একই গ্রাহক, একই মুহূর্ত। */
    public function test_the_available_credit_equals_the_direct_sale_screens_figure(): void
    {
        $terms = $this->get(route('sales.direct.create'))->assertOk()->viewData('customerTerms')[$this->dealer->id] ?? null;

        $this->assertNotNull($terms, 'দৃশ্যটাই বানানো যায়নি — কাউন্টারের তালিকায় গ্রাহকটা নেই।');

        // ⓘ কাউন্টারের খালি কার্টে `creditLeft = limit − due − held`, পর্দায় শূন্যের নিচে নামে না
        $left = max(0.0, $terms['limit'] - $terms['due'] - $terms['held']);
        $counter = \App\Core\Support\Money::format((string) $left);

        $this->assertSame('65,300.00', $counter, 'দৃশ্যটাই বানানো যায়নি — কাউন্টারের অবশিষ্ট সীমা হাতের গোনা নয়।');

        $html = $this->get(route('customer.summary', $this->dealer))->assertOk()->getContent();
        $this->assertSame($counter, $this->figureOn($html, 'available-credit'), '⛔ সারাংশ আর কাউন্টার দুই সংখ্যা বলে।');
    }

    // ── ২ · তালিকার 👁 সারাংশে যায় ──────────────────────────────────────

    public function test_the_eye_in_the_list_opens_the_summary_not_the_ledger(): void
    {
        $html = $this->get(route('customer.index', ['q' => $this->dealer->code]))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<a href="([^"]+)" data-eye/', $html, $m), '⛔ তালিকায় 👁 পাওয়া যায়নি।');
        $this->assertSame(route('customer.summary', $this->dealer), html_entity_decode($m[1]), '⛔ 👁 এখনো সারাংশে যায় না।');
    }

    // ── ৩ · দরজা — একই মানুষ, চাবি বন্ধ তারপর খোলা ─────────────────────

    public function test_the_same_user_is_refused_without_the_view_key_and_let_in_with_it(): void
    {
        $this->assertTrue(Permission::query()->where('name', 'customer.view')->exists(), "⛔ 'customer.view' চাবিটাই নেই।");

        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id);
        $user->givePermissionTo(Permission::query()->where('name', '<>', 'customer.view')->get());
        $user = $user->fresh();

        $this->assertFalse($user->can('customer.view'), 'প্রস্তুতিটাই ভুল — চাবি হাতে আছে।');
        $this->actingAs($user)->get(route('customer.summary', $this->dealer))->assertForbidden();

        $user->givePermissionTo('customer.view');
        $user = $user->fresh();

        $html = $this->actingAs($user)->get(route('customer.summary', $this->dealer))->assertOk()->getContent();
        $this->assertSame('34,700.00', $this->figureOn($html, 'outstanding'));
    }

    /** ⛔ অন্য কোম্পানির গ্রাহক — আছে কি না তাও বলা হয় না। */
    public function test_a_customer_outside_the_users_scope_is_not_found(): void
    {
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($beta->id, $beta->defaultBranch()?->id);

        $foreign = Customer::query()->create([
            'code' => 'FOREIGN-GL',
            'name_en' => 'Foreign Glance',
            'name_bn' => 'অন্যের দোকান',
            'credit_limit' => 0,
            'credit_days' => 0,
            'is_active' => true,
        ]);

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->assertSame($beta->id, (int) $foreign->company_id, 'প্রস্তুতিটাই ভুল — গ্রাহকটা অন্য কোম্পানির নয়।');

        $this->get(route('customer.summary', $foreign->id))->assertNotFound();
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function sell(int $bags, string $deposit, string $on): SalesInvoice
    {
        $sale = app(DirectSaleService::class)->complete(
            [
                'customer_id' => $this->dealer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => $on,
                'deposit' => $deposit,
            ],
            [['product_id' => $this->rice->id, 'qty' => (string) $bags, 'rate' => '3550']],
        );

        $invoice = $sale['invoice']->fresh();

        $this->assertContains($invoice->status, DocumentStatus::POSTED, 'দৃশ্যটাই বানানো যায়নি — বিলটা খাতায় বসেনি।');
        $this->assertSame(0, bccomp(bcmul((string) $bags, '3550', 4), (string) $invoice->total, 4),
            'দৃশ্যটাই বানানো যায়নি — বিলের মোট হাতের গোনা নয় (ভ্যাট বা ছাড় বসেছে)।');
        $this->assertSame($on, $invoice->trx_date->toDateString(), 'দৃশ্যটাই বানানো যায়নি — বিলের তারিখ বদলে গেছে।');

        return $invoice;
    }

    private function collect(SalesInvoice $against, string $amount, string $on): Collection
    {
        $service = app(CollectionService::class);

        $collection = $service->confirm($service->create([
            'customer_id' => $this->dealer->id,
            'trx_date' => $on,
            'amount' => $amount,
        ], [['sales_invoice_id' => $against->id, 'amount' => $amount]]));

        $this->assertContains($collection->fresh()->status, DocumentStatus::POSTED, 'দৃশ্যটাই বানানো যায়নি — আদায়টা খাতায় বসেনি।');

        return $collection->fresh();
    }

    /** `data-figure` ঘরটার লেখা — পাতার অন্য কোথাও একই সংখ্যা থাকলেও ভুল ঘরে মেলে না। */
    private function figureOn(string $html, string $key): string
    {
        $found = preg_match('/data-figure="'.preg_quote($key, '/').'"[^>]*>([^<]*)</u', $html, $m);

        $this->assertSame(1, $found, "⛔ সারাংশে '{$key}' ঘরটাই নেই।");

        return trim(html_entity_decode($m[1]));
    }

    private function hrefOf(string $html, string $key): string
    {
        $found = preg_match('/<a href="([^"]+)"[^>]*data-figure="'.preg_quote($key, '/').'"/u', $html, $m);

        $this->assertSame(1, $found, "⛔ সারাংশে '{$key}' লিংক নয়।");

        return html_entity_decode($m[1]);
    }
}

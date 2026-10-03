<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase\Direct;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * সরাসরি ক্রয় — টাকা যে পথেই যাক, পাঁচটা অঙ্ক মেলে কি না।
 *
 * ── ⭐ চেকলিস্ট §২, ঘর ১–৪ ─────────────────────────────────────────────
 *   ১. নগদে কেনা — টাকা নগদ বাক্স থেকে
 *   ২. বাকিতে কেনা — দেনা বসে, পরে পরিশোধে কমে
 *   ৩. ব্যাংক বা বিকাশে পরিশোধ, চার্জসহ
 *   ৪. আংশিক "এখনই দেওয়া", বাকিটা দেনা
 *
 * ── ⛔ কেন সারি গোনা নয়, অঙ্ক ─────────────────────────────────────────
 * মালিকের কথা: *"business zehetu asol hisab tai gormil holeei bipod"*।
 * একটা সারি বসলেই পরীক্ষা সবুজ হলে ভুল অঙ্কের সারিও সবুজ হত। ⭐ তাই
 * প্রতিটা দাবির অঙ্ক এখানে **হাতে গোনা** (দর × পরিমাণ, মন্তব্যে লেখা), আর
 * খাতা, মজুদ, সরবরাহকারী, টাকার খাত আর লাভ — পাঁচটাই সেই অঙ্কের সাথে মেলে।
 *
 * ⓘ ডেমোর কোম্পানিতে আগে থেকেই লেনদেন আছে, তাই প্রতিটা মাপ **আগে-পরে
 * পার্থক্য**: লেনদেনের আগে একটা ছবি, পরে আরেকটা, আর মাঝের নড়াচড়াটাই
 * হাতে গোনা অঙ্কের সাথে মেলানো হয়।
 */
final class DirectPurchasePaysEveryWayTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

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

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        // ⓘ লট বা সিরিয়াল ধরা পণ্যে বাড়তি ঘর লাগে — এই ঘরগুলোর প্রশ্ন টাকা, লট নয়
        $this->product = Product::query()
            ->where('track_batch', false)
            ->where('track_serial', false)
            ->orderBy('id')
            ->firstOrFail();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ১ · নগদে কেনা
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⭐ নগদ বাক্স থেকে পুরো দাম — দেনা বসে আর সাথে সাথেই মোছে।
     *
     * হাতে গোনা: ১২ × ৫৭.২৫ = ৬৮৭.০০
     *   মজুদ ১১২০   ডেবিট ৬৮৭
     *   দেনা ২১১১   ক্রেডিট ৬৮৭ (বিল) · ডেবিট ৬৮৭ (পরিশোধ)
     *   নগদ বাক্স   ক্রেডিট ৬৮৭
     *
     * ⛔ কেন দুইটা দেনার সারি, একটাও বাদ নয়: বিলটা সরবরাহকারীর খাতায় দেনা
     * হিসেবে জন্মায়, আর পরিশোধ সেটা মোছে। কেউ "নগদে কিনলাম তো দেনার সারি
     * কেন" ভেবে ছেঁটে দিলে বিলের পাশে "শোধ" লেখা থাকত না।
     */
    public function test_a_cash_purchase_empties_the_cash_box_by_the_bill_and_leaves_no_debt(): void
    {
        $till = $this->tillAccount();
        $this->fund($till, '1000');
        $before = $this->snapshot();

        $result = $this->buy(
            [['product_id' => $this->product->id, 'qty' => '12', 'rate' => '57.25', 'sales_price' => '57.25', 'tax' => '0']],
            ['deposits' => [$this->row('CASH', $till, '687')]],
        );

        $this->assertSame(0, bccomp((string) $result['bill']->total, '687', 4),
            'বিলের মোট ১২ × ৫৭.২৫ = ৬৮৭.০০ হওয়ার কথা।');

        $this->assertFiveMatches($before, 'নগদে কেনা', [
            'moves' => [
                $this->acc(StandardChart::INVENTORY) => ['687', '0'],
                $this->acc(StandardChart::PAYABLE) => ['687', '687'],
                $till => ['0', '687'],
            ],
            'qty' => '12',
            'layers' => [['12', '57.25']],
            'value' => '687',
            'supplier' => '0',
            'money' => [$till => '-687'],
            'profit' => '0',
        ]);

        $this->assertSame(0, bccomp($result['bill']->fresh()->dueAmount(), '0', 4),
            'পুরো দাম নগদে দেওয়ার পরেও বিলে বাকি দেখাচ্ছে।');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ২ · বাকিতে কেনা, পরে পরিশোধ
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⭐ বাকিতে দুই চালান, দুই দরে — তারপর ব্যাংক থেকে আংশিক পরিশোধ।
     *
     * হাতে গোনা:
     *   চালান ক: ১০ × ৬০.০০ = ৬০০.০০  → দেনা +৬০০
     *   চালান খ:  ৫ × ৬৪.৫০ = ৩২২.৫০  → দেনা +৩২২.৫০ (মোট ৯২২.৫০)
     *   পরিশোধ:  ৪০০ ব্যাংক থেকে, চালান ক-এর বিপরীতে → দেনা ৫২২.৫০
     *            চালান ক-এর বাকি ২০০, চালান খ-এর ৩২২.৫০
     *
     * ⓘ দুই দর ইচ্ছাকৃত: FIFO মানে প্রতিটা চালান নিজের স্তর — ৬০ আর
     * ৬৪.৫০ গড় হয়ে ৬১.৫০ হয়ে গেলে পরে বিক্রির খরচ ভুল বসত।
     */
    public function test_a_credit_purchase_raises_the_debt_and_a_later_payment_lowers_it(): void
    {
        $bank = $this->bankAccount();
        $start = $this->snapshot();

        // ── চালান ক — বাকিতে ─────────────────────────────────────────
        $before = $this->snapshot();
        $first = $this->buy([['product_id' => $this->product->id, 'qty' => '10', 'rate' => '60', 'sales_price' => '60', 'tax' => '0']])['bill'];

        $this->assertFiveMatches($before, 'বাকিতে কেনা — চালান ক', [
            'moves' => [
                $this->acc(StandardChart::INVENTORY) => ['600', '0'],
                $this->acc(StandardChart::PAYABLE) => ['0', '600'],
            ],
            'qty' => '10',
            'layers' => [['10', '60']],
            'value' => '600',
            'supplier' => '600',
            'money' => [],
            'profit' => '0',
        ]);

        // ── চালান খ — বাকিতে, অন্য দরে ──────────────────────────────
        $before = $this->snapshot();
        $second = $this->buy([['product_id' => $this->product->id, 'qty' => '5', 'rate' => '64.50', 'sales_price' => '64.50', 'tax' => '0']])['bill'];

        $this->assertFiveMatches($before, 'বাকিতে কেনা — চালান খ', [
            'moves' => [
                $this->acc(StandardChart::INVENTORY) => ['322.50', '0'],
                $this->acc(StandardChart::PAYABLE) => ['0', '322.50'],
            ],
            'qty' => '5',
            'layers' => [['5', '64.50']],
            'value' => '322.50',
            'supplier' => '322.50',
            'money' => [],
            'profit' => '0',
        ]);

        // ── পরে পরিশোধ — সরবরাহকারীর পরিশোধের পথে, ব্যাংক থেকে ──────
        $before = $this->snapshot();
        $payments = app(PaymentService::class);
        $payment = $payments->create([
            'supplier_id' => $this->supplier->id,
            'account_id' => $bank,
            'trx_date' => now()->toDateString(),
            'amount' => '400',
            'instrument' => 'transfer',
            'instrument_no' => 'NPSB-400',
        ], [['purchase_bill_id' => $first->id, 'amount' => '400']]);
        $payments->confirm($payment);

        $this->assertFiveMatches($before, 'বাকির পরিশোধ', [
            'moves' => [
                $this->acc(StandardChart::PAYABLE) => ['400', '0'],
                $bank => ['0', '400'],
            ],
            'qty' => '0',
            'layers' => [],
            'value' => '0',
            'supplier' => '-400',
            'money' => [$bank => '-400'],
            'profit' => '0',
        ]);

        /*
         * ⭐ শুরু থেকে গোটা পথের যোগফল — ধাপে ধাপে মিলেও মোটে না মিললে
         * কোথাও একটা ধাপ দুইবার গোনা হয়েছে।
         */
        $this->assertMoney('522.50', bcsub($this->supplier->fresh()->payable(), $start['supplier'], 4),
            'দুই চালান (৬০০ + ৩২২.৫০) − পরিশোধ ৪০০ = ৫২২.৫০ সরবরাহকারীর কাছে বাকি থাকার কথা।');
        $this->assertMoney('922.50', bcsub($this->costs()->valueOnHand($this->product), $start['layer_value'], 4),
            'দুই স্তরের মূল্য ৬০০ + ৩২২.৫০ = ৯২২.৫০ — পরিশোধে মজুদের মূল্য নড়ার কথা নয়।');
        $this->assertMoney('200', $first->fresh()->dueAmount(),
            'চালান ক: ৬০০ − ৪০০ = ২০০ বাকি থাকার কথা — পরিশোধটা ঠিক বিলে বসেনি।');
        $this->assertMoney('322.50', $second->fresh()->dueAmount(),
            'চালান খ-এর বিপরীতে কিছু দেওয়া হয়নি, তবু তার বাকি বদলেছে।');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ৩ · ব্যাংক বা বিকাশে পরিশোধ — দরজা, আর চার্জ
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⭐ আসল দরজা দিয়ে, একই মানুষ: চাবি নেই → ৪০৩ আর কিছুই বসে না;
     * চাবি দিলে → বসে, আর পাঁচ মিল।
     *
     * ⛔ কেন একই মানুষ: দুইজন আলাদা মানুষ হলে ৪০৩-টা কোম্পানির সদস্যপদ
     * বা অন্য কোনো সুইচ থেকেও আসতে পারত — তখন দাবিটা চাবির কথা বলত না।
     *
     * হাতে গোনা: ২০ × ৪৫.৩৫ = ৯০৭.০০, পুরোটা ব্যাংক ট্রান্সফারে।
     */
    public function test_the_counter_door_opens_only_with_the_key_and_a_bank_payment_matches(): void
    {
        $bank = $this->bankAccount();
        $clerk = $this->roleLessClerk();

        $payload = [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'MILL-'.fake()->unique()->numberBetween(10000, 99999),
            'lines' => [['product_id' => $this->product->id, 'qty' => '20', 'rate' => '45.35', 'sales_price' => '45.35', 'tax' => '0']],
            'deposits' => [$this->row('BANK', $bank, '907', 'NPSB-907')],
        ];

        // ── চাবি নেই ──────────────────────────────────────────────────
        $before = $this->snapshot();
        $bills = PurchaseBill::query()->count();

        $this->actingAs($clerk)->post(route('purchase.direct.store'), $payload)->assertForbidden();

        $this->assertSame($bills, PurchaseBill::query()->count(), 'চাবি ছাড়া ডাকে একটা বিল বসে গেছে।');
        $this->assertSame($before['ledger_max'], (int) LedgerEntry::query()->max('id'),
            'চাবি ছাড়া ডাকে খাতায় সারি বসে গেছে।');
        $this->assertSame($before['layer_max'], (int) CostLayer::query()->max('id'),
            'চাবি ছাড়া ডাকে মজুদের স্তর বসে গেছে।');

        // ── একই মানুষ, চাবি দেওয়া হলো ───────────────────────────────
        $clerk->givePermissionTo(Permission::findOrCreate('purchase.bill.create', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($clerk->fresh())->post(route('purchase.direct.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame($bills + 1, PurchaseBill::query()->count(), 'চাবি দেওয়ার পরেও বিল বসেনি।');

        $this->assertFiveMatches($before, 'দরজা দিয়ে ব্যাংকে কেনা', [
            'moves' => [
                $this->acc(StandardChart::INVENTORY) => ['907', '0'],
                $this->acc(StandardChart::PAYABLE) => ['907', '907'],
                $bank => ['0', '907'],
            ],
            'qty' => '20',
            'layers' => [['20', '45.35']],
            'value' => '907',
            'supplier' => '0',
            'money' => [$bank => '-907'],
            'profit' => '0',
        ]);
    }

    /**
     * ⭐ বিকাশে পরিশোধ, চার্জসহ — চার্জ খরচের খাতে, দেনা পুরোটাই মোছে।
     *
     * হাতে গোনা: ৮ × ১১২.৫০ = ৯০০.০০ · বিকাশ ফি ৫.০০ (আমাদের ঘাড়ে)
     *   মজুদ ১১২০        ডেবিট ৯০০
     *   দেনা ২১১১        ক্রেডিট ৯০০ · ডেবিট ৯০০ — মিল পুরো ৯০০ পেয়েছে
     *   বিকাশ চার্জ ৫২১১  ডেবিট ৫
     *   বিকাশ খাত        ক্রেডিট ৯০৫ — অ্যাপে যা কাটল
     *
     * ⛔ চার্জ না লিখলে বিকাশের খাত ৯০০ কমত অথচ অ্যাপে কমেছে ৯০৫ — মাস শেষে
     * ৫ টাকা করে অমিল, আর *"বিকাশে বছরে কত গেল"* প্রশ্নের উত্তর শূন্য।
     * ⓘ চার্জের খাত বাছার নিয়ম [[VoucherService::withCharge()]]-এর: MFS → ৫২১১।
     */
    public function test_a_bkash_payment_books_its_charge_as_an_expense(): void
    {
        $bkash = $this->bkashAccount();
        $this->fund($bkash, '1000');
        $before = $this->snapshot();

        $this->buy(
            [['product_id' => $this->product->id, 'qty' => '8', 'rate' => '112.50', 'sales_price' => '112.50', 'tax' => '0']],
            ['deposits' => [$this->row('MFS', $bkash, '900', 'TRX8BK900', ['charge_amount' => '5', 'charge_borne_by' => 'us'])]],
        );

        $this->assertFiveMatches($before, 'বিকাশে কেনা, চার্জসহ', [
            'moves' => [
                $this->acc(StandardChart::INVENTORY) => ['900', '0'],
                $this->acc(StandardChart::PAYABLE) => ['900', '900'],
                $this->acc(StandardChart::MFS_CHARGES) => ['5', '0'],
                $bkash => ['0', '905'],
            ],
            'qty' => '8',
            'layers' => [['8', '112.50']],
            'value' => '900',
            'supplier' => '0',
            'money' => [$bkash => '-905'],
            'profit' => '-5',
        ]);
    }

    /**
     * ⭐ ব্যাংক ট্রান্সফারে পরিশোধ, চার্জসহ — চার্জ ব্যাংক চার্জের খাতে।
     *
     * হাতে গোনা: ১৫ × ৭০.০০ = ১,০৫০.০০ · ট্রান্সফার ফি ১১.৫০ (আমাদের ঘাড়ে)
     *   মজুদ ১১২০          ডেবিট ১,০৫০
     *   দেনা ২১১১          ক্রেডিট ১,০৫০ · ডেবিট ১,০৫০
     *   ব্যাংক চার্জ ৫২১০   ডেবিট ১১.৫০
     *   ব্যাংক খাত          ক্রেডিট ১,০৬১.৫০ — বিবরণীতে যা কাটল
     *
     * ⛔ মজুদের দামে চার্জ ঢুকলে (১,০৬১.৫০ / ১৫) প্রতিটা পিসের খরচ ফুলত,
     * আর বিক্রির লাভ কম দেখাত — চার্জটা টাকা পাঠানোর খরচ, মালের নয়।
     */
    public function test_a_bank_payment_books_its_charge_as_an_expense_not_as_stock(): void
    {
        $bank = $this->bankAccount();
        $before = $this->snapshot();

        $this->buy(
            [['product_id' => $this->product->id, 'qty' => '15', 'rate' => '70', 'sales_price' => '70', 'tax' => '0']],
            ['deposits' => [$this->row('BANK', $bank, '1050', 'NPSB-1050', ['charge_amount' => '11.50', 'charge_borne_by' => 'us'])]],
        );

        $this->assertFiveMatches($before, 'ব্যাংকে কেনা, চার্জসহ', [
            'moves' => [
                $this->acc(StandardChart::INVENTORY) => ['1050', '0'],
                $this->acc(StandardChart::PAYABLE) => ['1050', '1050'],
                $this->acc(StandardChart::BANK_CHARGES) => ['11.50', '0'],
                $bank => ['0', '1061.50'],
            ],
            'qty' => '15',
            'layers' => [['15', '70']],
            'value' => '1050',
            'supplier' => '0',
            'money' => [$bank => '-1061.50'],
            'profit' => '-11.50',
        ]);
    }

    /**
     * ⭐ চার্জ অঙ্কের সমান বা বেশি — দরজাতেই ফেরে, কিছুই বসে না।
     *
     * ⛔ আগে `deposits.*.charge_amount`-এর কোনো নিয়ম ছিল না, তাই `validate()`
     * ঘরটা নীরবে ছেঁটে ফেলত — ৯০০-র বিকাশে ৯০০ চার্জ লিখলেও বিল, মাল আর
     * ৯০০-র পরিশোধ দিব্যি বসে যেত, চার্জের কোনো চিহ্ন ছাড়া।
     *
     * ⓘ বিকাশে আগে ১০০০ রাখা, যাতে ফেরাটা "টাকা নেই"-এর জন্য না হয় — ভুলের
     * চাবিটা ঠিক `deposits.0.charge_amount` কি না সেটাই দাবি।
     */
    public function test_a_charge_as_big_as_the_payment_is_refused_at_the_door_and_posts_nothing(): void
    {
        $bkash = $this->bkashAccount();
        $this->fund($bkash, '1000');

        $before = $this->snapshot();
        $bills = PurchaseBill::query()->count();

        $this->actingAs($this->owner)->post(route('purchase.direct.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'MILL-'.fake()->unique()->numberBetween(10000, 99999),
            'lines' => [['product_id' => $this->product->id, 'qty' => '8', 'rate' => '112.50', 'sales_price' => '112.50', 'tax' => '0']],
            'deposits' => [$this->row('MFS', $bkash, '900', 'TRX8BK900X', ['charge_amount' => '900', 'charge_borne_by' => 'us'])],
        ])->assertSessionHasErrors('deposits.0.charge_amount');

        $this->assertSame($bills, PurchaseBill::query()->count(),
            'অঙ্কের সমান চার্জ, অথচ বিল বসে গেছে — চার্জটা নীরবে ছাঁটা হয়েছে।');
        $this->assertSame($before['ledger_max'], (int) LedgerEntry::query()->max('id'),
            'অঙ্কের সমান চার্জ, অথচ খাতায় সারি বসেছে।');
        $this->assertSame($before['layer_max'], (int) CostLayer::query()->max('id'),
            'অঙ্কের সমান চার্জ, অথচ মজুদে স্তর বসেছে।');
    }

    /**
     * ⛔ পদ্ধতি আর খাত মেলে না — "নগদ" পদ্ধতিতে বিকাশ থেকে পরিশোধ — ২৮ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ দুইটাই টাকার খাত, তাই MoneyAccountRule পেরোত। কিন্তু ভাউচারে "নগদ" আর টাকা
     * বেরোত বিকাশ থেকে — ক্যাশ গোনা আর বিকাশের জের দুইটাই ভুল। নিয়ম
     * [[MethodFitsAccount]]-এর, বিক্রয়ের কাউন্টারের হুবহু।
     */
    public function test_a_cash_method_cannot_pay_out_of_bkash_and_nothing_is_written(): void
    {
        $bkash = $this->bkashAccount();
        $this->fund($bkash, '1000');

        $before = $this->snapshot();
        $bills = PurchaseBill::query()->count();

        $this->actingAs($this->owner)->post(route('purchase.direct.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'MILL-'.fake()->unique()->numberBetween(10000, 99999),
            'lines' => [['product_id' => $this->product->id, 'qty' => '8', 'rate' => '112.50', 'sales_price' => '112.50', 'tax' => '0']],
            'deposits' => [$this->row('CASH', $bkash, '900', 'CASH-IN-BKASH')],
        ])->assertSessionHasErrors('deposits.0.account_id');

        $this->assertSame($bills, PurchaseBill::query()->count(), '⛔ নগদ পদ্ধতিতে বিকাশ থেকে পরিশোধ — বিল বসে গেছে।');
        $this->assertSame($before['ledger_max'], (int) LedgerEntry::query()->max('id'), '⛔ …আর খাতায় সারি বসেছে।');
        $this->assertSame($before['layer_max'], (int) CostLayer::query()->max('id'), '⛔ …আর মজুদে স্তর বসেছে।');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ৪ · আংশিক "এখনই দেওয়া"
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⭐ কিছু নগদে এখনই, বাকিটা দেনা — পরে বিকাশে শোধ হলে দেনা শূন্য।
     *
     * হাতে গোনা: ২৫ × ৩৮.৪০ = ৯৬০.০০
     *   এখনই নগদ ৩৫০ → দেনা ৯৬০ − ৩৫০ = ৬১০, বিলের বাকি ৬১০
     *   পরে বিকাশে ৬১০ → দেনা ০, বিলের বাকি ০
     *
     * ⛔ আংশিকের বিপদ দুই দিকে: বাকিটা দেনায় না বসলে মিলের টাকা হারাত, আর
     * পুরোটা দেনায় বসে নগদও কাটলে একই ৩৫০ দুইবার গোনা হত।
     */
    public function test_a_part_paid_now_leaves_exactly_the_rest_as_debt(): void
    {
        $till = $this->tillAccount();
        $bkash = $this->bkashAccount();
        $this->fund($till, '500');
        $this->fund($bkash, '700');

        $before = $this->snapshot();
        $bill = $this->buy(
            [['product_id' => $this->product->id, 'qty' => '25', 'rate' => '38.40', 'sales_price' => '38.40', 'tax' => '0']],
            ['deposits' => [$this->row('CASH', $till, '350')]],
        )['bill'];

        $this->assertFiveMatches($before, 'আংশিক এখনই', [
            'moves' => [
                $this->acc(StandardChart::INVENTORY) => ['960', '0'],
                $this->acc(StandardChart::PAYABLE) => ['350', '960'],
                $till => ['0', '350'],
            ],
            'qty' => '25',
            'layers' => [['25', '38.40']],
            'value' => '960',
            'supplier' => '610',
            'money' => [$till => '-350'],
            'profit' => '0',
        ]);

        $this->assertMoney('350', $bill->fresh()->paidAmount(), 'কাউন্টারে দেওয়া ৩৫০ বিলের শোধ হিসেবে গোনা হয়নি।');
        $this->assertMoney('610', $bill->fresh()->dueAmount(), 'বিলের বাকি ৯৬০ − ৩৫০ = ৬১০ হওয়ার কথা।');

        // ── বাকিটা পরে, বিকাশে ───────────────────────────────────────
        $before = $this->snapshot();
        $payments = app(PaymentService::class);
        $payment = $payments->create([
            'supplier_id' => $this->supplier->id,
            'account_id' => $bkash,
            'trx_date' => now()->toDateString(),
            'amount' => '610',
            'instrument' => 'mfs',
            'instrument_no' => 'TRX610REST',
        ], [['purchase_bill_id' => $bill->id, 'amount' => '610']]);
        $payments->confirm($payment);

        $this->assertFiveMatches($before, 'বাকিটা বিকাশে', [
            'moves' => [
                $this->acc(StandardChart::PAYABLE) => ['610', '0'],
                $bkash => ['0', '610'],
            ],
            'qty' => '0',
            'layers' => [],
            'value' => '0',
            'supplier' => '-610',
            'money' => [$bkash => '-610'],
            'profit' => '0',
        ]);

        $this->assertMoney('0', $bill->fresh()->dueAmount(), 'পুরো ৯৬০ শোধের পরেও বিলে বাকি দেখাচ্ছে।');
    }

    /**
     * ⛔ বাক্সে যা আছে তার চেয়ে বেশি দেওয়া যায় না — আর কিছুই বসে না।
     *
     * হাতে গোনা: বাক্সে ১০০.০০ · কেনা ১০ × ৬০.০০ = ৬০০.০০ · নগদে দিতে চাওয়া ৬০০
     *   → প্রত্যাখ্যান; বাক্স ১০০.০০-তেই থাকে; খাতায় একটা সারিও নতুন নয়,
     *     মজুদে একটা স্তরও নয়।
     *
     * ⓘ লাইভ QA-র PMT-0001: খালি টিল থেকে ১,০০০ দিয়ে টিল −১,০০০ হয়েছিল।
     * নিয়মটা [[CashOnHand]]-এর; সরবরাহকারীর পরিশোধের পথে সেটা বসেছে (1da4285a),
     * ⚠️ কাউন্টারের পরিশোধ ভাউচারের পথে বসার কথা abos-10-এর VoucherService পাহারায়।
     *
     * ⛔ কেন "কিছুই বসে না", শুধু "টাকা বসে না" নয়: কাউন্টারে মানুষ একটা
     * কাজ করেছেন — বিল, মাল আর টাকা একসাথে। টাকাটা আটকে বিল ও মাল বসে গেলে
     * পর্দা বলত "হয়নি" অথচ দেনা আর মজুদ নড়ে থাকত, আর আবার চেষ্টা করলে
     * একই মাল দুইবার ঢুকত।
     */
    public function test_paying_more_than_the_cash_box_holds_is_refused_and_posts_nothing(): void
    {
        $till = $this->tillAccount();
        $this->fund($till, '100');

        $before = $this->snapshot();
        $bills = PurchaseBill::query()->count();

        $refused = null;

        try {
            $this->buy(
                [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '60', 'sales_price' => '60', 'tax' => '0']],
                ['deposits' => [$this->row('CASH', $till, '600')]],
            );
        } catch (ValidationException $e) {
            $refused = $e;
        }

        $this->assertMoney('100', bcadd($this->balances()[$till] ?? '0', '0', 4),
            'বাক্সে ১০০ ছিল, ৬০০ দেওয়ার চেষ্টার পরে বাক্সের জের ১০০ থাকার কথা — বাক্স শূন্যের নিচে নেমেছে বা নড়েছে।');
        $this->assertNotNull($refused,
            'বাক্সে ১০০, অথচ ৬০০ নগদ দেওয়াটা কোনো বাধা ছাড়াই মেনে নেওয়া হয়েছে।');
        $this->assertSame($before['ledger_max'], (int) LedgerEntry::query()->max('id'),
            'পরিশোধ আটকানোর পরেও খাতায় নতুন সারি বসেছে (বিল বা মজুদের দাখিলা) — কাজটা আধা-বসা।');
        $this->assertSame($before['layer_max'], (int) CostLayer::query()->max('id'),
            'পরিশোধ আটকানোর পরেও মজুদে নতুন স্তর বসেছে — মাল ঢুকেছে, টাকা যায়নি।');
        $this->assertSame($bills, PurchaseBill::query()->count(),
            'পরিশোধ আটকানোর পরেও একটা বিল রয়ে গেছে।');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  পাঁচ মিল
    // ═══════════════════════════════════════════════════════════════════

    /**
     * লেনদেনের আগের ছবি — পাঁচ মিলের প্রতিটা এখান থেকে পার্থক্য মাপে।
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'ledger_max' => (int) LedgerEntry::query()->max('id'),
            'layer_max' => (int) CostLayer::query()->max('id'),
            'balances' => $this->balances(),
            'on_hand' => (string) app(StockService::class)->statesFor($this->product, $this->warehouse)['on_hand'],
            'layer_qty' => $this->costs()->qtyOnHand($this->product),
            'layer_value' => $this->costs()->valueOnHand($this->product),
            'supplier' => $this->supplier->fresh()->payable(),
            'supplier_share' => $this->supplierShareOfPayable(),
        ];
    }

    /**
     * ⭐ চেকলিস্ট §১-এর পাঁচ মিল, হাতে গোনা অঙ্কে।
     *
     * ⓘ ইচ্ছে করেই ছোট আর নিজের মধ্যে সম্পূর্ণ — ভাগের trait
     * (`tests/Concerns/ChecksTheFiveMatches.php`) তৈরি হলে এটা সেটায় বদলানো যাবে।
     *
     * @param  array<string, mixed>  $before  [[snapshot()]]
     * @param  array{moves: array<int, array{0: string, 1: string}>, qty: string, layers: list<array{0: string, 1: string}>, value: string, supplier: string, money: array<int, string>, profit: string}  $expect
     */
    private function assertFiveMatches(array $before, string $label, array $expect): void
    {
        $new = LedgerEntry::query()->where('id', '>', $before['ledger_max'])->get(['account_id', 'debit', 'credit']);

        // ── ১ · খাতা: ডেবিট = ক্রেডিট, আর প্রতিটা খাতের নড়াচড়া ────────
        $debit = $new->reduce(fn (string $s, $e) => bcadd($s, (string) $e->debit, 4), '0');
        $credit = $new->reduce(fn (string $s, $e) => bcadd($s, (string) $e->credit, 4), '0');
        $this->assertSame(0, bccomp($debit, $credit, 4),
            "[{$label}] খাতা ১: নতুন সারিতে ডেবিট {$debit} আর ক্রেডিট {$credit} — খাতা মেলে না।");

        $actual = [];
        foreach ($new as $e) {
            $id = (int) $e->account_id;
            $actual[$id] ??= ['0', '0'];
            $actual[$id] = [bcadd($actual[$id][0], (string) $e->debit, 4), bcadd($actual[$id][1], (string) $e->credit, 4)];
        }

        $expected = [];
        foreach ($expect['moves'] as $id => [$dr, $cr]) {
            $expected[(int) $id] = [bcadd($dr, '0', 4), bcadd($cr, '0', 4)];
        }

        ksort($actual);
        ksort($expected);
        $this->assertSame($this->named($expected), $this->named($actual),
            "[{$label}] খাতা ১: কোন খাত কত নড়ল তা হাতে গোনা অঙ্কের সাথে মেলে না "
            .'(বাঁয়ে হাতে গোনা, ডানে খাতায় যা বসল — খাত => [ডেবিট, ক্রেডিট])।');

        // ── ২ · মজুদ: পরিমাণ, FIFO স্তর, আর মজুদ-খাত = মজুদের মূল্য ───
        $onHand = (string) app(StockService::class)->statesFor($this->product, $this->warehouse)['on_hand'];
        $this->assertMoney($expect['qty'], bcsub($onHand, $before['on_hand'], 4),
            "[{$label}] মজুদ ২: গুদামে পরিমাণ হাতে গোনা {$expect['qty']} নড়েনি।");
        $this->assertMoney($expect['qty'], bcsub($this->costs()->qtyOnHand($this->product), $before['layer_qty'], 4),
            "[{$label}] মজুদ ২: খরচের স্তরে পরিমাণ গুদামের পরিমাণের সাথে মেলে না।");

        $layers = CostLayer::query()
            ->where('id', '>', $before['layer_max'])
            ->orderBy('id')
            ->get(['product_id', 'qty_in', 'unit_cost'])
            ->map(fn (CostLayer $l) => [(int) $l->product_id, bcadd((string) $l->qty_in, '0', 4), bcadd((string) $l->unit_cost, '0', 4)])
            ->all();
        $this->assertSame(
            array_map(fn (array $l) => [(int) $this->product->id, bcadd($l[0], '0', 4), bcadd($l[1], '0', 4)], $expect['layers']),
            $layers,
            "[{$label}] মজুদ ২: নতুন FIFO স্তর [পণ্য, পরিমাণ, একক খরচ] হাতে গোনার সাথে মেলে না।",
        );

        $valueMoved = bcsub($this->costs()->valueOnHand($this->product), $before['layer_value'], 4);
        $this->assertMoney($expect['value'], $valueMoved,
            "[{$label}] মজুদ ২: মজুদের মূল্য হাতে গোনা {$expect['value']} নড়েনি।");
        $this->assertMoney($valueMoved, $this->moved($before, $this->acc(StandardChart::INVENTORY)),
            "[{$label}] মজুদ ২: ব্যালান্স শিটের মজুদ-খাত ১১২০ আর মজুদের মূল্য আলাদা নড়েছে।");

        // ── ৩ · পক্ষ: সরবরাহকারীর খতিয়ান = দেনা-খাতে তার অংশ ────────
        $ledgerMoved = bcsub($this->supplier->fresh()->payable(), $before['supplier'], 4);
        $shareMoved = bcsub($this->supplierShareOfPayable(), $before['supplier_share'], 4);
        $this->assertMoney($expect['supplier'], $ledgerMoved,
            "[{$label}] পক্ষ ৩: সরবরাহকারীর খতিয়ানের বাকি হাতে গোনা {$expect['supplier']} নড়েনি।");
        $this->assertMoney($ledgerMoved, $shareMoved,
            "[{$label}] পক্ষ ৩: সরবরাহকারীর খতিয়ান আর দেনা-খাত ২১১১-এ তার অংশ আলাদা কথা বলে।");
        $this->assertMoney($shareMoved, bcmul($this->moved($before, $this->acc(StandardChart::PAYABLE)), '-1', 4),
            "[{$label}] পক্ষ ৩: দেনা-খাত ২১১১ এমন টাকা নড়েছে যা কোনো সরবরাহকারীর নামে নেই।");

        // ── ৪ · নগদ, ব্যাংক, বিকাশ: জের = হাতে গোনা টাকা ──────────────
        foreach (Account::query()->whereNotNull('money_kind')->where('is_group', false)->pluck('id') as $id) {
            $this->assertMoney($expect['money'][(int) $id] ?? '0', $this->moved($before, (int) $id),
                "[{$label}] টাকা ৪: {$this->nameOf((int) $id)}-এর জের হাতে গোনা টাকার সাথে মেলে না।");
        }

        // ── ৫ · লাভ: কেনায় লাভ-ক্ষতি নড়ে কেবল চার্জ বা খরচে ─────────
        $types = Account::query()->whereIn('type', [Account::INCOME, Account::EXPENSE])->pluck('type', 'id');
        $profit = '0';
        foreach ($actual as $id => [$dr, $cr]) {
            if (isset($types[$id])) {
                $profit = bcadd($profit, bcsub($cr, $dr, 4), 4);
            }
        }
        $this->assertMoney($expect['profit'], $profit,
            "[{$label}] লাভ ৫: কেনায় লাভ-ক্ষতি হাতে গোনা {$expect['profit']} নড়ার কথা (কেবল চার্জ)।");
    }

    /** @return array<int, string> খাত => ডেবিট − ক্রেডিট, গোটা খাতা */
    private function balances(): array
    {
        return LedgerEntry::query()
            ->selectRaw('account_id, SUM(debit) - SUM(credit) as net')
            ->groupBy('account_id')
            ->pluck('net', 'account_id')
            ->map(fn ($n) => bcadd((string) $n, '0', 4))
            ->all();
    }

    /** আগের ছবি থেকে এই খাতের জের কতটা নড়ল (ডেবিট − ক্রেডিট)। */
    private function moved(array $before, int $accountId): string
    {
        return bcsub($this->balances()[$accountId] ?? '0', $before['balances'][$accountId] ?? '0', 4);
    }

    /** দেনা-খাত ২১১১-এ এই সরবরাহকারীর নামে যা বসে আছে (ক্রেডিট − ডেবিট)। */
    private function supplierShareOfPayable(): string
    {
        $net = LedgerEntry::query()
            ->where('account_id', $this->acc(StandardChart::PAYABLE))
            ->where('party_type', Supplier::drillSourceType())
            ->where('party_id', $this->supplier->id)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as net')
            ->value('net');

        return bcadd((string) ($net ?? '0'), '0', 4);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $moves
     * @return array<string, array{0: string, 1: string}>
     */
    private function named(array $moves): array
    {
        $out = [];
        foreach ($moves as $id => $pair) {
            $out[$this->nameOf($id)] = $pair;
        }

        return $out;
    }

    private function nameOf(int $accountId): string
    {
        $a = Account::query()->find($accountId);

        return $a === null ? "#{$accountId}" : "{$a->code} {$a->name_en}";
    }

    private function assertMoney(string $expected, string $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, $actual, 4), $message." (হাতে গোনা {$expected}, পাওয়া গেল {$actual})");
    }

    // ═══════════════════════════════════════════════════════════════════
    //  সহায়ক
    // ═══════════════════════════════════════════════════════════════════

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     * @return array{bill: PurchaseBill, payments: list<mixed>}
     */
    private function buy(array $lines, array $extra = []): array
    {
        return app(DirectPurchaseService::class)->complete([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'MILL-'.fake()->unique()->numberBetween(10000, 99999),
            ...$extra,
        ], $lines);
    }

    /**
     * কাউন্টারের এক সারি টাকা — পর্দা যে গড়নে পাঠায়।
     *
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function row(string $method, int $accountId, string $amount, ?string $reference = null, array $more = []): array
    {
        return [
            'payment_method_id' => (int) PaymentMethod::query()->where('code', $method)->value('id'),
            'account_id' => $accountId,
            'amount' => $amount,
            'reference' => $reference,
            'ref_date' => now()->toDateString(),
            ...$more,
        ];
    }

    private function acc(string $code): int
    {
        return (int) Account::query()->where('code', $code)->valueOrFail('id');
    }

    private function tillAccount(): int
    {
        return (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
    }

    /** টিল বা বিকাশে টাকা রাখা — মালিকের পুঁজি থেকে, খাতা মিলিয়ে ([[PutsMoneyInTheTill]])। */
    private function fund(int $accountId, string $amount): void
    {
        $this->putMoneyIn(Account::query()->findOrFail($accountId), $amount);
    }

    private function bankAccount(): int
    {
        return $this->moneyAccount('1102-91', StandardChart::BANK, 'Test Bank CD', 'পরীক্ষার ব্যাংক', Account::BANK);
    }

    private function bkashAccount(): int
    {
        return $this->moneyAccount('1105-91', StandardChart::MOBILE_MONEY, 'Test bKash', 'পরীক্ষার বিকাশ', Account::MFS);
    }

    private function moneyAccount(string $code, string $parent, string $en, string $bn, string $kind): int
    {
        return (int) Account::query()->firstOrCreate(['code' => $code], [
            'company_id' => $this->company->id,
            'parent_id' => Account::query()->where('code', $parent)->value('id'),
            'name_en' => $en,
            'name_bn' => $bn,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'is_group' => false,
            'money_kind' => $kind,
        ])->id;
    }

    /** কোনো রোল নেই, কোনো চাবি নেই — কেবল কোম্পানির সদস্য। */
    private function roleLessClerk(): User
    {
        $clerk = User::factory()->create();
        $clerk->companies()->attach($this->company, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();

        return $clerk;
    }

    private function costs(): CostLayerService
    {
        return app(CostLayerService::class);
    }
}

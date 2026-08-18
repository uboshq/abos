<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\PosService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ম্যানেজার ছাড়টা কোনোদিন দেখেননি।
 *
 * ── কী ভাঙা ─────────────────────────────────────────────────────────
 * `SalesInvoiceService::confirm()` ছাড়ের পাহারাটা **ইচ্ছাকৃতভাবে
 * লেনদেনের বাইরে** ডাকে, আর কোডে মন্তব্য করে কারণটাও লেখা: ভেতরে
 * রাখলে ব্যতিক্রমটা অনুরোধের সারিটাও রোল-ব্যাক করত, আর অনুমোদনকারীর
 * তালিকায় কোনোদিন কিছু আসত না।
 *
 * কাউন্টারের পথে ঠিক সেটাই ঘটে — অন্য জায়গা থেকে। `PosService::
 * checkout()` নিজেই `create()` ও `confirm()` দুইটাকে **তার নিজের**
 * `DB::transaction`-এ মুড়ে রাখে, তাই ভেতরের সতর্কতাটা এক স্তর উপরে
 * এসে অকেজো হয়ে যায়। ক্যাশিয়ার "ছাড় অনুমোদনের অপেক্ষায়" বার্তা
 * পান, মালিকের তালিকা ফাঁকাই থাকে, আর বিক্রয়টা কোনোদিন হয় না।
 *
 * ── কেন কোনো টেস্ট এটা ধরেনি ────────────────────────────────────────
 * ছাড়ের পাহারার টেস্টগুলো বিলের পর্দা ধরে চলে, যেখানে বাইরের কোনো
 * লেনদেন নেই। কাউন্টারের টেস্টগুলো ছাড় দেয় না। **দুইটা সঠিক অংশের
 * মাঝখানে ভুলটা**, আর ঠিক সেখানেই কোনো টেস্ট দাঁড়ানো ছিল না।
 *
 * ডেমোর প্রবাহ: sales/discount, সীমা ৳১,০০০, অনুমোদনকারী মালিক।
 */
class TheManagerNeverSawTheDiscountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    private Warehouse $warehouse;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->manager = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->cashier = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($this->cashier);

        app(SettingsService::class)->set('sales.screen_pos', true);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->product = Product::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->firstOrFail();
    }

    /**
     * সীমা ছাড়ানো একটা ছাড় দিয়ে কাউন্টারে বিক্রির চেষ্টা।
     *
     * ৳১,৫০০ ছাড় — ডেমোর সীমা ৳১,০০০, তাই অনুমোদন লাগবেই।
     */
    private function sellWithBigDiscount(): void
    {
        app(PosService::class)->checkout([
            'warehouse_id' => $this->warehouse->id,
            'paid' => '3500',
        ], [
            ['product_id' => $this->product->id, 'qty' => '1', 'rate' => '5000', 'discount' => '1500'],
        ]);
    }

    /** পাহারাটা অন্তত আটকায় — বিলটা নীরবে বসে যায় না। */
    public function test_a_discount_over_the_limit_stops_the_sale(): void
    {
        $this->expectException(ValidationException::class);

        $this->sellWithBigDiscount();
    }

    /**
     * আর অনুরোধটা ম্যানেজারের তালিকায় পৌঁছায়।
     *
     * এটাই আসল কথা: আটকানোটা তখনই কাজে লাগে যখন আটকানোর কারণটা কেউ
     * দেখতে পান। না পৌঁছালে ক্যাশিয়ার একই বার্তা পেয়ে যেতেন চিরকাল।
     */
    public function test_the_request_reaches_the_manager(): void
    {
        try {
            $this->sellWithBigDiscount();
        } catch (ValidationException) {
            // আটকানোটা উপরের টেস্টের বিষয়; এখানে দেখা হচ্ছে কী রয়ে গেল।
        }

        $this->assertDatabaseHas('approvals', [
            'company_id' => $this->company->id,
            'module' => 'sales',
            'action' => 'discount',
            'status' => Approval::PENDING,
        ]);

        $this->assertCount(1, app(ApprovalEngine::class)->pendingFor($this->manager),
            'মালিকের অনুমোদনের তালিকায় ছাড়ের অনুরোধটা নেই।');
    }

    /**
     * আর বিলটাও থেকে যায় — খসড়া হয়ে।
     *
     * অনুরোধ থাকল অথচ বিল নেই মানে অনুমোদনকারী এমন একটা কাগজ দেখছেন
     * যা আর নেই, আর ক্যাশিয়ারকে পুরো কার্ট আবার টাইপ করতে হত।
     */
    public function test_the_bill_survives_as_a_draft(): void
    {
        try {
            $this->sellWithBigDiscount();
        } catch (ValidationException) {
        }

        $this->assertSame(1, SalesInvoice::query()->count(),
            'অনুমোদনের অপেক্ষায় থাকা বিলটা রোল-ব্যাকে হারিয়ে গেছে।');
    }

    /** সীমার নিচের ছাড়ে কিছুই আটকায় না — পাহারাটা রোজকার বিক্রি থামায় না। */
    public function test_a_small_discount_passes_untouched(): void
    {
        app(PosService::class)->checkout([
            'warehouse_id' => $this->warehouse->id,
            'paid' => '4950',
        ], [
            ['product_id' => $this->product->id, 'qty' => '1', 'rate' => '5000', 'discount' => '50'],
        ]);

        $this->assertDatabaseCount('approvals', 0);
        $this->assertSame(1, SalesInvoice::query()->count());
    }

    // ── কাউন্টারে দাঁড়িয়েই অনুমোদন ──────────────────────────────────

    /**
     * ম্যানেজার নিজের লগইন দিলে বিক্রয়টা তখনই হয়ে যায়।
     *
     * এটাই ৩২ নং-এর আসল কথা: ক্রেতা কাউন্টারে দাঁড়িয়ে, ম্যানেজার
     * পাশেই — তাঁকে অন্য পর্দায় গিয়ে সিদ্ধান্ত দিয়ে আসতে হবে না।
     */
    public function test_the_manager_approves_standing_at_the_counter(): void
    {
        $result = app(PosService::class)->checkout([
            'warehouse_id' => $this->warehouse->id,
            'paid' => '3500',
            'approver_email' => $this->manager->email,
            'approver_password' => 'password',
        ], [
            ['product_id' => $this->product->id, 'qty' => '1', 'rate' => '5000', 'discount' => '1500'],
        ]);

        $this->assertSame(DocumentStatus::CONFIRMED, $result['invoice']->status,
            'ম্যানেজার সম্মতি দেওয়ার পরেও বিক্রয়টা হয়নি।');

        $this->assertDatabaseHas('approvals', [
            'module' => 'sales',
            'action' => 'discount',
            'status' => Approval::APPROVED,
        ]);
    }

    /** কে অনুমোদন করলেন তা বিলের অডিটেই লেখা থাকে। */
    public function test_who_said_yes_is_written_on_the_bill(): void
    {
        $result = app(PosService::class)->checkout([
            'warehouse_id' => $this->warehouse->id,
            'paid' => '3500',
            'approver_email' => $this->manager->email,
            'approver_password' => 'password',
        ], [
            ['product_id' => $this->product->id, 'qty' => '1', 'rate' => '5000', 'discount' => '1500'],
        ]);

        $this->assertDatabaseHas('audit_trails', [
            'auditable_type' => SalesInvoice::class,
            'auditable_id' => $result['invoice']->id,
            'action' => 'discount_approved',
            'reason' => $this->manager->name,
        ]);
    }

    /**
     * ভুল পাসওয়ার্ডে কিছুই হয় না।
     *
     * আর পাসওয়ার্ডটা যেন কোথাও লেখা না থাকে — সেটার পাহারা
     * `bootstrap/app.php`-এর `dontFlash`-এ।
     */
    public function test_a_wrong_password_approves_nothing(): void
    {
        try {
            app(PosService::class)->checkout([
                'warehouse_id' => $this->warehouse->id,
                'paid' => '3500',
                'approver_email' => $this->manager->email,
                'approver_password' => 'not-the-password',
            ], [
                ['product_id' => $this->product->id, 'qty' => '1', 'rate' => '5000', 'discount' => '1500'],
            ]);

            $this->fail('ভুল পাসওয়ার্ডেও ছাড়টা অনুমোদন হয়ে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approver_password', $e->errors());
        }

        $this->assertDatabaseMissing('approvals', ['status' => Approval::APPROVED]);
        $this->assertSame(DocumentStatus::DRAFT, SalesInvoice::query()->firstOrFail()->status);
    }

    /**
     * নিজের চাওয়া ছাড় নিজে অনুমোদন করা যায় না।
     *
     * এটা না থাকলে পুরো ব্যবস্থাটাই সাজানো — যিনি ছাড় দিচ্ছেন তিনিই
     * সম্মতি দিয়ে দিতেন, আর অডিটে দুইবার তাঁরই নাম বসত।
     */
    public function test_nobody_approves_their_own_discount(): void
    {
        $this->actingAs($this->manager);

        try {
            app(PosService::class)->checkout([
                'warehouse_id' => $this->warehouse->id,
                'paid' => '3500',
                'approver_email' => $this->manager->email,
                'approver_password' => 'password',
            ], [
                ['product_id' => $this->product->id, 'qty' => '1', 'rate' => '5000', 'discount' => '1500'],
            ]);

            $this->fail('নিজের চাওয়া ছাড় নিজেই অনুমোদন করে ফেলেছেন।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approver_email', $e->errors());
        }

        $this->assertDatabaseMissing('approvals', ['status' => Approval::APPROVED]);
    }

    /** অনুমোদনকারী নন এমন কেউ "হ্যাঁ" বলতে পারেন না। */
    public function test_someone_who_is_not_an_approver_is_refused(): void
    {
        $accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        try {
            app(PosService::class)->checkout([
                'warehouse_id' => $this->warehouse->id,
                'paid' => '3500',
                'approver_email' => $accountant->email,
                'approver_password' => 'password',
            ], [
                ['product_id' => $this->product->id, 'qty' => '1', 'rate' => '5000', 'discount' => '1500'],
            ]);

            $this->fail('অনুমোদনকারী না হয়েও ছাড়টা অনুমোদন হয়ে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approver_email', $e->errors());
        }

        $this->assertDatabaseMissing('approvals', ['status' => Approval::APPROVED]);
    }

    /**
     * আটকে যাওয়া বিলটা কাউন্টারের ঝুলন্ত তালিকায় ওঠে।
     *
     * নাহলে ক্যাশিয়ার পর্দায় তার কোনো চিহ্ন পেতেন না, আর অনুমোদনের
     * পর পুরো কার্ট আবার টাইপ করতেন।
     */
    public function test_the_blocked_bill_waits_on_the_counter(): void
    {
        try {
            $this->sellWithBigDiscount();
        } catch (ValidationException) {
        }

        $this->assertCount(1, app(PosService::class)->parked(),
            'আটকে যাওয়া বিলটা কাউন্টারের ঝুলন্ত তালিকায় নেই।');
    }

    // ── পর্দা ধরে, পুরো পথটা ────────────────────────────────────────

    /**
     * ক্যাশিয়ারের পর্দা থেকে শুরু করে বিক্রয় পর্যন্ত — একটাও ধাপ বাদ না দিয়ে।
     *
     * সেবার স্তরে পরীক্ষা করাই যেত, কিন্তু ঘরগুলো পর্দায় না থাকলে
     * কাউন্টারে কেউ ওখানে পৌঁছাতেই পারতেন না — কাজটা কোডে থাকত,
     * ব্যবহারে নয়।
     */
    public function test_the_whole_path_from_the_screen(): void
    {
        $cart = [
            'warehouse_id' => $this->warehouse->id,
            'paid' => '3500',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => '1', 'rate' => '5000', 'discount' => '1500'],
            ],
        ];

        // ১. ক্যাশিয়ার বেচার চেষ্টা করলেন — আটকাল, আর বিলটা ঝুলে রইল।
        $this->post(route('sales.pos.checkout'), $cart)
            ->assertSessionHasErrors('discount');

        $parked = SalesInvoice::query()->whereNotNull('parked_at')->firstOrFail();

        // ২. তুললেন — কার্ট ফিরল, আর অনুমোদনের ঘরটা এল।
        $this->post(route('sales.pos.resume', ['invoice' => $parked->id]))
            ->assertRedirect(route('sales.pos.index', ['resume' => $parked->id]));

        $this->get(route('sales.pos.index', ['resume' => $parked->id]))
            ->assertOk()
            ->assertSee(__('sales::message.pos_needs_approval'))
            ->assertSee('name="approver_password"', false)
            ->assertSee('value="'.$parked->id.'"', false);

        // ৩. ম্যানেজার নিজের লগইন দিলেন — বিক্রয় হয়ে গেল।
        $this->post(route('sales.pos.checkout'), [
            ...$cart,
            'resumed_invoice_id' => $parked->id,
            'approver_email' => $this->manager->email,
            'approver_password' => 'password',
        ])->assertSessionHasNoErrors();

        $sold = SalesInvoice::query()->findOrFail($parked->id);

        $this->assertSame(DocumentStatus::CONFIRMED, $sold->status,
            'ম্যানেজারের সম্মতির পরেও তোলা বিলটা বিক্রি হয়নি।');

        $this->assertSame(1, SalesInvoice::query()->count(),
            'পুরো পথে একটার বেশি বিল তৈরি হয়েছে।');
    }

    /**
     * ভুল পাসওয়ার্ডটা সেশনে ফ্ল্যাশ হয় না।
     *
     * Laravel নিজে থেকে `password` বাদ দেয়, কিন্তু ঘরটার নাম
     * `approver_password` — ওই তালিকায় পড়ে না। না বললে ম্যানেজারের
     * টাইপ করা পাসওয়ার্ড সেশনে গিয়ে বসত।
     */
    public function test_the_typed_password_is_never_kept(): void
    {
        $this->post(route('sales.pos.checkout'), [
            'warehouse_id' => $this->warehouse->id,
            'paid' => '3500',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => '1', 'rate' => '5000', 'discount' => '1500'],
            ],
            'approver_email' => $this->manager->email,
            'approver_password' => 'secret-of-the-manager',
        ])->assertSessionHasErrors('approver_password');

        $this->assertStringNotContainsString(
            'secret-of-the-manager',
            json_encode(session()->all(), JSON_UNESCAPED_UNICODE) ?: '',
            'ম্যানেজারের টাইপ করা পাসওয়ার্ডটা সেশনে রয়ে গেছে।',
        );
    }
}

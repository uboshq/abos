<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Events\SalesOrderApproved;
use App\Modules\Sales\Events\SalesOrderCancelled;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\SalesOrderStatus as S;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * বিক্রয় আদেশ জমা হয়, বাকির যাচাই হয়, তারপর সুপারভাইজার সই দেন — নতুন ধারা (নকশা "DO বিক্রয় আদেশে মেশানো", ধাপ ৩)।
 *
 * ── ⭐ মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান; সমন্বয়কের সাত উত্তর ─────────────────────────────────────────
 * ⓘ সুইচ `sales.orders_replace_do` (ডিফল্ট বন্ধ)। বন্ধে সব আজকের মতো; চালুতে: জমা → বাকির যাচাই (উত্তর ১: জমার মুহূর্তে,
 * সুপারভাইজারের আগে) → সীমায় আটকে | সইয়ের অপেক্ষা | অনুমোদিত। সুপারভাইজার পরিমাণ কমাতে পারেন, বাড়াতে নয়; শেষ সইয়ে
 * অনুমোদিত (abos-86-এর সংকেত), ফেরতে ফেরত (বাতিলের সংকেত)।
 *
 * ⓘ এই পরীক্ষা ধরে: সুইচ বন্ধে আজকের পথ অটুট (একই মানুষ, সুইচ বন্ধ তারপর চালু); সীমা না কুলোলে সই চাওয়াই হয় না, টাকা এলে
 * নিজে এগোয়; কেবল এখনকার অনুমোদনকারী পরিমাণ কমান (একই কাগজে দুই মানুষ); শেষ সই আর ফেরত ঠিক অবস্থায় নেয় আর সংকেত দেয়;
 * আজকের নিয়মের আদেশের সই এই পথ ছোঁয় না; নতুন ধারায় DO-র ভাঁজ মেনু থেকে সরে, পাতা খোলে।
 */
final class AnOrderIsSubmittedCheckedAndSignedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $buyer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(SettingsService::class)->set('customer.credit_limit_enabled', true);

        $this->buyer = Customer::query()->create(['code' => 'OS3-BUY', 'name_en' => 'Order Flow Buyer', 'name_bn' => 'আদেশের ক্রেতা', 'is_active' => true]);
        $this->limit('100000000');

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('track_batch', false)->where('is_active', true)
            ->where('sale_price', '>', 0)->orderBy('id')->firstOrFail();

        app(StockService::class)->move(
            product: $this->product, warehouse: $this->warehouse,
            sourceType: StockService::ADJUSTMENT, sourceId: $this->product->id, floor: '50',
        );
    }

    /**
     * ⭐ সুইচ বন্ধে আজকের পথ অটুট — একই মানুষ, সুইচ বন্ধ তারপর চালু।
     *
     * ⓘ বন্ধে: "নিশ্চিত" আজকের মতো সংরক্ষিত করে আর মজুদের খাতায় ধরে, জমার নতুন পথ বন্ধ। চালুতে: একই বোতাম জমা দেয় —
     * ছক নেই আর সীমা কুলোয়, তাই সোজা অনুমোদিত; মজুদের খাতায় কিছু ধরে না (হোল্ড abos-86-এর), আর সংকেত যায় ঠিক একবার।
     */
    public function test_the_switch_off_keeps_today_and_on_submits_the_same_button(): void
    {
        Event::fake([SalesOrderApproved::class]);
        $orders = app(SalesOrderService::class);
        $reserved = fn (): string => (string) app(StockService::class)->statesFor($this->product, $this->warehouse)['reserved'];

        $today = $orders->confirm($this->draft('4')->fresh(['lines']));
        $this->assertSame(S::CONFIRMED, $today->status);
        $this->assertSame(S::HOLD_LEDGER, $today->fresh()->hold_mode);
        $this->assertStringContainsString('⛔', $this->refused(fn () => $orders->submit($this->draft('1')->fresh(['lines'])), 'status'),
            '⛔ সুইচ বন্ধেও জমার নতুন পথ খোলা।');
        Event::assertNotDispatched(SalesOrderApproved::class);

        $this->replaceDo(true);
        $before = $reserved();
        $new = $orders->confirm($this->draft('6')->fresh(['lines']));

        $this->assertSame(S::APPROVED, $new->status, '⛔ সুইচ চালুতে "নিশ্চিত" জমার পথে যায়নি।');
        $this->assertSame(S::HOLD_HOLDS, $new->hold_mode);
        $this->assertNotNull($new->submitted_at);
        $this->assertNotNull($new->approved_at);
        $this->assertSame(0, bccomp('6', (string) $new->lines->first()->requested_qty, 4), '⛔ চাওয়া পরিমাণ লেখা হয়নি।');
        $this->assertSame(0, bccomp($before, $reserved(), 4), '⛔ নতুন ধারার আদেশ মজুদের খাতায় সরাসরি ধরেছে — হোল্ড abos-86-এর।');
        Event::assertDispatchedTimes(SalesOrderApproved::class, 1);
        Event::assertDispatched(SalesOrderApproved::class, fn (SalesOrderApproved $e) => $e->payload['sales_order_id'] === (int) $new->id);

        $this->assertStringContainsString('⛔', $this->refused(fn () => $orders->submit($new->fresh(['lines'])), 'status'),
            '⛔ জমা দেওয়া আদেশ আবার জমা হলো।');
    }

    /**
     * ⭐ সীমা না কুলোলে সীমায় আটকে — সই চাওয়াই হয় না; টাকা (বা সীমা) এলে আবার যাচাইয়ে নিজে সইয়ের অপেক্ষায় যায়।
     */
    public function test_a_short_limit_holds_it_before_the_supervisor_and_money_moves_it_on(): void
    {
        $this->replaceDo(true);
        $signer = $this->signerFlow();
        $this->limit('0');
        $orders = app(SalesOrderService::class);

        $order = $orders->submit($this->draft('10')->fresh(['lines']));

        $this->assertSame(S::CREDIT_HELD, $order->status, '⛔ সীমা ০, অথচ আদেশ সীমায় আটকায়নি।');
        $this->assertSame(0, bccomp((string) $order->total, (string) $order->credit_short, 4), '⛔ কত কম, ভুল।');
        $this->assertNotNull($order->credit_held_at);
        $this->assertNull($this->approvalOf($order), '⛔ সীমায় আটকে থাকা আদেশের সই চাওয়া হয়েছে — যাচাই সুপারভাইজারের আগে।');

        $firstHeld = (string) $order->credit_held_at;
        $this->travel(1)->hours();
        $this->assertSame(0, $orders->recheckCustomer((int) $this->buyer->id), '⛔ টাকা ছাড়াই আদেশ ছেড়ে দিয়েছে।');
        $this->assertSame($firstHeld, (string) $order->fresh()->credit_held_at, '⛔ আবার যাচাইয়ে প্রথম আটকানোর মুহূর্ত বদলেছে।');

        $this->limit('100000000');
        $this->assertSame(1, $orders->recheckCustomer((int) $this->buyer->id));
        $this->assertSame(S::AWAITING_APPROVAL, $order->fresh()->status, '⛔ সীমা কুলোনোর পরে আদেশ সইয়ের অপেক্ষায় যায়নি।');
        $this->assertNull($order->fresh()->credit_short);
        $this->assertTrue($this->approvalOf($order)?->isPending() ?? false, '⛔ সই চাওয়া হয়নি।');
        $this->assertTrue(app(ApprovalEngine::class)->canDecide($this->approvalOf($order), $signer));
    }

    /**
     * ⭐ সুপারভাইজার পরিমাণ কমান — কেবল এখনকার অনুমোদনকারী (একই কাগজে দুই মানুষ), ০ থেকে চাওয়া পর্যন্ত; টাকা আবার গোনা।
     */
    public function test_only_the_current_approver_lowers_the_quantity_and_the_money_follows(): void
    {
        $this->replaceDo(true);
        $signer = $this->signerFlow();
        $orders = app(SalesOrderService::class);

        $order = $orders->submit($this->draft('10')->fresh(['lines']));
        $this->assertSame(S::AWAITING_APPROVAL, $order->status);
        $line = $order->lines->first();
        $rate = (string) $line->rate;

        $clerk = $this->member();
        try {
            $orders->setApprovedQuantities($order->fresh(), [$line->id => '6'], $clerk);
            $this->fail('⛔ অনুমোদনকারী নন এমন মানুষ পরিমাণ বদলেছেন।');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, bccomp('10', (string) $line->fresh()->ordered_qty, 4));

        $this->assertStringContainsString('⛔', $this->refused(
            fn () => $orders->setApprovedQuantities($order->fresh(), [$line->id => '11'], $signer), "lines.{$line->id}"),
            '⛔ সুপারভাইজার চাওয়ার বেশি বসিয়েছেন।');

        $lowered = $orders->setApprovedQuantities($order->fresh(), [$line->id => '6'], $signer);
        $l = $lowered->lines->first();
        $this->assertSame(0, bccomp('6', (string) $l->ordered_qty, 4));
        $this->assertSame(0, bccomp('10', (string) $l->requested_qty, 4), '⛔ চাওয়া পরিমাণ হারিয়েছে।');
        $this->assertSame(0, bccomp(bcmul('6', $rate, 4), (string) $l->amount, 4), '⛔ লাইনের টাকা আবার গোনা হয়নি।');
        $this->assertSame(0, bccomp(bcmul('6', $rate, 4), (string) $lowered->total, 4), '⛔ আদেশের মোট আবার গোনা হয়নি।');
    }

    /**
     * ⭐ শেষ সইয়ে অনুমোদিত (সংকেতসহ), ফেরতে ফেরত (বাতিলের সংকেতসহ) — আর আজকের নিয়মের আদেশের সই এই পথ ছোঁয় না।
     */
    public function test_the_last_signature_approves_a_refusal_rejects_and_todays_orders_are_left_alone(): void
    {
        Event::fake([SalesOrderApproved::class, SalesOrderCancelled::class]);
        $signer = $this->signerFlow();
        $engine = app(ApprovalEngine::class);
        $orders = app(SalesOrderService::class);

        // ── আজকের নিয়ম (সুইচ বন্ধ): সই পেলেও খসড়া থাকে, এই শ্রোতা কিছু করে না
        $ledger = $this->draft('2');
        try {
            $orders->confirm($ledger->fresh(['lines']));
            $this->fail('প্রস্তুতিটাই ভুল — ছক থাকতেও আজকের আদেশ সই ছাড়া নিশ্চিত হলো।');
        } catch (HeldForApproval) {
            // ⓘ প্রত্যাশিত
        }
        $engine->approve($this->approvalOf($ledger), $signer);
        $this->assertSame(S::DRAFT, $ledger->fresh()->status, '⛔ আজকের নিয়মের আদেশ শ্রোতা নিজে এগিয়ে দিয়েছে।');

        // ── নতুন ধারা
        $this->replaceDo(true);
        $this->rebuild();
        $orders = app(SalesOrderService::class);
        $engine = app(ApprovalEngine::class);

        $signed = $orders->submit($this->draft('3')->fresh(['lines']));
        $engine->approve($this->approvalOf($signed), $signer);
        $this->assertSame(S::APPROVED, $signed->fresh()->status, '⛔ শেষ সইয়ের পরেও আদেশ অনুমোদিত নয়।');
        $this->assertNotNull($signed->fresh()->approved_at);
        Event::assertDispatchedTimes(SalesOrderApproved::class, 1);

        $refused = $orders->submit($this->draft('4')->fresh(['lines']));
        $engine->reject($this->approvalOf($refused), $signer, 'দর মেলে না');
        $this->assertSame(S::REJECTED, $refused->fresh()->status, '⛔ ফেরানো আদেশ ফেরত নয়।');
        Event::assertDispatched(SalesOrderCancelled::class, fn (SalesOrderCancelled $e) => $e->payload['sales_order_id'] === (int) $refused->id
            && $e->payload['status'] === S::REJECTED);

        // ⓘ seam: abos-86 মাল আটকানোর পরে অনুমোদিত → সংরক্ষিত; অন্য অবস্থায় কিছুই নয়
        $this->assertSame(S::CONFIRMED, $orders->markConfirmed($signed->fresh())->status);
        $this->assertSame(S::REJECTED, $orders->markConfirmed($refused->fresh())->status);
    }

    /**
     * ⭐ বিক্রয় আদেশ DO-র কাজ নিলে DO-র ভাঁজ মেনু থেকে সরে, কিন্তু খোলা DO-র পাতা খোলে — একই মানুষ, সুইচ বন্ধ তারপর চালু
     * (সমন্বয়ক, ৪ অক্টোবর ২০২৬)।
     */
    public function test_the_do_fold_leaves_the_menu_when_the_order_takes_its_job(): void
    {
        $doList = route('sales.delivery_order.index');
        $doTab = route('sales.delivery_order.index', ['tab' => 'pending']);

        $urls = $this->menuUrls();
        $this->assertContains($doList, $urls, 'প্রস্তুতিটাই ভুল — সুইচ বন্ধে DO-র ভাঁজ মেনুতে নেই।');
        $this->assertContains($doTab, $urls);

        $this->replaceDo(true);

        $urls = $this->menuUrls();
        $this->assertNotContains($doList, $urls, '⛔ নতুন ধারায়ও মেনুতে DO তালিকা।');
        $this->assertNotContains($doTab, $urls, '⛔ নতুন ধারায়ও মেনুতে DO-র ট্যাব।');
        $this->assertContains(route('sales.order.index'), $urls, '⛔ আদেশ তালিকাও মেনু থেকে সরে গেছে।');

        // ⓘ খোলা DO নিজের নম্বরে শেষ হয় — পুরনো লিংক খোলে
        $this->get($doList)->assertOk();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return list<string> */
    private function menuUrls(): array
    {
        $urls = [];

        foreach (app()->make(MenuBuilder::class)->forUser($this->owner->fresh()) as $module) {
            foreach ($module['groups'] as $rows) {
                foreach ($rows as $row) {
                    if (($row['url'] ?? null) !== null) {
                        $urls[] = $row['url'];
                    }
                }
            }
        }

        return $urls;
    }

    private function replaceDo(bool $on): void
    {
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, $on);
    }

    private function limit(string $limit): void
    {
        Customer::query()->whereKey($this->buyer->id)->update(['credit_limit' => $limit]);
    }

    private function draft(string $qty): SalesOrder
    {
        return app(SalesOrderService::class)->create([
            'customer_id' => $this->buyer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'ordered_qty' => $qty,
            'rate' => (string) $this->product->sale_price,
        ]]);
    }

    private function signerFlow(): User
    {
        $signer = $this->member();
        $flow = ApprovalFlow::create(['module' => 'sales', 'action' => 'order']);
        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id,
        ]);
        $this->rebuild();

        return $signer;
    }

    /** ⓘ ইঞ্জিনগুলো ছক মনে রাখে — নতুন ছক বা সুইচের পরে নতুন করে বানানো */
    private function rebuild(): void
    {
        foreach ([ApprovalEngine::class, DocumentApproval::class, SalesOrderService::class] as $class) {
            app()->forgetInstance($class);
        }
    }

    private function member(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    private function approvalOf(SalesOrder $order): ?Approval
    {
        return Approval::query()
            ->where('approvable_type', SalesOrder::class)
            ->where('approvable_id', $order->id)
            ->where('action', 'order')
            ->latest('id')
            ->first();
    }

    private function refused(callable $act, string $field): string
    {
        try {
            $act();
        } catch (ValidationException $e) {
            return (string) ($e->errors()[$field][0] ?? '');
        }

        $this->fail("⛔ কাজটা থামার কথা ছিল ('{$field}')।");
    }
}

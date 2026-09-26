<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Policies\CustomerPolicy;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * সীমা সবার জন্যই সীমা ছিল না।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৫–২৬ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * *"limit mane limit 100%, karo khomota thakbe na limit cross korar, emon ki
 * malikero"*। ⓘ কারণ অঙ্কের: মার্জিন **৩.৮২%**, আর *"ekjaygay taka
 * atkale koyek bochorer lav sesh"*।
 *
 * ── ⛔ যা ধরা পড়েছিল ─────────────────────────────────────────────────
 * ৫০,০০০ সীমার গ্রাহকে ৮৯,৭২০ টাকার কাগজ *"অনুমোদনের জন্য পাঠানো
 * হয়েছে"* বলে দাঁড়িয়ে ছিল। ⚠️ দেয়ালটা ছিল বিলে, আর চালানের অনুমোদন
 * (`assertClear()`) তার **আগে** কাগজ পাঠিয়ে থেমে যেত। তার উপর চারটা
 * দরজা খোলা ছিল: ওভাররাইডের চাবি, "পার হতে দাও" সুইচ, সীমার সুইচ, আর
 * অনুমোদন।
 *
 * ── ⭐ মালিকের তিনটা বাড়তি সিদ্ধান্ত, ২৬ সেপ্টেম্বর ─────────────────────
 *   ⓵ বিল না হওয়া ডিও আর **খসড়া বিল**ও সীমা আটকায়
 *   ⓶ বিক্রয় আদেশ আটকায় না — *"DO/delivery order theke suro hobe"*
 *   ⓷ কাউন্টারে চেক নয় — *"cheek sudu accounts e"*
 *
 * ── ⚠️ অভিনেতা কে ─────────────────────────────────────────────────────
 * বেশিরভাগ দাবি চলে **বিক্রয়কর্মীর** নামে। ⛔ কিন্তু আলাদা একটা দাবি
 * চলে **মালিকের** নামে — সুপার অ্যাডমিন, সব চাবি — কারণ এই কাজের পুরো
 * কথাই হলো *"emon ki malikero"*। ⓘ ফাঁকটা ঠিক ঐ অভিনেতার হাতেই খুলত।
 */
final class TheLimitWasALimitForEveryoneTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private User $salesman;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $this->salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->salesman);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->set('customer.zero_limit_blocks', false);

        $this->limit('1000');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function limit(string $amount): void
    {
        $this->customer->forceFill(['credit_limit' => $amount])->save();
        $this->customer->refresh();
    }

    /** অফিসের একটা খসড়া চালান — `qty` × ১০০ টাকা। */
    private function challan(int $qty): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'delivered_qty' => (string) $qty, 'rate' => '100']],
        );
    }

    /**
     * কাউন্টারের বিক্রয় — `qty` × ১০০ টাকা, বাকিতে।
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function sell(int $qty, string $paying = '0', array $extra = []): array
    {
        return app(DirectSaleService::class)->complete(
            array_merge([
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'deposit' => $paying,
            ], $extra),
            [['product_id' => $this->product->id, 'qty' => (string) $qty, 'rate' => '100']],
        );
    }

    /** ব্যর্থ হলে কোন ঘরের বার্তা — আর কোনো ব্যতিক্রম না হলে দাবিটা ব্যর্থ। */
    private function refusedOn(callable $work): string
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        $this->fail('কিছুই আটকায়নি — সীমা পার করা কাগজ দিব্যি চলে গেল।');
    }

    // ── ⛔ অনুমোদনের আগে ─────────────────────────────────────────────────

    /**
     * ⛔ এটাই মালিকের স্ক্রিনশটের ঘটনা: সীমা পার করা চালান **অনুমোদনে যায় না**।
     *
     * ⚠️ ছক বসানো, সীমাহীন — অর্থাৎ **প্রতিটা** চালানে সই লাগে। ⓘ দেয়াল
     * `assertClear()`-এর পরে থাকলে উত্তর আসত `status` ঘরে, আর সইয়ের
     * অনুরোধ তৈরি হয়ে যেত।
     */
    public function test_an_over_limit_challan_is_refused_not_sent_to_approval(): void
    {
        $this->challanNeedsApproval();

        $challan = $this->challan(15);

        $field = $this->refusedOn(fn () => app(DeliveryChallanService::class)->confirm($challan->fresh(['lines'])));

        $this->assertSame('customer_id', $field,
            "⛔ চালানটা সীমার দেয়ালে থামেনি, থেমেছে `{$field}`-এ — অর্থাৎ অনুমোদনে চলে গেছে।");

        $this->assertSame(0, DB::table('approvals')->where('approvable_id', $challan->id)
            ->where('action', 'challan')->count(),
            '⛔ সীমা পার করা চালানের জন্য সইয়ের অনুরোধ তৈরি হয়েছে — মালিকের ঠিক সেই অভিযোগ।');

        $this->assertSame(DocumentStatus::DRAFT, $challan->fresh()->status);
    }

    /**
     * ⭐ পাল্টা-দাবি: ছকটা সত্যিই চালু — সীমার ভিতরের চালান ঠিকই সইয়ে যায়।
     *
     * ⚠️ এটা ছাড়া উপরের দাবি সবুজ হতে পারত কেবল এই কারণে যে ছকটা কাজই
     * করছে না — তখন "অনুমোদনে যায়নি" কথাটার কোনো মানে থাকত না।
     */
    public function test_a_challan_within_the_limit_still_goes_for_approval(): void
    {
        $this->challanNeedsApproval();

        $challan = $this->challan(5);

        $this->assertSame('status',
            $this->refusedOn(fn () => app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']))),
            '⛔ অনুমোদনের ছকটা কাজ করছে না — উপরের দাবিটা তাহলে কিছুই মাপে না।');
    }

    // ── ⭐ আটকে থাকা টাকা ────────────────────────────────────────────────

    /**
     * ⛔ বিল না হওয়া চালান সীমা আটকায় — ৮০০ গেছে, ৩০০ আর যায় না।
     *
     * ⚠️ বকেয়া তখনো শূন্য (খাতায় কিছু বসেনি), তাই পুরনো হিসাবে দ্বিতীয়
     * চালানটা দিব্যি যেত — তিনটা ডিও মিলে সীমার তিনগুণ মাল।
     */
    public function test_an_unbilled_challan_holds_the_limit(): void
    {
        app(DeliveryChallanService::class)->confirm($this->challan(8)->fresh(['lines']));

        $this->assertSame(0, bccomp($this->customer->fresh()->outstanding(), '0', 4),
            'দৃশ্যটাই বানানো যায়নি — চালানে খাতায় কিছু বসার কথা নয়।');

        $this->assertSame('customer_id',
            $this->refusedOn(fn () => app(DeliveryChallanService::class)->confirm($this->challan(3)->fresh(['lines']))));
    }

    /** ⛔ খসড়া বিলও সীমা আটকায় — *"bill khosora hole … balanceo atkabe"*। */
    public function test_a_draft_bill_holds_the_limit(): void
    {
        $held = $this->sell(8, '0', ['save_as_draft' => '1']);

        $this->assertSame(DocumentStatus::DRAFT, $held['invoice']->status,
            'দৃশ্যটাই বানানো যায়নি — খসড়া রাখা হয়নি।');

        $this->assertSame('customer_id', $this->refusedOn(fn () => $this->sell(3)));
    }

    /** ⛔ সীমার বাইরে খসড়াও হয় না — সীমার বেশি টাকা আটকে রাখা যায় না। */
    public function test_an_over_limit_draft_is_refused_too(): void
    {
        $before = SalesInvoice::query()->count();

        $this->assertSame('customer_id',
            $this->refusedOn(fn () => $this->sell(15, '0', ['save_as_draft' => '1'])));

        $this->assertSame($before, SalesInvoice::query()->count(),
            '⛔ বাধা পেয়েও খসড়াটা থেকে গেছে — লেনদেন ফেরেনি।');
    }

    /**
     * ⭐ একই টাকা দুইবার নয় — চালান আর তার বিল একসাথে।
     *
     * ⓘ ৮০০ টাকার বিক্রয়: চালান আগে নিশ্চিত হয়ে "বিল না হওয়া" হয়, তারপর
     * বিল। ⛔ দুইবার গুনলে ১,৬০০ — ১,০০০ সীমায় আটকে যেত, অথচ আসল বাকি ৮০০।
     */
    public function test_a_sale_is_not_counted_twice_on_its_way_through(): void
    {
        $result = $this->sell(8);

        $this->assertSame(DocumentStatus::CONFIRMED, $result['invoice']->status);
    }

    /** ⭐ ধরে রাখা বিক্রয় শেষ করাও দুইবার গোনে না — খসড়া বিল আর তার চালান এক টাকা। */
    public function test_finishing_a_held_sale_is_not_counted_twice(): void
    {
        $held = $this->sell(8, '0', ['save_as_draft' => '1']);

        $invoice = app(DirectSaleService::class)->finishHeld($held['invoice']);

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status);
    }

    // ── ⭐ টাকা গুনলে পথ খোলে ─────────────────────────────────────────────

    /**
     * ⭐ সীমা ভরা, তবু নগদে পুরো দাম দিলে বিক্রি চলে — চালানের দরজাতেও।
     *
     * ⛔ চালান বিলের **আগে** নিশ্চিত হয়। টাকাটা চালানে না পৌঁছালে দেয়াল
     * পুরো চালানকে বাকি ধরত, আর নগদে কেনা গ্রাহকও আটকাতেন।
     */
    public function test_cash_at_the_counter_passes_the_challan_wall(): void
    {
        $this->sell(10);

        $this->assertSame(0, bccomp($this->customer->fresh()->outstanding(), '1000', 4),
            'দৃশ্যটাই বানানো যায়নি — সীমা ভরা থাকার কথা।');

        $result = $this->sell(5, '500');

        $this->assertSame(DocumentStatus::CONFIRMED, $result['invoice']->status);
    }

    // ── ⛔ কারও জন্য দরজা নেই ─────────────────────────────────────────────

    /** ⛔ মালিকও না — সুপার অ্যাডমিন, সব চাবি। */
    public function test_even_the_owner_cannot_cross_the_limit(): void
    {
        $this->actingAs($this->owner);

        $this->assertTrue($this->owner->roles->contains('name', 'super_admin'),
            'দৃশ্যটাই বানানো যায়নি — মালিক সুপার অ্যাডমিন নন।');

        $this->assertSame('customer_id', $this->refusedOn(fn () => $this->sell(15)));
    }

    /** ⛔ ওভাররাইডের চাবি আর তার নিয়ম — দুইটাই তোলা, ফেরার পথ নেই। */
    public function test_the_override_key_and_its_rule_are_gone(): void
    {
        $this->assertFalse(Permission::query()->where('name', 'customer.credit_limit.override')->exists(),
            '⛔ সীমা পার করার চাবি এখনো আছে — কেউ একদিন কাউকে দিয়ে দেবেন।');

        $this->assertFalse(method_exists(CustomerPolicy::class, 'overrideCreditLimit'),
            '⛔ ওভাররাইডের নিয়ম এখনো নীতিতে আছে।');

        $this->assertArrayNotHasKey('customer.block_over_limit', app(SettingsService::class)->definitions(),
            '⛔ "পার হতে দাও" সুইচ এখনো আছে।');
    }

    // ── ⓘ যা আটকায় না ──────────────────────────────────────────────────

    /** ⭐ বিক্রয় আদেশ আটকায় না — *"sales order astei pare"*। */
    public function test_a_sales_order_is_not_stopped_by_the_limit(): void
    {
        $order = app(SalesOrderService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'ordered_qty' => '50', 'rate' => '100']],
        );

        $order = app(SalesOrderService::class)->confirm($order->fresh(['lines']));

        $this->assertSame(DocumentStatus::CONFIRMED, $order->status,
            '⛔ ৫,০০০ টাকার আদেশ ১,০০০ সীমায় আটকে গেছে — আদেশ আন্দাজের জিনিস।');
    }

    /** ⭐ কোম্পানি সুইচ বন্ধ রাখলে সীমা কিছুই আটকায় না — মালিকের দেওয়া একমাত্র বিকল্প। */
    public function test_with_the_company_switch_off_nothing_is_stopped(): void
    {
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        $this->assertSame(DocumentStatus::CONFIRMED, $this->sell(15)['invoice']->status);
    }

    /**
     * ⛔ সুইচটা কেবল সুপার অ্যাডমিনের।
     *
     * ⚠️ বিপজ্জনক ইনপুট: **সব অনুমতি** আছে এমন একজন, কেবল সুপার অ্যাডমিনের
     * ভূমিকা নেই। ⓘ অনুমতি দিয়ে পাহারা লিখলে ইনি পার পেতেন।
     */
    public function test_only_a_super_admin_can_switch_the_limit_off(): void
    {
        $settings = app(SettingsService::class);

        $this->salesman->givePermissionTo(Permission::all());
        $this->actingAs($this->salesman->fresh());

        $this->put(route('system_admin.control-panel.update'), [
            'scope' => ['customer.credit_limit_enabled'],
            'settings' => [],
        ])->assertRedirect();

        $settings->flush();
        $this->assertTrue($settings->enabled('customer.credit_limit_enabled'),
            '⛔ সুপার অ্যাডমিন নন এমন একজন পুরো কোম্পানির সীমা বন্ধ করে দিলেন।');

        /* ⭐ পাল্টা-দাবি: সুপার অ্যাডমিন ঠিকই পারেন — পাহারা সবাইকে আটকাচ্ছে না */
        $this->actingAs($this->owner);

        $this->put(route('system_admin.control-panel.update'), [
            'scope' => ['customer.credit_limit_enabled'],
            'settings' => [],
        ])->assertRedirect();

        $settings->flush();
        $this->assertFalse($settings->enabled('customer.credit_limit_enabled'),
            '⛔ সুপার অ্যাডমিনও সুইচ বদলাতে পারছেন না — পাহারা সবাইকে আটকাচ্ছে।');
    }

    // ── ⛔ কাউন্টারে চেক নয় ───────────────────────────────────────────────

    /** ⛔ হাতে বানানো অনুরোধে চেকের উপায় পাঠালেও সেবা নেয় না। */
    public function test_a_cheque_is_refused_at_the_counter(): void
    {
        $cheque = PaymentMethod::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'CHQ-T',
            'name_en' => 'Cheque',
            'name_bn' => 'চেক',
            'kind' => 'cheque',
        ]);

        $this->assertSame('deposits', $this->refusedOn(fn () => $this->sell(1, '0', [
            'deposits' => [['amount' => '100', 'payment_method_id' => $cheque->id, 'reference' => 'C-1']],
        ])));
    }

    /** ⓘ অনুমোদনের ছক — চালানে, সীমাহীন, অর্থাৎ প্রতিটা চালানে সই লাগে। */
    private function challanNeedsApproval(): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => 'sales',
            'action' => 'challan',
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $this->owner->id,
        ]);
    }
}

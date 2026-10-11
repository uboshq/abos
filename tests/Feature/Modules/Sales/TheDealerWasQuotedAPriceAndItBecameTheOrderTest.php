<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\PricingRule;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Services\SalesQuotationService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ডিলারকে একটা দর বলা হল, আর সেই দরটাই আদেশ হল — হুবহু।
 *
 * ── ⭐ NEXUS §৮ — বিক্রয় উদ্ধৃতি ─────────────────────────────────────────
 * দর বলা হত মুখে, আর রাজি হলে আদেশটা আবার হাতে লেখা হত। ⚠️ দুই কাগজের
 * দর মিলত কি না কেউ দেখত না — ভুলটা ধরা পড়ত বিলের দিন, ডিলারের সামনে।
 *
 * ── এই ফাইলের দাবিগুলো ─────────────────────────────────────────────────
 *   ⓵ প্রতিটা দরজা তার নিজের চাবিতে খোলে — **একই মানুষ**, চাবি বন্ধ → ৪০৩,
 *      চাবি চালু → কাজ হয়, আর ডাটাবেসে সত্যিই বদলায়
 *   ⓶ রূপান্তরে আদেশের মোট উদ্ধৃতির মোটের সাথে পয়সায় পয়সায় মেলে
 *   ⓷ একই উদ্ধৃতি থেকে দুইটা আদেশ হয় না — বাসি বস্তু হাতে থাকলেও
 *   ⓸ মেয়াদ পেরোলে আদেশ হয় না — আর মেয়াদের শেষ দিনে এখনো হয়
 *   ⓹ অন্য কোম্পানি উদ্ধৃতিটা দেখতেও পায় না, রূপান্তরও করতে পারে না
 *   ⓺ বাকির সীমা এখানে দেখা হয় না (মালিক, ২৬ সেপ্টেম্বর ২০২৬)
 *   ⓻ ছক থাকলে সই ছাড়া অনুমোদন হয় না — যিনি জমা দিলেন তাঁর চাপে নয়
 *
 * ── ⚠️ অভিনেতা ─────────────────────────────────────────────────────────
 * দরজার দাবিগুলো চলে একজন **ভূমিকাহীন** মানুষের নামে, চাবি দাবির
 * ভিতরে দেওয়া হয় ([[same-user-key-off-then-on]]) — ভূমিকার ছাঁচ বদলালে
 * দাবিটা ভুল কারণে লাল হবে না।
 */
final class TheDealerWasQuotedAPriceAndItBecameTheOrderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $outsider;

    private User $salesman;

    private User $owner;

    private Customer $customer;

    private Product $product;

    private string $rate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /* ⭐ ভূমিকাহীন — কারো ভূমিকা ধার করা হয় না, উপরের ব্লকে কেন */
        $this->outsider = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->outsider->companies()->attach($this->company->id, ['is_active' => true]);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();

        /*
         * ⓘ পণ্যের নিজের দামেই দর — দামের নীতি "আটকাও" হলেও দাবিগুলো
         * ভুল কারণে লাল হবে না। দাম না থাকলে ১০০, আর তখন নীতি খাটেই না।
         */
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->rate = bccomp((string) $this->product->sale_price, '0', 4) > 0
            ? bcadd((string) $this->product->sale_price, '0', 4)
            : '100.0000';

        $this->actingAs($this->salesman);
    }

    // ── ⓵ দরজা ও চাবি ──────────────────────────────────────────────────

    /** ⛔ চাবি ছাড়া উদ্ধৃতি লেখা যায় না; ⭐ চাবি দিলে একই মানুষ লেখেন, আর সারিটা সত্যিই বসে। */
    public function test_writing_a_quotation_opens_only_with_the_create_key(): void
    {
        $this->assertFalse($this->outsider->can('sales.quotation.create'),
            'ⓘ এই দাবির ভিত্তি: ভূমিকাহীন মানুষের চাবি নেই। ⛔ থাকলে নিচের ৪০৩ কিছুই প্রমাণ করত না।');

        $before = SalesQuotation::query()->count();

        $this->actingAs($this->outsider)
            ->post(route('sales.quotation.store'), $this->payload())
            ->assertForbidden();

        $this->assertSame($before, SalesQuotation::query()->count(),
            '⛔ ৪০৩ ফিরেছে, তবু উদ্ধৃতি লেখা হয়ে গেছে।');

        $this->outsider->givePermissionTo('sales.quotation.create');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.quotation.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $saved = SalesQuotation::query()->latest('id')->firstOrFail();

        $this->assertSame($before + 1, SalesQuotation::query()->count());
        $this->assertSame(SalesQuotation::DRAFT, $saved->status);
        $this->assertSame($this->customer->id, $saved->customer_id);
        $this->assertSame(now()->addDays(10)->toDateString(), $saved->valid_until->toDateString());
        $this->assertCount(1, $saved->lines);
        $this->assertSame(0, bccomp(bcmul('3', $this->rate, 4), (string) $saved->subtotal, 4),
            '⛔ তিনটা পণ্যের দামে উপ-মোট মেলেনি।');
        $this->assertNotSame('', (string) $saved->document_no,
            '⛔ উদ্ধৃতি নম্বর ছাড়া বসেছে — `QTN` সিরিজ কাজ করেনি।');
    }

    /** ⛔ দেখার চাবি ছাড়া পাতা খোলে না; ⭐ চাবি দিলে একই মানুষ দেখেন, নম্বরসহ। */
    public function test_reading_a_quotation_opens_only_with_the_view_key(): void
    {
        $quotation = $this->draft();

        $this->actingAs($this->outsider)
            ->get(route('sales.quotation.show', $quotation))
            ->assertForbidden();

        $this->outsider->givePermissionTo('sales.quotation.view');

        $this->actingAs($this->outsider->fresh())
            ->get(route('sales.quotation.show', $quotation))
            ->assertOk()
            ->assertSee($quotation->document_no);

        /* ⚠️ তালিকার সারি আঁকার কোড কেবল একটা সারি থাকলেই চলে — উপরে একটা আছে */
        $this->actingAs($this->outsider->fresh())
            ->get(route('sales.quotation.index'))
            ->assertOk()
            ->assertSee($quotation->document_no);
    }

    /** ⛔ সম্পাদনার চাবি ছাড়া দর বদলানো যায় না; ⭐ চাবি দিলে বদলায় — ডাটাবেসে। */
    public function test_changing_a_quotation_opens_only_with_the_update_key(): void
    {
        $quotation = $this->draft();

        $changed = $this->payload(['header_discount' => '10', 'delivery_terms' => 'গুদাম থেকে তুলে নেবেন']);

        $this->actingAs($this->outsider)
            ->put(route('sales.quotation.update', $quotation), $changed)
            ->assertForbidden();

        $this->assertSame(0, bccomp('0', (string) $quotation->fresh()->header_discount, 4),
            '⛔ ৪০৩ ফিরেছে, তবু ছাড়টা বসে গেছে।');

        $this->outsider->givePermissionTo('sales.quotation.update');

        $this->actingAs($this->outsider->fresh())
            ->put(route('sales.quotation.update', $quotation), $changed)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $fresh = $quotation->fresh();

        $this->assertSame(0, bccomp('10', (string) $fresh->header_discount, 4));
        $this->assertSame('গুদাম থেকে তুলে নেবেন', $fresh->delivery_terms);
    }

    /**
     * ⛔ সম্পাদনার চাবি দিয়ে বাতিল হয় না; ⭐ বাতিলের নিজের চাবিতে হয়।
     *
     * ⓘ দুইটা চাবি আলাদা রাখা নকশা, আর এই দাবি ছাড়া কেউ দুইটা এক করে
     * ফেললে কোথাও লাল হত না।
     */
    public function test_the_update_key_does_not_open_the_cancel_door(): void
    {
        $quotation = $this->draft();

        $this->outsider->givePermissionTo('sales.quotation.update');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.quotation.cancel', $quotation), ['reason' => 'ডিলার আর চান না'])
            ->assertForbidden();

        $this->assertSame(SalesQuotation::DRAFT, $quotation->fresh()->status);

        $this->outsider->givePermissionTo('sales.quotation.cancel');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.quotation.cancel', $quotation), ['reason' => 'ডিলার আর চান না'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(SalesQuotation::CANCELLED, $quotation->fresh()->status);
    }

    /**
     * ⛔ রূপান্তরের চাবি একা আদেশ কাটে না — আদেশ তৈরির চাবিও লাগে।
     *
     * ⚠️ নাহলে এই দরজাটা আদেশের নিজের দরজার চেয়ে দুর্বল হত: যাঁর আদেশ
     * কাটার অধিকার নেই, তিনি উদ্ধৃতির পথ ধরে আদেশ কেটে ফেলতেন।
     */
    public function test_converting_needs_the_convert_key_and_the_order_key_together(): void
    {
        $quotation = $this->accepted();

        $this->actingAs($this->outsider)
            ->post(route('sales.quotation.convert', $quotation))
            ->assertForbidden();

        $this->outsider->givePermissionTo('sales.quotation.convert');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.quotation.convert', $quotation))
            ->assertForbidden();

        $this->assertNull($quotation->fresh()->sales_order_id,
            '⛔ আদেশ তৈরির চাবি ছাড়াই উদ্ধৃতি থেকে আদেশ কাটা হয়ে গেছে।');

        $this->outsider->givePermissionTo('sales.order.create');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.quotation.convert', $quotation))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $fresh = $quotation->fresh();

        $this->assertSame(SalesQuotation::CONVERTED, $fresh->status);
        $this->assertNotNull($fresh->sales_order_id);
        $this->assertSame($quotation->id, (int) SalesOrder::query()->findOrFail($fresh->sales_order_id)->sales_quotation_id,
            '⛔ আদেশ জানে না সে কোন উদ্ধৃতি থেকে এসেছে।');
    }

    /**
     * ⛔ ধাপ এগোনো (জমা) সম্পাদনার চাবি ছাড়া হয় না; ⭐ চাবি দিলে একই মানুষ জমা দেন।
     *
     * ⓘ ছক নেই, তাই জমা মানেই অনুমোদিত — ছক থাকলে কী হয় তা ⓻-এ মাপা।
     */
    public function test_moving_a_quotation_forward_needs_the_update_key(): void
    {
        $quotation = $this->draft();

        $this->outsider->givePermissionTo('sales.quotation.view');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.quotation.submit', $quotation))
            ->assertForbidden();

        $this->assertSame(SalesQuotation::DRAFT, $quotation->fresh()->status,
            '⛔ ৪০৩ ফিরেছে, তবু উদ্ধৃতিটা জমা হয়ে গেছে।');

        $this->outsider->givePermissionTo('sales.quotation.update');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.quotation.submit', $quotation))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(SalesQuotation::APPROVED, $quotation->fresh()->status);
    }

    /**
     * ⭐ প্রতিটা পাতা সত্যিই খোলে — একটা সারি বসিয়ে, খালি তালিকায় নয়।
     *
     * ⚠️ তালিকার সারি আঁকার কোড খালি তালিকায় চলেই না; কাগজের পাতাটা
     * PDF — ২০০ আর ধরনটাই প্রমাণ। আদেশের পাতা উৎস উদ্ধৃতির নম্বর দেখায়।
     */
    public function test_every_quotation_page_opens_with_a_real_row(): void
    {
        $quotation = $this->draft();

        $this->actingAs($this->owner)->get(route('sales.quotation.index'))
            ->assertOk()->assertSee($quotation->document_no);
        $this->actingAs($this->owner)->get(route('sales.quotation.create'))->assertOk();
        $this->actingAs($this->owner)->get(route('sales.quotation.edit', $quotation))->assertOk();
        $this->actingAs($this->owner)->get(route('sales.quotation.show', $quotation))
            ->assertOk()->assertSee($quotation->document_no);

        $paper = $this->actingAs($this->owner)->get(route('sales.quotation.paper', $quotation));
        $paper->assertOk();
        $this->assertSame('application/pdf', $paper->headers->get('Content-Type'));

        $accepted = $this->accepted();
        $order = app(SalesQuotationService::class)->convert($accepted);

        $this->actingAs($this->owner)->get(route('sales.order.show', $order))
            ->assertOk()
            ->assertSee($accepted->document_no);
    }

    /**
     * ⭐ ডিলার রাজি, অথচ আদেশের আগেই মেয়াদ গেল — খসড়ায় ফিরিয়ে মেয়াদ বাড়ানো যায়।
     *
     * ⚠️ বিপজ্জনক ইনপুট: আদেশ হয়ে যাওয়া উদ্ধৃতি — সেটা ফেরে না, আদেশটা বেঁচে।
     */
    public function test_an_accepted_quotation_can_go_back_to_draft_but_a_converted_one_cannot(): void
    {
        $service = app(SalesQuotationService::class);

        $open = $this->accepted(validUntil: now()->toDateString());
        $this->travel(1)->days();

        $this->assertSame(SalesQuotation::DRAFT, $service->revise($open->fresh())->status);

        $this->travelBack();

        $converted = $this->accepted();
        $service->convert($converted);

        $this->assertSame('status', $this->refusedOn(fn () => $service->revise($converted->fresh())));
        $this->assertSame(SalesQuotation::CONVERTED, $converted->fresh()->status);
    }

    // ── ⓶ মোট মেলে ─────────────────────────────────────────────────────

    /**
     * ⭐ আদেশের প্রতিটা সারি আর প্রতিটা মোট উদ্ধৃতির সাথে হুবহু।
     *
     * ⚠️ বিপজ্জনক ইনপুট: পুরো কাগজের ছাড় **৩৩.৩৩** — তিন ভাগে সমান ভাগ হয়
     * না, তাই এক পয়সা হারানো বা বাড়ানোর সুযোগ ঠিক এখানেই। সাথে একটা সারির
     * হাতে লেখা ভ্যাট, যেটা আদেশে পুরনো অঙ্কেই যেতে হবে।
     */
    public function test_the_order_carries_the_same_lines_and_the_same_totals(): void
    {
        $quotation = $this->accepted(
            lines: [
                ['product_id' => $this->product->id, 'qty' => '3', 'rate' => $this->rate, 'discount' => '5'],
                ['product_id' => $this->product->id, 'qty' => '1', 'rate' => $this->rate, 'tax' => '7.5'],
                ['product_id' => $this->product->id, 'qty' => '2', 'rate' => $this->rate],
            ],
            header: '33.33',
        );

        $shares = $quotation->lines->reduce(fn (string $c, $l) => bcadd($c, (string) $l->header_share, 4), '0');
        $this->assertSame(0, bccomp('33.33', $shares, 4),
            '⛔ পুরো কাগজের ছাড় সারিতে ভাগ করতে গিয়ে পয়সা হারিয়েছে বা বেড়েছে: '.$shares);

        // ⓘ ছাড় আছে — মালিকের ছাড়ের সই আগে (পুনঃঅডিট ৯ অক্টোবর ২০২৬, বিক্রয় ৭; [[AQuotedDiscountNeedsTheOwnersSignatureTest]])
        $this->signTheDiscount($quotation);
        $order = app(SalesQuotationService::class)->convert($quotation);

        foreach (['subtotal', 'discount', 'tax', 'total'] as $field) {
            $this->assertSame(0, bccomp((string) $quotation->{$field}, (string) $order->{$field}, 4),
                "⛔ আদেশের {$field} ({$order->{$field}}) উদ্ধৃতির ({$quotation->{$field}}) সাথে মেলেনি।");
        }

        $this->assertCount(3, $order->lines);

        foreach ($quotation->lines as $i => $line) {
            $orderLine = $order->lines[$i];

            $this->assertSame(0, bccomp((string) $line->qty, (string) $orderLine->ordered_qty, 4));
            $this->assertSame(0, bccomp((string) $line->rate, (string) $orderLine->rate, 4),
                '⛔ ডিলারকে বলা দর আদেশে বদলে গেছে।');
            $this->assertSame(0, bccomp((string) $line->amount, (string) $orderLine->amount, 4));
        }

        $this->assertSame('draft', SalesOrder::query()->findOrFail($order->id)->status,
            'ⓘ আদেশ খসড়া হয়েই জন্মায় — মজুদ আর অনুমোদন আদেশের নিজের পথে।');
    }

    // ── ⓷ দুইবার নয় ────────────────────────────────────────────────────

    /**
     * ⛔ একই উদ্ধৃতি থেকে দ্বিতীয় আদেশ হয় না।
     *
     * ⚠️ বিপজ্জনক ইনপুট: **বাসি বস্তু** — দ্বিতীয় ট্যাবটা যে বস্তু ধরে
     * আছে, তাতে এখনো "গৃহীত" লেখা। পাহারা যদি হাতের বস্তু দেখত, তালা
     * নয়, তবে এই ডাকটাই দ্বিতীয় আদেশ কাটত।
     */
    public function test_one_quotation_never_becomes_two_orders(): void
    {
        $quotation = $this->accepted();
        $stale = SalesQuotation::query()->findOrFail($quotation->id);

        app(SalesQuotationService::class)->convert($quotation);

        $this->assertSame(SalesQuotation::ACCEPTED, $stale->status,
            'ⓘ দাবির ভিত্তি: হাতের বস্তুটা সত্যিই বাসি।');

        $this->assertSame('status', $this->refusedOn(fn () => app(SalesQuotationService::class)->convert($stale)));

        $this->assertSame(1, SalesOrder::query()->where('sales_quotation_id', $quotation->id)->count(),
            '⛔ একই উদ্ধৃতি থেকে দুইটা আদেশ — মাল দুইবার যেত।');

        /* ⓘ দরজা দিয়েও একই উত্তর — যাঁর দুইটা চাবিই আছে তাঁর জন্যও */
        $this->actingAs($this->owner)
            ->post(route('sales.quotation.convert', $quotation))
            ->assertSessionHasErrors('status');

        $this->assertSame(1, SalesOrder::query()->where('sales_quotation_id', $quotation->id)->count());
    }

    // ── ⓸ মেয়াদ ────────────────────────────────────────────────────────

    /**
     * ⛔ মেয়াদ পেরোনো দরে আদেশ হয় না — আর রাজিও নেওয়া যায় না।
     *
     * ⚠️ সারিতে কেউ "মেয়াদোত্তীর্ণ" লেখেনি; অবস্থা এখনো "গৃহীত"। পাহারাটা
     * তারিখ থেকে গোনে — রাতের কাজ না চললেও দরজা বন্ধ থাকে।
     */
    public function test_an_expired_quotation_cannot_become_an_order(): void
    {
        $quotation = $this->accepted(validUntil: now()->toDateString());
        $sent = $this->sent(validUntil: now()->toDateString());

        $this->travel(1)->days();

        $this->assertSame(SalesQuotation::ACCEPTED, $quotation->fresh()->status);
        $this->assertSame(SalesQuotation::EXPIRED, $quotation->fresh()->effectiveStatus());

        $this->assertSame('status', $this->refusedOn(fn () => app(SalesQuotationService::class)->convert($quotation->fresh())));
        $this->assertSame('status', $this->refusedOn(fn () => app(SalesQuotationService::class)->accept($sent->fresh())));

        $this->assertSame(0, SalesOrder::query()->where('sales_quotation_id', $quotation->id)->count(),
            '⛔ মেয়াদ পেরোনো দরে আদেশ কাটা হয়ে গেছে।');

        $this->assertSame(1, SalesQuotation::query()->expired()->whereKey($quotation->id)->count(),
            '⛔ তালিকার "মেয়াদোত্তীর্ণ" ছাঁকনি উদ্ধৃতিটা খুঁজে পায়নি, অথচ পাতা বলছে মেয়াদোত্তীর্ণ।');
    }

    /** ⭐ মেয়াদের শেষ দিনটা নিজে বৈধ — "৩০ তারিখ পর্যন্ত" মানে ৩০ তারিখেও চলে। */
    public function test_the_last_day_of_validity_still_converts(): void
    {
        $quotation = $this->accepted(validUntil: now()->toDateString());

        $order = app(SalesQuotationService::class)->convert($quotation);

        $this->assertSame($quotation->id, (int) $order->fresh()->sales_quotation_id);
    }

    // ── ⓹ অন্য কোম্পানি ────────────────────────────────────────────────

    /**
     * ⛔ অন্য কোম্পানি থেকে উদ্ধৃতিটা দেখা যায় না, রূপান্তরও হয় না।
     *
     * ⚠️ **একই মানুষ** (মালিক, দুই কোম্পানিতেই আছেন, সব চাবি) — কেবল
     * বাছা কোম্পানিটা বদলায়। ⓘ আগে সেখানে তালিকাটা খোলে (২০০), তাই ৪০৪
     * মানে "পর্দা বন্ধ" নয়, "কাগজটা এই কোম্পানির নয়"।
     */
    public function test_another_company_can_neither_see_nor_convert_it(): void
    {
        $quotation = $this->accepted();

        $this->actingAs($this->owner)
            ->get(route('sales.quotation.show', $quotation))
            ->assertOk();

        $mart = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->owner->switchCompany($mart->id);

        $this->actingAs($this->owner->fresh())
            ->get(route('sales.quotation.index'))
            ->assertOk()
            ->assertDontSee($quotation->document_no);

        $this->actingAs($this->owner->fresh())
            ->get(route('sales.quotation.show', $quotation))
            ->assertNotFound();

        $this->actingAs($this->owner->fresh())
            ->post(route('sales.quotation.convert', $quotation))
            ->assertNotFound();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->assertNull($quotation->fresh()->sales_order_id,
            '⛔ অন্য কোম্পানি থেকে এই উদ্ধৃতির আদেশ কাটা হয়ে গেছে।');
    }

    // ── ⓺ বাকির সীমা ────────────────────────────────────────────────────

    /**
     * ⭐ সীমা ছাড়ানো ডিলারকেও দর বলা যায় — সীমা খাটে চালান ও বিলে।
     *
     * ⓘ মালিক, ২৬ সেপ্টেম্বর ২০২৬: *"DO/delivery order theke suro hobe"*।
     * ⚠️ বিপজ্জনক ইনপুট: সীমা **এক টাকা**, সুইচ চালু — এখানে কেউ সীমা
     * বসিয়ে দিলে ডিলারকে দামই বলা যেত না।
     */
    public function test_the_credit_limit_does_not_stop_a_quotation(): void
    {
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->set('customer.zero_limit_blocks', false);
        $this->customer->forceFill(['credit_limit' => '1'])->save();

        $quotation = $this->accepted(lines: [
            ['product_id' => $this->product->id, 'qty' => '500', 'rate' => $this->rate],
        ]);

        $this->assertSame(SalesQuotation::ACCEPTED, $quotation->status);
    }

    // ── ⓻ অনুমোদন ──────────────────────────────────────────────────────

    /**
     * ⛔ ছক থাকলে জমা মানেই অনুমোদন নয় — আর যিনি জমা দিলেন তিনি বোতাম
     * আবার চাপলেও নয়। ⭐ সইদাতা সই দিলে তবেই।
     */
    public function test_with_a_rule_only_a_human_signature_approves(): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => 'sales',
            'action' => SalesQuotation::APPROVAL_ACTION,
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

        $service = app(SalesQuotationService::class);
        $quotation = $this->draft();

        $this->refusedOn(fn () => $service->submit($quotation));
        $this->assertSame(SalesQuotation::SUBMITTED, $quotation->fresh()->status,
            '⛔ ছক থাকতেও জমা দেওয়ার সাথে সাথে অনুমোদিত — মানুষ ছাড়া সই।');

        $this->refusedOn(fn () => $service->approve($quotation->fresh()));
        $this->assertSame(SalesQuotation::SUBMITTED, $quotation->fresh()->status,
            '⛔ জমাদাতা নিজে আবার চেপেই অনুমোদন পেয়ে গেছেন।');

        $approval = Approval::query()
            ->where('approvable_type', SalesQuotation::class)
            ->where('approvable_id', $quotation->id)
            ->latest('id')
            ->firstOrFail();

        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $service->approve($quotation->fresh());

        $this->assertSame(SalesQuotation::APPROVED, $quotation->fresh()->status);
    }

    /**
     * ⛔ বিলে যে দর আটকাবে, উদ্ধৃতিতেও সেটা বলা যায় না।
     *
     * ⚠️ বিপজ্জনক ইনপুট: পণ্যের দামের অর্ধেক, নীতি "আটকাও", সহনসীমা শূন্য।
     */
    public function test_a_price_the_bill_would_refuse_cannot_be_quoted(): void
    {
        $product = Product::query()->where('sale_price', '>', 0)->orderBy('id')->first();

        if ($product === null) {
            $this->fail('ⓘ ডেমোতে দামওয়ালা কোনো পণ্য নেই — দাবিটা মাপার কিছু পেল না।');
        }

        $settings = app(SettingsService::class);
        $settings->set(PricingRule::POLICY, PricingRule::BLOCK);
        $settings->set(PricingRule::BELOW, true);
        $settings->set(PricingRule::TOLERANCE, 0);

        $before = SalesQuotation::query()->count();

        $this->assertSame('lines', $this->refusedOn(fn () => $this->draft(lines: [[
            'product_id' => $product->id,
            'qty' => '1',
            'rate' => bcdiv((string) $product->sale_price, '2', 4),
        ]])));

        $this->assertSame($before, SalesQuotation::query()->count());
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'trx_date' => now()->toDateString(),
            'valid_until' => now()->addDays(10)->toDateString(),
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => '3', 'rate' => $this->rate],
            ],
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>|null  $lines
     */
    private function draft(?array $lines = null, string $header = '0', ?string $validUntil = null): SalesQuotation
    {
        return app(SalesQuotationService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'trx_date' => now()->toDateString(),
                'valid_until' => $validUntil ?? now()->addDays(10)->toDateString(),
                'header_discount' => $header,
            ],
            $lines ?? [['product_id' => $this->product->id, 'qty' => '3', 'rate' => $this->rate]],
        );
    }

    /**
     * @param  list<array<string, mixed>>|null  $lines
     */
    private function sent(?array $lines = null, string $header = '0', ?string $validUntil = null): SalesQuotation
    {
        $service = app(SalesQuotationService::class);

        // ⓘ ছক নেই, তাই জমা দিলেই অনুমোদিত — ছক থাকলে কী হয় তা ⓻-এ মাপা
        $approved = $service->submit($this->draft($lines, $header, $validUntil));

        return $service->markSent($approved);
    }

    /**
     * @param  list<array<string, mixed>>|null  $lines
     */
    private function accepted(?array $lines = null, string $header = '0', ?string $validUntil = null): SalesQuotation
    {
        return app(SalesQuotationService::class)->accept($this->sent($lines, $header, $validUntil));
    }

    /** ⓘ ছাড়ের সই — রূপান্তর অনুরোধ বসিয়ে থামে, মালিক সই দেন */
    private function signTheDiscount(SalesQuotation $quotation): void
    {
        $signer = User::factory()->create(['current_company_id' => $this->company->id]);
        $signer->companies()->attach($this->company->id, ['is_active' => true]);
        $flow = ApprovalFlow::query()->where('module', 'sales')->where('action', 'discount')->firstOrFail();
        $flow->steps()->delete();
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $signer->id, 'requires_all' => false]);
        $this->app->forgetInstance(ApprovalEngine::class);
        $this->app->forgetScopedInstances();

        $this->assertSame('discount', $this->refusedOn(fn () => app(SalesQuotationService::class)->convert($quotation)));
        $asked = Approval::query()->where('approvable_type', $quotation->getMorphClass())->where('approvable_id', $quotation->id)
            ->where('action', 'discount')->where('status', Approval::PENDING)->firstOrFail();
        app(ApprovalEngine::class)->approve($asked, $signer, 'ঠিক আছে');
    }

    /** ব্যর্থ হলে কোন ঘরের বার্তা — আর কোনো ব্যতিক্রম না হলে দাবিটা ব্যর্থ। */
    private function refusedOn(callable $work): string
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        $this->fail('⛔ কাজটা আটকানোর কথা ছিল, অথচ পার হয়ে গেছে।');
    }
}

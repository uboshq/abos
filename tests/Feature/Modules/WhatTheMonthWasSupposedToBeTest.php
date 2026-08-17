<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesTarget;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesTargetService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * মাসটার কত হওয়ার কথা ছিল।
 *
 * ── কী ছিল না ───────────────────────────────────────────────────────
 * ABOS বলতে পারত "করিম এ মাসে ৪,২০,০০০ টাকা বিক্রি করেছেন"। কিন্তু
 * ডিপোতে প্রশ্নটা কখনো ওটা নয় — প্রশ্নটা **"টার্গেটের কত পারসেন্ট
 * হলো?"** টার্গেট বলে কোনো সংখ্যা কোথাও লেখা হত না, তাই প্রশ্নটার
 * উত্তর দেওয়াই অসম্ভব ছিল। মালিকের উত্তর, ১৬ আগস্ট: *ডিপোতে
 * বিক্রয়কর্মীর টার্গেট ধরা হয়।*
 */
class WhatTheMonthWasSupposedToBeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function sell(string $amount, ?string $on = null): void
    {
        $service = app(SalesInvoiceService::class);

        $invoice = $service->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => $on ?? now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => $amount]],
        );

        $service->confirm($invoice);
    }

    private function rowFor(User $user, ?Carbon $month = null): ?array
    {
        foreach (app(SalesTargetService::class)->scoreboard($month ?? Carbon::today()) as $row) {
            if ($row['user']->id === $user->id) {
                return $row;
            }
        }

        return null;
    }

    // ── বসানো ───────────────────────────────────────────────────────

    /** পর্দা থেকে টার্গেট বসে, আর মাসটা সবসময় ১ তারিখে গুটিয়ে যায়। */
    public function test_a_target_is_set_for_a_month(): void
    {
        $this->post(route('sales.target.store'), [
            'month' => now()->toDateString(),
            'amount' => [$this->owner->id => '50000'],
        ])->assertRedirect();

        $target = SalesTarget::query()->where('user_id', $this->owner->id)->first();

        $this->assertNotNull($target, 'টার্গেটটা বসেনি।');
        $this->assertSame(now()->startOfMonth()->toDateString(), $target->month->toDateString(),
            'মাসটা ১ তারিখে গুটিয়ে যায়নি — একই মাসের দুইটা সারি বসতে পারত।');
    }

    /**
     * একজনের এক মাসে একটাই টার্গেট।
     *
     * দুইবার বসালে দ্বিতীয়টা প্রথমটার জায়গায় বসে, পাশে নয় — নাহলে
     * "টার্গেট কত" প্রশ্নটার দুইটা উত্তর থাকত।
     */
    public function test_setting_it_twice_replaces_it(): void
    {
        $this->post(route('sales.target.store'), [
            'month' => now()->toDateString(),
            'amount' => [$this->owner->id => '50000'],
        ]);

        $this->post(route('sales.target.store'), [
            'month' => now()->toDateString(),
            'amount' => [$this->owner->id => '70000'],
        ]);

        $targets = SalesTarget::query()->where('user_id', $this->owner->id)->get();

        $this->assertCount(1, $targets);
        $this->assertSame(0, bccomp((string) $targets->first()->amount, '70000', 4));
    }

    /**
     * খালি ঘর মানে টার্গেট নেই, শূন্য টাকার টার্গেট নয়।
     *
     * উল্টোটা ধরলে প্রত্যেকের অর্জন সাথে সাথেই অসীম শতাংশ দেখাত।
     */
    public function test_an_empty_box_means_no_target_at_all(): void
    {
        $this->post(route('sales.target.store'), [
            'month' => now()->toDateString(),
            'amount' => [$this->owner->id => '50000'],
        ]);

        $this->post(route('sales.target.store'), [
            'month' => now()->toDateString(),
            'amount' => [$this->owner->id => ''],
        ]);

        $this->assertNull(SalesTarget::query()->where('user_id', $this->owner->id)->first(),
            'খালি ঘর দিয়েও টার্গেটটা রয়ে গেছে।');

        $this->assertNull($this->rowFor($this->owner)['percent'],
            'টার্গেট নেই, তবু একটা শতাংশ দেখানো হচ্ছে।');
    }

    // ── অর্জন ───────────────────────────────────────────────────────

    /** বিক্রি করলে অর্জন বাড়ে, আর শতাংশটা সেই অনুপাতেই। */
    public function test_what_was_sold_counts_towards_the_target(): void
    {
        app(SalesTargetService::class)->setForMonth(now(), [$this->owner->id => '1000']);

        $this->sell('250');

        $row = $this->rowFor($this->owner);

        $this->assertSame(0, bccomp($row['achieved'], '250', 4));
        $this->assertSame(0, bccomp($row['percent'], '25.0', 1));
    }

    /**
     * খসড়া বিল অর্জনে গোনা হয় না।
     *
     * ── কেন এটাই সবচেয়ে জরুরি পরীক্ষা ───────────────────────────────
     * গুনলে মাসের শেষ দিনে কয়েকটা খসড়া বিল লিখে টার্গেট পূরণ দেখানো
     * যেত, আর ওটাই সবচেয়ে সহজ ফাঁকি — কাগজগুলো পরে মুছে ফেললেই হত।
     */
    public function test_a_draft_invoice_does_not_count(): void
    {
        app(SalesTargetService::class)->setForMonth(now(), [$this->owner->id => '1000']);

        app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => '900']],
        );

        $this->assertSame(0, bccomp($this->rowFor($this->owner)['achieved'], '0', 4),
            'খসড়া বিলের টাকা অর্জনে গোনা হয়েছে।');
    }

    /** অন্য মাসের বিক্রয় এই মাসের অর্জনে আসে না। */
    public function test_last_months_sales_belong_to_last_month(): void
    {
        app(SalesTargetService::class)->setForMonth(now(), [$this->owner->id => '1000']);

        $this->sell('700', now()->subMonthNoOverflow()->startOfMonth()->addDay()->toDateString());

        $this->assertSame(0, bccomp($this->rowFor($this->owner)['achieved'], '0', 4),
            'গত মাসের বিক্রয় এই মাসের ঘরে এসে পড়েছে।');
    }

    /** টার্গেট না থাকলেও বিক্রয়টা দেখা যায় — কে মাপা হচ্ছে না, সেটাও তথ্য। */
    public function test_somebody_with_no_target_still_shows_their_sales(): void
    {
        $this->sell('400');

        $row = $this->rowFor($this->owner);

        $this->assertNull($row['target']);
        $this->assertSame(0, bccomp($row['achieved'], '400', 4));
    }

    // ── পর্দা ও অনুমতি ──────────────────────────────────────────────

    /** পর্দাটা খোলে আর সংখ্যাগুলো দেখায়। */
    public function test_the_screen_shows_the_month(): void
    {
        app(SalesTargetService::class)->setForMonth(now(), [$this->owner->id => '1000']);
        $this->sell('250');

        $this->get(route('sales.target.index'))
            ->assertOk()
            ->assertSee($this->owner->name)
            ->assertSee('25.0%');
    }

    /**
     * নিজের টার্গেট নিজে বদলানো যায় না।
     *
     * ── কেন দুইটা আলাদা চাবি ────────────────────────────────────────
     * বদলাতে পারলে ওটা আর টার্গেট নয়, ইচ্ছা — মাসের ২৮ তারিখে সংখ্যাটা
     * নামিয়ে দিলে অর্জন হঠাৎ ১২০% দেখাত।
     */
    public function test_a_salesman_can_look_but_not_change(): void
    {
        $seller = User::query()->where('email', 'sales@abos.test')->first();

        if ($seller === null) {
            $this->markTestSkipped('ডেমোতে বিক্রয়কর্মীর অ্যাকাউন্ট নেই।');
        }

        $this->actingAs($seller)->get(route('sales.target.index'))->assertOk();

        $this->actingAs($seller)->post(route('sales.target.store'), [
            'month' => now()->toDateString(),
            'amount' => [$seller->id => '1'],
        ])->assertForbidden();

        $this->assertNull(SalesTarget::query()->where('user_id', $seller->id)->first(),
            'নিষেধ সত্ত্বেও টার্গেটটা বসে গেছে।');
    }

    /** টার্গেট বদল খাতায় ওঠে — এর উপর মানুষের মূল্যায়ন ভর করে। */
    public function test_changing_a_target_leaves_a_trace(): void
    {
        app(SalesTargetService::class)->setForMonth(now(), [$this->owner->id => '1000']);
        app(SalesTargetService::class)->setForMonth(now(), [$this->owner->id => '400']);

        $target = SalesTarget::query()->where('user_id', $this->owner->id)->firstOrFail();

        $this->assertTrue(
            AuditTrail::query()
                ->where('auditable_type', SalesTarget::class)
                ->where('auditable_id', $target->id)
                ->exists(),
            'টার্গেট বদলের কোনো চিহ্ন খাতায় নেই।');
    }
}

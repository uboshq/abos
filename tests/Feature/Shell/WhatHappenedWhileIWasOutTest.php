<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Dashboard\ActivityRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * আমি না থাকতে কী কী হলো।
 *
 * ── কেন এটা করণীয় তালিকার চেয়ে আলাদা ────────────────────────────────
 * করণীয় বলে **কী আটকে আছে** — ভবিষ্যতের কাজ। কিন্তু দিনের শুরুতে
 * মালিকের প্রথম প্রশ্নটা সেটা নয়; প্রশ্নটা হলো কাল বিকেলে দোকানে কী কী
 * ঘটেছে। আজ পর্যন্ত তার উত্তর পেতে বিক্রয়, আদায়, ক্রয় আর নগদ গণনার
 * চারটা তালিকা আলাদা করে খুলে তারিখ ধরে ছাঁকতে হত।
 */
class WhatHappenedWhileIWasOutTest extends TestCase
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
    }

    private function sell(string $rate = '1000'): SalesInvoice
    {
        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => $rate]],
        );

        return app(SalesInvoiceService::class)->confirm($invoice);
    }

    // ── তালিকাটা সত্যিই ভরে ─────────────────────────────────────────

    /** একটা বিল কাটলে সেটা তালিকায় আসে। */
    public function test_a_confirmed_invoice_shows_up(): void
    {
        $invoice = $this->sell();

        $rows = app(ActivityRegistry::class)->forUser($this->owner);

        $this->assertNotSame([], $rows, 'কিছুই ঘটেনি বলে দেখাচ্ছে, অথচ একটা বিল কাটা হয়েছে।');

        $titles = array_map(fn ($r) => $r->title, $rows);

        $this->assertNotEmpty(array_filter($titles,
            fn ($t) => str_contains($t, $invoice->document_no)));
    }

    /**
     * খসড়া আসে না।
     *
     * খসড়া "হয়েছে" নয়, "শুরু হয়েছে" — ওগুলোর জায়গা করণীয় তালিকায়,
     * আর সেখানে ওরা আছেও। দুই জায়গায় দেখালে চার সারির তালিকাটা একই
     * জিনিসে ভরে যেত।
     */
    public function test_a_draft_does_not(): void
    {
        app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => '777']],
        );

        $titles = array_map(fn ($r) => $r->title, app(ActivityRegistry::class)->forUser($this->owner));

        $this->assertEmpty(array_filter($titles, fn ($t) => str_contains($t, '777')),
            'খসড়াটাও "সদ্য হয়েছে" তালিকায় এসেছে।');
    }

    /** সাম্প্রতিকতমটা আগে — নাহলে তালিকাটা "সদ্য" নয়। */
    public function test_the_newest_is_first(): void
    {
        $this->sell('100');
        $newest = $this->sell('200');

        $rows = app(ActivityRegistry::class)->forUser($this->owner);

        $this->assertStringContainsString($newest->document_no, $rows[0]->title);
    }

    /**
     * চারটার বেশি নয়।
     *
     * পাশের করণীয় তালিকাটাও চার-পাঁচ সারির; দুইটা কার্ড সমান উঁচু না
     * হলে পর্দাটা একদিকে হেলে থাকে। আর যে পঞ্চম ঘটনাটা কেউ দেখল না,
     * সেটা তালিকায় থাকা আর না থাকা সমান।
     */
    public function test_it_stops_at_four(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->sell((string) (100 + $i));
        }

        $this->assertCount(4, app(ActivityRegistry::class)->forUser($this->owner));
    }

    // ── অনুমতি ──────────────────────────────────────────────────────

    /** @param  list<string>  $extra */
    private function clerk(array $extra = []): User
    {
        foreach (['sales.invoice.view', 'sales.collection.view'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();
        $user->givePermissionTo($extra);

        return $user->fresh();
    }

    /**
     * যাঁর চাবি নেই তিনি টাকার অঙ্কটা দেখেন না।
     *
     * ── কেন এটাই সবচেয়ে জরুরি পরীক্ষা ───────────────────────────────
     * এই তালিকায় টাকার অঙ্ক থাকে, আর হোম পর্দাটা সবার। ডেলিভারিম্যানের
     * পর্দায় "INV-0031 · ৳4,050" ভেসে উঠলে সেটা ফাঁস — আর ফাঁসটা
     * কোথাও ভুল বলে দেখাত না, সবকিছু কাজ করতেই থাকত।
     */
    public function test_somebody_without_the_key_sees_none_of_it(): void
    {
        $this->sell();

        $this->assertSame([], app(ActivityRegistry::class)->forUser($this->clerk()),
            'অনুমতি ছাড়াই বিলের অঙ্ক তালিকায় এসেছে।');
    }

    /** চাবি থাকলে দেখেন — নাহলে উপরেরটা কিছুই প্রমাণ করত না। */
    public function test_somebody_with_it_does(): void
    {
        $this->sell();

        $this->assertNotSame([],
            app(ActivityRegistry::class)->forUser($this->clerk(['sales.invoice.view'])));
    }

    // ── পর্দা ───────────────────────────────────────────────────────

    /** সারিটা হোম পর্দায় সত্যিই ছাপা হয়। */
    public function test_it_reaches_the_home_screen(): void
    {
        $invoice = $this->sell();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('core.dashboard.just_happened'))
            ->assertSee($invoice->document_no);
    }

    /**
     * সারিটা থেকে কাগজটায় যাওয়া যায় — নিয়ম ১।
     *
     * "৳4,050 বিক্রয়" পড়ে মালিক জানতে চান কার কাছে, কী কী। লিংক ছাড়া
     * সারিটা কেবল একটা ঘোষণা, আর ঘোষণা যাচাই করা যায় না।
     */
    public function test_each_row_goes_to_its_document(): void
    {
        $invoice = $this->sell();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('sales.invoice.show', ['invoice' => $invoice->id]), false);
    }

    /**
     * কিছু না ঘটলে কার্ডটাই থাকে না।
     *
     * "কিছু হয়নি" লেখা একটা খালি কার্ড পাশের করণীয় তালিকাটাকে অর্ধেক
     * করে দিত, কোনো তথ্য না দিয়ে।
     */
    public function test_an_empty_day_shows_no_card(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(__('core.dashboard.just_happened'));
    }
}

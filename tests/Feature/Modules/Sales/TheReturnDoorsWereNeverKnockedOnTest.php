<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ফেরতের চারটা দরজার একটাতেও কেউ কোনোদিন কড়া নাড়েনি।
 *
 * ── ⛔ কেন এই ফাইলটা, ২৬ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * বিক্রয়ের চেকলিস্টে ১০৪টা রুট একে একে খুঁজে দেখা গেছে **৩৭টা দরজা
 * কোনো পরীক্ষা নাম ধরে ডাকে না** (`de052cdc`)। ⓘ ফেরতে **৮টার ৪টা**।
 *
 * ⓘ কাজটা মাপা — `SalesReturnTest`-এ ৭টা দাবি, সবগুলো
 * `SalesReturnService` ধরে। ⚠️ দরজার দিকে কেবল পড়ার চারটা
 * (`index`·`create`·`show`·`edit`, ঐ ফাইলের ২৫৯-২৬২ লাইন) — লেখার
 * চারটা নয়।
 *
 * ── ⚠️ এখানে দুইটা আলাদা চাবি, আর সেটাই পরীক্ষার আসল কথা ─────────────
 * ```
 * confirm → sales.return.create    (মাল গুদামে ফেরে, খাতায় দাখিলা বসে)
 * cancel  → sales.return.cancel    (আলাদা চাবি, ইচ্ছাকৃত)
 * ```
 * ⭐ দুইটা আলাদা হওয়াটা নকশা: যিনি ফেরত নিতে পারেন, তিনি একটা নেওয়া
 * ফেরত মুছে দিতে পারবেন না। ⛔ কিন্তু কোনো পরীক্ষা এটা মাপত না, তাই
 * দুইটা এক করে ফেললেও কোথাও লাল হত না।
 *
 * ── ⭐ নকশা: ভূমিকাহীন লোক, চাবি দাবির ভিতরে ─────────────────────────
 * ⚠️ কোনো ডেমো-ভূমিকা ধার করা হয় না। ⓘ মালিকের সিদ্ধান্তে ভূমিকার ছাঁচ
 * বদলাচ্ছে (২৬ সেপ্টেম্বর), তাই ভূমিকার উপর দাঁড়ানো দাবি কাল **ভুল
 * কারণে** লাল হত ([[same-user-key-off-then-on]])।
 */
final class TheReturnDoorsWereNeverKnockedOnTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $outsider;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /* ⭐ ভূমিকাহীন — কারো ভূমিকা ধার করা হয় না, উপরের ব্লকে কেন */
        $this->outsider = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->outsider->companies()->attach($this->company->id, ['is_active' => true]);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();

        /* ⛔ কাউন্টারের সুইচ — বিলটা ওখান থেকেই বানানো হয় */
        app(SettingsService::class)->set('sales.screen_pos', true);
    }

    /**
     * ⛔ চাবি ছাড়া ফেরত নিশ্চিত করা যায় না — মাল গুদামে ফেরে না।
     *
     * ⚠️ দ্বিতীয় দাবিটাই আসল: ৪০৩ ফেরত দিয়েও যদি কাগজটা পাশ হয়ে যেত,
     * তবে মজুদ বেড়ে যেত আর কেউ জানত না।
     */
    public function test_a_return_cannot_be_confirmed_without_the_key(): void
    {
        $return = $this->draftReturn();

        $this->assertFalse($this->outsider->can('sales.return.create'),
            'ⓘ এই দাবির ভিত্তি: ভূমিকাহীন ব্যবহারকারীর ফেরতের চাবি নেই। '
            .'⛔ থাকলে নিচের ৪০৩ কিছুই প্রমাণ করত না।');

        $this->actingAs($this->outsider)
            ->post(route('sales.return.confirm', $return))
            ->assertForbidden();

        $this->assertTrue($return->fresh()->isDraft(),
            '⛔ ৪০৩ ফিরেছে, তবু কাগজটা পাশ হয়ে গেছে — মাল মজুদে উঠেছে।');
    }

    /** ⭐ চাবিটাই দরজা খোলে — আর খুলে কাগজটা সত্যিই পাশ করে। */
    public function test_the_key_is_what_opens_the_confirm_door(): void
    {
        $return = $this->draftReturn();

        $this->outsider->givePermissionTo('sales.return.create');

        /* ⚠️ যাচাই-ব্যর্থতাও ৩০২, তাই আলাদা করে দেখা */
        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.return.confirm', $return))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(DocumentStatus::CONFIRMED, $return->fresh()->status,
            '⛔ দরজা খুলেছে, কিন্তু কাগজটা খসড়াই — ৩০২ এসেছে, কাজ হয়নি।');
    }

    /**
     * ⛔ ফেরত নেওয়ার চাবি দিয়ে সেটা **বাতিল** করা যায় না।
     *
     * ⭐ এটাই এই ফাইলের সবচেয়ে জরুরি দাবি। ⓘ দুইটা চাবি আলাদা রাখা
     * হয়েছে ইচ্ছাকৃতভাবে, আর এই দাবিটা ছাড়া কেউ দুইটা এক করে ফেললে
     * কোথাও লাল হত না।
     *
     * ⚠️ কী হারাত: যিনি ফেরত নেন, তিনিই একটা নেওয়া ফেরত মুছে দিতে
     * পারতেন — মাল ফিরেছে, কাগজ নেই।
     */
    public function test_the_confirm_key_does_not_open_the_cancel_door(): void
    {
        $return = $this->draftReturn();

        $this->outsider->givePermissionTo('sales.return.create');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.return.cancel', $return), ['reason' => 'ভুল করে বসানো'])
            ->assertForbidden();

        $this->assertFalse($return->fresh()->isCancelled(),
            '⛔ ফেরত নেওয়ার চাবি দিয়েই কাগজটা বাতিল হয়ে গেছে — '
            .'দুইটা চাবি আলাদা রাখার কোনো মানে থাকল না।');
    }

    /** ⭐ বাতিলের নিজের চাবিতে বাতিল হয়। */
    public function test_the_cancel_key_is_what_opens_the_cancel_door(): void
    {
        $return = $this->draftReturn();

        $this->outsider->givePermissionTo('sales.return.cancel');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.return.cancel', $return), ['reason' => 'ভুল করে বসানো'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertTrue($return->fresh()->isCancelled(),
            '⛔ দরজা খুলেছে, কিন্তু কাগজটা বাতিল হয়নি।');
    }

    /**
     * ⚠️ কারণ ছাড়া বাতিল হয় না — নিয়ামকের যাচাইটাও দরজা ধরেই মাপা।
     *
     * ⓘ কারণটা পরে কেউ পড়েন: *"মালটা কেন ফেরত আসেনি"*। ⛔ ঘরটা খালি
     * রেখে বাতিল করা গেলে ঐ প্রশ্নের উত্তর কোথাও থাকত না।
     */
    public function test_a_cancellation_needs_a_reason(): void
    {
        $return = $this->draftReturn();

        $this->outsider->givePermissionTo('sales.return.cancel');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.return.cancel', $return), [])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($return->fresh()->isCancelled());
    }

    /**
     * একটা খসড়া ফেরত — সেবা ধরে, কারণ এখানে দরজাটা মাপার জিনিস নয়।
     *
     * ⓘ মূল বিলটা বাধ্যতামূলক: কোন বিলের মাল ফিরছে না জানলে ওই মালের
     * খরচ কত ছিল তাও জানা যায় না (`SalesReturnTest:130-131`)।
     */
    private function draftReturn(): SalesReturn
    {
        $invoice = $this->soldAtTheCounter();

        return app(SalesReturnService::class)->create(
            [
                'customer_id' => $invoice->customer_id,
                'warehouse_id' => $this->warehouse->id,
                'sales_invoice_id' => $invoice->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $this->product->id,
                'sales_invoice_line_id' => $invoice->lines->first()->id,
                'qty' => '1',
            ]],
        );
    }

    /** কাউন্টারে একটা বিক্রি — মালিকের হাতে, কারণ তাঁর চাবি আছে। */
    private function soldAtTheCounter(): SalesInvoice
    {
        $this->actingAs($this->owner)
            ->post(route('sales.pos.checkout'), [
                'warehouse_id' => $this->warehouse->id,
                'paid' => '300',
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => '2', 'rate' => '150'],
                ],
            ]);

        return SalesInvoice::query()->with('lines')->latest('id')->firstOrFail();
    }
}

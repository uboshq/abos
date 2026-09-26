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
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টার থেকে মাল ফেরত নেওয়ার দরজায় কেউ কোনোদিন কড়া নাড়েনি।
 *
 * ── ⛔ কেন এই ফাইলটা, ২৬ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * বিক্রয়ের চেকলিস্টে ১০৪টা রুট একে একে খুঁজে দেখা গেছে **৩৭টা দরজা
 * কোনো পরীক্ষা নাম ধরে ডাকে না** (`de052cdc`)। ⓘ `sales.pos.return`
 * তাদের মধ্যে সবচেয়ে ভারীটা: **মাল গুদামে ফেরে, আর টাকা ড্রয়ার থেকে
 * বেরোতে পারে**।
 *
 * ⚠️ `PosService::takeBack()` সেবা হিসেবে মাপা ছিল, কিন্তু রুটটা নয়।
 *
 * ── ⭐ এই ফাইলের সবচেয়ে জরুরি লাইনটা `setUp`-এ ──────────────────────
 * ⛔ কাউন্টারের পর্দা **ডিফল্টে বন্ধ**, আর সুইচটা দেখা হয় অনুমতির
 * **আগে** ([[RefuseSwitchedOffScreens]])।
 *
 * ⚠️ সুইচটা চালু না করলে "চাবি ছাড়া ঢোকা যায় না" দাবিটা সবুজ হত —
 * কিন্তু **ভুল কারণে**। ⓘ পর্দাটা বন্ধ বলে সবাই আটকাত, চাবি থাকুক বা
 * না থাকুক, আর তখন তালাটা আদৌ কিছু পাহারা দেয় কি না তার কোনো প্রমাণ
 * থাকত না ([[default-off-screens-404-every-test]])।
 *
 * ⭐ তাই সুইচ চালু, আর তারপর একই লোককে আগে চাবি ছাড়া, পরে চাবি দিয়ে
 * দেখা — দ্বিতীয়বার খুলে গেলেই প্রমাণ হয় তালাটা ঐ চাবিরই।
 */
final class TheCounterReturnDoorWasNeverKnockedOnTest extends TestCase
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
        /*
         * ⭐ চাবিহীন লোকটা **ভূমিকাহীন**, কোনো ডেমো-ভূমিকা নয়।
         *
         * ── ⛔ কেন, ২৬ সেপ্টেম্বর ২০২৬ ────────────────────────────
         * ⚠️ প্রথমে বিক্রয়কর্মী ধরেছিলাম — ভুল, তাঁর চাবি ছিল। তারপর
         * হিসাবরক্ষক — ⛔ সেটাও ভুল হয়ে যাবে, কারণ মালিকের সিদ্ধান্তে
         * `sales.claim.decide` এখন **হিসাবরক্ষকের ভূমিকাতেই** যাচ্ছে।
         *
         * ⓘ অর্থাৎ যেকোনো ডেমো-ভূমিকার উপর দাঁড়ালে এই দাবিগুলো
         * ভূমিকার ছাঁচ বদলানোমাত্র **ভুল কারণে** লাল হত।
         *
         * ⭐ তাই কারো ভূমিকা ধার করা হয় না: একজন নতুন ব্যবহারকারী,
         * কোম্পানির সদস্য কিন্তু ভূমিকাহীন, আর চাবিটা প্রতিটা দাবির
         * ভিতরেই দেওয়া হয় ([[same-user-key-off-then-on]])।
         */
        $this->outsider = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->outsider->companies()->attach($this->company->id, ['is_active' => true]);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();

        /* ⛔ সুইচটা অনুমতির আগে চলে — উপরের ব্লকে কেন, লেখা আছে */
        app(SettingsService::class)->set('sales.screen_pos', true);
    }

    /**
     * ⛔ চাবি ছাড়া ফেরত নেওয়া যায় না — আর গুদামে কিছুই ফেরে না।
     *
     * ⚠️ দ্বিতীয় দাবিটাই আসল: ৪০৩ ফেরত দিয়েও যদি ভিতরে সারিটা বসে
     * যেত, তবে মাল গুদামে ঢুকত আর কেউ জানত না।
     */
    public function test_a_bill_cannot_be_taken_back_without_the_key(): void
    {
        $invoice = $this->soldAtTheCounter();

        $this->assertFalse($this->outsider->can('sales.pos'),
            'ⓘ এই দাবির ভিত্তি: ভূমিকাহীন ব্যবহারকারীর কাউন্টারের চাবি নেই। '
            .'⛔ থাকলে নিচের ৪০৩ কিছুই প্রমাণ করত না।');

        $this->actingAs($this->outsider)
            ->post(route('sales.pos.return'), $this->takeBackPayload($invoice))
            ->assertForbidden();

        $this->assertSame(0, SalesReturn::query()->count(),
            '⛔ ৪০৩ ফিরেছে, তবু একটা ফেরতের কাগজ তৈরি হয়ে গেছে — '
            .'অর্থাৎ মাল গুদামে ঢুকেছে।');
    }

    /**
     * ⭐ চাবিটাই দরজা খোলে — আর খুলে সত্যিই মালটা ফিরিয়ে নেয়।
     *
     * ⓘ একই ব্যবহারকারী, কেবল চাবিটা যোগ করা। ⚠️ সফল হওয়া মানে ৪০৩-টা
     * পর্দার সুইচ বা সদস্যপদের জন্য ছিল না — ঠিক এই চাবিটার জন্যই ছিল।
     */
    public function test_the_key_is_what_opens_the_counter_return_door(): void
    {
        $invoice = $this->soldAtTheCounter();

        $this->outsider->givePermissionTo('sales.pos');

        /*
         * ⚠️ `assertRedirect()` একা যথেষ্ট নয় — ২৬ সেপ্টেম্বর শেখা।
         *
         * ⛔ একটা **যাচাই-ব্যর্থতাও ৩০২**, তাই ফর্মের ভুল সাফল্যের মতো
         * দেখায়। ⓘ সকালের "কেবল ২০০ নয়" ফাঁদটারই ৩০২-সংস্করণ।
         */
        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.pos.return'), $this->takeBackPayload($invoice))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.pos.index'));

        $return = SalesReturn::query()->latest('id')->first();

        $this->assertNotNull($return,
            '⛔ দরজা খুলেছে, কিন্তু কোনো ফেরতের কাগজ তৈরি হয়নি — ২০০ এসেছে, কাজ হয়নি।');

        $this->assertSame($invoice->id, $return->sales_invoice_id,
            '⛔ ফেরতটা কোন বিলের বিপরীতে, সেটা লেখা হয়নি।');

        /*
         * ⭐ কাগজটা **নিশ্চিত** হওয়া চাই, খসড়া নয়।
         *
         * ⓘ `PosService::takeBack()` নিজেই `confirm()` ডাকে, আর মাল
         * গুদামে ফেরে ঐ ধাপেই। ⚠️ খসড়া থেকে গেলে কাউন্টারে ক্যাশিয়ার
         * মাল হাতে নিয়েছেন অথচ মজুদে কিছুই যোগ হয়নি।
         */
        $this->assertSame(DocumentStatus::CONFIRMED, $return->status,
            '⛔ ফেরতের কাগজটা খসড়াই রয়ে গেছে — মাল হাতে এসেছে, মজুদে ওঠেনি।');
    }

    /**
     * ⚠️ যে বিল নেই তার বিপরীতে ফেরত হয় না — নিয়ামকের যাচাইটাও দরজা ধরেই।
     *
     * ⓘ সেবার নিজের পাহারা আছে (`PosService::soldOn()`), কিন্তু ফর্মের
     * ভুল-বার্তা হয়ে ফিরে আসাটা HTTP পথেরই কাজ। ⛔ ওটা ভাঙলে ব্যবহারকারী
     * ৫০০ দেখতেন, আর কাউন্টারে লাইনে মানুষ দাঁড়িয়ে থাকত।
     */
    public function test_a_bill_that_does_not_exist_cannot_be_taken_back(): void
    {
        $this->outsider->givePermissionTo('sales.pos');

        $this->actingAs($this->outsider->fresh())
            ->post(route('sales.pos.return'), [
                'document_no' => 'কোনো-বিল-নেই',
                'lines' => [['product_id' => $this->product->id, 'qty' => '1']],
            ])
            ->assertSessionHasErrors('document_no');

        $this->assertSame(0, SalesReturn::query()->count());
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

        return SalesInvoice::query()->latest('id')->firstOrFail();
    }

    /**
     * ফেরতের ফর্ম — দুইটার একটা ফেরত।
     *
     * @return array<string, mixed>
     */
    private function takeBackPayload(SalesInvoice $invoice): array
    {
        return [
            'document_no' => $invoice->document_no,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => '1'],
            ],
        ];
    }
}

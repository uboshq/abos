<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseRequisition;
use App\Modules\Purchase\Services\PurchaseRequisitionService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * যার লাগত, তার চাওয়ার কোনো পথ ছিল না।
 *
 * ── ⛔ আগে যা হত ────────────────────────────────────────────────────
 * ক্রয়াদেশ সরাসরি লেখা হত। ⚠️ *"কে চেয়েছিল, আর কেন"* প্রশ্নের উত্তর
 * ছিল কারও স্মৃতি — চাওয়াটা হত মুখে, হোয়াটসঅ্যাপে, বা কাগজের টুকরোয়।
 *
 * ⛔ আর তার চেয়েও বড়: চাওয়া ও কেনা এক হয়ে থাকায় **থামার কোনো জায়গা
 * ছিল না**। যে মুহূর্তে কাগজটা লেখা হত, সেটাই প্রতিশ্রুতি।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. চাহিদা লেখায় কিছুই প্রতিশ্রুত হয় না
 *   ২. মঞ্জুর না হলে আদেশ বানানো যায় না
 *   ৩. একই চাহিদা থেকে **দুইবার** আদেশ নয়
 *   ৪. আন্দাজি দর আদেশে যায় না
 *   ৫. একই পণ্য দুইবার বসানো আটকায়
 *   ৬. যিনি চান তিনি নিজের চাওয়া মঞ্জুর করতে পারেন না
 *
 * ⓘ (৩) সবচেয়ে দামি। ⛔ ভুল হলে একই জিনিস দুইবার কেনা হত, আর ভুলটা
 * ধরা পড়ত মাল এসে গুদামে জায়গা না পাওয়ার দিনে।
 */
final class ThePersonWhoNeededItHadNoWayToAskTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    private Supplier $supplier;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        /*
         * ⭐ পর্দার সুইচটা চালু করে নেওয়া — ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ `purchase.screen_requisitions` ডিফল্টে **বন্ধ** (module.php),
         * কারণ চাহিদাপত্র বড় প্রতিষ্ঠানের জিনিস। ⚠️ ফলে দরজার পাহারা
         * ([[RefuseSwitchedOffScreens]]) সব ঠিকানায় ৪০৪ দেয় — মালিককেও।
         *
         * ⓘ এটা কোডের ভুল নয়, বরং সুইচটা সত্যিই কাজ করার প্রমাণ। তাই
         * পর্দার পরীক্ষাগুলোর আগে সুইচটা হাতে চালু করা হয়, ঠিক যেমন
         * একজন ব্যবহারকারী কন্ট্রোল প্যানেলে করতেন।
         */
        app(SettingsService::class)->set('purchase.screen_requisitions', true);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->product = Product::query()->firstOrFail();
        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    // ── ১ · লেখায় কিছুই প্রতিশ্রুত হয় না ──────────────────────────────

    public function test_writing_a_requisition_commits_nothing(): void
    {
        $requisition = $this->aRequisition();

        $this->assertSame(DocumentStatus::DRAFT, $requisition->status,
            'চাহিদা লেখামাত্রই মঞ্জুর হয়ে গেছে — তাহলে থামার জায়গাটাই নেই।');

        $this->assertNull($requisition->purchase_order_id);
    }

    public function test_the_estimate_is_the_sum_of_the_lines(): void
    {
        /*
         * ⓘ অনুমোদনের ছক এই সংখ্যাটাই দেখে। ⚠️ ভুল হলে ছোট কাগজ বড়
         * ধাপে যেত, বা বড় কাগজ কারও সই ছাড়াই পাশ হত।
         */
        $requisition = $this->aRequisition(qty: '10', rate: '25');

        $this->assertSame(0, bccomp($requisition->estimatedTotal(), '250', 4));
    }

    public function test_a_line_with_no_rate_counts_as_nothing_not_as_a_guess(): void
    {
        /*
         * ⛔ ধরে-নেওয়া কোনো দর বসানো হয় না — ⚠️ বসালে অনুমোদনের
         * সীমাটাই মিথ্যা হয়ে যেত।
         */
        $requisition = $this->aRequisition(qty: '10', rate: null);

        $this->assertSame(0, bccomp($requisition->estimatedTotal(), '0', 4));
    }

    // ── ২ ও ৩ · আদেশে রূপান্তর ────────────────────────────────────────

    public function test_an_unapproved_requisition_cannot_become_an_order(): void
    {
        $requisition = $this->aRequisition();

        $this->expectException(ValidationException::class);
        $this->service()->toOrder($requisition, $this->orderData());
    }

    public function test_an_approved_requisition_becomes_an_order_once(): void
    {
        $requisition = $this->aRequisition(qty: '10', rate: '25');
        $this->service()->approve($requisition);

        $done = $this->service()->toOrder($requisition->fresh(), $this->orderData());

        $this->assertNotNull($done->purchase_order_id,
            'রূপান্তরের পরেও চাহিদাটা কোনো আদেশের দিকে দেখাচ্ছে না।');

        $this->assertSame(DocumentStatus::CLOSED, $done->status);
    }

    public function test_the_same_requisition_cannot_be_ordered_twice(): void
    {
        /*
         * ⛔ পারলে একই জিনিস **দুইবার কেনা** হত, আর ভুলটা ধরা পড়ত মাল
         * এসে গুদামে জায়গা না পাওয়ার দিনে।
         */
        $requisition = $this->aRequisition(qty: '10', rate: '25');
        $this->service()->approve($requisition);
        $this->service()->toOrder($requisition->fresh(), $this->orderData());

        $this->expectException(ValidationException::class);
        $this->service()->toOrder($requisition->fresh(), $this->orderData());
    }

    // ── ৪ · আন্দাজি দর আদেশে যায় না ──────────────────────────────────

    public function test_the_guessed_rate_never_reaches_the_order(): void
    {
        /*
         * ⛔ গেলে আন্দাজটাই একদিন দাম হয়ে বসত, আর সরবরাহকারী অন্য দর
         * চাইলে কেউ বুঝত না সংখ্যাটা কোথা থেকে এসেছিল।
         */
        $requisition = $this->aRequisition(qty: '10', rate: '25');
        $this->service()->approve($requisition);

        $done = $this->service()->toOrder($requisition->fresh(), $this->orderData());

        $rate = (string) $done->order->lines->first()->rate;

        $this->assertSame(0, bccomp($rate, '0', 4),
            'চাহিদার আন্দাজি দরটা আদেশে চলে গেছে — অথচ ওটা আন্দাজ ছিল, '
            .'দাম নয়। আসল দর আসবে সরবরাহকারীর কাছ থেকে।');
    }

    public function test_the_ordered_quantity_does_come_across(): void
    {
        /*
         * ⚠️ উপরের পাহারাটা উল্টো দিকেও সবুজ থাকত: রূপান্তরটা যদি কিছুই
         * না নিত, দরও শূন্য হত। ⓘ তাই পরিমাণটা সত্যিই পৌঁছায় কি না
         * আলাদা করে দেখা হয়।
         */
        $requisition = $this->aRequisition(qty: '10', rate: '25');
        $this->service()->approve($requisition);

        $done = $this->service()->toOrder($requisition->fresh(), $this->orderData());

        /*
         * ⛔ ঘরটার নাম `ordered_qty`, `qty` নয় — আদেশে দুই রকম পরিমাণ
         * থাকে (যতটা চাওয়া হলো, যতটা এল)। ⚠️ `qty` পড়লে `null` ফিরত,
         * আর দাবিটা লাল হত এমন একটা কারণে যার সাথে চাহিদার কোনো
         * সম্পর্কই নেই — ঠিক যে ফাঁদে সেবাটাও পড়েছিল।
         */
        $this->assertSame(0, bccomp((string) $done->order->lines->first()->ordered_qty, '10', 4));
    }

    // ── ৫ · একই পণ্য দুইবার নয় ───────────────────────────────────────

    public function test_the_same_product_twice_is_refused(): void
    {
        /*
         * ⓘ বাস্তবে ওটা প্রায় সবসময়ই একটা ভুল: মানুষ সারিটা দুইবার
         * বসিয়ে ফেলেন, আর পরিমাণটা দ্বিগুণ হয়ে যায়।
         */
        $this->expectException(ValidationException::class);

        $this->service()->create(
            ['trx_date' => now()->toDateString()],
            [
                ['product_id' => $this->product->id, 'qty' => '5'],
                ['product_id' => $this->product->id, 'qty' => '3'],
            ],
        );
    }

    // ── ৬ · চাওয়া আর মঞ্জুর করা আলাদা হাতে ───────────────────────────

    public function test_the_one_who_asks_cannot_approve_their_own_asking(): void
    {
        $requisition = $this->aRequisition(qty: '10', rate: '25');

        $asker = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        CompanyContext::forCompany(
            (int) CompanyContext::id(),
            fn () => $asker->givePermissionTo('purchase.requisition.view', 'purchase.requisition.create'),
        );

        $this->actingAs($asker->fresh())
            ->post(route('purchase.requisition.approve', $requisition))
            ->assertForbidden();

        $this->assertSame(DocumentStatus::DRAFT, $requisition->fresh()->status,
            'চাওয়ার চাবি নিয়েই নিজের চাওয়া মঞ্জুর হয়ে গেছে — তাহলে '
            .'অনুমোদনের ধাপটার কোনো মানেই থাকে না।');
    }

    public function test_that_person_can_still_write_one(): void
    {
        /*
         * ⚠️ উপরের পাহারাটা উল্টো দিকেও সবুজ থাকত: চাবিটা এত শক্ত করে
         * বসানো যে কেউ চাহিদাই লিখতে পারেন না।
         */
        $asker = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        CompanyContext::forCompany(
            (int) CompanyContext::id(),
            fn () => $asker->givePermissionTo('purchase.requisition.view', 'purchase.requisition.create'),
        );

        $this->actingAs($asker->fresh())
            ->get(route('purchase.requisition.create'))
            ->assertOk();
    }

    // ── দরজা ও পর্দা ─────────────────────────────────────────────────

    public function test_the_list_opens_and_shows_the_paper(): void
    {
        $requisition = $this->aRequisition();

        $this->actingAs($this->owner)
            ->get(route('purchase.requisition.index'))
            ->assertOk()
            ->assertSee($requisition->document_no);
    }

    public function test_the_screen_is_closed_without_the_key(): void
    {
        $stranger = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($stranger)
            ->get(route('purchase.requisition.index'))
            ->assertForbidden();
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function service(): PurchaseRequisitionService
    {
        return app(PurchaseRequisitionService::class);
    }

    private function aRequisition(string $qty = '5', ?string $rate = null): PurchaseRequisition
    {
        return $this->service()->create(
            [
                'trx_date' => now()->toDateString(),
                'needed_by' => now()->addDays(7)->toDateString(),
                'purpose' => 'পরীক্ষার জন্য',
            ],
            [['product_id' => $this->product->id, 'qty' => $qty, 'estimated_rate' => $rate]],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function orderData(): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
        ];
    }
}

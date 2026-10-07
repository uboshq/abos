<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * বিলটা অনুমোদনে গেল, আর তারপর কোথাও রইল না।
 *
 * ── ⛔ মালিকের অভিযোগ, ১৮ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"বিল create করার পর খুঁজে পাওয়া যায় না কেন?"*
 *
 * পর্দায় লাল অক্ষরে: **"This has gone for approval. It moves on once the
 * approver decides."** ⓘ বাক্যটা পড়ে মানুষ ধরে নেন কাগজটা কোথাও আছে,
 * কেউ একজন সই দেবেন। ⛔ কিন্তু তালিকায় কিছু নেই, অনুমোদনের ইনবক্সেও
 * কিছু নেই — **কিছুই তৈরি হয়নি**।
 *
 * ── ⓘ কারণটা একটা লেনদেনের সীমানা ───────────────────────────────────
 * [[PurchaseBillService::confirm()]]-এ `assertClear()` ইচ্ছে করেই
 * লেনদেনের **বাইরে** — ওখানকার মন্তব্যেই লেখা *"অপেক্ষা করা মানে কিছুই
 * না বসা"*। ⭐ ওটা ঠিক ছিল।
 *
 * ⚠️ কিন্তু [[DirectPurchaseService::complete()]] `create()` **আর**
 * `confirm()` দুইটাকেই একটা বাইরের `DB::transaction`-এ মুড়ে দেয়। ⛔ তাই
 * ভেতরের সাবধানতাটা অর্থহীন হয়ে যায়: `assertClear()` ব্যতিক্রম ছোঁড়ে,
 * বাইরের লেনদেন রোলব্যাক করে, আর সাথে **যে অনুমোদনের অনুরোধটা সে
 * এইমাত্র লিখেছিল সেটাও মুছে যায়**।
 *
 * ⓘ ফল: কাগজ নেই, অনুরোধ নেই, কেবল একটা বাক্য যা মিথ্যা বলে। ⚠️ আর
 * প্রতিবার চেষ্টা করলে হুবহু একই জিনিস — বেরোনোর পথ নেই।
 *
 * ── ⭐ এই ফাইল কী দাবি করে ───────────────────────────────────────────
 * অনুমোদন আটকালে **কাগজটা খসড়া হয়ে থেকে যাবে**, আর অনুরোধটাও থাকবে।
 * ⓘ তখন বাক্যটা সত্যি হয়: সই হলে মানুষ বিলটা খুলে নিশ্চিত করতে পারেন।
 */
final class TheBillWentForApprovalAndVanishedTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();

        /*
         * ⛔ ছকটা ছাড়া এই ফাইলের একটা দাবিও কিছু মাপে না।
         *
         * ⚠️ ডেমো ডেটায় কেবল `sales|discount` ছকটা থাকে, তাই ক্রয় বিলে
         * অনুমোদন কোনোদিন চলত না — আর ঠিক এই কারণেই বাগটা এত দিন
         * কোনো পরীক্ষায় ধরা পড়েনি। ⓘ সীমা নেই, অর্থাৎ **প্রতিটা**
         * বিলে সই লাগে।
         */
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => 'purchase',
            'action' => 'bill',
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $user->id,
        ]);
    }

    /**
     * ⭐ সই আটকালেও কাগজটা থেকে যায় — খসড়া হয়ে।
     *
     * ⚠️ দাবিটা ইচ্ছে করে **সারির উপর**, বার্তার উপর নয়: বার্তাটা তো
     * আগেও ঠিকই আসত, কেবল সত্যি ছিল না।
     */
    public function test_a_blocked_bill_stays_on_the_books_as_a_draft(): void
    {
        $before = PurchaseBill::query()->count();

        try {
            app(\App\Modules\Purchase\Services\DirectPurchaseService::class)->complete(
                $this->documentData(),
                $this->lines(),
            );

            $this->fail('অনুমোদনের ছক বসানো, তবু কিছুই আটকায়নি।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors(),
                'বার্তাটা অন্য ঘরে গেছে — পর্দায় তখন কিছুই দেখা যেত না।');
        }

        $this->assertSame($before + 1, PurchaseBill::query()->count(),
            'বিলটা উবে গেছে — ঠিক যেটার অভিযোগ মালিক করেছেন।');

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();

        $this->assertSame('draft', $bill->status,
            'বিলটা আছে, কিন্তু খসড়া নয় — তাহলে সই ছাড়াই খাতায় বসে গেছে।');
    }

    /**
     * ⭐ অনুমোদনের অনুরোধটাও থেকে যায় — নাহলে সই দেওয়ার কিছু থাকে না।
     *
     * ⛔ এটাই সবচেয়ে নীরব অংশ ছিল: ইঞ্জিন অনুরোধটা লিখত, আর বাইরের
     * রোলব্যাক সেটা একই নিঃশ্বাসে মুছে দিত। ⓘ তাই অনুমোদনের ইনবক্স
     * চিরকাল খালি থাকত, আর কেউ বুঝত না কেন।
     */
    public function test_the_request_survives_so_somebody_can_sign_it(): void
    {
        try {
            app(\App\Modules\Purchase\Services\DirectPurchaseService::class)->complete(
                $this->documentData(),
                $this->lines(),
            );
        } catch (ValidationException) {
            // ⓘ আটকানোটাই প্রত্যাশিত — দাবিটা নিচে।
        }

        $bill = PurchaseBill::query()->latest('id')->first();

        $this->assertNotNull($bill, 'কাগজই নেই, তাই অনুরোধের প্রশ্নও ওঠে না।');

        $this->assertDatabaseHas('approvals', [
            'approvable_id' => $bill->id,
            'module' => 'purchase',
            'action' => 'bill',
            'status' => 'pending',
        ]);
    }

    /**
     * ⭐ পর্দা সোজা খসড়া বিলের পাতায় যায় — ভরা ফর্মে ফেরে না।
     *
     * ── ⛔ মালিকের দ্বিতীয় অভিযোগ, ১৯ সেপ্টেম্বর ২০২৬ ────────────────
     * *"অনুমোদনে গেলে ক্রয়ের পর হারিয়ে যায়।"* ⓘ খসড়াটা আগের দিনই টিকে
     * যাচ্ছিল (উপরের দুইটা দাবি), কিন্তু পর্দা ফিরত সরাসরি ক্রয়ের ভরা
     * ফর্মে — খসড়ার কোনো লিংক ছাড়া। ⚠️ মানুষের চোখে ওটা "কিছুই হয়নি"।
     *
     * ⚠️ দাবিটা **HTTP ধরে**, সেবা ধরে নয়: সেবা ঠিকই ছিল, ভাঙা ছিল
     * কন্ট্রোলার কোথায় পাঠায় সেটা।
     */
    public function test_the_screen_goes_straight_to_the_held_draft(): void
    {
        $response = $this->post(route('purchase.direct.store'), [
            ...$this->documentData(),
            'lines' => $this->lines(),
        ]);

        $draft = PurchaseBill::query()->latest('id')->firstOrFail();

        $this->assertSame('draft', $draft->status);

        $response->assertRedirect(route('purchase.bill.show', $draft->id));

        /*
         * ⓘ দুইটা বার্তাই সাথে যায়: সবুজটা বলে কাগজটা কোথায় আর পরের
         * ধাপ কী, লালটা বলে কেন আটকেছে। ⛔ একটা হারালে মানুষ হয় জানেন
         * না কাগজটা আছে, নয় জানেন না কেন এগোচ্ছে না।
         */
        $response->assertSessionHas('saved');
        $response->assertSessionHasErrors('status');
    }

    /**
     * ⭐ বাকি ছয় জায়গা আগের মতোই — বার্তা দেখায়, ৫০০ নয়।
     *
     * ⓘ [[HeldForApproval]] `ValidationException`-এর উপ-ধরন, আর দাবি ছিল
     * যে ছয়টা সেবা [[DocumentApproval::assertClear()]] ডাকে তাদের কিছুই
     * বদলায় না। ⚠️ দাবিটা লিখে রাখাই যথেষ্ট নয় — বিলের সাধারণ "নিশ্চিত"
     * বোতামটা (যেখানে কেউ আলাদা করে ধরে না) আসলে চেপে দেখা হয়।
     */
    public function test_the_plain_confirm_button_still_shows_the_message(): void
    {
        $bill = app(\App\Modules\Purchase\Services\PurchaseBillService::class)->create(
            $this->documentData(),
            [[
                'product_id' => $this->product->id,
                'qty' => '10',
                'rate' => '60',
            ]],
        );

        $response = $this->from(route('purchase.bill.show', $bill->id))
            ->post(route('purchase.bill.confirm', $bill->id));

        $response->assertRedirect(route('purchase.bill.show', $bill->id));
        $response->assertSessionHasErrors('status');

        $this->assertSame('draft', $bill->fresh()->status);
    }

    /**
     * ⭐ ছক না থাকলে আজকের মতোই — এক পর্দায় সব শেষ।
     *
     * ⚠️ এই দাবিটা সংশোধনের পাহারা: লেনদেনের সীমানা সরানোর পর স্বাভাবিক
     * পথটা যেন ভাঙে না। ⛔ নাহলে একটা বাগ সারাতে গিয়ে সব দোকানের
     * সরাসরি ক্রয় থেমে যেত।
     */
    public function test_without_a_flow_the_bill_is_confirmed_in_one_go(): void
    {
        ApprovalFlow::query()->where('module', 'purchase')->delete();

        $result = app(\App\Modules\Purchase\Services\DirectPurchaseService::class)->complete(
            $this->documentData(),
            $this->lines(),
        );

        $this->assertSame('confirmed', $result['bill']->fresh()->status,
            'ছক নেই, তবু বিলটা খসড়া রয়ে গেছে — মাল গুদামে ঢুকল না।');
    }

    /** @return array<string, mixed> */
    private function documentData(): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'APRV-'.fake()->unique()->numberBetween(1000, 9999),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        return [[
            'product_id' => $this->product->id,
            'qty' => '10',
            'rate' => '60',
            'sales_price' => '90',
        ]];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\Rfq;
use App\Modules\Purchase\Services\RfqService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * তিনজনের কাছে দর চাওয়া হলো, আর কিছুই রাখা হলো না।
 *
 * ── ⛔ আগে যা হত ────────────────────────────────────────────────────
 * দর চাওয়া হত ফোনে, জবাবগুলো থাকত কারও ইনবক্সে, আর ক্রয়াদেশে বসত কেবল
 * **জেতা দরটা**। ⚠️ বাকি দুইজন কত চেয়েছিলেন তা কোথাও থাকত না — তাই
 * *"সবচেয়ে কম দর নেওয়া হয়েছিল তো?"* প্রশ্নের কোনো প্রমাণ ছিল না।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. কাকে জিজ্ঞেস করা হলো তা থেকে যায়, জবাব না এলেও
 *   ২. পাঠানোর পর তালিকা আর বদলানো যায় না
 *   ৩. একই RFQ-তে একজনের একটাই দর
 *   ৪. তুলনায় মোট = পণ্য + ভাড়া + অন্যান্য
 *   ৫. সবচেয়ে কম দাগানো হয়, কিন্তু **মেয়াদ পেরোনোটা নয়**
 *   ৬. দর লেখার চাবি আলাদা
 *
 * ⓘ (৫) সবচেয়ে সহজে ভাঙে। ⛔ মেয়াদ পেরোনো একটা সস্তা দরকে "সেরা"
 * দাগালে মানুষ ওটাই নিতেন, আর সরবরাহকারী বলতেন *"ওটা তো পুরনো দর"* —
 * আর কথাটা তাঁরই ঠিক হত।
 */
final class WeAskedThreeSuppliersAndKeptNoneOfItTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    /** @var list<Supplier> */
    private array $suppliers = [];

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

        $existing = Supplier::query()->orderBy('id')->take(3)->get()->all();

        /* ⓘ ডেমোয় তিনজন না থাকলে বানিয়ে নেওয়া — তুলনার জন্য অন্তত দুইজন লাগে */
        while (count($existing) < 3) {
            $existing[] = Supplier::query()->create([
                'code' => 'SUP-'.count($existing).mb_substr(md5(microtime()), 0, 4),
                'name_en' => 'Supplier '.count($existing),
                'name_bn' => 'সরবরাহকারী '.count($existing),
                'is_active' => true,
            ]);
        }

        $this->suppliers = $existing;
    }

    // ── ১ · কাকে জিজ্ঞেস করা হলো তা থেকে যায় ─────────────────────────

    public function test_who_was_asked_survives_even_when_they_never_answer(): void
    {
        /*
         * ⛔ এই দাবিটাই *"তিনজনের কাছে চেয়েছিলাম"* কথাটাকে প্রমাণে
         * পরিণত করে। ⚠️ কেবল জবাবদাতাদের রাখলে যিনি চুপ ছিলেন তিনি
         * ইতিহাস থেকেই মুছে যেতেন — অথচ ঐ নীরবতাটাও একটা তথ্য।
         */
        $rfq = $this->anRfq();

        $this->assertCount(3, $rfq->suppliers,
            'তিনজনকে জিজ্ঞেস করা হয়েছিল, অথচ তালিকায় তিনজন নেই।');

        $this->service()->send($rfq);

        $this->assertCount(3, $rfq->fresh()->silentSuppliers(),
            'কেউ জবাব দেননি, তবু "যাঁরা জবাব দেননি" তালিকাটা খালি — '
            .'তাহলে তাগাদা দেওয়ার কোনো ভিত্তিই থাকে না।');
    }

    public function test_answering_takes_a_supplier_off_the_chase_list(): void
    {
        $rfq = $this->anRfq();
        $this->service()->send($rfq);

        $this->aQuote($rfq, $this->suppliers[0], rate: '100');

        $this->assertCount(2, $rfq->fresh()->silentSuppliers());
    }

    // ── ২ · পাঠানোর পর তালিকা বদলায় না ───────────────────────────────

    public function test_a_request_cannot_be_sent_twice(): void
    {
        /*
         * ⛔ দুইবার পাঠানো গেলে মাঝখানে তালিকা বদলে ফেলা যেত, আর
         * ⚠️ ইতিহাস বলত এমন একজনকে জিজ্ঞেস করা হয়েছিল যাঁকে হয়নি —
         * বা উল্টোটা।
         */
        $rfq = $this->anRfq();
        $this->service()->send($rfq);

        $this->expectException(ValidationException::class);
        $this->service()->send($rfq->fresh());
    }

    public function test_sending_marks_the_day_it_went_out(): void
    {
        $rfq = $this->anRfq();
        $sent = $this->service()->send($rfq);

        $this->assertSame(DocumentStatus::CONFIRMED, $sent->status);

        $this->assertNotNull($sent->suppliers->first()->pivot->sent_on,
            'পাঠানোর তারিখটা কোথাও বসেনি — তাহলে কত দিন ধরে জবাব বাকি '
            .'তা বলা যেত না।');
    }

    // ── ৩ · একজনের একটাই দর ──────────────────────────────────────────

    public function test_one_supplier_cannot_answer_the_same_request_twice(): void
    {
        /*
         * ⛔ দুইটা থাকলে তুলনায় একজনই দুইবার বসতেন, আর *"সবচেয়ে কম"*
         * হিসাবটা তাঁর দুইটা দরের মধ্যেই আটকে যেত।
         */
        $rfq = $this->anRfq();
        $this->service()->send($rfq);

        $this->aQuote($rfq, $this->suppliers[0], rate: '100');

        $this->expectException(ValidationException::class);
        $this->aQuote($rfq, $this->suppliers[0], rate: '90');
    }

    // ── ৪ · তুলনার অঙ্ক ──────────────────────────────────────────────

    public function test_the_total_is_goods_plus_freight_plus_charges(): void
    {
        /*
         * ⚠️ যে সরবরাহকারী পণ্যে কম দর দিয়ে ভাড়ায় পুষিয়ে নেন, তিনি
         * কেবল পণ্যের মোট দেখলে জিতে যেতেন। ⓘ সিদ্ধান্তটা হওয়া উচিত
         * **যা সত্যিই দিতে হবে** তার উপর।
         */
        $rfq = $this->anRfq(qty: '10');
        $this->service()->send($rfq);

        $quote = $this->aQuote($rfq, $this->suppliers[0], rate: '100', freight: '250', other: '50');

        $this->assertSame(0, bccomp($quote->goodsTotal(), '1000', 4));
        $this->assertSame(0, bccomp($quote->grandTotal(), '1300', 4));
    }

    public function test_tax_is_charged_after_the_discount_not_before(): void
    {
        /*
         * ⛔ উল্টো করলে প্রতিটা সারিতে একটু বেশি কর গোনা হত, আর
         * তুলনায় সস্তা দরটা মিথ্যা দামি দেখাত।
         *
         * ⓘ ১০ × ১০০ = ১০০০, ছাড় ১০০, কর ৯০ → ৯৯০।
         */
        $rfq = $this->anRfq(qty: '10');
        $this->service()->send($rfq);

        $quote = $this->aQuote(
            $rfq, $this->suppliers[0],
            rate: '100', discount: '100', tax: '90',
        );

        $this->assertSame(0, bccomp($quote->goodsTotal(), '990', 4));
    }

    // ── ৫ · সবচেয়ে কম, কিন্তু টেকা দরগুলোর মধ্যে ─────────────────────

    public function test_the_cheapest_living_quote_is_marked(): void
    {
        $rfq = $this->anRfq(qty: '10');
        $this->service()->send($rfq);

        $this->aQuote($rfq, $this->suppliers[0], rate: '100');
        $this->aQuote($rfq, $this->suppliers[1], rate: '90');

        $rows = $this->service()->compare($rfq->fresh());

        $lowest = collect($rows)->firstWhere('lowest', true);

        $this->assertSame($this->suppliers[1]->id, (int) $lowest['supplier']->id,
            'সবচেয়ে কম দরটা দাগানো হয়নি।');
    }

    public function test_an_expired_quote_is_never_marked_cheapest(): void
    {
        /*
         * ⛔ এটাই সবচেয়ে সহজে ভাঙে। ⚠️ মেয়াদ পেরোনো সস্তা দরকে "সেরা"
         * দাগালে মানুষ ওটাই নিতেন, আর সরবরাহকারী বলতেন *"ওটা তো পুরনো
         * দর"* — আর কথাটা তাঁরই ঠিক হত।
         */
        $rfq = $this->anRfq(qty: '10');
        $this->service()->send($rfq);

        $this->aQuote($rfq, $this->suppliers[0], rate: '100');
        $this->aQuote($rfq, $this->suppliers[1], rate: '50',
            validUntil: now()->subDay()->toDateString());

        $rows = $this->service()->compare($rfq->fresh());

        $lowest = collect($rows)->firstWhere('lowest', true);

        $this->assertSame($this->suppliers[0]->id, (int) $lowest['supplier']->id,
            'মেয়াদ পেরোনো দরটাকেই "সবচেয়ে কম" দাগানো হয়েছে।');
    }

    public function test_an_expired_quote_still_appears_in_the_table(): void
    {
        /*
         * ⛔ বাদ দিলে ইতিহাসটাই অসম্পূর্ণ হত — ⓘ আর মেয়াদ পেরোনো একটা
         * দরও পরের বার দরাদরির ভিত্তি হতে পারে।
         */
        $rfq = $this->anRfq(qty: '10');
        $this->service()->send($rfq);

        $this->aQuote($rfq, $this->suppliers[0], rate: '50',
            validUntil: now()->subDay()->toDateString());

        $rows = $this->service()->compare($rfq->fresh());

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['expired']);
    }

    // ── ৬ · দর লেখার চাবি আলাদা ──────────────────────────────────────

    public function test_sending_a_request_does_not_let_you_write_the_answers(): void
    {
        /*
         * ⚠️ এক চাবিতে রাখলে যিনি অনুরোধ পাঠান তিনিই দর বসাতে পারতেন —
         * ⛔ আর তখন *"তিনজনের দর নিয়ে তুলনা করা হয়েছে"* কথাটার কোনো
         * স্বাধীন সাক্ষী থাকত না।
         */
        $rfq = $this->anRfq();
        $this->service()->send($rfq);

        $buyer = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        CompanyContext::forCompany(
            (int) CompanyContext::id(),
            fn () => $buyer->givePermissionTo('purchase.rfq.view', 'purchase.rfq.create'),
        );

        $this->actingAs($buyer->fresh())
            ->get(route('purchase.rfq.quote', $rfq))
            ->assertForbidden();
    }

    public function test_the_comparison_screen_opens(): void
    {
        $rfq = $this->anRfq(qty: '10');
        $this->service()->send($rfq);
        $this->aQuote($rfq, $this->suppliers[0], rate: '100');

        $this->actingAs($this->owner)
            ->get(route('purchase.rfq.show', $rfq))
            ->assertOk()
            ->assertSee($this->suppliers[0]->name());
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function service(): RfqService
    {
        return app(RfqService::class);
    }

    private function anRfq(string $qty = '5'): Rfq
    {
        return $this->service()->create(
            ['trx_date' => now()->toDateString(), 'respond_by' => now()->addDays(3)->toDateString()],
            [['product_id' => $this->product->id, 'qty' => $qty]],
            array_map(fn (Supplier $s) => $s->id, $this->suppliers),
        );
    }

    private function aQuote(
        Rfq $rfq,
        Supplier $supplier,
        string $rate,
        string $discount = '0',
        string $tax = '0',
        string $freight = '0',
        string $other = '0',
        ?string $validUntil = null,
    ) {
        return $this->service()->quote(
            [
                'rfq_id' => $rfq->id,
                'supplier_id' => $supplier->id,
                'quoted_on' => now()->toDateString(),
                'valid_until' => $validUntil,
                'freight' => $freight,
                'other_charges' => $other,
            ],
            [[
                'product_id' => $this->product->id,
                'qty' => (string) $rfq->lines->first()->qty,
                'rate' => $rate,
                'discount' => $discount,
                'tax' => $tax,
            ]],
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseBillLine;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * চালান নম্বরটা কোথাও নিয়ে যেত না, আর মালের তালিকা ঘর ছাপিয়ে যেত।
 *
 * ── ⛔ মালিকের কথা, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * *"goods e item zodi ekhane besi hoy tahole vaj kora thbe & bill no
 * hyper link kore daw zate vew kore dekte pari"*।
 *
 * ⓘ দুইটা আলাদা অভিযোগ, একই ঘরে:
 *
 *   ১ · চালান নম্বরটা নিছক লেখা ছিল। কোন চালানে ভাড়া বসাবেন সেটা
 *       ঠিক করতে ভিতরে কী আছে দেখা দরকার, আর দেখার কোনো পথ ছিল না।
 *   ২ · তিনটার বেশি মাল থাকলে `goods_summary` " …" বসাত — অর্থাৎ
 *       বাকিগুলো **ছিলই না কোথাও**। ⚠️ "…" দেখে কেউ বুঝতেন আরও
 *       আছে, কিন্তু কী আছে তা জানার উপায় ছিল না।
 *
 * ── ⭐ কেন পরীক্ষাটা নিজে একটা চালান বানায় ──────────────────────────
 * ডেমোর চালানগুলোয় তিনটার বেশি মাল নেই। ⛔ ওগুলোর উপর ভরসা করলে
 * ভাঁজের দাবিটা **কখনো ভাঁজ দেখতই না** — সবুজ থাকত, অথচ কিছুই মাপত না।
 * ⓘ তাই এখানে পাঁচ মালের একটা চালান বানানো হয়, আর দুই মালের আরেকটা —
 * একটায় ভাঁজ থাকার কথা, অন্যটায় নয়।
 */
final class TheBillNumberLedNowhereAndTheGoodsRanOffTheCellTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->switchCompany($company->id);
    }

    /**
     * ⭐ চালান নম্বরটা তার নিজের কাগজে নিয়ে যায়।
     */
    public function test_the_bill_number_opens_the_bill(): void
    {
        $bill = $this->billWith(2, 'PBL-LINK');

        $row = $this->rowFor($this->screen(), $bill);

        $this->assertStringContainsString(
            'href="'.route('purchase.bill.show', ['bill' => $bill->id]).'"',
            $row,
            implode("\n", [
                'চালান নম্বরটা কোথাও নিয়ে যায় না।',
                '',
                'ⓘ মালিকের কথা: *"bill no hyper link kore daw zate vew kore dekte pari"*।',
                '⛔ খোঁজা হচ্ছিল: '.route('purchase.bill.show', ['bill' => $bill->id]),
            ]),
        );
    }

    /**
     * ⭐ লিংকটা নতুন ট্যাবে খোলে — নাহলে অর্ধেক ভরা ফর্মটা হারাত।
     *
     * ── ⚠️ কেন এটা আলাদা দাবি ───────────────────────────────────────
     * উপরের দাবিটা কেবল বলে লিংক **আছে**। ⛔ কিন্তু একই ট্যাবে খুললে
     * ব্যবহারকারী চালানটা দেখে ফিরে এসে দেখতেন তারিখ, খাত, টিক দেওয়া
     * চালান, বসানো ভাগ — সব গেছে। ⓘ আর সেটা লিংক না থাকার চেয়েও খারাপ,
     * কারণ ক্ষতিটা হয় তাঁর কাজ শেষ হওয়ার ঠিক আগে।
     */
    public function test_the_bill_link_does_not_eat_the_half_filled_form(): void
    {
        $bill = $this->billWith(2, 'PBL-TAB');

        $row = $this->rowFor($this->screen(), $bill);

        $at = strpos($row, 'href="'.route('purchase.bill.show', ['bill' => $bill->id]).'"');

        $this->assertNotFalse($at, 'লিংকটাই নেই — আগের দাবিটা দেখুন।');

        $tag = substr($row, (int) $at, 260);

        $this->assertStringContainsString('target="_blank"', $tag,
            'চালানের লিংকটা একই ট্যাবে খোলে — ফেরত এসে ব্যবহারকারী খালি ফর্ম পাবেন।');

        $this->assertStringContainsString('rel="noopener"', $tag,
            'নতুন ট্যাবে খুলছে কিন্তু `rel="noopener"` নেই।');
    }

    /**
     * ⭐ তিনটার বেশি মাল থাকলে ভাঁজ, আর ভাঁজের ভিতরে **সবগুলো**।
     */
    public function test_a_bill_with_many_goods_folds_and_hides_nothing(): void
    {
        $bill = $this->billWith(5, 'PBL-MANY');

        $row = $this->rowFor($this->screen(), $bill);

        $this->assertStringContainsString('<details>', $row, implode("\n", [
            'পাঁচ মালের চালানটার ঘরে ভাঁজ নেই।',
            '',
            'ⓘ মালিকের কথা: *"goods e item zodi ekhane besi hoy tahole vaj kora thbe"*।',
        ]));

        foreach ($bill->lines as $line) {
            $name = $line->product?->display_name ?? $line->product?->name_bn ?? '—';

            $this->assertStringContainsString('<li>'.e($name).'</li>', $row, implode("\n", [
                'ভাঁজ খুললেও "'.$name.'" মালটা নেই।',
                '',
                '⛔ তাহলে "…" আগের মতোই মিথ্যা — আরও আছে বলে, কিন্তু দেখায় না।',
            ]));
        }
    }

    /**
     * ⛔ তিনটা বা তার কম হলে ভাঁজ নেই — খালি তিরচিহ্ন দেওয়া হয় না।
     *
     * ── ⚠️ কেন এই দাবিটা লাগে ───────────────────────────────────────
     * উপরেরটা কেবল বলে ভাঁজ **আসে**। ⓘ সবসময় ভাঁজ বসিয়ে দিলেও ওটা
     * সবুজ থাকত — আর তখন দুই মালের চালানেও একটা তিরচিহ্ন থাকত, যেটা
     * ক্লিক করলে হুবহু একই লেখা দেখা যেত। ⭐ ভাঁজের মানেই হলো ভিতরে
     * নতুন কিছু আছে।
     */
    public function test_a_short_bill_is_not_given_a_pointless_fold(): void
    {
        $bill = $this->billWith(2, 'PBL-FEW');

        $row = $this->rowFor($this->screen(), $bill);

        $this->assertStringNotContainsString('<details>', $row,
            'দুই মালের চালানেও ভাঁজ বসেছে — খুলে কেউ নতুন কিছু পাবেন না।');

        $this->assertStringNotContainsString('<li>', $row,
            'দুই মালের চালানের ঘরে তালিকা বসেছে — ঘরটা অকারণে লম্বা হবে।');
    }

    /**
     * ⭐ ভাঁজটা `PurchaseBill::GOODS_SHOWN`-এর সাথেই বাঁধা।
     *
     * ── ⛔ কেন সংখ্যাটা আলাদা করে দাবি করা হয় ───────────────────────
     * কাটাটা (মডেলে) আর ভাঁজটা (পর্দায়) দুই জায়গায় লেখা। ⚠️ দুইটা
     * আলাদা হয়ে গেলে দুইটাই "কাজ করত", কেবল ভুল করে: ঘরটা "…" দেখাত
     * অথচ ভাঁজ খুলত না।
     */
    public function test_the_fold_and_the_trim_agree_on_one_number(): void
    {
        $this->assertSame(3, PurchaseBill::GOODS_SHOWN,
            'GOODS_SHOWN বদলেছে — এই ফাইলের সংখ্যাগুলোও (২ ও ৫) মিলিয়ে দেখুন।');

        $just = $this->billWith(PurchaseBill::GOODS_SHOWN, 'PBL-EDGE');

        $this->assertStringNotContainsString('<details>', $this->rowFor($this->screen(), $just),
            'ঠিক GOODS_SHOWN-টা মাল থাকলেও ভাঁজ বসেছে — অথচ কাটা হয়নি, তাই লুকানোরও কিছু নেই।');
    }

    /**
     * একটা চালান, `$count`টা আলাদা মাল নিয়ে।
     */
    private function billWith(int $count, string $no): PurchaseBill
    {
        $products = Product::query()->limit($count)->get();

        // ⚠️ শূন্য বা কম পণ্য মানে পরীক্ষাটা যা মাপার কথা তা মাপছে না
        $this->assertCount($count, $products,
            'ডেমোতে '.$count.'টা পণ্যই নেই — পরীক্ষাটা তাহলে কিছুই প্রমাণ করে না।');

        $bill = PurchaseBill::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'financial_year_id' => FinancialYear::query()->firstOrFail()->id,
            'supplier_id' => Supplier::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'document_no' => $no,
            'trx_date' => now()->subDay()->toDateString(),
            'subtotal' => '100',
            'discount' => '0',
            'tax' => '0',
            'total' => '100',
            'status' => DocumentStatus::CONFIRMED,
        ]);

        foreach ($products as $n => $product) {
            PurchaseBillLine::query()->create([
                'purchase_bill_id' => $bill->id,
                'product_id' => $product->id,
                'qty' => '1',
                'rate' => '100',
                'discount' => '0',
                'tax' => '0',
                'amount' => '100',
                'line_no' => $n + 1,
            ]);
        }

        return $bill->fresh(['lines.product']);
    }

    /**
     * পর্দার HTML থেকে ঠিক এই চালানের সারিটা।
     *
     * ── ⛔ নোঙরটা `value="{id}"` ছিল, আর সেটা ভুল ─────────────────────
     * চালানের id ছোট হলে (যেমন ৩) `value="3"` পাতার **উপরের** একটা
     * `<option>`-এর সাথেও মিলত — খরচের খাতের ড্রপডাউনে। ⚠️ ওটা কোনো
     * `<tr>`-এর ভিতরে নয়, তাই সারি খোঁজা পিছিয়ে গিয়ে কিছুই পেত না।
     *
     * ⓘ ধরা পড়েছে অদ্ভুতভাবে: পাঁচটার মধ্যে **একটাই** লাল হচ্ছিল, আর
     * সেটা ঠিক ঐটা যেখানে চালানটা সবার আগে বানানো — অর্থাৎ id সবচেয়ে
     * ছোট। ⭐ তাই এখন নোঙর চালান নম্বর, যা এই পর্দায় একবারই বসে।
     */
    private function rowFor(string $html, PurchaseBill $bill): string
    {
        $at = strpos($html, $bill->document_no);

        $this->assertNotFalse($at, implode("\n", [
            'চালান '.$bill->document_no.' পর্দার তালিকাতেই নেই।',
            '',
            'ⓘ তালিকাটা শেষ ৬০ দিনের, সর্বোচ্চ ৫০টা — তারিখটা দেখুন।',
        ]));

        $open = strrpos(substr($html, 0, (int) $at), '<tr');
        $close = strpos($html, '</tr>', (int) $at);

        $this->assertNotFalse($open, 'সারিটার `<tr` পাওয়া গেল না।');
        $this->assertNotFalse($close, 'সারিটার `</tr>` পাওয়া গেল না।');

        return substr($html, (int) $open, (int) $close - (int) $open);
    }

    private function screen(): string
    {
        $response = $this->actingAs($this->owner)->get('/accounts/vouchers/expense/create');

        $response->assertOk();

        return (string) $response->getContent();
    }
}

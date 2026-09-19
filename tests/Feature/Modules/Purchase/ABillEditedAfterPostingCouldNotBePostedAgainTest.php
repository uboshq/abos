<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নিশ্চিত ক্রয় বিল সম্পাদনা — উল্টানো, বদলানো, আবার বসানো; বারবার।
 *
 * ── ⛔ কী ঘটত, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────
 * ১৮ তারিখে মালিকের সিদ্ধান্তে super admin নিশ্চিত বিলও বদলাতে পারলেন
 * ([[PurchaseBillService::updatePosted()]])। পথটা ছিল: পুরনো দাখিলা উল্টানো,
 * সারি বদলানো, নতুন দাখিলা বসানো।
 *
 * ⚠️ কিন্তু খাতার ইঞ্জিন জিজ্ঞেস করত *"এই বিলের কোনো সারি খাতায় আছে?"* —
 * আর উল্টো সারি মূল সারি মোছে না, তাই উত্তর সবসময় "হ্যাঁ"। ⛔ ফল:
 * **প্রতিটা** সম্পাদনা `PostingException`-এ ভাঙত — ৫০০, আর কিছুই বদলাত না।
 * ধরা পড়ল abos-45-এর Voucher List পরীক্ষায়; সম্পাদনার পথের নিজের কোনো
 * পরীক্ষা ছিল না — এটাই সেই পরীক্ষা।
 *
 * ⚠️ আর প্রথম বাধা পেরোলেও দুইটা নীরব ভুল অপেক্ষা করছিল: দ্বিতীয়বার
 * সম্পাদনা বা পরে বাতিল করলে উল্টানোটা **সব** সারি ধরত — প্রথম দাখিলাসহ —
 * আর খাতা ও মজুদ দুইবার বিয়োগ হত। রেওয়ামিল তবু মিলত।
 *
 * ⭐ তাই প্রতিটা ধাপে দুইটা মাপ: খাতায় বিলের নিট অঙ্ক = বিলের মোট, আর
 * গুদামে এই বিলের নিট মাল = বিলের পরিমাণ।
 */
final class ABillEditedAfterPostingCouldNotBePostedAgainTest extends TestCase
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
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        // মালিক — super admin; নিশ্চিত বিল বদলানোর অধিকার কেবল তাঁর
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    public function test_a_posted_bill_can_be_edited_twice_and_then_cancelled(): void
    {
        $data = [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'EDIT-'.fake()->unique()->numberBetween(1000, 9999),
        ];

        $bill = app(DirectPurchaseService::class)->complete($data, [$this->line('48')])['bill'];

        $this->assertBooksAndShelfSay($bill, '48', 'প্রথম দাখিলা');

        // ── প্রথম সম্পাদনা — আগে এখানেই PostingException ─────────────
        $bill = app(PurchaseBillService::class)->update($bill->fresh(), $data, [$this->line('30')], repost: true);

        $this->assertBooksAndShelfSay($bill, '30', 'প্রথম সম্পাদনার পরে');

        // ── দ্বিতীয় সম্পাদনা — উল্টানো যেন কেবল খোলা দাখিলা ধরে ────────
        $bill = app(PurchaseBillService::class)->update($bill->fresh(), $data, [$this->line('20')], repost: true);

        $this->assertBooksAndShelfSay($bill, '20', 'দ্বিতীয় সম্পাদনার পরে');

        // ── বাতিল — খাতা আর তাক দুইটাই শূন্যে ফেরে, নিচে নয় ─────────────
        app(PurchaseBillService::class)->cancel($bill->fresh(), 'পরীক্ষা');

        $this->assertSame(0, bccomp($this->ledgerNet($bill), '0', 4), 'বাতিলের পরে খাতায় বিলের কিছু থেকে গেছে, বা বেশি উল্টেছে।');
        $this->assertSame(0, bccomp($this->shelfNet($bill), '0', 4), 'বাতিলের পরে মজুদ শূন্যে ফেরেনি — প্রথম দাখিলার মালও আবার বেরিয়েছে।');
    }

    /** একই খোলা দাখিলা দুইবার উল্টানো যায় না — দুইবার ক্লিকেও। */
    public function test_a_second_cancel_is_still_refused(): void
    {
        $bill = app(DirectPurchaseService::class)->complete([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'TWICE-'.fake()->unique()->numberBetween(1000, 9999),
        ], [$this->line('10')])['bill'];

        app(\App\Core\Engines\Posting\PostingEngine::class)->reverse(PurchaseBill::drillSourceType(), $bill->id, now());

        $this->expectException(\App\Core\Engines\Posting\PostingException::class);

        app(\App\Core\Engines\Posting\PostingEngine::class)->reverse(PurchaseBill::drillSourceType(), $bill->id, now());
    }

    /** @return array<string, mixed> */
    private function line(string $qty): array
    {
        return ['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '100'];
    }

    private function assertBooksAndShelfSay(PurchaseBill $bill, string $qty, string $when): void
    {
        $bill = $bill->fresh();

        $this->assertSame(0, bccomp($this->ledgerNet($bill), (string) $bill->total, 4),
            "{$when}: খাতায় বিলের নিট ডেবিট {$this->ledgerNet($bill)}, অথচ বিলের মোট {$bill->total}।");

        $this->assertSame(0, bccomp($this->shelfNet($bill), $qty, 4),
            "{$when}: গুদামে এই বিলের নিট মাল {$this->shelfNet($bill)}, অথচ বিলে {$qty}।");
    }

    /** খাতায় এই বিলের নিট ডেবিট — বসানো বিয়োগ উল্টানো। */
    private function ledgerNet(PurchaseBill $bill): string
    {
        $type = PurchaseBill::drillSourceType();

        $posted = (string) LedgerEntry::query()->where('source_type', $type)->where('source_id', $bill->id)->sum('debit');
        $reversed = (string) LedgerEntry::query()->where('source_type', $type.':reversal')->where('source_id', $bill->id)->sum('credit');

        return bcsub($posted, $reversed, 4);
    }

    /**
     * গুদামে এই বিলের নিট মাল — ঢোকা আর ফেরত মিলিয়ে।
     *
     * ⓘ সরাসরি বিলের মাল ঢোকে "তাকে বসানো হয়নি" ঘরে (`unplaced`), তাকে নয় —
     * তাই দুইটা ঘরই গোনা হয়। ⚠️ প্রথম লেখায় কেবল তাক গোনা হত, আর ঠিক
     * কোডেও পরীক্ষাটা বলত "গুদামে ০"।
     */
    private function shelfNet(PurchaseBill $bill): string
    {
        $row = StockMovement::query()
            ->whereIn('source_type', [PurchaseBill::STOCK_SOURCE, PurchaseBill::STOCK_SOURCE.':cancel'])
            ->where('source_id', $bill->id)
            ->where('product_id', $this->product->id)
            ->selectRaw('COALESCE(SUM(floor_change), 0) + COALESCE(SUM(unplaced_change), 0) AS qty')
            ->first();

        return (string) ($row?->qty ?? '0');
    }
}

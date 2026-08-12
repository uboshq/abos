<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\PosService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ক্রেতা টাকা আনতে গেছেন, আর পেছনে লাইন।
 *
 * এটা না থাকলে ক্যাশিয়ার যা করেন সেটাই সমস্যা: বিলটা বাতিল করেন, আর
 * ক্রেতা ফিরলে আবার গোড়া থেকে টাইপ করেন। **তখন বাতিলের সংখ্যা দিয়ে আর
 * কিছু বোঝা যায় না** — দিনে ত্রিশটা বাতিল দেখে বলার উপায় থাকে না কোনটা
 * ভুল, কোনটা চুরির চেষ্টা, আর কোনটা কেবল একজন ক্রেতা টাকা আনতে
 * গিয়েছিলেন।
 */
class PosParkedBillTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    private function pos(): PosService
    {
        return app(PosService::class);
    }

    private function park(string $qty = '2'): SalesInvoice
    {
        return $this->pos()->park(
            ['warehouse_id' => $this->warehouse->id],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '100']],
        );
    }

    // ── ধরে রাখা ─────────────────────────────────────────────────

    /**
     * ধরে রাখা বিল খসড়াই থাকে — খাতায় কিছু বসে না।
     *
     * মাল নড়ে না, টাকা ওঠে না। ক্রেতা ফিরে না এলে দিন শেষে ওটা মুছে
     * দিলেই হয়, আর খতিয়ানে তার কোনো চিহ্নই থাকে না।
     */
    public function test_a_parked_bill_stays_a_draft(): void
    {
        $invoice = $this->park();

        $this->assertSame(DocumentStatus::DRAFT, $invoice->status);
        $this->assertNotNull($invoice->parked_at);
    }

    /** ধরে রাখা মানে কাউন্টারের তালিকায় আসা। */
    public function test_a_parked_bill_shows_up_at_the_counter(): void
    {
        $invoice = $this->park();

        $this->assertTrue($this->pos()->parked()->contains('id', $invoice->id));
    }

    /**
     * সাধারণ খসড়া কাউন্টারের তালিকায় আসে না।
     *
     * `parked_at` না থাকলে ওটা কেউ ফেলে রাখা খসড়া — কাউন্টারে
     * অপেক্ষমাণ কেউ নয়। দুইটা এক করে ফেললে টিলের পর্দা সব পুরনো খসড়ায়
     * ভরে যেত, আর কেউ ওটা পড়া বন্ধ করে দিত।
     */
    public function test_an_ordinary_draft_is_not_waiting_at_the_counter(): void
    {
        $draft = $this->park();
        $draft->forceFill(['parked_at' => null])->save();

        $this->assertFalse($this->pos()->parked()->contains('id', $draft->id));
    }

    /** পুরনোটা আগে — যেটা সবচেয়ে বেশিক্ষণ ঝুলে আছে সেটাই আগে সিদ্ধান্ত চায়। */
    public function test_the_longest_waiting_bill_comes_first(): void
    {
        $first = $this->park();
        $first->forceFill(['parked_at' => now()->subHours(3)])->save();

        $second = $this->park();
        $second->forceFill(['parked_at' => now()->subMinutes(5)])->save();

        $this->assertSame($first->id, $this->pos()->parked()->first()->id);
    }

    // ── আবার তোলা ────────────────────────────────────────────────

    /**
     * তোলার পর ওটা আর অপেক্ষা করছে না।
     *
     * `parked_at` মুছে না দিলে একই বিল দুই কাউন্টারে একসাথে তোলা যেত,
     * আর একজন নিশ্চিত করার পর অন্যজন খালি পর্দা নিয়ে বসে থাকতেন।
     */
    public function test_resuming_takes_it_off_the_waiting_list(): void
    {
        $invoice = $this->pos()->resume($this->park());

        $this->assertNull($invoice->parked_at);
        $this->assertFalse($this->pos()->parked()->contains('id', $invoice->id));
    }

    public function test_the_lines_survive_the_wait(): void
    {
        $resumed = $this->pos()->resume($this->park('7'));

        $this->assertCount(1, $resumed->lines);
        $this->assertSame(0, bccomp((string) $resumed->lines->first()->qty, '7', 4));
    }

    public function test_a_bill_that_is_not_waiting_cannot_be_resumed(): void
    {
        $invoice = $this->park();
        $this->pos()->resume($invoice);

        $this->expectException(ValidationException::class);

        $this->pos()->resume($invoice->fresh());
    }

    /**
     * সম্পূর্ণ হয়ে যাওয়া বিল আর তোলা যায় না।
     *
     * তুললে ক্যাশিয়ার ভাবতেন ওটা এখনো অসমাপ্ত, আর দ্বিতীয়বার টাকা
     * নিতেন — অথচ খাতায় ওটা আগেই বসে গেছে।
     */
    public function test_a_completed_bill_cannot_be_picked_up_again(): void
    {
        $invoice = $this->park();
        $invoice->forceFill(['status' => DocumentStatus::CONFIRMED])->save();

        $this->expectException(ValidationException::class);

        $this->pos()->resume($invoice->fresh());
    }

    // ── সীমানা ───────────────────────────────────────────────────

    public function test_parking_an_empty_cart_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->pos()->park(['warehouse_id' => $this->warehouse->id], []);
    }

    /** এক কোম্পানির ঝুলে থাকা বিল অন্য কোম্পানি দেখে না। */
    public function test_one_company_never_sees_anothers_waiting_bills(): void
    {
        $this->park();

        $other = Company::query()->where('code', '!=', 'TDEPOT')->first();

        if ($other === null) {
            $this->markTestSkipped('ডেমোতে দ্বিতীয় কোম্পানি নেই।');
        }

        CompanyContext::set($other->id, null);

        $this->assertCount(0, $this->pos()->parked());
    }
}

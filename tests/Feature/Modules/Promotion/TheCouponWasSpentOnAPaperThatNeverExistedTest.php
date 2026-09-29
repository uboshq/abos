<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCoupon;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * কুপন খরচ হত এমন কাগজে যা কখনো ছিলই না — গভীর অডিট, ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ আগে ([[PromotionCouponController::redeem()]]) ──────────────────────
 * কাগজের ধরন আর নম্বর, পণ্য, পরিমাণ, অঙ্ক, ক্রেতা — সবই অনুরোধ থেকে। `source_id=9301` (নেই) দিলেও কুপনের
 * ব্যবহার গোনা হত, অফারের বাজেট খরচ হত, আর অঙ্ক যা খুশি পাঠালে ছাড়ও তত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * কুপন খাটে কেবল এই কোম্পানির **পাকা** বিল বা আদেশের সত্যিকারের সারিতে ([[CouponPapers]], বিক্রয়ের
 * [[SalesCouponPapers]]); পণ্য, পরিমাণ, অঙ্ক আর ক্রেতা ঐ সারি থেকে — অনুরোধের ঘর উপেক্ষিত।
 */
final class TheCouponWasSpentOnAPaperThatNeverExistedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Promotion $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->givePermissionTo([
            Permission::findOrCreate('promotion.coupon', 'web'),
            Permission::findOrCreate('promotion.apply', 'web'),
        ]);
        $this->actingAs($this->owner);

        $this->offer = $this->aCouponOffer();
    }

    /** ⛔ নেই এমন কাগজ — ফেরত, আর কুপনের একটা ব্যবহারও গোনা হয় না। */
    public function test_a_paper_that_does_not_exist_spends_nothing(): void
    {
        $this->coupon('GHOST-PAPER');

        $this->redeem('GHOST-PAPER', ['source_id' => 9301, 'source_line_id' => 1])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['source_id']]);

        $this->assertSame(0, (int) PromotionCoupon::query()->where('code', 'GHOST-PAPER')->value('used_count'),
            '⛔ বানানো কাগজের নম্বরে কুপনের ব্যবহার গোনা হয়েছে।');
        $this->assertSame(0, PromotionApplication::query()->count(), '⛔ বানানো কাগজে অফার খাটানো হয়েছে।');
    }

    /** ⛔ খসড়া বিল — এখনো পাকা নয়, তাই কুপন খাটে না। */
    public function test_a_draft_paper_is_not_enough(): void
    {
        $this->coupon('DRAFT-PAPER');
        $draft = app(SalesInvoiceService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->biscuit()->id, 'qty' => '2', 'rate' => '10']]);

        $this->redeem('DRAFT-PAPER', ['source_id' => $draft->id, 'source_line_id' => $draft->lines()->value('id')])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['source_id']]);
    }

    /**
     * ⭐ পাকা বিলের সত্যিকারের সারি — খাটে; আর অঙ্ক কাগজের (২ × ১০ = ২০), অনুরোধের ৯,৯৯,৯৯,৯৯৯ নয়: শর্তহীন
     * ১০০ টাকার ছাড় সারির মূল্যে আটকে ২০।
     */
    public function test_a_real_line_is_used_with_the_papers_own_figures(): void
    {
        $this->coupon('REAL-PAPER');
        $invoice = $this->confirmedBill();

        $this->redeem('REAL-PAPER', [
            'source_id' => $invoice->id,
            'source_line_id' => $invoice->lines()->value('id'),
            'qty' => '999',
            'value' => '99999999',
        ])->assertOk();

        $applied = PromotionApplication::query()->latest('id')->firstOrFail();
        $this->assertSame(0, bccomp((string) $applied->worth, '20', 4), '⛔ ছাড় '.$applied->worth.' — অনুরোধের অঙ্ক ধরা হয়েছে, কাগজের নয়।');
        $this->assertSame((int) $invoice->customer_id, (int) $applied->customer_id, '⛔ ক্রেতা কাগজ থেকে আসেনি।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $overrides */
    private function redeem(string $code, array $overrides): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('promotion.coupon.redeem'), $overrides + [
            'code' => $code,
            'source_type' => 'sales_invoice',
            'product_id' => $this->biscuit()->id,
            'qty' => '10',
            'value' => '1000',
        ]);
    }

    private function coupon(string $code): void
    {
        // ⓘ `code` আর `used_count` ইচ্ছা করে fillable নয় ([[PromotionCoupon]]) — সরাসরি বসানো
        $coupon = new PromotionCoupon();
        $coupon->forceFill([
            'promotion_id' => $this->offer->id,
            'code' => $code,
            'max_uses' => 5,
            'used_count' => 0,
            'is_active' => true,
            'issued_by' => $this->owner->id,
        ])->save();
    }

    private function confirmedBill(): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => $this->biscuit()->id, 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();
    }

    private function biscuit(): Product
    {
        return Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
    }

    private function aCouponOffer(): Promotion
    {
        $offer = new Promotion([
            'name_en' => 'Paper coupon',
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-PAPER-1';
        $offer->type = PromotionType::COUPON;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        PromotionBenefit::query()->create([
            'promotion_id' => $offer->id,
            'kind' => BenefitKind::AMOUNT,
            'amount' => '100',
        ]);

        return $offer;
    }
}

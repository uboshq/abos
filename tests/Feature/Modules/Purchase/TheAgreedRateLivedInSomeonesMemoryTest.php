<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseContract;
use App\Modules\Purchase\Services\PurchaseContractService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * চুক্তির দরটা থাকত কারও স্মৃতিতে।
 *
 * ── ⛔ আগে যা হত ────────────────────────────────────────────────────
 * বছরের শুরুতে সরবরাহকারীর সাথে দর ঠিক হত, আর সেটা থাকত একটা কাগজে বা
 * কারও মনে। ⚠️ প্রতিটা আদেশে দর হাতে বসানো হত, আর কেউ মেলাত না — তাই
 * চুক্তির চেয়ে বেশি দরে অর্ডার চলে যেত, নীরবে।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. খসড়া চুক্তি কোনো দর বাঁধে না
 *   ২. চালু চুক্তির দর আদেশের পর্দা পায়
 *   ৩. মেয়াদ পেরোনো চুক্তির দর আর খাটে না
 *   ৪. এখনো শুরু হয়নি এমন চুক্তির দরও নয়
 *   ৫. শেষ তারিখ শুরুর আগে হলে সেবা থামে
 *   ৬. চুক্তি না থাকলে `null` ফেরে, শূন্য নয়
 *
 * ⓘ (৬) সবচেয়ে সহজে ভাঙে। ⛔ শূন্য ফেরালে আদেশের পর্দা ভাবত চুক্তিতে
 * জিনিসটা বিনামূল্যে, আর **প্রতিটা দরই** "চুক্তির চেয়ে বেশি" দেখাত।
 */
final class TheAgreedRateLivedInSomeonesMemoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->product = Product::query()->firstOrFail();
        $this->supplier = Supplier::query()->firstOrFail();
    }

    // ── ১ ও ২ · খসড়া বাঁধে না, চালু বাঁধে ─────────────────────────────

    public function test_a_draft_contract_binds_no_rate(): void
    {
        /*
         * ⛔ বাঁধলে যে কেউ একটা দর লিখে রেখে দিতেন, আর আদেশের পর্দা
         * ওটাকেই প্রতিষ্ঠানের কথা ধরত — অথচ সরবরাহকারীর সাথে ঐ কথা
         * কোনোদিন হয়নি।
         */
        $this->aContract(rate: '95');

        $this->assertNull($this->service()->rateFor($this->supplier->id, $this->product->id),
            'খসড়া চুক্তির দরটাই আদেশের পর্দা পাচ্ছে — তাহলে চালু করার '
            .'ধাপটার কোনো মানেই থাকে না।');
    }

    public function test_a_live_contract_hands_its_rate_to_the_order_screen(): void
    {
        /*
         * ⭐ এই জোড়াটাই গোটা মডিউলটার কারণ। ⛔ এটা ছাড়া চুক্তি কেবল
         * একটা সংরক্ষিত কাগজ — ঠিক যা আগে ছিল, শুধু কাগজের বদলে পর্দায়।
         */
        $contract = $this->aContract(rate: '95');
        $this->service()->activate($contract);

        $this->assertSame(0, bccomp(
            (string) $this->service()->rateFor($this->supplier->id, $this->product->id),
            '95', 4,
        ));
    }

    // ── ৩ ও ৪ · তারিখের দুই প্রান্ত ──────────────────────────────────

    public function test_an_expired_contract_stops_binding(): void
    {
        $contract = $this->aContract(
            rate: '95',
            starts: now()->subYear()->toDateString(),
            ends: now()->subDay()->toDateString(),
        );
        $this->service()->activate($contract);

        $this->assertNull($this->service()->rateFor($this->supplier->id, $this->product->id),
            'মেয়াদ পেরোনো চুক্তির দরটাও আজকের আদেশে খাটছে।');
    }

    public function test_a_contract_that_has_not_started_does_not_bind_yet(): void
    {
        /*
         * ⚠️ পরের বছরের চুক্তি আগে থেকে লিখে রাখা স্বাভাবিক। ⛔ ওটা
         * আজ থেকেই খাটলে আজকের আদেশ পরের বছরের দরে মেলানো হত।
         */
        $contract = $this->aContract(
            rate: '95',
            starts: now()->addMonth()->toDateString(),
            ends: now()->addYear()->toDateString(),
        );
        $this->service()->activate($contract);

        $this->assertNull($this->service()->rateFor($this->supplier->id, $this->product->id));
    }

    public function test_the_days_left_turns_negative_once_it_is_over(): void
    {
        $contract = $this->aContract(
            rate: '95',
            starts: now()->subYear()->toDateString(),
            ends: now()->subDays(5)->toDateString(),
        );

        $this->assertLessThan(0, $contract->daysLeft(),
            'মেয়াদ পেরিয়ে গেছে, তবু "কত দিন বাকি" ঋণাত্মক নয় — তাহলে '
            .'তালিকায় ওটা শেষ হয়ে যাওয়া বলে দেখাত না।');
    }

    // ── ৫ · উল্টো তারিখ আটকায় ────────────────────────────────────────

    public function test_a_contract_cannot_end_before_it_begins(): void
    {
        /*
         * ⛔ হলে চুক্তিটা লেখা থাকত অথচ কোনোদিন খাটত না, ⚠️ আর সেই
         * ব্যর্থতাটা সম্পূর্ণ নীরব: কেউ বুঝত না দরটা কেন আসছে না।
         */
        $this->expectException(ValidationException::class);

        $this->aContract(
            rate: '95',
            starts: now()->addMonth()->toDateString(),
            ends: now()->toDateString(),
        );
    }

    // ── ৬ · চুক্তি না থাকলে null ─────────────────────────────────────

    public function test_no_contract_gives_null_not_zero(): void
    {
        /*
         * ⛔ শূন্য ফেরালে আদেশের পর্দা ভাবত চুক্তিতে জিনিসটা বিনামূল্যে,
         * আর **প্রতিটা দরই** "চুক্তির চেয়ে বেশি" দেখাত — অর্থাৎ
         * সতর্কবার্তাটা এত ঘন ঘন আসত যে কেউ আর পড়ত না।
         */
        $this->assertNull($this->service()->rateFor($this->supplier->id, $this->product->id));
    }

    public function test_a_product_outside_the_contract_gives_null(): void
    {
        $contract = $this->aContract(rate: '95');
        $this->service()->activate($contract);

        $other = Product::query()->where('id', '<>', $this->product->id)->firstOrFail();

        $this->assertNull($this->service()->rateFor($this->supplier->id, $other->id),
            'চুক্তিতে নেই এমন পণ্যের জন্যও একটা দর ফিরছে।');
    }

    // ── মেয়াদ শেষ হয়ে আসা চুক্তি ─────────────────────────────────────

    public function test_contracts_about_to_expire_can_be_listed(): void
    {
        $soon = $this->aContract(
            rate: '95',
            starts: now()->subMonth()->toDateString(),
            ends: now()->addDays(10)->toDateString(),
        );
        $this->service()->activate($soon);

        $far = $this->aContract(
            rate: '80',
            starts: now()->subMonth()->toDateString(),
            ends: now()->addMonths(6)->toDateString(),
        );
        $this->service()->activate($far);

        $found = $this->service()->expiringWithin(30);

        $this->assertTrue($found->contains('id', $soon->id),
            'দশ দিনে মেয়াদ শেষ হওয়া চুক্তিটা তালিকায় নেই।');

        $this->assertFalse($found->contains('id', $far->id),
            'ছয় মাস পরের চুক্তিটাও "শেষ হয়ে আসছে" তালিকায় এসেছে।');
    }

    // ── দরজা ──────────────────────────────────────────────────────────

    public function test_the_rate_lookup_is_closed_without_the_order_key(): void
    {
        $stranger = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($stranger)
            ->get(route('purchase.contract.rate', [
                'supplier_id' => $this->supplier->id,
                'product_id' => $this->product->id,
            ]))
            ->assertForbidden();
    }

    public function test_the_list_opens_for_someone_with_the_key(): void
    {
        $contract = $this->aContract(rate: '95');

        $this->actingAs($this->owner)
            ->get(route('purchase.contract.index'))
            ->assertOk()
            ->assertSee($contract->document_no);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function service(): PurchaseContractService
    {
        return app(PurchaseContractService::class);
    }

    private function aContract(
        string $rate,
        ?string $starts = null,
        ?string $ends = null,
    ): PurchaseContract {
        return $this->service()->create(
            [
                'supplier_id' => $this->supplier->id,
                'starts_on' => $starts ?? Carbon::today()->subMonth()->toDateString(),
                'ends_on' => $ends ?? Carbon::today()->addMonths(6)->toDateString(),
            ],
            [['product_id' => $this->product->id, 'agreed_rate' => $rate]],
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase\Direct;

use App\Core\Services\FormIsNotSubmittedTwice;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * সরাসরি ক্রয় — ফেরানো যায়, আর দুইবার বসে না।
 *
 * চেকলিস্ট `ব্যবসা চালু, সরাসরি ক্রয় ও বিক্রয়` §২-এর শেষ দুইটা ঘর:
 *
 *   ১১. বিল সম্পাদনা বা বাতিল: উল্টো সারি পড়ে (কিছু মোছে না), আর পাঁচ মিল
 *       ঠিক কেনার আগের অবস্থায় ফেরে। মাল খরচ হয়ে গেলে সম্পাদনা/বাতিল
 *       আটকায়, আর কিছুই বসে না।
 *   ১২. একই বিল দুইবার বসে না — দুই ক্লিক (একই `_once`) আর দুই ট্যাব
 *       (আলাদা `_once`, একই কাগজ)।
 *
 * ── পাঁচ মিল, হাতে গোনা ────────────────────────────────────────────
 * প্রতিটা পরীক্ষা লেনদেনের আগে একটা ছবি তোলে ([[snapshot]]), পরে আরেকটা,
 * আর [[assertFiveMatches]] পার্থক্যটা **হাতে লেখা অঙ্কের** সাথে মেলায়:
 * খাতার প্রতিটা খাত (যা বলা নেই তার নড়াচড়া শূন্য), মজুদের পরিমাণ ও
 * FIFO মূল্য, সরবরাহকারীর বকেয়া, নগদ, আর লাভ।
 *
 * ⓘ পণ্যগুলো এই পরীক্ষাতেই নতুন বানানো — ডেমোর পুরনো স্তর থাকলে FIFO
 * আগে সেগুলো থেকে টানত, আর "এই বিলের মাল খরচ হয়েছে" দাবিটা মিথ্যা হত।
 */
final class DirectPurchaseUndoAndNoDoublePostTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $rice;

    private Product $bucket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        // মালিক — super admin; নিশ্চিত বিল বদলানোর অধিকার কেবল তাঁর
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->rice = $this->newProduct('UNDO-RICE', 'Undo Rice');
        $this->bucket = $this->newProduct('UNDO-BUCKET', 'Undo Bucket');
    }

    // ════════════════════════════════════════════════════════════════
    //  ১১ · বাতিল ও সম্পাদনা
    // ════════════════════════════════════════════════════════════════

    /**
     * বাকিতে কেনা, তারপর বাতিল — পাঁচটাই কেনার আগের অঙ্কে।
     *
     * হাতে গোনা: ১০ × ১০০ = ১,০০০, সাথে ২টা ফ্রি (দামহীন)।
     *   কেনার পরে  → মজুদ-খাত +১০০০ · দেনা −১০০০ · মাল ১০ (+২ ফ্রি), মূল্য ১০০০
     *   বাতিলের পরে → সব শূন্য, আর আসল সারিগুলো খাতায় অক্ষত
     */
    public function test_cancelling_a_credit_purchase_returns_all_five_to_before(): void
    {
        $before = $this->snapshot();

        $bill = $this->buy([['product_id' => $this->rice->id, 'qty' => '10', 'free_qty' => '2', 'rate' => '100']]);

        $this->assertFiveMatches('কেনার পরে', $before, $this->snapshot(),
            ledger: [StandardChart::INVENTORY => '1000', StandardChart::PAYABLE => '-1000'],
            stock: [$this->rice->id => ['qty' => '10', 'free' => '2', 'value' => '1000']],
            supplier: '1000', cash: '0', profit: '0');

        $postedIds = $this->billLedgerIds($bill);
        $postedMoves = $this->billStockIds($bill);

        app(PurchaseBillService::class)->cancel($bill->fresh(), 'ভুল সরবরাহকারী');

        $this->assertFiveMatches('বাতিলের পরে', $before, $this->snapshot(),
            ledger: [], stock: [$this->rice->id => ['qty' => '0', 'free' => '0', 'value' => '0']],
            supplier: '0', cash: '0', profit: '0');

        $this->assertSame(DocumentStatus::CANCELLED, $bill->fresh()->status, 'বিলটা বাতিল অবস্থায় যায়নি।');
        $this->assertNothingDeletedAndReversedOnce($bill, $postedIds, $postedMoves, postings: 1, reversals: 1);
    }

    /**
     * নগদে কেনা, তারপর বিল বাতিল, তারপর পরিশোধ ভাউচার বাতিল।
     *
     * হাতে গোনা: ৫ × ২০০ = ১,০০০, পুরোটা নগদ বাক্স থেকে।
     *   কেনার পরে      → মজুদ +১০০০ · নগদ −১০০০ · দেনা নিট ০
     *   বিল বাতিলের পরে → মাল ও মজুদ-খাত শূন্যে, কিন্তু টাকা তো সত্যিই গেছে:
     *                     নগদ −১০০০ আর সরবরাহকারী **আমাদের** কাছে ১০০০ ধারে
     *                     (দেনা-খাতে ডেবিট)। ⓘ বিল বাতিল টাকা ফেরায় না — টাকা
     *                     ফেরে পরিশোধটা বাতিল হলে।
     *   ভাউচার বাতিলের পরে → পাঁচটাই কেনার আগের অঙ্কে
     */
    public function test_cancelling_a_cash_purchase_and_its_payment_returns_the_cash(): void
    {
        $till = $this->tillAccount();

        // ⓘ নগদ খাত শূন্যের নিচে নামে না (1da4285a) — টাকাটা ছবির **আগে** রাখা, তাই পার্থক্যে পড়ে না
        $this->putMoneyIn($till, '1000');

        $before = $this->snapshot();

        $result = app(DirectPurchaseService::class)->complete(
            $this->header(['paid_now' => '1000', 'paid_from_account_id' => $till->id]),
            [['product_id' => $this->rice->id, 'qty' => '5', 'rate' => '200']],
        );
        $bill = $result['bill'];

        $this->assertCount(1, $result['payments'], 'নগদে কেনায় ঠিক একটা পরিশোধ ভাউচার হওয়ার কথা।');

        $this->assertFiveMatches('নগদে কেনার পরে', $before, $this->snapshot(),
            ledger: [StandardChart::INVENTORY => '1000', $till->code => '-1000'],
            stock: [$this->rice->id => ['qty' => '5', 'free' => '0', 'value' => '1000']],
            supplier: '0', cash: '-1000', profit: '0');

        app(PurchaseBillService::class)->cancel($bill->fresh(), 'মাল ফেরত গেছে');

        $this->assertFiveMatches('বিল বাতিলের পরে, ভাউচার তখনো খোলা', $before, $this->snapshot(),
            ledger: [$till->code => '-1000', StandardChart::PAYABLE => '1000'],
            stock: [$this->rice->id => ['qty' => '0', 'free' => '0', 'value' => '0']],
            supplier: '-1000', cash: '-1000', profit: '0');

        app(VoucherService::class)->cancel($result['payments'][0]->fresh(), 'সরবরাহকারী টাকা ফেরত দিয়েছেন');

        $this->assertFiveMatches('ভাউচার বাতিলের পরে', $before, $this->snapshot(),
            ledger: [], stock: [$this->rice->id => ['qty' => '0', 'free' => '0', 'value' => '0']],
            supplier: '0', cash: '0', profit: '0');
    }

    /**
     * নিশ্চিত বিল সম্পাদনা: পুরনোটা উল্টে, নতুনটা বসে — আর পরে বাতিলে শূন্য।
     *
     * হাতে গোনা: ১০ × ১০০ = ১,০০০ → ৬ × ১২০ = ৭২০।
     *   সম্পাদনার পরে → মজুদ +৭২০ · দেনা −৭২০ · মাল ৬, মূল্য ৭২০
     *   বাতিলের পরে   → সব শূন্য; দুই দাখিলা, দুই উল্টানো, একটাও মোছা নয়
     */
    public function test_editing_a_posted_bill_reverses_and_reposts_and_a_later_cancel_returns_to_zero(): void
    {
        $before = $this->snapshot();

        $bill = $this->buy([['product_id' => $this->rice->id, 'qty' => '10', 'rate' => '100']]);
        $postedIds = $this->billLedgerIds($bill);
        $postedMoves = $this->billStockIds($bill);

        $bill = app(PurchaseBillService::class)->update(
            $bill->fresh(),
            $this->header(['supplier_bill_no' => $bill->supplier_bill_no]),
            [['product_id' => $this->rice->id, 'qty' => '6', 'rate' => '120']],
            repost: true,
        );

        $this->assertFiveMatches('সম্পাদনার পরে', $before, $this->snapshot(),
            ledger: [StandardChart::INVENTORY => '720', StandardChart::PAYABLE => '-720'],
            stock: [$this->rice->id => ['qty' => '6', 'free' => '0', 'value' => '720']],
            supplier: '720', cash: '0', profit: '0');

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status, 'সম্পাদনার পরে বিলটা নিশ্চিত থাকার কথা।');
        $this->assertSame(0, bccomp((string) $bill->fresh()->total, '720', 4), "সম্পাদনার পরে বিলের মোট {$bill->fresh()->total}, হাতে গোনা ৭২০।");

        app(PurchaseBillService::class)->cancel($bill->fresh(), 'শেষে পুরোটাই ভুল');

        $this->assertFiveMatches('সম্পাদনা তারপর বাতিলের পরে', $before, $this->snapshot(),
            ledger: [], stock: [$this->rice->id => ['qty' => '0', 'free' => '0', 'value' => '0']],
            supplier: '0', cash: '0', profit: '0');

        $this->assertNothingDeletedAndReversedOnce($bill, $postedIds, $postedMoves, postings: 2, reversals: 2);
    }

    /**
     * মাল খরচ হয়ে গেলে সম্পাদনা আর বাতিল দুইটাই থামে — আর কিছুই বসে না।
     *
     * ── নকশা কী বলে ─────────────────────────────────────────────────
     * [[PurchaseBillService::takeBackDirectLines()]]: *"যে মাল বেচা হয়ে গেছে
     * তার বিল বাতিল করা যায় না — আগে বিক্রয়টা ফেরাতে হবে"*, আর পাহারাটা
     * [[CostLayerService::withdraw()]]-এ (`layer_already_used`)। দুই পথেই
     * সেটাই প্রথম ডাক, তাই কোনো উল্টো সারি বসার আগেই থামা উচিত।
     *
     * ⓘ "খরচ" এখানে বিক্রয়ের মজুদ-দিকের দুইটা ধাপ: তাকে বসানো, তাক থেকে
     * ৩টা বের করা আর FIFO স্তর থেকে ৩টা টানা। বিক্রয়ের খাতা-দিক এই দাবির
     * বিষয় নয় — দাবিটা হলো আটকানোর আগে-পরে **কিছুই নড়ে না**।
     */
    public function test_edit_and_cancel_are_refused_once_some_of_the_stock_was_used(): void
    {
        $bill = $this->buy([['product_id' => $this->rice->id, 'qty' => '10', 'rate' => '100']]);

        $stock = app(StockService::class);
        $stock->place($this->rice, $this->warehouse, '10', PurchaseBill::STOCK_SOURCE, $bill->id);
        $stock->issue($this->rice, $this->warehouse, 'test:sale', 1, '3');
        app(CostLayerService::class)->issue($this->rice->fresh(), '3', 'test:sale', 1);

        $used = $this->snapshot();
        $counts = $this->rowCounts();

        $this->assertRefused(fn () => app(PurchaseBillService::class)->update(
            $bill->fresh(),
            $this->header(['supplier_bill_no' => $bill->supplier_bill_no]),
            [['product_id' => $this->rice->id, 'qty' => '8', 'rate' => '100']],
            repost: true,
        ), 'সম্পাদনা');

        $this->assertFiveMatches('খরচ হওয়া মালের বিল সম্পাদনার চেষ্টার পরে', $used, $this->snapshot(),
            ledger: [], stock: [$this->rice->id => ['qty' => '0', 'free' => '0', 'value' => '0']],
            supplier: '0', cash: '0', profit: '0');
        $this->assertSame($counts, $this->rowCounts(), 'সম্পাদনা আটকানোর পরেও খাতা, মজুদ বা প্রহরী-টেবিলে সারি বসেছে।');

        $fresh = $bill->fresh(['lines']);
        $this->assertSame(DocumentStatus::CONFIRMED, $fresh->status);
        $this->assertSame(0, bccomp((string) $fresh->total, '1000', 4), "আটকানো সম্পাদনা তবু বিলের মোট বদলেছে: {$fresh->total}।");
        $this->assertSame(0, bccomp((string) $fresh->lines->sum('qty'), '10', 4), 'আটকানো সম্পাদনা তবু বিলের সারি বদলেছে।');

        $this->assertRefused(fn () => app(PurchaseBillService::class)->cancel($bill->fresh(), 'দেরিতে ধরা পড়ল'), 'বাতিল');

        $this->assertFiveMatches('খরচ হওয়া মালের বিল বাতিলের চেষ্টার পরে', $used, $this->snapshot(),
            ledger: [], stock: [$this->rice->id => ['qty' => '0', 'free' => '0', 'value' => '0']],
            supplier: '0', cash: '0', profit: '0');
        $this->assertSame($counts, $this->rowCounts(), 'বাতিল আটকানোর পরেও খাতা, মজুদ বা প্রহরী-টেবিলে সারি বসেছে।');
        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status, 'আটকানো বাতিল তবু বিলটাকে বাতিল দেখাচ্ছে।');
    }

    /**
     * মিলের উপহার — বিল বাতিলে উপহারের মালও গুদাম থেকে ফেরে।
     *
     * [[DirectPurchaseService::bringInGifts()]] উপহারটা `purchase_bill:gift`
     * উৎসে ঢোকায়, বিল নিশ্চিত হওয়ার পরে, "একই গাড়ির মাল" বলে। ⛔ বাতিলে
     * ওই মাল থেকে গেলে বাতিল বিলের একটা বালতি গুদামে বসে থাকে — মজুদের
     * পরিমাণ কেনার আগের অবস্থায় ফেরে না।
     *
     * হাতে গোনা: চাল ১০ × ১০০ = ১,০০০; বালতি ৩টা উপহার (দামহীন, ফ্রি ভাণ্ডার)।
     */
    public function test_cancelling_a_purchase_also_takes_back_the_gift(): void
    {
        $before = $this->snapshot();

        $bill = app(DirectPurchaseService::class)->complete(
            $this->header(),
            [['product_id' => $this->rice->id, 'qty' => '10', 'rate' => '100']],
            [['product_id' => $this->bucket->id, 'qty' => '3', 'against_product_id' => $this->rice->id]],
        )['bill'];

        $this->assertFiveMatches('উপহারসহ কেনার পরে', $before, $this->snapshot(),
            ledger: [StandardChart::INVENTORY => '1000', StandardChart::PAYABLE => '-1000'],
            stock: [
                $this->rice->id => ['qty' => '10', 'free' => '0', 'value' => '1000'],
                $this->bucket->id => ['qty' => '0', 'free' => '3', 'value' => '0'],
            ],
            supplier: '1000', cash: '0', profit: '0');

        app(PurchaseBillService::class)->cancel($bill->fresh(), 'পুরো চালানটাই ফেরত');

        $this->assertFiveMatches('উপহারসহ বিল বাতিলের পরে', $before, $this->snapshot(),
            ledger: [],
            stock: [
                $this->rice->id => ['qty' => '0', 'free' => '0', 'value' => '0'],
                $this->bucket->id => ['qty' => '0', 'free' => '0', 'value' => '0'],
            ],
            supplier: '0', cash: '0', profit: '0');
    }

    // ════════════════════════════════════════════════════════════════
    //  ১২ · একই বিল দুইবার নয় — HTTP পথে, ভূমিকাহীন একজন মানুষ
    // ════════════════════════════════════════════════════════════════

    /**
     * দুই ক্লিক: একই রেন্ডার, একই `_once` — একটা বিল, একটা দাখিলা, একবার টাকা।
     *
     * ⓘ মানুষটার কোনো ভূমিকা নেই, কেবল `purchase.bill.create` চাবি। প্রথমে
     * চাবি ছাড়া (৪০৩, কিছুই বসে না), তারপর **একই মানুষকে** চাবি দিয়ে।
     *
     * হাতে গোনা: ১০ × ১০০ = ১,০০০, পুরোটা নগদে — মজুদ +১০০০ · নগদ −১০০০।
     */
    public function test_a_double_click_posts_the_direct_purchase_once(): void
    {
        $clerk = $this->roleLessClerk();
        $till = $this->tillAccount();
        $payload = $this->httpPayload(['paid_now' => '1000', 'paid_from_account_id' => $till->id]);

        // ⓘ নগদ খাত শূন্যের নিচে নামে না (1da4285a) — টাকাটা ছবির **আগে** রাখা
        $this->putMoneyIn($till, '1000');

        $before = $this->snapshot();
        $counts = $this->rowCounts();

        // ── চাবি বন্ধ ───────────────────────────────────────────────
        $this->actingAs($clerk)
            ->post(route('purchase.direct.store'), $payload + [FormIsNotSubmittedTwice::FIELD => (string) Str::uuid7()])
            ->assertForbidden();

        $this->assertSame(0, PurchaseBill::query()->count(), 'চাবি ছাড়া অনুরোধে বিল তৈরি হয়েছে।');
        $this->assertSame($counts, $this->rowCounts(), 'চাবি ছাড়া অনুরোধে খাতা বা মজুদে সারি বসেছে।');

        // ── একই মানুষ, চাবি চালু — দুই ক্লিক ──────────────────────────
        $this->grant($clerk, 'purchase.bill.create');

        $token = (string) Str::uuid7();

        $first = $this->actingAs($clerk)->post(route('purchase.direct.store'), $payload + [FormIsNotSubmittedTwice::FIELD => $token]);
        $first->assertSessionHasNoErrors()->assertRedirect();

        $second = $this->actingAs($clerk)->post(route('purchase.direct.store'), $payload + [FormIsNotSubmittedTwice::FIELD => $token]);
        $second->assertRedirect();

        $this->assertSame(1, PurchaseBill::query()->count(), 'দুই ক্লিকে একাধিক বিল তৈরি হয়েছে।');
        $bill = PurchaseBill::query()->firstOrFail();

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status, 'চাবিওয়ালা মানুষের সরাসরি ক্রয় নিশ্চিত হয়নি (অনুমোদনে আটকেছে?)।');
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'),
            'দ্বিতীয় ক্লিক প্রথম ক্লিকের তৈরি বিলেই ফেরার কথা।');

        $this->assertPostedOnce($bill);
        $this->assertSame(1, Voucher::query()->where('against_type', PurchaseBill::drillSourceType())
            ->where('against_id', $bill->id)->count(), 'দুই ক্লিকে টাকা একাধিকবার দেওয়া হয়েছে।');

        $this->assertFiveMatches('দুই ক্লিকের পরে', $before, $this->snapshot(),
            ledger: [StandardChart::INVENTORY => '1000', $till->code => '-1000'],
            stock: [$this->rice->id => ['qty' => '10', 'free' => '0', 'value' => '1000']],
            supplier: '0', cash: '-1000', profit: '0');
    }

    /**
     * দুই ট্যাব: দুইটা আলাদা রেন্ডার, একই সরবরাহকারীর একই বিল নম্বর।
     *
     * ⓘ `_once` এখানে কিছু আটকায় না (দুইটা আলাদা টোকেন), আর আটকানো উচিতও
     * না — আবার খোলা ফর্ম বৈধ ([[FormIsNotSubmittedTwice]])। ⭐ আটকায়
     * সরবরাহকারীর বিল নম্বর ([[PurchaseBillService::assertBillNoIsFree]])।
     */
    public function test_two_tabs_with_the_same_supplier_bill_post_it_once(): void
    {
        $clerk = $this->roleLessClerk();
        $this->grant($clerk, 'purchase.bill.create');
        $payload = $this->httpPayload();

        $before = $this->snapshot();

        $this->actingAs($clerk)
            ->post(route('purchase.direct.store'), $payload + [FormIsNotSubmittedTwice::FIELD => (string) Str::uuid7()])
            ->assertSessionHasNoErrors()->assertRedirect();

        $afterFirst = $this->rowCounts();

        $this->actingAs($clerk)
            ->post(route('purchase.direct.store'), $payload + [FormIsNotSubmittedTwice::FIELD => (string) Str::uuid7()])
            ->assertSessionHasErrors('supplier_bill_no');

        $this->assertSame(1, PurchaseBill::query()->count(), 'দ্বিতীয় ট্যাবে একই বিল নম্বরে দ্বিতীয় বিল তৈরি হয়েছে।');
        $this->assertSame($afterFirst, $this->rowCounts(), 'দ্বিতীয় ট্যাবের প্রত্যাখ্যাত জমায় খাতা বা মজুদে সারি বসেছে।');

        $this->assertPostedOnce(PurchaseBill::query()->firstOrFail());

        $this->assertFiveMatches('দুই ট্যাবের পরে', $before, $this->snapshot(),
            ledger: [StandardChart::INVENTORY => '1000', StandardChart::PAYABLE => '-1000'],
            stock: [$this->rice->id => ['qty' => '10', 'free' => '0', 'value' => '1000']],
            supplier: '1000', cash: '0', profit: '0');
    }

    /**
     * দুই ট্যাব: একই খসড়া বিল দুই জায়গা থেকে নিশ্চিত — একবারই বসে।
     *
     * চাবি: `purchase.bill.create` (নিশ্চিত করার দরজা)। একই মানুষ, চাবি বন্ধ →
     * ৪০৩ আর খসড়া খসড়াই; চাবি চালু → প্রথম ট্যাব বসায়, দ্বিতীয়টা ফেরে।
     */
    public function test_two_tabs_confirming_the_same_draft_post_it_once(): void
    {
        $bill = app(PurchaseBillService::class)->create(
            $this->header(),
            [['product_id' => $this->rice->id, 'qty' => '10', 'rate' => '100']],
        );
        $this->assertSame(DocumentStatus::DRAFT, $bill->status);

        $clerk = $this->roleLessClerk();
        $before = $this->snapshot();
        $counts = $this->rowCounts();

        $this->actingAs($clerk)
            ->post(route('purchase.bill.confirm', $bill), [FormIsNotSubmittedTwice::FIELD => (string) Str::uuid7()])
            ->assertForbidden();

        $this->assertSame(DocumentStatus::DRAFT, $bill->fresh()->status, 'চাবি ছাড়া অনুরোধে খসড়াটা নিশ্চিত হয়ে গেছে।');
        $this->assertSame($counts, $this->rowCounts(), 'চাবি ছাড়া নিশ্চিতের অনুরোধে খাতা বা মজুদে সারি বসেছে।');

        $this->grant($clerk, 'purchase.bill.create');

        $this->actingAs($clerk)
            ->post(route('purchase.bill.confirm', $bill), [FormIsNotSubmittedTwice::FIELD => (string) Str::uuid7()])
            ->assertSessionHasNoErrors()->assertRedirect(route('purchase.bill.show', $bill));

        $afterFirst = $this->rowCounts();

        $this->actingAs($clerk)
            ->post(route('purchase.bill.confirm', $bill), [FormIsNotSubmittedTwice::FIELD => (string) Str::uuid7()])
            ->assertSessionHasErrors('status');

        $this->assertSame($afterFirst, $this->rowCounts(), 'দ্বিতীয় ট্যাবের নিশ্চিতে খাতা বা মজুদে আবার সারি বসেছে।');
        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status);
        $this->assertPostedOnce($bill);

        $this->assertFiveMatches('দুই ট্যাবে নিশ্চিতের পরে', $before, $this->snapshot(),
            ledger: [StandardChart::INVENTORY => '1000', StandardChart::PAYABLE => '-1000'],
            stock: [$this->rice->id => ['qty' => '10', 'free' => '0', 'value' => '1000']],
            supplier: '1000', cash: '0', profit: '0');
    }

    /**
     * দুই ট্যাব: একই বিল দুই জায়গা থেকে বাতিল — উল্টানো একবারই।
     *
     * ⛔ দ্বিতীয় বাতিল বসলে মজুদ আর দেনা শূন্যের **নিচে** নামত — খাতা মিলত,
     * অঙ্ক ভুল। চাবি: `purchase.bill.cancel`, একই মানুষ বন্ধ → চালু।
     */
    public function test_two_tabs_cancelling_the_same_bill_reverse_it_once(): void
    {
        $before = $this->snapshot();
        $bill = $this->buy([['product_id' => $this->rice->id, 'qty' => '10', 'rate' => '100']]);
        $postedIds = $this->billLedgerIds($bill);
        $postedMoves = $this->billStockIds($bill);

        $clerk = $this->roleLessClerk();
        $counts = $this->rowCounts();

        $this->actingAs($clerk)
            ->post(route('purchase.bill.cancel', $bill), ['reason' => 'ভুল', FormIsNotSubmittedTwice::FIELD => (string) Str::uuid7()])
            ->assertForbidden();

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status, 'চাবি ছাড়া অনুরোধে বিল বাতিল হয়ে গেছে।');
        $this->assertSame($counts, $this->rowCounts(), 'চাবি ছাড়া বাতিলের অনুরোধে খাতা বা মজুদে সারি বসেছে।');

        $this->grant($clerk, 'purchase.bill.cancel');

        $this->actingAs($clerk)
            ->post(route('purchase.bill.cancel', $bill), ['reason' => 'ভুল', FormIsNotSubmittedTwice::FIELD => (string) Str::uuid7()])
            ->assertSessionHasNoErrors()->assertRedirect(route('purchase.bill.show', $bill));

        $afterFirst = $this->rowCounts();

        $this->actingAs($clerk)
            ->post(route('purchase.bill.cancel', $bill), ['reason' => 'ভুল', FormIsNotSubmittedTwice::FIELD => (string) Str::uuid7()])
            ->assertSessionHasErrors('status');

        $this->assertSame($afterFirst, $this->rowCounts(), 'দ্বিতীয় ট্যাবের বাতিলে আবার উল্টো সারি বসেছে।');

        $this->assertFiveMatches('দুই ট্যাবে বাতিলের পরে', $before, $this->snapshot(),
            ledger: [], stock: [$this->rice->id => ['qty' => '0', 'free' => '0', 'value' => '0']],
            supplier: '0', cash: '0', profit: '0');

        $this->assertNothingDeletedAndReversedOnce($bill, $postedIds, $postedMoves, postings: 1, reversals: 1);
    }

    // ════════════════════════════════════════════════════════════════
    //  পাঁচ মিল — ছবি আর মেলানো
    // ════════════════════════════════════════════════════════════════

    /**
     * এই মুহূর্তের পাঁচটা অঙ্ক।
     *
     * @return array{ledger: array<string, string>, debit: string, credit: string,
     *               stock: array<int, array{qty: string, free: string, value: string}>,
     *               supplier: string, supplier_on_payable: string, cash: string, profit: string}
     */
    private function snapshot(): array
    {
        $sums = LedgerEntry::query()
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c')
            ->groupBy('account_id')
            ->get();

        $accounts = Account::query()->whereIn('id', $sums->pluck('account_id'))->get()->keyBy('id');

        $ledger = [];
        $debit = '0';
        $credit = '0';
        $profit = '0';

        foreach ($sums as $row) {
            $account = $accounts->get($row->account_id);
            $net = bcsub((string) $row->d, (string) $row->c, 4);
            $ledger[(string) $account->code] = bcadd($ledger[(string) $account->code] ?? '0', $net, 4);
            $debit = bcadd($debit, (string) $row->d, 4);
            $credit = bcadd($credit, (string) $row->c, 4);

            // লাভ = আয় − ব্যয় = −(আয় ও ব্যয় খাতের নিট ডেবিট)
            if (in_array($account->type, [Account::INCOME, Account::EXPENSE], true)) {
                $profit = bcsub($profit, $net, 4);
            }
        }

        $stock = [];
        foreach ([$this->rice, $this->bucket] as $product) {
            $states = app(StockService::class)->statesFor($product);
            $stock[$product->id] = [
                'qty' => $states['on_hand'],
                'free' => $states['free_on_hand'],
                'value' => app(CostLayerService::class)->valueOnHand($product),
            ];
        }

        $payable = $this->account(StandardChart::PAYABLE);
        $party = LedgerEntry::query()->forParty('supplier', $this->supplier->id);

        $supplier = bcsub(
            (string) ((clone $party)->sum('credit') ?: '0'),
            (string) ((clone $party)->sum('debit') ?: '0'),
            4,
        );
        $supplierOnPayable = bcsub(
            (string) ((clone $party)->where('account_id', $payable->id)->sum('credit') ?: '0'),
            (string) ((clone $party)->where('account_id', $payable->id)->sum('debit') ?: '0'),
            4,
        );

        $till = $this->tillAccount();

        return [
            'ledger' => $ledger,
            'debit' => $debit,
            'credit' => $credit,
            'stock' => $stock,
            'supplier' => $supplier,
            'supplier_on_payable' => $supplierOnPayable,
            'cash' => $ledger[(string) $till->code] ?? '0',
            'profit' => $profit,
        ];
    }

    /**
     * পাঁচ মিল — দুই ছবির পার্থক্য হাতে গোনা অঙ্কের সমান কি না।
     *
     * ⓘ শেয়ার্ড ট্রেইট আসছে; এর নাম আর আকার তাই বদলযোগ্য রাখা।
     *
     * @param  array<string, string>  $ledger  খাতের কোড => নিট ডেবিটের পরিবর্তন (ডেবিট − ক্রেডিট); যা নেই তা শূন্য
     * @param  array<int, array{qty: string, free: string, value: string}>  $stock  পণ্য => পরিবর্তন
     * @param  string  $supplier  সরবরাহকারীকে আমাদের দেনার পরিবর্তন (+ মানে দেনা বাড়ল)
     * @param  string  $cash  নগদ বাক্সের পরিবর্তন
     * @param  string  $profit  লাভের পরিবর্তন
     */
    private function assertFiveMatches(
        string $when,
        array $before,
        array $after,
        array $ledger,
        array $stock,
        string $supplier,
        string $cash,
        string $profit,
    ): void {
        // ── ১. খাতা: ডেবিট = ক্রেডিট, আর প্রতিটা খাতের নড়াচড়া হাতে গোনা ──
        $this->assertSame(0, bccomp($after['debit'], $after['credit'], 4),
            "{$when}: খাতায় মোট ডেবিট {$after['debit']}, মোট ক্রেডিট {$after['credit']} — মেলে না।");

        $codes = array_unique([...array_keys($before['ledger']), ...array_keys($after['ledger']), ...array_map('strval', array_keys($ledger))]);

        foreach ($codes as $code) {
            $moved = bcsub($after['ledger'][$code] ?? '0', $before['ledger'][$code] ?? '0', 4);
            $expected = $ledger[$code] ?? '0';

            $this->assertSame(0, bccomp($moved, $expected, 4),
                "{$when}: খাত {$code}-এর নিট নড়াচড়া {$moved}, হাতে গোনা {$expected}।");
        }

        // ── ২. মজুদ: পরিমাণ, ফ্রি, FIFO মূল্য; আর মজুদ-খাত = স্তরের মূল্য ──
        $valueMoved = '0';

        foreach ($stock as $productId => $want) {
            foreach (['qty', 'free', 'value'] as $key) {
                $moved = bcsub($after['stock'][$productId][$key], $before['stock'][$productId][$key], 4);

                $this->assertSame(0, bccomp($moved, $want[$key], 4),
                    "{$when}: পণ্য #{$productId}-এর মজুদ '{$key}' নড়েছে {$moved}, হাতে গোনা {$want[$key]}।");
            }

            $valueMoved = bcadd($valueMoved, bcsub($after['stock'][$productId]['value'], $before['stock'][$productId]['value'], 4), 4);
        }

        $inventoryMoved = bcsub($after['ledger'][StandardChart::INVENTORY] ?? '0', $before['ledger'][StandardChart::INVENTORY] ?? '0', 4);
        $this->assertSame(0, bccomp($inventoryMoved, $valueMoved, 4),
            "{$when}: মজুদ-খাত ১১২০ নড়েছে {$inventoryMoved}, অথচ FIFO স্তরের মূল্য নড়েছে {$valueMoved}।");

        // ── ৩. পক্ষের বকেয়া: সরবরাহকারীর খতিয়ান = দেনা-খাতে তাঁর অংশ ──
        $supplierMoved = bcsub($after['supplier'], $before['supplier'], 4);
        $this->assertSame(0, bccomp($supplierMoved, $supplier, 4),
            "{$when}: সরবরাহকারীর বকেয়া নড়েছে {$supplierMoved}, হাতে গোনা {$supplier}।");

        $onPayableMoved = bcsub($after['supplier_on_payable'], $before['supplier_on_payable'], 4);
        $this->assertSame(0, bccomp($onPayableMoved, $supplierMoved, 4),
            "{$when}: সরবরাহকারীর খতিয়ান নড়েছে {$supplierMoved}, অথচ দেনা-খাত ২১১১-এ তাঁর অংশ নড়েছে {$onPayableMoved}।");

        // ── ৪. নগদ ও ব্যাংক ──
        $cashMoved = bcsub($after['cash'], $before['cash'], 4);
        $this->assertSame(0, bccomp($cashMoved, $cash, 4),
            "{$when}: নগদ বাক্স নড়েছে {$cashMoved}, হাতে গোনা {$cash}।");

        // ── ৫. লাভ ──
        $profitMoved = bcsub($after['profit'], $before['profit'], 4);
        $this->assertSame(0, bccomp($profitMoved, $profit, 4),
            "{$when}: লাভ-ক্ষতিতে লাভ নড়েছে {$profitMoved}, হাতে গোনা {$profit} — কেনা বা ফেরানো লাভ বদলায় না।");
    }

    // ════════════════════════════════════════════════════════════════
    //  সাক্ষ্য — কিছু মোছে না, একবারই বসে
    // ════════════════════════════════════════════════════════════════

    /**
     * আসল সারিগুলো অক্ষত, উল্টো সারি পড়েছে, আর প্রহরী-টেবিলে গোনা ঠিক।
     *
     * @param  list<int>  $postedIds  প্রথম দাখিলার খাতার সারি
     * @param  list<int>  $postedMoves  প্রথম দাখিলার মজুদের সারি
     */
    private function assertNothingDeletedAndReversedOnce(PurchaseBill $bill, array $postedIds, array $postedMoves, int $postings, int $reversals): void
    {
        $type = PurchaseBill::drillSourceType();

        $this->assertSame(count($postedIds), LedgerEntry::query()->whereIn('id', $postedIds)->count(),
            'প্রথম দাখিলার খাতার সারি মুছে গেছে — উল্টানো মানে নতুন সারি, মোছা নয় (নিয়ম ৫)।');
        $this->assertSame(count($postedMoves), StockMovement::query()->whereIn('id', $postedMoves)->count(),
            'প্রথম দাখিলার মজুদের সারি মুছে গেছে।');

        $this->assertGreaterThan(0, LedgerEntry::query()->where('source_type', $type.':reversal')->where('source_id', $bill->id)->count(),
            'খাতায় কোনো উল্টো সারি নেই।');
        $this->assertGreaterThan(0, StockMovement::query()->where('source_type', PurchaseBill::STOCK_SOURCE.':cancel')->where('source_id', $bill->id)->count(),
            'মজুদে কোনো ফেরতের সারি নেই।');

        // খাতায় বিলের নিট: বসানো − উল্টানো = শূন্য
        $posted = (string) (LedgerEntry::query()->where('source_type', $type)->where('source_id', $bill->id)->sum('debit') ?: '0');
        $reversed = (string) (LedgerEntry::query()->where('source_type', $type.':reversal')->where('source_id', $bill->id)->sum('credit') ?: '0');
        $this->assertSame(0, bccomp($posted, $reversed, 4), "খাতায় বিলের বসানো ডেবিট {$posted}, উল্টানো {$reversed} — নিট শূন্য নয়।");

        [$p, $r] = $this->claims($bill);
        $this->assertSame($postings, $p, "প্রহরী-টেবিলে দাখিলা {$p}টা, প্রত্যাশা {$postings}টা।");
        $this->assertSame($reversals, $r, "প্রহরী-টেবিলে উল্টানো {$r}টা, প্রত্যাশা {$reversals}টা।");
    }

    /** একটাই দাখিলা, কোনো উল্টানো নয় — আর খাতার সারিগুলো ঠিক একবারের অঙ্ক। */
    private function assertPostedOnce(PurchaseBill $bill): void
    {
        [$p, $r] = $this->claims($bill);
        $this->assertSame(1, $p, "প্রহরী-টেবিলে এই বিলের দাখিলা {$p}টা — একটাই হওয়ার কথা।");
        $this->assertSame(0, $r, "প্রহরী-টেবিলে এই বিলের উল্টানো {$r}টা — শূন্য হওয়ার কথা।");

        $rows = LedgerEntry::query()->where('source_type', PurchaseBill::drillSourceType())->where('source_id', $bill->id)->get();
        $debit = (string) $rows->sum('debit');
        $credit = (string) $rows->sum('credit');

        $this->assertSame(0, bccomp($debit, (string) $bill->fresh()->total, 4),
            "খাতায় এই বিলের ডেবিট {$debit}, অথচ বিলের মোট {$bill->fresh()->total} — দুইবার বসলে দ্বিগুণ হত।");
        $this->assertSame(0, bccomp($debit, $credit, 4), "এই বিলের দাখিলা নিজেই মেলে না: ডেবিট {$debit}, ক্রেডিট {$credit}।");

        $moved = (string) (StockMovement::query()->where('source_type', PurchaseBill::STOCK_SOURCE)->where('source_id', $bill->id)
            ->selectRaw('COALESCE(SUM(floor_change), 0) + COALESCE(SUM(unplaced_change), 0) AS q')->value('q') ?? '0');
        $this->assertSame(0, bccomp($moved, (string) $bill->fresh(['lines'])->lines->sum('qty'), 4),
            "গুদামে এই বিলের মাল {$moved} — বিলের পরিমাণের সমান নয়, অর্থাৎ মাল একাধিকবার নড়েছে।");
    }

    /** @return array{0: int, 1: int} প্রহরী-টেবিলে এই বিলের [দাখিলা, উল্টানো] */
    private function claims(PurchaseBill $bill): array
    {
        $type = PurchaseBill::drillSourceType();
        $rows = DB::table('posted_documents')
            ->where('company_id', $this->company->id)
            ->where('source_id', $bill->id)
            ->where(fn ($q) => $q->where('source_type', $type)->orWhere('source_type', 'like', $type.'@%')
                ->orWhere('source_type', 'like', $type.':reversal%'))
            ->pluck('source_type');

        $reversals = $rows->filter(fn (string $t) => str_starts_with($t, $type.':reversal'))->count();

        return [$rows->count() - $reversals, $reversals];
    }

    /** @return array<string, int> খাতা, মজুদ, প্রহরী-টেবিল, ভাউচার আর বিলের মোট সারি */
    private function rowCounts(): array
    {
        return [
            'ledger' => LedgerEntry::query()->count(),
            'stock' => StockMovement::query()->count(),
            'posted_documents' => DB::table('posted_documents')->count(),
            'vouchers' => Voucher::query()->count(),
            'bills' => PurchaseBill::query()->count(),
        ];
    }

    /** @return list<int> */
    private function billLedgerIds(PurchaseBill $bill): array
    {
        return LedgerEntry::query()->where('source_type', PurchaseBill::drillSourceType())
            ->where('source_id', $bill->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private function billStockIds(PurchaseBill $bill): array
    {
        return StockMovement::query()->where('source_type', PurchaseBill::STOCK_SOURCE)
            ->where('source_id', $bill->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** থামল কি না — যাচাইয়ের বার্তা নিয়ে, ৫০০ নয়। */
    private function assertRefused(callable $attempt, string $what): void
    {
        try {
            $attempt();
        } catch (ValidationException $refused) {
            $this->assertNotEmpty($refused->errors(), "{$what} থেমেছে, কিন্তু কোনো কারণ বলেনি।");

            return;
        }

        $this->fail("মাল খরচ হয়ে যাওয়ার পরেও {$what} হয়ে গেল — নকশা বলে আগে বিক্রয়টা ফেরাতে হবে।");
    }

    // ════════════════════════════════════════════════════════════════
    //  গঠন
    // ════════════════════════════════════════════════════════════════

    /** @param list<array<string, mixed>> $lines */
    private function buy(array $lines): PurchaseBill
    {
        $bill = app(DirectPurchaseService::class)->complete($this->header(), $lines)['bill'];

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status, 'সরাসরি ক্রয় নিশ্চিত হয়নি (অনুমোদনে আটকেছে?)।');

        return $bill->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function header(array $overrides = []): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'UNDO-'.Str::upper(Str::random(8)),
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function httpPayload(array $overrides = []): array
    {
        return $this->header([
            'lines' => [['product_id' => $this->rice->id, 'qty' => '10', 'rate' => '100']],
            ...$overrides,
        ]);
    }

    private function newProduct(string $code, string $name): Product
    {
        return Product::query()->create([
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    /** ভূমিকাহীন একজন — কোম্পানির সদস্য, কিন্তু কোনো চাবি নেই। */
    private function roleLessClerk(): User
    {
        $clerk = User::factory()->create();
        $clerk->companies()->attach($this->company, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();

        $this->assertCount(0, $clerk->roles, 'পরীক্ষার মানুষটার কোনো ভূমিকা থাকার কথা নয়।');

        return $clerk;
    }

    private function grant(User $user, string $key): void
    {
        $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('permissions');
    }

    private function tillAccount(): Account
    {
        return Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id);
    }

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }
}

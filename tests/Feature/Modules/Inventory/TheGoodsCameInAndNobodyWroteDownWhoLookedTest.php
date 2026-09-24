<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মাল এল, আর কে দেখল তা কেউ লিখে রাখল না।
 *
 * ── ⛔ আগে যা হত ────────────────────────────────────────────────────
 * মাল এলে গুদামের লোক দেখে নিতেন, খারাপ হলে আটকে দিতেন। ⓘ সিদ্ধান্তটা
 * হত, **সিদ্ধান্তের কাগজটা হত না**। ⚠️ তিন মাস পরে সরবরাহকারী বলতেন
 * *"আমার মাল খারাপ ছিল না"*, আর আমাদের হাতে থাকত কেবল একটা আটকানোর
 * সারি — কে দেখেছিল, কী দেখে বাতিল করেছিল, কিছুই নয়।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. কাগজ খোলায় মজুদ নড়ে না
 *   ২. গৃহীত হলে কিছুই আটকায় না
 *   ৩. কোয়ারেন্টাইনে **পুরোটা** আটকায়
 *   ৪. বাতিলে **কেবল বাতিল অংশটা** আটকায়
 *   ৫. যোগফল না মিললে রায় নেওয়া হয় না
 *   ৬. রায়ের চাবি আলাদা — যিনি কাগজ খোলেন তিনি রায় দিতে পারেন না
 *
 * ⓘ (৪) সবচেয়ে সহজে ভাঙে। ⛔ পুরোটা আটকে দিলে যে চল্লিশ বস্তা ঠিক
 * আছে সেগুলোও বিক্রির বাইরে চলে যেত, আর কেউ টের পেত না — কারণ পর্দায়
 * লেখা থাকত "বাতিল ১০"।
 */
final class TheGoodsCameInAndNobodyWroteDownWhoLookedTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->product = $this->stocked('পরিদর্শনের পণ্য', '50');
    }

    // ── ১ · কাগজ খোলায় মজুদ নড়ে না ───────────────────────────────────

    public function test_opening_a_paper_moves_no_stock(): void
    {
        $this->open('50');

        $this->assertSame(0, bccomp($this->heldQty(), '0', 4),
            'কেবল কাগজ খুলতেই মাল আটকে গেছে — তাহলে যে কেউ কাগজ খুলে '
            .'গুদামের মাল বিক্রির বাইরে পাঠিয়ে দিতে পারতেন।');
    }

    public function test_the_sheet_only_offers_products_that_ask_for_inspection(): void
    {
        /*
         * ⛔ মালিকের সিদ্ধান্ত: পরীক্ষাটা **পণ্য ধরে ধরে** চালু, সব
         * পণ্যে নয়। ⚠️ সব পণ্য দেখালে তালিকাটা হাজার সারির হত, আর
         * সিদ্ধান্তটাই অর্থহীন হয়ে যেত।
         */
        $other = $this->stocked('যে পণ্যে পরিদর্শন লাগে না', '10', qc: false);

        $html = (string) $this->actingAs($this->owner)
            ->get(route('inventory.qc.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->product->name(), $html);

        $this->assertStringNotContainsString($other->name(), $html,
            'যে পণ্যে পরিদর্শন লাগে না সেটাও তালিকায় এসেছে — তাহলে '
            .'"পণ্য ধরে ধরে চালু" কথাটার কোনো মানে থাকে না।');
    }

    // ── ২–৪ · রায় কতটা আটকায় ─────────────────────────────────────────

    public function test_approved_goods_are_not_held_at_all(): void
    {
        $inspection = $this->open('50');
        $this->decide($inspection, QualityInspection::APPROVED, '50', '0');

        $this->assertSame(0, bccomp($this->heldQty(), '0', 4),
            'পাশ করা মালও আটকে আছে — অর্থাৎ পরিদর্শনে পাশ করাটাই শাস্তি।');
    }

    public function test_quarantine_holds_every_bit_of_it(): void
    {
        /*
         * ⓘ কোয়ারেন্টাইন মানে *"এখনো জানি না"*, ⚠️ তাই কোনটা ভালো
         * কোনটা খারাপ আলাদা করার প্রশ্নই ওঠে না — পুরোটাই আটকায়।
         */
        $inspection = $this->open('50');
        $this->decide($inspection, QualityInspection::QUARANTINE, '50', '0');

        $this->assertSame(0, bccomp($this->heldQty(), '50', 4),
            'কোয়ারেন্টাইনে পুরো মালটা আটকায়নি — তাহলে যে মাল নিয়ে সন্দেহ '
            .'আছে তারই একটা অংশ দিব্যি বিক্রি হয়ে যেত।');
    }

    public function test_rejection_holds_only_the_rejected_part(): void
    {
        /*
         * ⛔ এটাই সবচেয়ে সহজে ভাঙে। ⚠️ পুরোটা আটকে দিলে যে চল্লিশ
         * বস্তা ঠিক আছে সেগুলোও বিক্রির বাইরে চলে যেত, আর পর্দায়
         * লেখা থাকত "বাতিল ১০" — অর্থাৎ ক্ষতিটা নীরব।
         */
        $inspection = $this->open('50');
        $this->decide($inspection, QualityInspection::REJECTED, '40', '10');

        $this->assertSame(0, bccomp($this->heldQty(), '10', 4),
            'বাতিলে পুরো চালানটাই আটকে গেছে — অথচ চল্লিশ বস্তা ঠিক আছে, '
            .'আর সেগুলো বিক্রির বাইরে পাঠানোর কোনো কারণ নেই।');
    }

    // ── ৫ · যোগফল মিলতেই হবে ─────────────────────────────────────────

    public function test_the_parts_must_add_up_to_what_was_inspected(): void
    {
        /*
         * ⛔ না মিললে বাকিটা কোথায় গেল তার কোনো উত্তর থাকত না, আর
         * ⚠️ ঝুলে থাকা মাল কারও খাতায় নেই বলে সেটাই সবচেয়ে সহজে হারায়।
         */
        $inspection = $this->open('50');

        $this->actingAs($this->owner)
            ->from(route('inventory.qc.show', $inspection))
            ->post(route('inventory.qc.decide', $inspection), [
                'result' => QualityInspection::REJECTED,
                'accepted_qty' => '30',
                'rejected_qty' => '10',
            ])
            ->assertSessionHasErrors('accepted_qty');

        $this->assertTrue($inspection->fresh()->isPending(),
            'যোগফল না মিললেও রায়টা বসে গেছে।');

        $this->assertSame(0, bccomp($this->heldQty(), '0', 4),
            'ব্যর্থ রায়েও মাল আটকে গেছে।');
    }

    public function test_a_decided_paper_cannot_be_decided_again(): void
    {
        $inspection = $this->open('50');
        $this->decide($inspection, QualityInspection::REJECTED, '40', '10');

        $this->actingAs($this->owner)
            ->from(route('inventory.qc.show', $inspection))
            ->post(route('inventory.qc.decide', $inspection), [
                'result' => QualityInspection::REJECTED,
                'accepted_qty' => '0',
                'rejected_qty' => '50',
            ])
            ->assertForbidden();

        $this->assertSame(0, bccomp($this->heldQty(), '10', 4),
            'দ্বিতীয়বার রায় বসে গেছে, আর আটকানো মালের পরিমাণ দুইবার গোনা হয়েছে।');
    }

    // ── ৬ · রায়ের চাবি আলাদা ─────────────────────────────────────────

    public function test_the_one_who_opens_the_paper_cannot_decide_it(): void
    {
        $inspection = $this->open('50');

        $this->actingAs($this->aUserWhoCanOnlyOpen())
            ->post(route('inventory.qc.decide', $inspection), [
                'result' => QualityInspection::REJECTED,
                'accepted_qty' => '0',
                'rejected_qty' => '50',
            ])
            ->assertForbidden();

        $this->assertTrue($inspection->fresh()->isPending());
    }

    public function test_that_person_can_still_open_a_paper(): void
    {
        /*
         * ⚠️ উপরের পাহারাটা উল্টো দিকেও সবুজ থাকত: চাবিটা এত শক্ত করে
         * বসানো যে গুদামের লোক কাগজই খুলতে পারেন না। ⓘ তখন পরিদর্শন
         * বলে কিছুই হত না, আর পরীক্ষাটা কিছুই বলত না।
         */
        $this->actingAs($this->aUserWhoCanOnlyOpen())
            ->get(route('inventory.qc.create'))
            ->assertOk();
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function open(string $qty): QualityInspection
    {
        $this->actingAs($this->owner)
            ->post(route('inventory.qc.store'), [
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'inspected_on' => now()->toDateString(),
                'inspected_qty' => $qty,
            ])
            ->assertRedirect();

        return QualityInspection::query()->latest('id')->firstOrFail();
    }

    private function decide(
        QualityInspection $inspection,
        string $result,
        string $accepted,
        string $rejected,
    ): void {
        $this->actingAs($this->owner)
            ->from(route('inventory.qc.show', $inspection))
            ->post(route('inventory.qc.decide', $inspection), [
                'result' => $result,
                'accepted_qty' => $accepted,
                'rejected_qty' => $rejected,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    private function heldQty(): string
    {
        return app(StockService::class)->holdQty($this->product, $this->warehouse);
    }

    /**
     * কাগজ খোলার চাবি আছে, রায়ের নেই — ঠিক গুদামের লোকের মতো।
     */
    private function aUserWhoCanOnlyOpen(): User
    {
        $user = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        CompanyContext::forCompany(
            (int) CompanyContext::id(),
            fn () => $user->givePermissionTo('inventory.qc.view', 'inventory.qc.create'),
        );

        return $user->fresh();
    }

    private function stocked(string $name, string $onHand, bool $qc = true): Product
    {
        $product = Product::query()->create([
            'code' => 'QC-'.mb_substr(md5($name.microtime()), 0, 8),
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'qc_required' => $qc,
            'is_active' => true,
        ]);

        app(StockService::class)->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'test.opening',
            sourceId: $product->id,
            floor: $onHand,
        );

        return $product;
    }
}

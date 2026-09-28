<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Models\ComboItem;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * কম্বোর উপাদান লেখার কোনো জায়গা ছিল না — স্পেক §৭-ছ, §৭-জ।
 *
 * ── ⚠️ কী ঘটছিল ────────────────────────────────────────────────────
 * ⓘ [[ComboRules]] ছিল, [[BillPromotionEngine]] উপাদান পড়ত — কিন্তু কেবল
 * পরীক্ষা উপাদান লিখত। ⛔ মানুষের হাতে *"ক + খ একসাথে"* বলার কোনো দরজা
 * ছিল না; কম্বো অফার বানানো গেলেও সেটা কোনোদিন খুলত না।
 *
 * ── ⓘ কী মাপা হলো ──────────────────────────────────────────────────
 * [[PromotionComboController]] — চাবি, খসড়া, শূন্য পরিমাণ, অন্য কোম্পানির পণ্য।
 * ⭐ প্রতিটা *"না"*-এর পাশে একটা *"হ্যাঁ"*: কেবল না-গুলো লিখলে ভাঙা রুটও
 * সবুজ হত।
 */
final class TheComboHadNoPlaceToNameItsPartsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Product $a;

    private Product $b;

    private int $serial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        [$this->a, $this->b] = Product::query()->orderBy('id')->take(2)->get()->all();
    }

    /**
     * ⓘ কম্বো সরাসরি সারি হিসেবে — [[PromotionType::isBuilt()]] আজ কম্বোকে
     * তৈরির পর্দায় বাছতে দেয় না, আর সেটা ইচ্ছাকৃত।
     */
    private function anOffer(PromotionStatus $status = PromotionStatus::DRAFT, PromotionType $type = PromotionType::COMBO): Promotion
    {
        $this->serial++;

        $offer = new Promotion([
            'name_en' => 'Combo door '.$this->serial,
            'starts_on' => Carbon::today()->subDay(),
            'ends_on' => Carbon::today()->addWeek(),
        ]);
        $offer->code = 'PROM-CD-'.$this->serial;
        $offer->type = $type;
        $offer->status = $status;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        return $offer;
    }

    private function addPart(Promotion $offer, int $productId, string $minQty, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->post(route('promotion.combo.store', $offer), [
                'product_id' => $productId,
                'min_qty' => $minQty,
            ]);
    }

    private function partsOf(Promotion $offer): int
    {
        return ComboItem::query()->where('promotion_id', $offer->id)->count();
    }

    /** ⭐ একই মানুষ: `update` চাবি ছাড়া দরজা বন্ধ, চাবি পেলে খোলে আর উপাদান বসে। */
    public function test_the_same_person_needs_the_update_key(): void
    {
        $offer = $this->anOffer();

        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();
        $clerk->givePermissionTo(Permission::findOrCreate('promotion.view', 'web'));

        $this->addPart($offer, $this->a->id, '2', $clerk)->assertForbidden();
        $this->assertSame(0, $this->partsOf($offer), '৪০৩ দিয়েও উপাদান বসে গেছে।');

        $clerk->givePermissionTo(Permission::findOrCreate('promotion.update', 'web'));

        $this->addPart($offer, $this->a->id, '2', $clerk->fresh())->assertSessionHasNoErrors();

        $row = ComboItem::query()->where('promotion_id', $offer->id)->first();
        $this->assertNotNull($row, 'চাবি পাওয়ার পরেও উপাদান বসেনি — তাহলে ৪০৩-টা চাবির জন্য ছিল না।');
        $this->assertSame($this->a->id, $row->product_id);
        $this->assertSame(0, bccomp((string) $row->min_qty, '2', 4));
    }

    /**
     * ⛔ খসড়া ছাড়া উপাদান বসে না, সরেও না।
     *
     * ⚠️ বিপজ্জনক ইনপুট: অনুমোদনের অপেক্ষায় থাকা অফারে নতুন পণ্য। ⓘ ধরা না
     * পড়লে অনুমোদনকারী যা দেখেছিলেন আর যা চলত, দুইটা আলাদা হত।
     */
    public function test_parts_change_only_in_draft(): void
    {
        $waiting = $this->anOffer(PromotionStatus::SUBMITTED);

        $this->addPart($waiting, $this->a->id, '1')->assertSessionHasErrors('status');
        $this->assertSame(0, $this->partsOf($waiting));

        $item = ComboItem::query()->create([
            'promotion_id' => $waiting->id, 'product_id' => $this->b->id, 'min_qty' => '1',
        ]);

        $this->actingAs($this->owner)
            ->delete(route('promotion.combo.destroy', [$waiting, $item]))
            ->assertSessionHasErrors('status');
        $this->assertSame(1, $this->partsOf($waiting), 'অনুমোদনের পথে থাকা কম্বো থেকে পণ্য সরে গেছে।');

        /* ⭐ পাল্টা-দাবি: খসড়ায় যোগ ও সরানো দুটোই চলে */
        $draft = $this->anOffer();
        $this->addPart($draft, $this->a->id, '1')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->partsOf($draft));

        $mine = ComboItem::query()->where('promotion_id', $draft->id)->firstOrFail();
        $this->actingAs($this->owner)
            ->delete(route('promotion.combo.destroy', [$draft, $mine]))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $this->partsOf($draft));
    }

    /**
     * ⛔ শূন্য পরিমাণ ফেরে — ঋণাত্মক আর বৈজ্ঞানিক রূপও।
     *
     * ⚠️ শূন্য মানে *"না কিনলেও চলে"* — উপাদানটা তালিকায় থাকত অথচ কিছুই চাইত
     * না। ⓘ `1e2` bcmath-এ ৫০০ দিত ([[Decimal]])।
     */
    public function test_a_zero_quantity_is_refused(): void
    {
        $offer = $this->anOffer();

        foreach (['0', '0.0000', '-1', '1e2'] as $bad) {
            $this->addPart($offer, $this->a->id, $bad)->assertSessionHasErrors('min_qty');
        }
        $this->assertSame(0, $this->partsOf($offer), 'শূন্য বা অচল পরিমাণের উপাদান বসে গেছে।');

        /* ⭐ পাল্টা-দাবি: ভগ্নাংশ চলে — খোলা মালের কম্বো */
        $this->addPart($offer, $this->a->id, '0.5')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->partsOf($offer));
    }

    /**
     * ⛔ অন্য কোম্পানির পণ্য উপাদান হয় না।
     *
     * ⚠️ বিপজ্জনক ইনপুট: FMART-এর একটা **সত্যিকারের** পণ্যের নম্বর — কাল্পনিক
     * নম্বর দিলে দাবিটা কেবল *"নেই"* মাপত, দেয়াল নয়।
     */
    public function test_another_companys_product_is_refused(): void
    {
        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        /*
         * ⓘ অন্য কোম্পানির পণ্য এখানেই বানানো — ডেমোর উপর ভরসা নয়।
         * ⚠️ ডেমো বদলালে (FMART এখন পণ্য-শূন্য) দাবিটা মাপার কিছু পেত না।
         */
        $theirs = \App\Core\Support\CompanyContext::forCompany($other->id, fn () => Product::query()->create([
            'code' => 'FM-COMBO-'.mt_rand(1000, 9999),
            'name_en' => 'Their Rice',
            'sale_price' => '100',
            'purchase_price' => '80',
            'is_active' => true,
        ]))->id;

        $this->assertNotNull($theirs, 'FMART-এ কোনো পণ্য নেই — দাবিটা কিছু মাপছে না।');
        $this->assertNotSame($this->company->id, $other->id);

        $offer = $this->anOffer();

        $this->addPart($offer, (int) $theirs, '1')->assertSessionHasErrors('product_id');
        $this->assertSame(0, $this->partsOf($offer), 'অন্য কোম্পানির পণ্য কম্বোতে বসে গেছে।');

        /* ⭐ পাল্টা-দাবি: নিজের পণ্য বসে */
        $this->addPart($offer, $this->b->id, '1')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->partsOf($offer));
    }

    /**
     * ⭐ উপাদানের ঘরটা অফারের পাতায় সত্যিই আছে — কম্বোয় হ্যাঁ, সাধারণ অফারে না।
     *
     * ⛔ দরজা আর সেবা থাকলেও পাতায় ফর্ম না থাকলে কম্বো কেবল কোডে বানানো যেত —
     * অর্ধেক জোড়া লাগানো কাজ ([[the-work-is-done-the-wiring-is-not]])।
     */
    public function test_the_offer_page_carries_the_parts_box_only_for_a_combo(): void
    {
        $combo = $this->anOffer();
        $this->addPart($combo, $this->a->id, '2')->assertSessionHasNoErrors();

        $page = $this->actingAs($this->owner)->get(route('promotion.show', $combo))->assertOk();
        $page->assertSee(__('promotion::combo.title'));
        $page->assertSee(route('promotion.combo.store', $combo), escape: false);
        $page->assertSee($this->a->name());

        $plain = $this->anOffer(PromotionStatus::DRAFT, PromotionType::PERCENT_DISCOUNT);

        $this->actingAs($this->owner)->get(route('promotion.show', $plain))->assertOk()
            ->assertDontSee(route('promotion.combo.store', $plain), escape: false);
    }

    /** ⛔ সাধারণ অফারে পণ্যের তালিকা বসে না — ধরন কম্বো বা বান্ডল হতে হবে। */
    public function test_a_plain_offer_takes_no_parts(): void
    {
        $plain = $this->anOffer(PromotionStatus::DRAFT, PromotionType::PERCENT_DISCOUNT);

        $this->addPart($plain, $this->a->id, '1')->assertSessionHasErrors('type');
        $this->assertSame(0, $this->partsOf($plain));

        /* ⭐ পাল্টা-দাবি: বান্ডলও কম্বোর মতোই নেয় */
        $bundle = $this->anOffer(PromotionStatus::DRAFT, PromotionType::BUNDLE);
        $this->addPart($bundle, $this->a->id, '1')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->partsOf($bundle));
    }

    /** ⛔ এক অফারের ঠিকানা দিয়ে আরেক অফারের উপাদান সরানো যায় না। */
    public function test_a_part_of_another_offer_cannot_be_removed(): void
    {
        $mine = $this->anOffer();
        $theirs = $this->anOffer();

        $item = ComboItem::query()->create([
            'promotion_id' => $theirs->id, 'product_id' => $this->a->id, 'min_qty' => '1',
        ]);

        $this->actingAs($this->owner)
            ->delete(route('promotion.combo.destroy', [$mine, $item]))
            ->assertNotFound();

        $this->assertSame(1, $this->partsOf($theirs), 'অন্য অফারের উপাদান সরে গেছে।');
    }
}

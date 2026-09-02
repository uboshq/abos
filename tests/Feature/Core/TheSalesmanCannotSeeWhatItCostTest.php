<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিক্রয়কর্মী দেখেন না জিনিসটা কত দামে কেনা।
 *
 * ── কী ভাঙা ছিল (২ সেপ্টেম্বর ২০২৬) ──────────────────────────────────
 * ঘর-স্তরের কোনো নিরাপত্তা ছিল না — গোনা হয়েছিল, **শূন্য**। একটামাত্র
 * হাতে লেখা শর্ত ছিল বিক্রয় চালানের `cost_of_goods` ঘরে; বাকি সব খোলা।
 *
 * সবচেয়ে জোরালোটা পণ্যের পাতায়: **ক্রয়মূল্য যেকোনো লগইন-করা মানুষ
 * দেখতে পেতেন।** বিক্রয়কর্মীকে পণ্যের পাতা দেখতেই হয় — নাহলে তিনি
 * বেচবেন কী করে — তাই পাতাটা বন্ধ করা কোনো উত্তর ছিল না।
 *
 * ── কেন এটাই সবচেয়ে দামি ফাঁক ────────────────────────────────────────
 * ERP বিক্রি করতে গেলে প্রথম যে প্রশ্নটা আসে, প্রায় আক্ষরিক অর্থেই এটা:
 * *"আমার সেলসম্যান কি ক্রয়মূল্য দেখতে পাবে?"* ক্রয়মূল্য জানা থাকলে
 * দরকষাকষিতে সেটাই ব্যবহার হয়, আর কোম্পানির মার্জিন বাইরে চলে যায়।
 *
 * ── তিনটা দরজা, তিনটাই বন্ধ করতে হয় ─────────────────────────────────
 * দেখা · সম্পাদনার ফর্ম · আর হাতে বানানো POST। যেকোনো একটা খোলা
 * থাকলে বাকি দুইটা কেবল সাজ — আর এই ফাইলটা তিনটাই আলাদা করে দেখে।
 */
class TheSalesmanCannotSeeWhatItCostTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        FieldSecurity::forget();

        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->product->forceFill(['purchase_price' => '640'])->save();
    }

    /**
     * বিক্রয়কর্মী — পণ্য দেখতে পারেন, ক্রয়মূল্য নয়।
     *
     * ── কেন পণ্য দেখার অনুমতিটা এখানে দিয়ে দেওয়া হয় ─────────────────
     * ডেমোর বিক্রয়কর্মীর ওই অনুমতিটা নেই, তাই পাতাটাই ৪০৩ দেয়। কিন্তু
     * প্রশ্নটা "পাতা দেখতে পান কি না" নয় — **পাতা দেখতে পেয়েও ঘরটা
     * দেখতে পান কি না**। অনুমতিটা না দিলে পরীক্ষাটা ভুল কারণে সবুজ
     * হত: সংখ্যাটা নেই, কারণ পুরো পাতাটাই নেই।
     *
     * ঘরের অনুমতিটা ইচ্ছে করে দেওয়া হয় না — সেটাই তো মাপার জিনিস।
     */
    private function salesman(): User
    {
        $user = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        foreach (['inventory.product.view', 'inventory.product.update'] as $permission) {
            if (! $user->can($permission)) {
                $user->givePermissionTo($permission);
            }
        }

        return $user->fresh();
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    // ── ঘোষণাটাই আছে কি না ───────────────────────────────────────────

    /**
     * ঘরটা কোনো অনুমতির পেছনে আছে বলে ঘোষিত।
     *
     * এটা না থাকলে নিচের সবগুলো পরীক্ষা সবুজ থাকত — কারণ ঘোষণাহীন
     * ঘর সবসময় দেখা যায়, আর "দেখা যায়" মানেই তো পরীক্ষাগুলো পাশ।
     */
    public function test_the_purchase_price_is_declared_sensitive(): void
    {
        $this->assertSame(
            'inventory.cost.view',
            FieldSecurity::permissionFor(Product::class, 'purchase_price'),
        );
    }

    // ── দরজা ১ · দেখা ────────────────────────────────────────────────

    /** বিক্রয়কর্মীর পর্দায় সংখ্যাটা নেই, চিহ্নটা আছে। */
    public function test_the_salesman_sees_a_mask_instead_of_the_cost(): void
    {
        $html = (string) $this->actingAs($this->salesman())
            ->get(route('inventory.product.show', $this->product))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(FieldSecurity::mask(), $html);
        $this->assertStringNotContainsString('640', $html, 'ক্রয়মূল্যটা পর্দায় রয়ে গেছে।');
    }

    /**
     * আর যাঁর দেখার কথা, তিনি দেখেন।
     *
     * ── কেন এই উল্টো পরীক্ষাটা লাগে ─────────────────────────────────
     * উপরেরটা একা থাকলে "সবার কাছে সবসময় ঢাকা" রেখেও সবুজ থাকত —
     * আর তখন মালিক নিজেও নিজের ক্রয়মূল্য দেখতে পেতেন না, যা একটা
     * ফাঁসের চেয়ে দ্রুত ধরা পড়ত কিন্তু ততটাই ভুল।
     */
    public function test_the_owner_still_sees_the_number(): void
    {
        $html = (string) $this->actingAs($this->owner())
            ->get(route('inventory.product.show', $this->product))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('640', $html);
    }

    // ── দরজা ২ · সম্পাদনার ফর্ম ──────────────────────────────────────

    /**
     * ফর্মেও ঘরটা নেই।
     *
     * পর্দায় ঢেকে ফর্মে খোলা রাখলে "সম্পাদনা" চাপলেই সংখ্যাটা দেখা
     * যেত — অর্থাৎ পাহারাটা এক ক্লিক দূরে।
     */
    public function test_the_cost_field_is_absent_from_the_edit_form(): void
    {
        $html = (string) $this->actingAs($this->salesman())
            ->get(route('inventory.product.edit', $this->product))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('name="purchase_price"', $html);
        $this->assertStringNotContainsString('640', $html);
    }

    // ── দরজা ৩ · হাতে বানানো অনুরোধ ──────────────────────────────────

    /**
     * যে ঘর দেখা যায় না, সে ঘর বদলানোও যায় না।
     *
     * ── কেন এটাই সবচেয়ে জরুরি পরীক্ষা ───────────────────────────────
     * উপরের দুইটা কেবল **পর্দা** দেখে। ফর্মে ঘরটা না থাকলেও একটা
     * হাতে বানানো POST-এ `purchase_price` পাঠিয়ে দেওয়া যায়, আর
     * ব্রাউজারের ডেভেলপার কনসোল থেকে সেটা এক মিনিটের কাজ।
     *
     * দর বদলে দিলে ক্ষতি দুই দিকেই: মুনাফার হিসাব মিথ্যা হয়, আর
     * পরের গণনায় ওই ভুল দরই ব্যবহার হয়।
     */
    public function test_a_hand_made_post_cannot_change_the_cost(): void
    {
        $this->actingAs($this->salesman())->put(
            route('inventory.product.update', $this->product),
            [
                'code' => $this->product->code,
                'name_en' => $this->product->name_en,
                'unit_id' => $this->product->unit_id,
                'purchase_price' => '1',
                'sale_price' => $this->product->sale_price,
            ],
        );

        $this->assertSame(
            0,
            bccomp('640', (string) $this->product->fresh()->purchase_price, 2),
            'দেখা যায় না এমন একটা ঘর হাতে বানানো অনুরোধে বদলে গেছে।',
        );
    }

    /** অথচ মালিক ওই একই অনুরোধে দরটা বদলাতে পারেন। */
    public function test_the_owner_can_still_change_it(): void
    {
        $this->actingAs($this->owner())->put(
            route('inventory.product.update', $this->product),
            [
                'code' => $this->product->code,
                'name_en' => $this->product->name_en,
                'unit_id' => $this->product->unit_id,
                'purchase_price' => '700',
                'sale_price' => $this->product->sale_price,
            ],
        );

        $this->assertSame(
            0,
            bccomp('700', (string) $this->product->fresh()->purchase_price, 2),
            'যাঁর অধিকার আছে তিনিও দর বদলাতে পারছেন না।',
        );
    }
}

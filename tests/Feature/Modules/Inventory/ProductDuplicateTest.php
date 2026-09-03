<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই নামে দুইবার পণ্য নয় — দরজাটা এতদিন ছিল না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * [[DuplicateGuard]] অনেক দিন ধরেই আছে, কিন্তু ডাকত কেবল গ্রাহক ও
 * সরবরাহকারী। পণ্য ডাকত না, তাই লাইভে হুবহু এক নামে দুইটা পণ্য বসে
 * গিয়েছিল ("QA পরীক্ষা চাল ৫০ কেজি" দুইবার), দুইটাতেই আলাদা মজুদ।
 *
 * ── নাম আটকায় না, সতর্ক করে ─────────────────────────────────────────
 * একই নামে দুইটা আলাদা পণ্য সত্যিই থাকতে পারে না বেশিরভাগ ক্ষেত্রে, তবু
 * ব্যতিক্রম আছে — তাই আটকানো নয়, `allow_duplicate` দিয়ে জেনেশুনে এগোনো
 * যায়, ঠিক গ্রাহকের মতোই।
 */
class ProductDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private ProductService $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->products = app(ProductService::class);
    }

    /** @param array<string, mixed> $extra */
    private function make(string $nameEn, ?string $nameBn = null, array $extra = []): Product
    {
        return $this->products->create([
            'name_en' => $nameEn,
            'name_bn' => $nameBn,
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'purchase_price' => 100,
            'sale_price' => 120,
            'reorder_level' => 0,
            ...$extra,
        ]);
    }

    public function test_the_same_name_twice_is_stopped(): void
    {
        $this->make('Zeta Widget');

        $this->expectException(ValidationException::class);
        $this->make('zeta  widget'); // ফাঁক/বড়-ছোট হাতের ভিন্নতা — একই নাম
    }

    public function test_the_stop_can_be_overridden(): void
    {
        $this->make('Acme Gadget');

        try {
            $this->make('Acme Gadget');
            $this->fail('একই নামে দ্বিতীয় পণ্য নীরবে বসে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name_en', $e->errors());
        }

        $second = $this->make('Acme Gadget', extra: ['allow_duplicate' => true]);
        $this->assertNotNull($second->id);
        $this->assertSame(2, Product::query()->where('name_en', 'Acme Gadget')->count());
    }

    /**
     * ★ বাংলা নামে মিল — trap ৩।
     *
     * ইংরেজি নাম আলাদা, বাংলা নাম এক। বাংলা-first এন্ট্রি এই রিপোতে
     * সাধারণ, তাই কেবল ইংরেজি নাম দেখলে এই নকলটা ধরা পড়ত না।
     */
    public function test_a_bengali_only_duplicate_is_caught(): void
    {
        $this->make('Product One', 'দেশি চিনি ১ কেজি');

        $this->expectException(ValidationException::class);
        $this->make('Product Two', 'দেশি চিনি ১ কেজি');
    }

    /**
     * ★ অঙ্ক-ভাঁজ — "৫০" আর "50" এক পণ্য।
     */
    public function test_bengali_and_ascii_digits_are_the_same_product(): void
    {
        $this->make('Chal 50');

        $this->expectException(ValidationException::class);
        $this->make('Chal ৫০'); // বাংলা অঙ্ক — ভাঁজ করে মিলে যায়
    }

    /**
     * ★ ভিন্ন সংখ্যা = ভিন্ন পণ্য — trap ২, কখনো এক নয়।
     *
     * "চাল ৫০ কেজি" আর "চাল ২৫ কেজি" আলাদা পণ্য; এই দুইটা আটকালে
     * কাউন্টারে মানুষ কাজই করতে পারত না।
     */
    public function test_a_different_number_is_a_different_product(): void
    {
        $this->make('Chal 50 kg');
        $second = $this->make('Chal 25 kg');

        $this->assertNotNull($second->id);
    }

    /** নিজের সারি নিজের নকল নয় — সম্পাদনায় নাম রাখলে আটকায় না। */
    public function test_editing_a_product_does_not_trip_over_itself(): void
    {
        $product = $this->make('Beta Tool');

        $updated = $this->products->update($product, [
            'name_en' => 'Beta Tool',
            'sale_price' => 130,
        ]);

        $this->assertSame('Beta Tool', $updated->name_en);
        $this->assertSame(0, bccomp((string) $updated->sale_price, '130', 2));
    }

    /**
     * অন্য কোম্পানির পণ্য নকল নয় — ABOS বহু-কোম্পানি।
     */
    public function test_another_company_is_not_a_duplicate(): void
    {
        $this->make('Shared Name');

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $twin = $this->make('Shared Name');
        $this->assertSame($other->id, $twin->company_id);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Brand;
use App\Modules\Sales\Models\Scheme;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ স্কিমের ফর্ম ভুল লক্ষ্য জমা দিত — ওয়েব-মান ধাপ ২, ২০ অক্টোবর ২০২৬ ([[SchemeController::validated()]])।
 *
 * <p>"কীসের উপর"-এর প্রতিটা ধরনের জন্য একটা select, পাঁচটারই নাম `target_id`, আর লুকানোগুলো (x-show) জমাও পড়ত। PHP শেষ
 * select-এর মান নিত: "পণ্য" বেছে পণ্য দিলেও শেষ ধরনের খালি বা পুরনো নম্বরটা বসত, আর সেই নম্বর কাকতালে কোনো পণ্যের নম্বর
 * হলে স্কিম নীরবে অন্য পণ্যে চালু হত।
 *
 * দাবি (আসল দরজা দিয়ে):
 *  - ফর্মের প্রতিটা ধরনের ঘর নিজের নামে (`targets[ধরন]`) আর x-if-এ — `name="target_id"`-এর select আর পাতায় নেই;
 *  - পণ্য বেছে পণ্যের ঘরে X, আর ব্র্যান্ডের ঘরে অন্য একটা নম্বর একসাথে এলে — স্কিম বসে X-এ;
 *  - পুরনো ডাক (কেবল `target_id`) আগের মতো চলে;
 *  - `target_id` আর বাছা ঘর দুটোই এলে আর না মিললে — বাংলা বার্তায় থামে, স্কিম জন্মায় না।
 */
final class TheSchemeTookTheWrongTargetTest extends TestCase
{
    use RefreshDatabase;

    private int $product;

    private int $otherProduct;

    private int $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($owner->fresh());

        [$this->product, $this->otherProduct] = Product::query()->orderBy('id')->limit(2)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->brand = (int) Brand::query()->create(['code' => 'TGT-BR', 'name_en' => 'Target brand', 'name_bn' => 'লক্ষ্যের ব্র্যান্ড', 'is_active' => true])->id;
    }

    public function test_the_form_names_each_kinds_box_and_keeps_hidden_ones_out(): void
    {
        $html = (string) $this->get(route('sales.scheme.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="targets['.Scheme::PRODUCT.']"', $html);
        $this->assertStringContainsString('x-if="appliesTo === \''.Scheme::PRODUCT.'\'"', $html, '⛔ লুকানো ঘর পাতায় থেকে জমা পড়বে');
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="target_id"/', $html, '⛔ পাঁচ ঘর আবার এক নামে');
    }

    public function test_only_the_chosen_kinds_box_counts(): void
    {
        $response = $this->post(route('sales.scheme.store'), $this->scheme('Chosen', [
            'targets' => [Scheme::PRODUCT => $this->product, Scheme::BRAND => $this->otherProduct, Scheme::CATEGORY => ''],
        ]));
        $response->assertSessionHasNoErrors();
        // ⛔ স্কিম সত্যিই বসে — ফর্মের `targets` স্কিমের সারিতে ঢুকে দরজা ভাঙে না (২২ অক্টোবর ২০২৬)
        $this->assertSame(302, $response->status(), '⛔ ফর্মের targets ঘর স্কিমের সারিতে গেল — স্কিম বসল না।');

        $this->assertSame($this->product, (int) Scheme::query()->where('name', 'Chosen')->value('target_id'),
            '⛔ বাছা পণ্যের বদলে অন্য ঘরের নম্বর বসল');

        $this->post(route('sales.scheme.store'), $this->scheme('Old door', ['target_id' => $this->otherProduct]))->assertSessionHasNoErrors();
        $this->assertSame($this->otherProduct, (int) Scheme::query()->where('name', 'Old door')->value('target_id'), 'পুরনো ডাক ভাঙল');
    }

    public function test_two_different_targets_stop_with_a_message(): void
    {
        $this->post(route('sales.scheme.store'), $this->scheme('Mixed', [
            'target_id' => $this->otherProduct,
            'targets' => [Scheme::PRODUCT => $this->product],
        ]))->assertSessionHasErrors(['target_id' => __('sales::validation.scheme_target_mismatch')]);

        $this->assertFalse(Scheme::query()->where('name', 'Mixed')->exists(), '⛔ কোনটা ঠিক না জেনেই স্কিম বসল');
        $this->assertNotSame('', trim(__('sales::validation.scheme_target_mismatch')));
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function scheme(string $name, array $target): array
    {
        return [
            'name' => $name,
            'basis' => Scheme::VALUE,
            'applies_to' => Scheme::PRODUCT,
            'valid_from' => now()->toDateString(),
            'valid_to' => now()->addMonth()->toDateString(),
            ...$target,
        ];
    }
}

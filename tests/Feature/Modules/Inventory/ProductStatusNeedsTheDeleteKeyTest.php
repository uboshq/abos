<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ পণ্যের সম্পাদনা দিয়ে মোছার চাবি ছাড়াই পণ্য নিষ্ক্রিয় করা যেত (পুরো-ERP অডিট, মজুদ ছ৪; মালিকের "সব খোলা ভুল", ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ নিষ্ক্রিয় আর আবার চালু — দুটোই মোছার চাবি চায়; সম্পাদনার ফর্মে `is_active` ছিল খোলা। এখন অবস্থা সত্যিই বদলালে চাবি লাগে;
 * না বদলালে (ফর্ম ঘরটা প্রতিবার পাঠায়) সম্পাদনা আগের মতো চলে।
 */
final class ProductStatusNeedsTheDeleteKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_editor_without_the_delete_key_edits_but_cannot_switch_a_product_off(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $product = Product::query()->create(['code' => 'ST-'.mb_substr(md5(microtime()), 0, 6), 'name_en' => 'Status probe',
            'name_bn' => 'অবস্থার নমুনা', 'unit_id' => Unit::query()->where('code', 'PCS')->firstOrFail()->id, 'is_active' => true,
            'sale_price' => '120', 'purchase_price' => '100']);

        $editor = User::factory()->create(['current_company_id' => $company->id]);
        $editor->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany($company->id, fn () => $editor->givePermissionTo(
            array_map(fn ($k) => Permission::findOrCreate($k, 'web'), ['inventory.product.view', 'inventory.product.update'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($editor->fresh());

        $form = fn (array $extra) => [
            'code' => $product->code, 'name_en' => 'Status probe', 'name_bn' => 'অবস্থার নমুনা',
            'unit_id' => $product->unit_id, 'sale_price' => '120', 'purchase_price' => '100', ...$extra,
        ];

        // ⛔ মোছার চাবি ছাড়া বন্ধ করা — না
        $this->put(route('inventory.product.update', $product), $form(['is_active' => '0']))->assertForbidden();
        $this->assertTrue((bool) $product->fresh()->is_active, '⛔ মোছার চাবি ছাড়া সম্পাদনায় পণ্য নিষ্ক্রিয় হল।');

        // ⓘ অবস্থা না বদলালে সম্পাদনা চলে — ফর্ম ঘরটা প্রতিবার পাঠায়
        $this->put(route('inventory.product.update', $product), $form(['is_active' => '1', 'name_en' => 'Status probe, renamed']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Status probe, renamed', $product->fresh()->name_en, '⛔ সাধারণ সম্পাদনাও আটকে গেল।');
    }
}

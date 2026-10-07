<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রতিটা পণ্য লট ধরে, বন্ধ করার পথ নেই — মালিক, ৩ অক্টোবর ২০২৬ ("ok")।
 *
 * ⓘ ডেমোর ৯১টা পণ্য লট ছাড়া ছিল: সরাসরি ক্রয়ের মাল লটহীন ঢুকল, বিক্রয়ে "লট নেই" আর ফ্রি নিজে বসল না।
 */
final class EveryProductIsCountedByLotTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_product_cannot_be_made_or_changed_without_lots(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $template = Product::query()->firstOrFail();

        /* ⓘ দরজা ফর্মের অনুরোধে ([[ProductRequest]]) — `0` পাঠালেও লট চালু */
        $this->post(route('inventory.product.store'), [
            'name_en' => 'Lotless Attempt 3Oct', 'unit_id' => $template->unit_id,
            'track_batch' => '0', 'allow_duplicate' => '1',
        ])->assertSessionHasNoErrors();
        $made = Product::query()->where('name_en', 'Lotless Attempt 3Oct')->firstOrFail();
        $this->assertTrue((bool) $made->track_batch, '⛔ লট ছাড়া পণ্য তৈরি হয়ে গেল।');

        $this->put(route('inventory.product.update', $made), [
            'name_en' => 'Lotless Attempt 3Oct', 'unit_id' => $template->unit_id, 'track_batch' => '0',
        ])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $made->fresh()->track_batch, '⛔ বদলে লট বন্ধ হয়ে গেল।');

        /* ফর্মে ঘরটা চালু আর বন্ধ করা যায় না */
        $form = (string) $this->get(route('inventory.product.create'))->assertOk()->getContent();
        $this->assertStringContainsString('name="track_batch" value="1"', $form);
        $this->assertStringNotContainsString('name="track_batch" value="0"', $form, '⛔ ফর্ম এখনো লট বন্ধ পাঠাতে পারে।');
    }
}

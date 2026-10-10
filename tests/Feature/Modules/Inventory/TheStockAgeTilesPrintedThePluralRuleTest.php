<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মজুদের বয়সের চারটা ঘরে ভাঙা লেখা — বহুবচনের কাঁচা নিয়মটাই ছাপা হত — পাতা-ঝাড়ু, ধাপ ০ (১০ অক্টোবর ২০২৬)।
 *
 * ⭐ প্রতিটা ঘরে "০-৩০ দিন"-এর মতো এক লাইন; নিয়মের চিহ্ন (`|`, `[2,*]`, `{1}`) পাতায় নেই।
 */
final class TheStockAgeTilesPrintedThePluralRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_age_tile_reads_as_one_plain_line(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $page = $this->get(route('inventory.stock.age'))->assertOk();
        $buckets = $page->viewData('buckets');
        $this->assertNotEmpty($buckets, 'দৃশ্যটাই বানানো যায়নি — বয়সের ভাগ নেই।');

        $html = $page->getContent();
        $this->assertStringNotContainsString('[2,*]', $html, '⛔ পাতায় বহুবচনের কাঁচা নিয়ম।');
        $this->assertStringNotContainsString('{1}', $html, '⛔ পাতায় বহুবচনের কাঁচা নিয়ম।');

        foreach ($buckets as $b) {
            $this->assertStringContainsString(e(trans_choice('inventory::analysis.days', 2, ['count' => $b])), $html, 'বয়সের ঘরের লেখা নেই: '.$b);
        }
    }
}

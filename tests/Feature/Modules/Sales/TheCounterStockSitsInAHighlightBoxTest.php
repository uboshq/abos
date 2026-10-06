<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টারের মজুদের সংখ্যা এক হাইলাইট বাক্সে — মালিক, ৬ অক্টোবর ২০২৬: *"বিক্রয়যোগ্য · ফ্রি · ধরা · হোল্ড · প্রধান
 * মজুদ — এগুলো একটা হাইলাইট বক্সে দিয়ে দাও"*।
 *
 * দাবি: পাঁচটা সংখ্যাই একটা বাক্সের ভিতরে, আর বাক্সটা টোকেনের রঙে (অন্ধকার থিমেও মেলে)।
 */
final class TheCounterStockSitsInAHighlightBoxTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_five_stock_numbers_sit_in_one_highlighted_box(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $html = $this->get(route('sales.direct.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="[^"]*border-\(--color-brand-400\)[^"]*bg-\(--color-brand-50\)[^"]*"\s+x-show="picked" x-cloak data-stock-box>/', $html, '⛔ মজুদের সংখ্যাগুলোর হাইলাইট বাক্স নেই।');

        $box = substr($html, (int) strpos($html, 'data-stock-box'), 6000);
        foreach (['available', 'free_available', 'reserved', 'hold', 'main'] as $key) {
            $this->assertStringContainsString("picked.{$key})", $box, "⛔ {$key} বাক্সের বাইরে।");
        }
    }
}

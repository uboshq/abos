<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * শূন্যে "–" — মালিক, ৬ অক্টোবর ২০২৬: *"যে স্টক 0.00 সেখানে - চিহ্ন দাও"*।
 *
 * দাবি:
 *  · মজুদের তালিকায় পরিমাণের ঘর শূন্য হলে "–" (লিংক নয়), শূন্য না হলে অঙ্ক।
 *  · উপাদানের সাধারণ আচরণ বদলায়নি — অন্য পর্দায় শূন্য টাকা আগের মতো 0.00।
 */
final class AZeroOnTheShelfReadsAsADashTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_amount_shows_a_dash_only_when_asked(): void
    {
        $dash = Blade::render('<x-ui.amount :value="0" href="/x" :dash-on-zero="true" />');
        $this->assertStringContainsString('–', $dash);
        $this->assertStringNotContainsString('0.00', $dash);
        $this->assertStringNotContainsString('href=', $dash, '⛔ শূন্যের "–" লিংক হয়ে গেছে।');

        $plain = Blade::render('<x-ui.amount :value="0" href="/x" />');
        $this->assertStringContainsString('0.00', $plain, '⛔ "–" চাওয়া ছাড়াও শূন্য বদলে গেছে।');

        // ⓘ পরিমাণে অকারণ ০০ নয় — মালিক, ৬ অক্টোবর ২০২৬
        $this->assertStringContainsString('>493<', Blade::render('<x-ui.amount :value="493" :quantity="true" />'), '⛔ পরিমাণে এখনো .00।');
        $this->assertStringContainsString('>2.5<', Blade::render('<x-ui.amount :value="2.5" :quantity="true" />'));
        $this->assertStringContainsString('493.00', Blade::render('<x-ui.amount :value="493" />'), '⛔ টাকার অঙ্কেও দশমিক ছাঁটা হয়েছে।');

        $some = Blade::render('<x-ui.amount :value="12.5" href="/x" :dash-on-zero="true" />');
        $this->assertStringContainsString('12.50', $some);
        $this->assertStringContainsString('href="/x"', $some);
    }

    public function test_the_stock_list_writes_a_dash_where_a_quantity_is_zero(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $html = $this->get(route('inventory.stock.index'))->assertOk()->getContent();

        // ⓘ ডেমোর মজুদে ধরা/আটকানো/বসেনি সাধারণত শূন্য — সেই ঘরগুলো "–"
        $this->assertStringContainsString('text-(--color-ink-muted)">–</span>', $html, '⛔ মজুদের তালিকায় শূন্যের জায়গায় "–" নেই।');
    }
}

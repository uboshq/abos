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
 * পণ্যের তালিকার বাঁয়ে ক্রম নম্বর — মালিক, ৫ অক্টোবর ২০২৬: "product list er bame SL no daw"।
 *
 * দাবি:
 *  · প্রথম পাতার প্রথম সারি ১, শেষ সারি পাতার মাপ।
 *  · দ্বিতীয় পাতা প্রথম পাতার পর থেকে।
 *  · কলামটা শিরোনামে সবার আগে।
 */
final class TheProductListCountsItsRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_each_row_carries_its_place_and_the_second_page_goes_on_from_the_first(): void
    {
        // ⓘ তালিকা ৫০ সারির পাতা — দ্বিতীয় পাতা পেতে অন্তত ৫২টা পণ্য
        $model = Product::query()->firstOrFail();
        for ($i = Product::query()->count(); $i < 52; $i++) {
            $copy = $model->replicate(['public_id']);
            $copy->code = 'SL-'.$i;
            $copy->barcode = null;
            $copy->name_en = 'Serial '.$i;
            $copy->save();
        }
        $total = Product::query()->count();

        $first = $this->get(route('inventory.product.index'))->assertOk();
        $serials = $this->serials($first->getContent());

        $this->assertSame(array_map('strval', range(1, 50)), $serials, '⛔ প্রথম পাতার ক্রম ১ থেকে ৫০ নয়।');

        $second = $this->get(route('inventory.product.index', ['page' => 2]))->assertOk();
        $this->assertSame(array_map('strval', range(51, $total)), $this->serials($second->getContent()),
            '⛔ দ্বিতীয় পাতা প্রথম পাতার পর থেকে চলেনি।');

        // ⭐ শিরোনামে ক্রম সবার আগে
        $this->assertMatchesRegularExpression(
            '/<th[^>]*>\s*(?:<[^>]+>\s*)*'.preg_quote(__('core.table.serial'), '/').'/u',
            $first->getContent(),
        );
    }

    /** @return list<string> প্রতিটা সারির প্রথম ঘরের লেখা */
    private function serials(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new \DOMXPath($dom);
        $out = [];

        foreach ($xpath->query('//table//tbody/tr') as $tr) {
            $td = $xpath->query('./td', $tr)->item(0);

            if ($td !== null) {
                $out[] = trim($td->textContent);
            }
        }

        return $out;
    }
}

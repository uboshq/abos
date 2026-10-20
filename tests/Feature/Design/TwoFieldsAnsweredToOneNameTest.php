<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ এক পাতায় একই id দুবার — ওয়েব-মান ধাপ ২, ২০ অক্টোবর ২০২৬ (WCAG 2.2 §4.1.1; HTML: id অনন্য)।
 *
 * <p>লাইভ নিরীক্ষায় ছয়টা পাতা: খাতের ফর্ম (ব্যাংক আর MFS দুই অংশে একই ঘর), মজুদ সমন্বয় (দুই ফর্ম), ব্যাংক সুবিধা,
 * কাগজের প্রশাসন, স্কিম (ধরন প্রতি একটা টার্গেট), আর ডিপোর যাচাই (দুই তালিকার দুই "দৃশ্য সংরক্ষণ" ফর্ম)। একই id হলে
 * লেবেলে চাপলে ভুল ঘরে যায়, আর `form="…"` প্রথম ফর্মটাকেই ধরে — ডিপোর যাচাইয়ে দ্বিতীয় তালিকার দৃশ্য নীরবে প্রথমটার
 * ফর্মে জমা হত।
 *
 * দাবি: ছয়টা পাতা আসল দরজা দিয়ে খুললে কোনো id দুবার নেই; ঘরের লেবেল তার নিজের id-তে (`for`) বাঁধা।
 */
final class TwoFieldsAnsweredToOneNameTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = [
        'accounts.coa.create', 'inventory.stock.adjust', 'finance.bank_facility.create', 'documents.admin',
        'sales.scheme.create', 'sales.direct.depot_check',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_no_id_appears_twice_on_the_audited_pages(): void
    {
        $opened = 0;
        $wrong = [];

        foreach (self::PAGES as $name) {
            $response = $this->get(route($name));

            if (in_array($response->status(), [403, 404], true)) {
                continue;
            }

            $response->assertOk();
            $opened++;

            preg_match_all('/\sid="([^"]+)"/', (string) $response->getContent(), $m);
            $twice = array_keys(array_filter(array_count_values($m[1]), fn (int $n) => $n > 1));

            if ($twice !== []) {
                $wrong[] = $name.': '.implode(', ', $twice);
            }
        }

        $this->assertGreaterThanOrEqual(5, $opened, 'মাত্র '.$opened.'টা পাতা খুলল — দাবির ভিত্তি নেই');
        $this->assertSame([], $wrong, "⛔ এক পাতায় একই id দুবার:\n  ".implode("\n  ", $wrong));
    }

    public function test_a_second_field_of_the_same_name_is_labelled_by_its_own_id(): void
    {
        $html = (string) $this->get(route('inventory.stock.adjust'))->assertOk()->getContent();

        $this->assertStringContainsString('for="product_id-2"', $html, '⛔ দ্বিতীয় ফর্মের লেবেল প্রথম ফর্মের ঘরে বাঁধা');
        $this->assertStringContainsString('id="product_id-2"', $html);
        $this->assertStringContainsString('name="product_id"', $html, '⛔ জমার নাম বদলে গেল — সার্ভার ঘরটা চিনবে না');
    }
}

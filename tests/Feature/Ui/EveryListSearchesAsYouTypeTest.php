<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * তালিকার খোঁজ টাইপ করতেই — মালিক, ১ অক্টোবর ২০২৬।
 *
 * ⓘ আচরণটা `resources/js/search-as-you-type.js`-এ (দাবি: তার vitest)। এখানে কেবল এটুকু: টুলবার
 * প্রতিটা তালিকার খোঁজার ঘরে চিহ্নটা বসায় (`data-live-search` = এখন কী খোঁজা আছে), আর খোঁজের পরে ফেরা
 * পাতায় ঘরটা `autofocus` পায় — নাহলে প্রতিটা অক্ষরের পরে কার্সর হারাত।
 * ⚠️ খোঁজ ছাড়া খোলা পাতায় `autofocus` নয় — তালিকা খুললেই কিবোর্ড খোঁজার ঘরে আটকাত, আর ফোনে কিবোর্ড উঠত।
 */
final class EveryListSearchesAsYouTypeTest extends TestCase
{
    use RefreshDatabase;

    private const LISTS = ['customer.index', 'supplier.index', 'inventory.product.index', 'sales.invoice.index', 'purchase.bill.index'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_every_list_box_carries_the_hook_without_stealing_focus(): void
    {
        foreach (self::LISTS as $route) {
            $box = $this->box((string) $this->get(route($route))->assertOk()->getContent(), $route);

            $this->assertStringContainsString('data-live-search=""', $box, "{$route}: খোঁজার ঘরে চিহ্ন নেই।");
            $this->assertStringNotContainsString('autofocus', $box, "{$route}: খোঁজ ছাড়াই ঘর ফোকাস নিচ্ছে।");
        }
    }

    public function test_after_a_search_the_box_knows_the_word_and_keeps_the_cursor(): void
    {
        foreach (self::LISTS as $route) {
            $box = $this->box((string) $this->get(route($route, ['q' => 'রহিম']))->assertOk()->getContent(), $route);

            $this->assertStringContainsString('data-live-search="রহিম"', $box, "{$route}: ঘর জানে না কী খোঁজা আছে।");
            $this->assertStringContainsString('autofocus', $box, "{$route}: খোঁজের পরে কার্সর ঘরে নেই।");
        }

        // ⓘ ঘর খালি করে খোঁজা (`q=`) — ছাঁকনি উঠে গেলেও লেখা চলছে, কার্সর ঘরেই
        $box = $this->box((string) $this->get(route('customer.index', ['q' => '']))->assertOk()->getContent(), 'customer.index');
        $this->assertStringContainsString('autofocus', $box, 'ঘর খালি করে খোঁজার পরে কার্সর হারিয়েছে।');
    }

    private function box(string $html, string $route): string
    {
        $this->assertSame(1, preg_match('/<input\b[^>]*\bdata-quick-find\b[^>]*>/su', $html, $m), "{$route}: টুলবারের খোঁজার ঘর নেই।");

        return $m[0];
    }
}

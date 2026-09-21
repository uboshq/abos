<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * খতিয়ানের ছাপার বোতাম আগে ক্রম বদলায়, তারপর ছাপে।
 *
 * ── ⭐ মালিকের নিয়ম, ২১ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"Transactions dekhar somoy ajker date sobar upore … but print er somoy
 * ba printe dile bank er moto ledger dekhabe"*।
 *
 * ── ⛔ যে ফাঁকটা এটা বন্ধ করে ────────────────────────────────────────
 * ক্রম উল্টানোর কাজটা আগেই হয়েছিল — `?ledger=asc` দিলে কাগজের ক্রম আসে,
 * আর [[TheLedgerReadsNewestFirstButPrintsOldestFirstTest]] সেটা পাহারা
 * দেয়। ⚠️ কিন্তু **ছাপার বোতামটা ঐ ঠিকানায় যেতই না**: সে পর্দায় যা আছে
 * তা-ই ছাপত, অর্থাৎ নতুন আগে।
 *
 * ⓘ অর্থাৎ নিয়মটা লেখা ছিল, পথটা ছিল না — ABOS-এর সবচেয়ে সাধারণ রোগ:
 * কাজটা হয়েছে, শেষ জোড়াটা লাগানো হয়নি। ⭐ মালিকের কাছে জিনিসটা কোনোদিন
 * কাজ করেনি, কারণ তিনি ছাপার বোতামই চাপেন, ঠিকানা টাইপ করেন না।
 */
final class ThePrintButtonGoesTheOtherWayFirstTest extends TestCase
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

    /**
     * @return list<array{0: string}>
     */
    public static function parties(): array
    {
        return [
            'সরবরাহকারী' => ['supplier'],
            'গ্রাহক' => ['customer'],
        ];
    }

    #[DataProvider('parties')]
    public function test_the_print_button_carries_the_bank_order(string $party): void
    {
        $html = $this->page($party);

        $this->assertStringContainsString('ledger=asc', $html, implode("\n", [
            '⛔ ছাপার বোতামটা কাগজের ক্রমে যায় না।',
            '',
            '⚠️ তখন সে পর্দায় যা আছে তা-ই ছাপে — নতুন সারি উপরে। ⓘ মালিক',
            'চান ব্যাংকের খাতার মতো, পুরনো থেকে শুরু।',
        ]));
    }

    /**
     * ⭐ আর ঐ ঠিকানায় পৌঁছে ছাপাটা নিজে থেকেই শুরু হয়।
     *
     * ⛔ এই অর্ধেকটা না থাকলে জিনিসটা আধখানা: মানুষ সঠিক ক্রমের পাতায়
     * পৌঁছাতেন, তারপর আবার ছাপার বোতাম খুঁজতেন — আর সেটা আবার পর্দারটাই
     * ছাপত। ⚠️ দুই ক্লিকে একই ভুল ফল।
     */
    #[DataProvider('parties')]
    public function test_arriving_with_print_starts_the_printing(string $party): void
    {
        $this->assertStringContainsString('data-print-on-load', $this->page($party, ['ledger' => 'asc', 'print' => 1]),
            '⛔ ছাপার ঠিকানায় পৌঁছেও ছাপা নিজে থেকে শুরু হয় না।');
    }

    /**
     * ⚠️ আর সাধারণভাবে খুললে ঐ ঘরটা থাকে না।
     *
     * ⛔ নাহলে যে কেউ পাতাটা খুললেই ছাপার পর্দা লাফিয়ে উঠত — একটা
     * তালিকা দেখতে এসে ছাপার ডায়ালগ পাওয়ার চেয়ে বিরক্তিকর কিছু নেই।
     *
     * ⓘ এই দাবিটা উপরেরটাকে সত্যিকারের দাবি বানায়: এটা না থাকলে
     * `data-print-on-load` সবসময় বসিয়ে দিলেও দুইটাই সবুজ থাকত।
     */
    #[DataProvider('parties')]
    public function test_an_ordinary_visit_does_not_print(string $party): void
    {
        $this->assertStringNotContainsString('data-print-on-load', $this->page($party),
            '⛔ সাধারণভাবে পাতাটা খুললেই ছাপা শুরু হয়ে যাচ্ছে।');
    }

    /**
     * ⭐ আর টুলবারে খোঁজা-ছাঁকনির ঘর নেই, কারণ কন্ট্রোলার ওগুলো পড়ে না।
     *
     * ⓘ মালিক বলেছিলেন *"ok za lage ta daw"* — যা কাজ করে কেবল সেটুকু।
     * ⛔ খোঁজার ঘরটা বসালে সেটা দেখতে জীবন্ত হত, টাইপ করে এন্টার দিলে
     * পাতাটা নতুন করে আসত, আর তালিকা **যেমন ছিল তেমনই** থাকত।
     */
    #[DataProvider('parties')]
    public function test_the_dead_controls_are_not_offered(string $party): void
    {
        $html = $this->page($party);

        $this->assertStringNotContainsString('name="q"', $html, implode("\n", [
            '⛔ খতিয়ানের টুলবারে খোঁজার ঘর বসানো হয়েছে।',
            '',
            '⚠️ এই পাতার কন্ট্রোলার `q` পড়ে না, তাই ঘরটা একটা মৃত বোতাম —',
            'দেখতে কাজ করে, চাপলে কিছুই বদলায় না।',
        ]));
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের দাবিগুলো সবুজ থাকত যদি পাতাটাই না আসত। ⚠️ তাই টুলবারটা
     * সত্যিই ওখানে আছে কি না সেটাই আলাদা করে মাপা — আজ রাতে দুইটা
     * পাহারা ঠিক এভাবেই অন্ধ ছিল।
     */
    #[DataProvider('parties')]
    public function test_the_toolbar_is_really_there(string $party): void
    {
        $this->assertStringContainsString(__('core.action.print'), $this->page($party),
            'খতিয়ানের পাতায় টুলবারটাই নেই — দাবিগুলো তখন কিছুই প্রমাণ করে না।');
    }

    /** @param array<string, mixed> $query */
    private function page(string $party, array $query = []): string
    {
        $route = $party === 'supplier'
            ? route('supplier.show', Supplier::query()->firstOrFail())
            : route('customer.show', Customer::query()->firstOrFail());

        $url = $route.($query !== [] ? '?'.http_build_query($query) : '');

        return (string) $this->get($url)->assertOk()->getContent();
    }
}

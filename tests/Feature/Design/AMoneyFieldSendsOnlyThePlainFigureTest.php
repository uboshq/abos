<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ টাকার ঘর পাঠায় কেবল কাঁচা অঙ্ক — দেখানোর ঘর (লাখ-কমা) আর পাঠানোর ঘর আলাদা (x-ui.field type="money"; fe, ২৪ অক্টোবর ২০২৬)।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────────
 * ভাউচার আর সরবরাহকারীর পরিশোধের টাকার ঘর ঘর ছাড়লে নিজের মানেই কমা বসাত ("48,440.49") আর সেটাই পাঠাত — সার্ভার ছেঁটে পড়ত,
 * কিন্তু প্রতিটা অনুরোধকে মনে রাখতে হত; চার্জ আর খরচের ঘর ছিল type="number", যা কমা দেখাতেই পারে না।
 *
 * ⭐ দাবি: ভাউচার (রসিদ, পরিশোধ, খরচ) আর সরবরাহকারীর পরিশোধের পর্দায় প্রতিটা টাকার ঘরের নামের ঘর লুকানো — আর তার পাশে
 * দেখানোর ঘর, name ছাড়া, লাখ-কমার directive-সহ। ⓘ লাখ-কমা, বাংলা অঙ্ক আর FormData vitest-এ (money-display.test.js)।
 */
final class AMoneyFieldSendsOnlyThePlainFigureTest extends TestCase
{
    use RefreshDatabase;

    /** পর্দা => তার টাকার ঘরগুলো (নাম => পর্দার মডেল) */
    private const SCREENS = [
        'receipt' => ['amount' => 'amount', 'charge_amount' => 'charge'],
        'payment' => ['amount' => 'amount', 'charge_amount' => 'charge'],
        'expense' => ['gross_amount' => 'gross', 'ait_amount' => 'ait', 'vds_amount' => 'vds', 'charge_amount' => 'charge'],
        'pay_supplier' => ['amount' => 'amount', 'charge_amount' => 'charge'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_every_money_field_sends_a_hidden_plain_figure_beside_a_nameless_display(): void
    {
        foreach (self::SCREENS as $screen => $fields) {
            $html = $this->screen($screen);

            foreach ($fields as $name => $model) {
                preg_match_all('/<input\b[^>]*\bname="'.preg_quote($name, '/').'"[^>]*>/', $html, $named);
                $this->assertNotEmpty($named[0], "পর্দাটাই ঠিক নয় — {$screen}-এ {$name} নামের ঘর নেই");

                foreach ($named[0] as $tag) {
                    $this->assertStringContainsString('type="hidden"', $tag,
                        "⛔ {$screen}: {$name} নামের দেখা-যায় এমন ঘর — লাখ-কমার লেখাটাই পাঠানো হত: {$tag}");
                    $this->assertStringNotContainsString(',', (string) preg_replace('/^.*\bvalue="([^"]*)".*$/s', '$1', $tag),
                        "⛔ {$screen}: {$name}-এর পাঠানো মানে কমা: {$tag}");
                }

                $this->assertMatchesRegularExpression(
                    '/<input\b[^>]*data-money-shown="'.preg_quote($name, '/').'"[^>]*x-money-display="'.preg_quote($model, '/').'"[^>]*>/', $html,
                    "⛔ {$screen}: {$name}-এর দেখানোর ঘর (লাখ-কমার directive) নেই");
            }

            preg_match_all('/<input\b[^>]*data-money-shown="[^"]*"[^>]*>/', $html, $shown);
            foreach ($shown[0] as $tag) {
                $this->assertDoesNotMatchRegularExpression('/\bname="/', $tag, "⛔ {$screen}: দেখানোর ঘরের নাম আছে — কমা পাঠানো হত: {$tag}");
            }
        }
    }

    private function screen(string $screen): string
    {
        $url = $screen === 'pay_supplier'
            ? route('purchase.payment.create')
            : route('accounts.voucher.create', ['type' => $screen === 'expense' ? Voucher::EXPENSE : $screen]);

        return (string) $this->get($url)->assertOk()->getContent();
    }
}

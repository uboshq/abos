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

                // ⭐ লেবেল দেখানোর ঘরকে নির্দেশ করে — পর্দা-পাঠক আর চাপলে ঘরে ঢোকা দুটোই এর উপর (fe, ২৪ অক্টোবর ২০২৬)
                $id = $this->shownId($html, $name);
                $this->assertNotNull($id, "⛔ {$screen}: {$name}-এর দেখানোর ঘরের id নেই — লেবেল কোনো ঘরে বাঁধা নয়");
                $this->assertMatchesRegularExpression('/<label\b[^>]*\bfor="'.preg_quote((string) $id, '/').'"/', $html,
                    "⛔ {$screen}: {$name}-এর লেবেল দেখানোর ঘরকে ({$id}) নির্দেশ করে না");
            }

            preg_match_all('/<input\b[^>]*data-money-shown="[^"]*"[^>]*>/', $html, $shown);
            foreach ($shown[0] as $tag) {
                $this->assertDoesNotMatchRegularExpression('/\bname="/', $tag, "⛔ {$screen}: দেখানোর ঘরের নাম আছে — কমা পাঠানো হত: {$tag}");
            }
        }
    }

    public function test_a_wrong_figure_is_marked_on_the_field_people_see_not_the_hidden_one(): void
    {
        /*
         * ⓘ ফেরত-আসা ভুল — সেশনের errors-এ, json সেশনের নিজের আকারে (['default' => ['format', 'messages']])। ⛔ ViewErrorBag বস্তু
         * দিলে সেশন শুরুতে সেটা নতুন করে গড়ে (marshalErrorBag) আর খালি ব্যাগ পায় — ভুলটা পাতায় পৌঁছাতই না (cb-md-1-এর প্রথম লাল)।
         */
        $errors = ['default' => ['format' => ':message', 'messages' => ['amount' => ['⛔ টাকা ভুল']]]];
        $html = (string) $this->withSession(['errors' => $errors])->get(route('accounts.voucher.create', ['type' => 'receipt']))->assertOk()->getContent();
        $this->assertStringContainsString('⛔ টাকা ভুল', $html, 'দৃশ্যটাই ঠিক নয় — ফেরত-আসা ভুল পাতায় পৌঁছায়নি');

        $id = $this->shownId($html, 'amount');
        $this->assertNotNull($id, 'পর্দাটাই ঠিক নয় — টাকার দেখানোর ঘর নেই');

        preg_match('/<input\b[^>]*data-money-shown="amount"[^>]*>/', $html, $shown);
        preg_match('/<input\b[^>]*type="hidden"[^>]*name="amount"[^>]*>/', $html, $hidden);

        $this->assertStringContainsString('aria-invalid="true"', $shown[0] ?? '', '⛔ ভুলের দাগ দেখানোর ঘরে নেই — পর্দা-পাঠক ভুলটা বলত না');
        $this->assertMatchesRegularExpression('/aria-describedby="[^"]*\b'.preg_quote((string) $id, '/').'-error\b/', $shown[0] ?? '',
            '⛔ দেখানোর ঘর ভুলের বার্তাকে নির্দেশ করে না');
        $this->assertStringContainsString('id="'.$id.'-error"', $html, '⛔ ভুলের বার্তা ঘরের পাশে আসেনি');
        $this->assertStringContainsString('⛔ টাকা ভুল', $html, '⛔ ভুলের বার্তাটাই দেখা গেল না');
        $this->assertStringNotContainsString('aria-invalid', $hidden[0] ?? '', '⛔ ভুলের দাগ লুকানো ঘরে — কেউ দেখত না');
    }

    /** দেখানোর ঘরের id — লেবেল আর ভুলের বার্তা এটাই নির্দেশ করে */
    private function shownId(string $html, string $name): ?string
    {
        preg_match('/<input\b[^>]*data-money-shown="'.preg_quote($name, '/').'"[^>]*>/', $html, $tag);

        return isset($tag[0]) && preg_match('/\bid="([^"]+)"/', $tag[0], $id) ? $id[1] : null;
    }

    private function screen(string $screen): string
    {
        $url = $screen === 'pay_supplier'
            ? route('purchase.payment.create')
            : route('accounts.voucher.create', ['type' => $screen === 'expense' ? Voucher::EXPENSE : $screen]);

        return (string) $this->get($url)->assertOk()->getContent();
    }
}

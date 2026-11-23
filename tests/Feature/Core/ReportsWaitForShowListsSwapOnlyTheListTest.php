<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Core\Support\ListPart;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ মালিক, ২৩ নভেম্বর ২০২৬:
 *   · রিপোর্ট ঘর বদলালে বা তারিখ বাছলেই চলে না — কেবল "দেখান" বা Enter;
 *   · তালিকা আগের মতো ঘর বদলালেই ছাঁকে, আর খোঁজা টাইপের সাথে;
 *   · তালিকার খোঁজা, সাজানো, পাতা বদলে কেবল তালিকার অংশ আসে (`X-ABOS-Part: list`, [[ListPart]])।
 */
final class ReportsWaitForShowListsSwapOnlyTheListTest extends TestCase
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

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function reports(): array
    {
        return [
            'engine report (ledger)' => ['accounts.report.show', ['slug' => 'ledger']],
            'engine report (monthly cash)' => ['accounts.report.show', ['slug' => 'monthly-cash']],
            'balance sheet' => ['accounts.balance_sheet', []],
            'day book' => ['accounts.daybook', []],
            'equity statement' => ['accounts.equity_statement', []],
            'group report' => ['accounts.group_report', []],
            'net worth' => ['accounts.net_worth', []],
        ];
    }

    /**
     * @dataProvider reports
     *
     * @param  array<string, mixed>  $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reports')] // ⓘ PHPUnit 12 reads only the attribute, not the docblock
    public function test_a_report_page_runs_only_on_show_or_enter(string $route, array $params): void
    {
        $html = $this->get(route($route, $params))->assertOk()->getContent();
        $main = $this->mainOf($html);

        $this->assertStringNotContainsString('data-action="submit-form"', $main,
            "⛔ {$route}: রিপোর্টের কোনো ঘর বদলালেই জমা দেয় — চলার কথা কেবল \"দেখান\" বা Enter-এ।");
        $this->assertStringNotContainsString('data-live-search', $main,
            "⛔ {$route}: রিপোর্টের খোঁজার ঘর টাইপের সাথে চলে।");

        // ⓘ ফর্ম থাকলে সেটা রিপোর্টের চিহ্নসহ, আর তাতে জমার বোতাম — "দেখান"
        if (preg_match_all('/<form\b[^>]*method="GET"[^>]*>/i', $main, $forms) > 0) {
            $this->assertTrue(collect($forms[0])->contains(fn (string $f) => str_contains($f, 'data-report')),
                "⛔ {$route}: রিপোর্টের ফর্মে `data-report` নেই — ব্রাউজারের পাহারা (actions.js, date.js) চেনে না।");
            $this->assertStringContainsString(e(__('core.action.show')), $main, "⛔ {$route}: \"দেখান\" বোতাম নেই।");
        }
    }

    public function test_a_list_page_still_filters_at_once_and_searches_as_you_type(): void
    {
        $main = $this->mainOf($this->get(route('customer.index'))->assertOk()->getContent());

        $this->assertStringContainsString('data-live-search', $main, '⛔ তালিকার খোঁজা টাইপের সাথে চলা বন্ধ হয়ে গেছে।');
        $this->assertStringContainsString('data-list-part="table"', $main, '⛔ তালিকার টেবিলে অংশের চিহ্ন নেই — অংশ বদল চলবে না।');
        $this->assertStringContainsString('data-list-part="pager"', $main);
        $this->assertDoesNotMatchRegularExpression('/<form\b[^>]*\sdata-report[\s>]/i', $main, '⛔ তালিকাকে রিপোর্ট ধরা হয়েছে — ঘর বদলালে আর ছাঁকবে না।');
    }

    public function test_a_list_page_with_toolbar_filters_keeps_the_instant_submit(): void
    {
        $main = $this->mainOf($this->get(route('supplier.index'))->assertOk()->getContent());

        $this->assertStringContainsString('data-live-search', $main);
        $this->assertStringNotContainsString('data-report-filters', $main);
    }

    public function test_the_list_header_returns_only_the_list_part(): void
    {
        $response = $this->get(route('customer.index', ['q' => 'Rahim']), [ListPart::HEADER => ListPart::VALUE])->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString(ListPart::FRAGMENT, $html, '⛔ টুকরোর চিহ্ন নেই — ব্রাউজার পুরো পাতায় গড়াবে।');
        $this->assertStringContainsString('data-list-part="table"', $html);
        $this->assertStringContainsString('data-list-part="pager"', $html);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $html, '⛔ পুরো পাতা এল — খোলসসহ।');
        $this->assertStringNotContainsString('<body', $html);
        $this->assertStringNotContainsString('data-footer', $html, '⛔ নিচের বারও এল।');
        $this->assertStringNotContainsString('speculationrules', $html);

        $this->assertStringContainsString(ListPart::HEADER, (string) $response->headers->get('Vary'), '⛔ Vary-তে X-ABOS-Part নেই — ক্যাশ দুই চেহারা মেশাত।');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_without_the_header_the_whole_page_comes_and_still_varies(): void
    {
        $response = $this->get(route('customer.index'))->assertOk();

        $this->assertStringContainsString('<!DOCTYPE html>', $response->getContent());
        $this->assertStringNotContainsString(ListPart::FRAGMENT, $response->getContent());
        $this->assertStringContainsString(ListPart::HEADER, (string) $response->headers->get('Vary'));
    }

    public function test_the_header_never_changes_a_post_or_a_json_answer(): void
    {
        $this->assertFalse(ListPart::wanted(\Illuminate\Http\Request::create('/customers', 'POST', server: ['HTTP_X_ABOS_PART' => 'list'])));
        $this->assertFalse(ListPart::wanted(\Illuminate\Http\Request::create('/customers', 'GET', server: ['HTTP_X_ABOS_PART' => 'list', 'HTTP_ACCEPT' => 'application/json'])));
        $this->assertFalse(ListPart::wanted(\Illuminate\Http\Request::create('/customers', 'GET', server: ['HTTP_X_ABOS_PART' => 'other'])));
        $this->assertTrue(ListPart::wanted(\Illuminate\Http\Request::create('/customers', 'GET', server: ['HTTP_X_ABOS_PART' => 'list'])));
    }

    private function mainOf(string $html): string
    {
        $start = strpos($html, '<main');
        $end = strrpos($html, '</main>');

        return $start === false || $end === false ? $html : substr($html, $start, $end - $start);
    }
}

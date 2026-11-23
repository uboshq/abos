<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * ⭐ প্রতিটা রিপোর্টের নিজের ছাপার কাগজ আর "প্রিন্ট ভিউ" বোতাম, নতুন ট্যাবে (মালিক, ২৩ নভেম্বর ২০২৬: *"প্রতিটা রিপোর্টের আলাদা ছাপার
 * ছাঁচ — A4, কোম্পানির প্যাড, সময় আর ছাঁকনি, যোগফল, সইয়ের রেখা, ইংরেজি অঙ্ক — আর প্রিন্ট ভিউ বোতাম, নতুন ট্যাবে"*)।
 *
 *   - ইঞ্জিনের প্রতিটা রিপোর্ট (`*.report.show`, কন্ট্রোলারের `SLUGS`): `?export=print` → PDF, ব্রাউজারে (inline)
 *   - পর্দায় "প্রিন্ট ভিউ" বোতাম, `target="_blank"` — কাজের পাতা নড়ে না
 *   - নিজের পর্দা আঁকা রিপোর্টগুলোও (মালিকানা বিবরণী, গ্রুপ, নিট সম্পদ, দৈনিক খতিয়ান, দিন শেষ, স্থিতিপত্র, বছর শেষের রেওয়ামিল,
 *     বাজেট, হাজিরা, মজুদের বয়স আর চলাচল, খাত বিশ্লেষণ, খরচের রিপোর্ট)
 */
final class EveryReportHasAPrintViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ সব মডিউল চালু — বন্ধ মডিউলের রিপোর্ট ৪০৪ দিত ([[EveryReportScreenOpensInEveryModuleTest]]-এর একই প্রস্তুতি)
        app(SettingsService::class)->set('inventory.batch_enabled', true);
        $declared = app(SettingsService::class)->definitions();
        foreach (app(ModuleRegistry::class)->all() as $module) {
            $switch = app(MenuSwitches::class)->forModule($module->code);
            if (isset($declared[$switch])) {
                app(SettingsService::class)->set($switch, true);
            }
        }
    }

    public function test_every_engine_report_prints_on_its_own_paper(): void
    {
        $screens = $this->everyEngineReport();
        $this->assertGreaterThan(20, count($screens), 'রিপোর্ট মাত্র '.count($screens).'টা — খোঁজাটাই ভেঙেছে।');

        $broken = [];
        foreach ($screens as [$route, $slug]) {
            $paper = $this->get(route($route, ['slug' => $slug, 'export' => 'print']));
            if ($paper->getStatusCode() !== 200 || $paper->headers->get('content-type') !== 'application/pdf'
                || ! str_starts_with((string) $paper->headers->get('content-disposition'), 'inline;')) {
                $broken[] = $route.':'.$slug.' → '.$paper->getStatusCode().' '.$paper->headers->get('content-type');
            }
        }

        $this->assertSame([], $broken, "⛔ এই রিপোর্টগুলোর প্রিন্ট ভিউ PDF হয়ে খোলে না:\n".implode("\n", $broken));
    }

    public function test_every_engine_report_shows_a_print_view_button_that_opens_a_new_tab(): void
    {
        $missing = [];
        foreach ($this->everyEngineReport() as [$route, $slug]) {
            $html = (string) $this->get(route($route, ['slug' => $slug]))->getContent();
            if (preg_match('/<a\b[^>]*data-print-view[^>]*>/s', $html, $tag) !== 1 || ! str_contains($tag[0], 'target="_blank"')) {
                $missing[] = $route.':'.$slug;
            }
        }

        $this->assertSame([], $missing, "⛔ এই রিপোর্টে নতুন ট্যাবের \"প্রিন্ট ভিউ\" বোতাম নেই:\n".implode("\n", $missing));
    }

    public function test_every_custom_report_prints_on_its_own_paper(): void
    {
        $account = Account::query()->orderBy('code')->value('id');

        $routes = [
            route('accounts.daybook'),
            route('executive.dayclose'),
            route('accounts.balance_sheet'),
            route('accounts.financial_statements'),
            route('accounts.equity_statement'),
            route('accounts.group_report'),
            route('accounts.net_worth'),
            route('accounts.year_end_trial_balance'),
            route('finance.budget.report'),
            route('finance.account_analysis.index', ['account_id' => $account]),
            route('hr.attendance.sheet'),
            route('inventory.stock.age'),
            route('inventory.stock.movement'),
            route('purchase.cost_report.index'),
            // ⓘ লাভ-ক্ষতি আর নগদ প্রবাহ — ইঞ্জিনের পাতাই, তবে নিজের নামের ঠিকানায় (`{slug}` নেই)
            route('accounts.report.final.profit_loss'),
            route('accounts.report.final.cash_flow'),
        ];

        $broken = [];
        foreach ($routes as $url) {
            $glue = str_contains($url, '?') ? '&' : '?';
            $paper = $this->get($url.$glue.'export=print');
            if ($paper->getStatusCode() !== 200 || $paper->headers->get('content-type') !== 'application/pdf') {
                $broken[] = $url.' → '.$paper->getStatusCode().' '.$paper->headers->get('content-type');
            }
        }

        $this->assertSame([], $broken, "⛔ এই রিপোর্টগুলোর নিজের ছাপার কাগজ নেই:\n".implode("\n", $broken));
    }

    public function test_the_new_custom_papers_carry_the_pad_the_period_and_the_figures(): void
    {
        $html = $this->paperHtml(route('accounts.equity_statement', ['from' => '2026-07-01', 'to' => '2026-11-23']));

        $this->assertStringContainsString(__('accounts::menu.equity_statement'), $html);
        $this->assertStringContainsString('01-07-2026', $html, '⛔ কাগজে সময়কাল নেই');
        $this->assertStringContainsString(__('accounts::field.equity_closing'), $html, '⛔ শেষের জের কাগজে নেই');
        $this->assertStringContainsString('{PAGENO} / {nbpg}', $html);
    }

    public function test_the_custom_reports_show_the_print_view_button_in_a_new_tab(): void
    {
        foreach ([route('accounts.equity_statement'), route('accounts.group_report'), route('accounts.net_worth'),
            route('hr.attendance.sheet'), route('inventory.stock.age'), route('inventory.stock.movement'),
            route('accounts.report.final.profit_loss'), route('accounts.report.final.cash_flow')] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<a\b[^>]*target="_blank"[^>]*data-print-view|<a\b[^>]*data-print-view[^>]*target="_blank"/s', $html,
                "⛔ {$url}: \"প্রিন্ট ভিউ\" বোতাম নেই, বা নতুন ট্যাবে খোলে না");
            $this->assertStringNotContainsString('data-action="print"', $html, "⛔ {$url}: এখনো পর্দাটাই ছাপে");
        }
    }

    /** কাগজের HTML — mPDF-এ যাওয়ার আগের রূপ, ধরা টেবিল থেকে */
    private function paperHtml(string $url): string
    {
        $captured = null;
        \Illuminate\Support\Facades\View::composer('print.listing', function ($view) use (&$captured) {
            $captured = $view;
        });

        $glue = str_contains($url, '?') ? '&' : '?';
        $this->get($url.$glue.'export=print')->assertOk();
        $this->assertNotNull($captured, '⛔ কেন্দ্রীয় কাগজ (print.listing) আঁকাই হয়নি');

        return $captured->render();
    }

    /** @return list<array{0: string, 1: string}> */
    private function everyEngineReport(): array
    {
        $out = [];
        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || ! str_ends_with($name, '.report.show')) {
                continue;
            }
            $controller = strtok($route->getActionName(), '@');
            if (! class_exists($controller)) {
                continue;
            }
            foreach (array_keys((new ReflectionClass($controller))->getConstants()['SLUGS'] ?? []) as $slug) {
                $out[] = [$name, (string) $slug];
            }
        }

        return $out;
    }
}

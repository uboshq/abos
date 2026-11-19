<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Contracts\HomeBoard;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ হোমের গোটা-ব্যবসার বোর্ড — মালিকের অনুমোদিত নমুনা, ১৯ নভেম্বর ২০২৬ (claude.ai artifact AAjAfjZ8TnCfnjtt3Kthjz v11)।
 *
 * দাবি:
 *   ১ মালিকের হোমে নমুনার সব অংশ, নমুনার ক্রমে: ৮টা গ্রাফ (নিজের নামে, নিজের ক্রমে), ঘর, তিন তালিকা, সব প্রতিষ্ঠান, আজ যা করতে হবে
 *   ২ শাখার দেয়াল — হেডারে অন্য শাখা বাছলে সেই শাখার বিক্রি, গোটা কোম্পানির নয়
 *   ৩ খরচের চাবি — লাভ কেবল `sales.cost.view`-এ, ক্যাটাগরি ধরে মজুদ আর মজুদের ঘর কেবল `inventory.cost.view`-এ
 *      (a-board-must-keep-the-cost-key); চাবি না থাকলে ঘরটাই নেই, "০" বা "••••" নয়
 *   ৪ খালি হলে "এই সময়ে কিছু নেই।" — ফাঁকা অক্ষ নয়
 *   ৫ পর্দায় অঙ্ক ইংরেজি (মালিক, ১৬ নভেম্বর ২০২৬)
 */
final class TheHomeBoardDrawsTheOwnersSampleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_the_owner_sees_every_part_of_the_sample_in_its_order(): void
    {
        $this->sellToday();
        $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();
        $page = $this->xpath($html);

        $charts = array_map(fn ($n) => $n->getAttribute('data-board-chart'), iterator_to_array($page->query('//*[@data-board-chart]')));
        $this->assertSame(['sales_trend', 'sales_collection', 'dues_ageing', 'stock_category', 'top_products', 'top_shops',
            'collection_channel', 'working_capital'], $charts, '⛔ ৮টা গ্রাফ নমুনার ক্রমে নয় (গ্রাফ আগে — মালিকের নিয়ম)।');

        $lists = array_map(fn ($n) => $n->getAttribute('data-board-list'), iterator_to_array($page->query('//*[@data-board-list]')));
        $this->assertSame(['principals', 'commission', 'points'], $lists, '⛔ তিন তালিকা নমুনার নয়।');

        // ⓘ সব প্রতিষ্ঠান — অন্তত চলতি কোম্পানির এক লাইন ("আপনি এখানে") আর মোটের লাইন, সাত কলাম
        $this->assertSame(1, $page->query('//*[@data-home-companies]//tr[@data-home-company-row="'.$this->company->id.'"]')->length,
            '⛔ সব প্রতিষ্ঠানের টেবিলে চলতি কোম্পানি নেই।');
        $this->assertSame(1, $page->query('//*[@data-home-company-total]')->length, '⛔ সব প্রতিষ্ঠানের মোট নেই।');
        $this->assertSame(8, $page->query('//*[@data-home-companies]//thead//th')->length, '⛔ টেবিলের কলাম নমুনার নয়।');

        // ⓘ প্রতিটা ঘর "গত মাসের একই দিন পর্যন্ত"-এর তুলনা বলে (যেখানে প্রবাহ)
        $sales = $page->query('//*[@data-board-tile="sales"]//*[@data-board-tile-sub]')->item(0)?->textContent ?? '';
        $this->assertStringContainsString(__('home.board.vs_last_month'), $sales, '⛔ বিক্রির ঘরে আগের মাসের একই দিনের তুলনা নেই।');

        // ⓘ ঘরে চাপলে উৎস — প্রতিটা বোর্ডের ঘর লিংক
        foreach ($page->query('//*[@data-board-tile]') as $tile) {
            $this->assertNotSame('', $tile->getAttribute('href'), '⛔ "'.$tile->getAttribute('data-board-tile').'" ঘরে চাপলে কোথাও যায় না।');
        }

        // ⭐ পর্দায় অঙ্ক ইংরেজি — বোর্ডের কোনো অংশে বাংলা অঙ্ক নেই
        $board = '';
        foreach (['//*[@data-home-board-charts]', '//*[@data-kpis]', '//*[@data-home-lists]', '//*[@data-home-companies]', '//*[@data-home-today-work]'] as $part) {
            $board .= $page->query($part)->item(0)?->textContent ?? '';
        }
        $this->assertDoesNotMatchRegularExpression('/[০-৯]/u', $board, '⛔ বোর্ডে বাংলা অঙ্ক।');
    }

    public function test_the_board_stands_behind_the_branch_picked_in_the_header(): void
    {
        $this->sellToday();
        $today = Carbon::today()->toDateString();

        $all = $this->tileValue('sales');
        $this->assertSame('৳'.Money::format(SalesMetrics::net($today, $today), 0), $all);
        $this->assertNotSame('৳0', $all, 'বিক্রি ওঠেনি — দাবির ভিত নেই।');

        // ⛔ বিক্রিটা মূল শাখায়; হেডারে অন্য শাখা বাছলে সেই শাখার কিছুই নেই
        $other = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('id', '!=', $this->company->defaultBranch()?->id)->firstOrFail();
        $this->choose($other->id);

        $this->assertSame('৳0', $this->tileValue('sales'), '⛔ অন্য শাখা বাছা, অথচ বোর্ডে গোটা কোম্পানির বিক্রি — শাখার দেয়াল ভাঙল।');
        $data = app(HomeBoard::class)->for($this->owner, 'today');
        $this->assertSame('৳0', collect($data['cards'])->firstWhere('key', 'sales')['value'] ?? null);
    }

    public function test_profit_and_stock_money_stay_behind_the_cost_keys(): void
    {
        $keys = ['sales.invoice.view', 'sales.collection.view', 'customer.report', 'inventory.stock.view', 'accounts.till.view'];

        // ⓘ চাবিওয়ালা — ঘর আছে (দাবি অন্ধ নয়)
        $this->as($this->person([...$keys, 'sales.cost.view', 'inventory.cost.view']));
        $page = $this->xpath((string) $this->get(route('dashboard'))->assertOk()->getContent());
        $this->assertSame(1, $page->query('//*[@data-board-tile="profit"]')->length, 'প্রস্তুতিটাই ভুল — খরচের চাবিতেও লাভের ঘর নেই।');
        $this->assertSame(1, $page->query('//*[@data-board-chart="stock_category"]')->length, 'প্রস্তুতিটাই ভুল — খরচের চাবিতেও মজুদের গ্রাফ নেই।');

        // ⛔ চাবি নেই — ঘর আর গ্রাফ নেই; খাটানো মূলধনেও মজুদের ভাগ নেই
        $this->as($this->person($keys));
        $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();
        $page = $this->xpath($html);
        $this->assertSame(0, $page->query('//*[@data-board-tile="profit"]')->length, '⛔ খরচের চাবি ছাড়াই মোট লাভ দেখা গেল।');
        $this->assertSame(0, $page->query('//*[@data-board-tile="stock"] | //*[@data-home-stock]')->length, '⛔ খরচের চাবি ছাড়াই মজুদের মূল্য।');
        $this->assertSame(0, $page->query('//*[@data-board-chart="stock_category"]')->length, '⛔ খরচের চাবি ছাড়াই ক্যাটাগরি ধরে মজুদের টাকা।');
        $capital = $page->query('//*[@data-board-chart="working_capital"]')->item(0)?->textContent ?? '';
        $this->assertStringNotContainsString(__('home.board.stock_value'), $capital, '⛔ খাটানো মূলধনে খরচের চাবি ছাড়াই মজুদের ভাগ।');

        // ⛔ বিক্রয়ের চাবি একটাও নেই — বিক্রির গ্রাফ আর ঘর নেই
        $this->as($this->person(['customer.report']));
        $page = $this->xpath((string) $this->get(route('dashboard'))->assertOk()->getContent());
        $this->assertSame(0, $page->query('//*[@data-board-chart="sales_trend"] | //*[@data-board-tile="sales"]')->length,
            '⛔ বিক্রি দেখার চাবি ছাড়াই বিক্রির ধারা।');
        $this->assertSame(1, $page->query('//*[@data-board-chart="dues_ageing"]')->length, 'প্রস্তুতিটাই ভুল — বকেয়ার চাবিতেও বাকির বয়স নেই।');
    }

    public function test_an_empty_period_says_so_instead_of_drawing_empty_axes(): void
    {
        // ⓘ ডেমোতে আজ কোনো বিক্রি নেই — শীর্ষ পণ্য আর দোকান খালি
        $page = $this->xpath((string) $this->get(route('dashboard'))->assertOk()->getContent());

        foreach (['top_products', 'top_shops'] as $key) {
            $chart = $page->query('//*[@data-board-chart="'.$key.'"]')->item(0);
            $this->assertNotNull($chart, "প্রস্তুতিটাই ভুল — {$key} গ্রাফই নেই।");
            $this->assertSame(1, $page->query('.//*[@data-board-empty]', $chart)->length, "⛔ {$key}: খালি সময়ে খালি অক্ষ, \"কিছু নেই\" নয়।");
            $this->assertStringContainsString(__('home.board.empty'), $chart->textContent);
            $this->assertSame(0, $page->query('.//li', $chart)->length, "⛔ {$key}: খালি সময়ে সারি আঁকা হলো।");
        }

        $this->assertSame('এই সময়ে কিছু নেই।', __('home.board.empty', [], 'bn'));
    }

    // ── সাহায্য ─────────────────────────────────────────────────────────

    /**
     * ⛔ মালিকের নিয়ম (১৯ নভেম্বর ২০২৬): অন্য রূপের নকশা এক পিক্সেলও বদলাবে না — বোর্ড কেবল "ABOS"-এ; বাকি সবার আগের হোম
     * (`workspace.home`, bf90c405b-এর হুবহু), আর বোর্ডের কোয়েরি চলেই না।
     */
    public function test_every_other_look_keeps_its_old_home(): void
    {
        foreach (['navy', 'blue', 'dynamic', 'tiles'] as $look) {
            $this->owner->forceFill(['ui' => $look])->save();
            $response = $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertOk();
            $html = (string) $response->getContent();

            $response->assertViewIs('workspace.home');
            $this->assertNull($response->viewData('board'), "⛔ {$look}: বোর্ডের কোয়েরি চলেছে।");
            $this->assertStringNotContainsString('data-home-board-charts', $html, "⛔ {$look}: নতুন বোর্ড অন্য রূপে বসেছে।");
            $this->assertStringNotContainsString('data-module-picker', $html, "⛔ {$look}: মডিউল-তালিকা অন্য রূপে বসেছে।");
            $this->assertStringNotContainsString('data-notice-tag', $html, "⛔ {$look}: নোটিশের চিহ্ন অন্য রূপে বসেছে।");
            $this->assertStringContainsString('hm-pictures', $html, "⛔ {$look}: আগের হোমের চার্টের সারি নেই।");
        }

        $this->owner->forceFill(['ui' => 'rose'])->save();
        $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertOk()->assertViewIs('workspace.home-abos');
    }

    private function sellToday(): void
    {
        app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '5', 'rate' => '1250', 'free_qty' => '0']],
        );
    }

    private function tileValue(string $key): string
    {
        $page = $this->xpath((string) $this->get(route('dashboard'))->assertOk()->getContent());

        return trim($page->query('//*[@data-board-tile="'.$key.'"]//*[@data-board-tile-value]')->item(0)?->textContent ?? '');
    }

    private function choose(int $branch): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => (string) $branch])->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        CompanyContext::set($this->company->id, $user->current_branch_id);
        app(DataScope::class)->forget();
        $this->actingAs($user->fresh());
    }

    /** @param list<string> $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => null, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }
}

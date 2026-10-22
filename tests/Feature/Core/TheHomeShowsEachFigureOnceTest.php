<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerMetrics;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockFacts;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * নতুন হোম — মালিক, ৫ অক্টোবর ২০২৬: *"main deshboard ti deko ekoi jinis duibar ache"* → পরিকল্পনা ২।
 *
 * ⓘ দাবি: টাকার বাক্স একটা; ৮টা চার্ট config-এর ক্রমে, প্রতিটা মডিউল একবার; ৮টা মূল সূচক মালিকের ক্রমে;
 * পাতার কোনো নাম (টাকার বাক্স, সূচক, ব্যতিক্রম) দুইবার নয়; প্রতিটা সূচক আসল খাতা/মজুদের সাথে মেলে;
 * সময় বদলালে প্রবাহের সূচক বদলায়, জের নয়; পুরনো "গোটা ব্যবসা" সারি আর নেই।
 */
final class TheHomeShowsEachFigureOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_figure_on_the_home_appears_once_and_matches_the_books(): void
    {
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '5', 'rate' => '1250', 'free_qty' => '0']],
        );

        $page = $this->xpath($this->get(route('dashboard'))->assertOk()->getContent());

        // ── টাকার বাক্স একটা, পুরনো সারি নেই ──
        $this->assertSame(1, $page->query('//*[@data-money-position]')->length, '⛔ টাকার বাক্স নেই বা দুইটা।');
        $this->assertSame(0, $page->query('//*[@data-home-unit="overall"] | //*[@data-period-cards]')->length,
            '⛔ পুরনো "গোটা ব্যবসা" বা কালপর্বের কার্ড ফিরে এসেছে।');

        // ── ৮টা চার্ট, config-এর ক্রমে, প্রতিটা মডিউল একবার ──
        $pictures = array_map(fn ($n) => $n->getAttribute('data-picture'), iterator_to_array($page->query('//*[@data-picture]')));
        $this->assertSame(config('abos.home_pictures'), $pictures, '⛔ চার্টগুলো মালিকের ক্রমে নয়।');

        // ── মূল সূচক, মালিকের ক্রমে ──
        // ⭐ "বাজারে বকেয়া" এখন সূচকের সারিতে নয়, টাকার বাক্সের ঘরে — মালিকের অনুমোদিত নমুনা (ওয়েবের মান ধাপ ২, ২০ অক্টোবর ২০২৬)।
        //    জায়গা বদলেছে, যাচাই নয়: নিচে বাক্সের ঘর থেকে পড়া, একই উৎস, একই পয়সা, আর পাতায় ঠিক একবার।
        // ⭐ "মজুদের মূল্য"-ও একই পথে বাক্সে, বকেয়ার পাশে (মালিক, fe-র মারফত, ২১ অক্টোবর ২০২৬) — যাচাই নিচে বাক্সের ঘর থেকে।
        $kpis = $this->kpis($page);
        $this->assertSame([
            __('sales::dashboard.sales_today'),
            __('sales::dashboard.collected_today'),
            __('purchase::widget.margin_this_month'),
            __('supplier::widget.owed_to_principals'),
            __('purchase::dashboard.purchases_today'),
            __('approval::dashboard.waiting_for_me'),
        ], array_keys($kpis), '⛔ মূল সূচকের ঘর বা ক্রম মালিকের পরিকল্পনা মতো নয়।');

        $owedTile = $page->query('//*[@data-home-owed]');
        $this->assertSame(1, $owedTile->length, '⛔ "বাজারে বকেয়া" টাকার বাক্সে নেই, বা দুইবার।');
        $owedLabel = trim($page->query('.//p[1]', $owedTile->item(0))->item(0)?->textContent ?? '');
        $owedValue = trim($page->query('.//*[@data-money-tile-value]', $owedTile->item(0))->item(0)?->textContent ?? '');
        $this->assertSame(__('customer::dashboard.kpi_owed'), $owedLabel);
        $stockValue = $this->stockTile($page);
        $tileLabels = array_map(fn ($n) => trim($page->query('.//p[1]', $n)->item(0)?->textContent ?? ''),
            iterator_to_array($page->query('//*[@data-money-tile]')));

        // ── কোনো নাম দুইবার নয় — টাকার বাক্স (তার ঘরগুলোসহ), সূচক, ব্যতিক্রম মিলিয়ে ──
        $names = array_merge(
            [trim($page->query('//*[@data-money-position]/p[1]')->item(0)->textContent)],
            $tileLabels,
            array_keys($kpis),
            array_map(fn ($n) => trim($n->getElementsByTagName('span')->item(1)?->textContent ?? ''), iterator_to_array($page->query('//*[@data-exception]'))),
        );
        $this->assertSame([], array_values(array_diff_assoc($names, array_unique($names))), '⛔ একই জিনিস দুইবার: '.implode(', ', $names));

        // ── আসল খাতা আর মজুদের সাথে মেলা ──
        $today = Carbon::today()->toDateString();
        $this->assertSame(Money::format(SalesMetrics::invoiceTotal($today, $today)), $kpis[__('sales::dashboard.sales_today')],
            '⛔ আজকের বিক্রি বিলের খাতার সাথে মেলে না।');
        $this->assertSame(1, bccomp(SalesMetrics::invoiceTotal($today, $today), '0', 4), 'বিক্রি ওঠেনি — দাবির ভিত নেই।');

        // ⓘ ৬ অক্টোবর ২০২৬ থেকে পাওনা মোট — প্রতি দোকানের ধনাত্মক জেরের যোগ, ফোনের একই উৎস ([[TheHomeOwedByCustomersIsGrossTest]])
        $this->assertSame(Money::format(app(CustomerMetrics::class)->dues(auth()->user(), $today)['amount']),
            $owedValue, '⛔ বাজারে বকেয়া গ্রাহকের খতিয়ানের বকেয়ার যোগ নয়।');

        // ⓘ ১৩ অক্টোবর ২০২৬ থেকে দেনাও মোট, নিট নয় — প্রতি প্রিন্সিপালের বিল হওয়া ধনাত্মক জের আর বিল-না-আসা মাল; প্রদেয়ের তালিকার দুই ঘরের
        // যোগ ([[ThePayableListNettedTheAdvancesTest]])
        $owed = (string) (DB::query()->fromSub(DB::table('ledger_entries')->where('company_id', $company->id)->where('party_type', Supplier::drillSourceType())
            ->whereIn('party_id', Supplier::onlySuppliersIds())
            ->groupBy('party_id')
            ->selectRaw('SUM(CASE WHEN account_id IN ('.implode(',', Supplier::goodsNotBilledAccountIds()).') THEN 0 ELSE credit - debit END) as billed')
            ->selectRaw('SUM(CASE WHEN account_id IN ('.implode(',', Supplier::goodsNotBilledAccountIds()).') THEN credit - debit ELSE 0 END) as waiting'), 's')
            ->selectRaw('COALESCE(SUM(GREATEST(s.billed, 0)), 0) + COALESCE(SUM(s.waiting), 0) as n')->value('n') ?? '0');
        $this->assertSame(Money::format($owed), $kpis[__('supplier::widget.owed_to_principals')], '⛔ কোম্পানির দেনা সরবরাহকারীর খতিয়ানের সাথে মেলে না।');

        $this->assertSame(Money::format(app(StockFacts::class)->value()), $stockValue,
            '⛔ মজুদের মূল্য মজুদের খাতার সাথে মেলে না।');

        // ── সময় বদলালে প্রবাহ বদলায়, জের নয় ──
        $monthPage = $this->xpath($this->get(route('dashboard', ['period' => 'month']))->assertOk()->getContent());
        $month = $this->kpis($monthPage);
        $from = Carbon::today()->startOfMonth()->toDateString();
        $this->assertSame(Money::format(SalesMetrics::invoiceTotal($from, $today)), $month[__('sales::dashboard.sales_this_month')] ?? null,
            '⛔ "এ মাস" বাছলে বিক্রির ঘর মাসের মোট নয়।');
        $this->assertSame($stockValue, $this->stockTile($monthPage), '⛔ সময় বদলালে মজুদের জের বদলেছে।');
    }

    /** ⭐ বাক্সের "মজুদের মূল্য" ঘর — ঠিক একবার, নিজের নামে; ফেরে দেখানো অঙ্ক */
    private function stockTile(DOMXPath $page): string
    {
        $tile = $page->query('//*[@data-home-stock]');
        $this->assertSame(1, $tile->length, '⛔ "মজুদের মূল্য" টাকার বাক্সে নেই, বা দুইবার।');
        $this->assertSame(__('inventory::overview.stock_value'), trim($page->query('.//p[1]', $tile->item(0))->item(0)?->textContent ?? ''));

        return trim($page->query('.//*[@data-money-tile-value]', $tile->item(0))->item(0)?->textContent ?? '');
    }

    /** @return array<string, string> নাম => মান, পাতার ক্রমে */
    private function kpis(DOMXPath $page): array
    {
        $out = [];
        foreach ($page->query('//a[@data-kpi]') as $kpi) {
            $label = trim($page->query('.//span[contains(@class, "truncate")]/span[contains(@class, "truncate")]', $kpi)->item(0)?->textContent ?? '');
            $out[$label] = trim($page->query('.//span[contains(@class, "hm-kpi-value")]', $kpi)->item(0)?->textContent ?? '');
        }

        return $out;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }
}

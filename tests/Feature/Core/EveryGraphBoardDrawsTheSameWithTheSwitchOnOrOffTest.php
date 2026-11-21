<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseBill;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ লাইভ 1.3.7 (২১ নভেম্বর ২০২৬): গ্রাফের বোর্ড ([[dashboard.viz-board]]) নতুন-ড্যাশবোর্ডের সুইচ (`abos.dashboards_v2`) চালু বা বন্ধ — দুই
 * অবস্থাতেই আঁকা হয়, অথচ কয়েকটা কার্ডের অঙ্ক সুইচ দেখত। সুইচ বন্ধে তাই "এই সময়ে কিছু নেই":
 *   · ক্রয় — "2026 সালে সবচেয়ে বেশি কেনা" (৳30.4 লাখ কেনার পরেও) আর "ক্রয়াদেশের পথ"
 *   · সরবরাহকারী — দেনার বয়স, সবচেয়ে বেশি দেনা, সময়মতো মাল
 *   · অনুমোদন — মডিউল ধরে, কার কাছে আটকে
 *   · মাস্টার ডেটা — ছয়টাই
 *   · সিস্টেম প্রশাসন — ছয়টার পাঁচটা কার্ডই নেই; নিরীক্ষা — চারটা ফাঁকা বা নেই
 *
 * দাবি: প্রতিটা বোর্ডে সুইচ বন্ধ আর চালুতে একই কার্ড, একই ক্রমে, আর কোন কার্ড ফাঁকা সেটাও এক — ফাঁকা কেবল তথ্য না থাকলে, সুইচের জন্য নয়।
 * ক্রয়ে বিপজ্জনক উপাত্ত: এ বছরের একটা নিশ্চিত বিল — দুই অবস্থাতেই "সবচেয়ে বেশি কেনা" ভরা।
 */
final class EveryGraphBoardDrawsTheSameWithTheSwitchOnOrOffTest extends TestCase
{
    use RefreshDatabase;

    private const BOARDS = ['purchase', 'supplier', 'approval', 'master_data', 'system_admin', 'governance', 'customer',
        'credit_control', 'hr', 'promotion', 'backup', 'documents', 'notification', 'assistant', 'accounts'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-21 12:00:00'));
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_every_board_has_the_same_cards_and_the_same_empty_ones_with_the_switch_on_or_off(): void
    {
        foreach (self::BOARDS as $module) {
            $off = $this->cards($module, false);
            $on = $this->cards($module, true);

            if ($off === null && $on === null) {
                continue; // ⓘ এই মডিউল এই সাইটে নেই
            }

            $this->assertNotSame([], $on, "দাবির ভিত্তি নেই — {$module}-এর পাতায় গ্রাফের বোর্ড নেই।");
            $this->assertSame(array_keys($on), array_keys($off ?? []), "⛔ {$module}: সুইচ বন্ধে কার্ড কম বা অন্য ক্রমে।");
            $this->assertSame($on, $off, "⛔ {$module}: সুইচ বন্ধে কোনো কার্ড ফাঁকা, যা চালুতে ভরা (বা উল্টো) — অঙ্ক সুইচ দেখছে।");
        }
    }

    public function test_the_top_suppliers_card_counts_this_years_confirmed_bills_with_the_switch_on(): void
    {
        $this->topSuppliersIsDrawn(true);
    }

    public function test_the_top_suppliers_card_counts_this_years_confirmed_bills_with_the_switch_off(): void
    {
        $this->topSuppliersIsDrawn(false);
    }

    private function topSuppliersIsDrawn(bool $v2): void
    {
        $bill = PurchaseBill::query()->withoutGlobalScopes()->where('company_id', CompanyContext::id())->whereNotNull('supplier_id')
            ->orderBy('id')->first()
            // ⓘ ডেমোর এই কোম্পানিতে বিল না থাকলে দাবিটা নিজেই একটা বসায় (Mac-রান ২১ নভেম্বর ২০২৬: ডেমোতে বিল নেই, দাবি কিছুই মাপত না)
            ?? $this->aBillOfOneSupplier();
        $this->assertNotNull($bill, 'দাবির ভিত্তি নেই — ডেমোতে সরবরাহকারী নেই।');
        PurchaseBill::query()->withoutGlobalScopes()->whereKey($bill->id)
            ->update(['status' => DocumentStatus::CONFIRMED, 'trx_date' => '2026-11-10', 'total' => '3040000', 'deleted_at' => null]);

        $cards = $this->cards('purchase', $v2);
        $title = __('purchase::dashboard.top_suppliers', ['year' => 2026]);

        $this->assertArrayHasKey($title, $cards ?? [], 'দাবির ভিত্তি নেই — "সবচেয়ে বেশি কেনা" কার্ড পাতায় নেই।');
        $this->assertFalse($cards[$title], '⛔ এ বছরের নিশ্চিত বিল থাকতেও "সবচেয়ে বেশি কেনা" ফাঁকা (সুইচ '.($v2 ? 'চালু' : 'বন্ধ').')।');
    }

    /** একটা সরবরাহকারীর একটা বিল — খসড়া হিসেবে বসে, উপরে নিশ্চিত করা হয় */
    private function aBillOfOneSupplier(): ?PurchaseBill
    {
        $supplier = \App\Modules\Supplier\Models\Supplier::query()->orderBy('id')->first();
        $warehouse = \App\Modules\Inventory\Models\Warehouse::query()->orderBy('id')->first();
        $year = \App\Models\FinancialYear::query()->orderByDesc('id')->first();

        if ($supplier === null || $warehouse === null || $year === null) {
            return null;
        }

        return PurchaseBill::query()->create([
            'branch_id' => CompanyContext::branchId() ?? $warehouse->branch_id,
            'financial_year_id' => $year->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'document_no' => 'PBL-T'.random_int(10000, 99999),
            'supplier_bill_no' => 'TOP-1',
            'trx_date' => '2026-11-10',
            'subtotal' => '3040000',
            'discount' => '0',
            'tax' => '0',
            'total' => '3040000',
            'ait_amount' => '0',
            'vds_amount' => '0',
            'status' => DocumentStatus::DRAFT,
        ]);
    }

    /**
     * পাতার গ্রাফের কার্ড — শিরোনাম => ফাঁকা কি না; পাতা না থাকলে null।
     *
     * @return array<string, bool>|null
     */
    private function cards(string $module, bool $v2): ?array
    {
        config(['abos.dashboards_v2' => $v2]);
        $response = $this->get(route('module.dashboard', ['module' => $module]));

        if ($response->status() === 404) {
            return null;
        }

        $html = (string) $response->assertOk()->getContent();
        $out = [];

        foreach (array_slice(explode('class="viz-card"', $html), 1) as $chunk) {
            $card = substr($chunk, 0, (int) strpos($chunk, '</section>'));
            preg_match('~<h2>(.*?)</h2>~su', $card, $m);
            $out[html_entity_decode(trim($m[1] ?? ''))] = str_contains($card, 'data-viz-empty');
        }

        return $out;
    }
}

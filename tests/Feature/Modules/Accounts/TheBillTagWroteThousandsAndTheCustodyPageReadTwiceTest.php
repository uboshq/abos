<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * দুইটা ছোট জিনিস — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (হিসাব, ঠিক ৯)।
 *
 * ⛔ খরচের চালান-ট্যাগে "আগে বসেছে" অঙ্ক `number_format`-এ — হাজারের কমা (১২৩,৪৫৬.৭৮), অথচ গোটা ABOS লাখের কমায়
 *   ([[Money::format()]])।
 * ⛔ "টাকা কার হাতে" পর্দা প্রতিটা সারির জের দুইবার আলাদা করে খাতা থেকে পড়ত — এখন একবার।
 */
final class TheBillTagWroteThousandsAndTheCustodyPageReadTwiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_the_already_charged_amount_has_lakh_commas(): void
    {
        // ⓘ পাতার মাঝের টুকরো একা আঁকা — মিডলওয়্যার যা ভাগ করে দেয় (সেশন, ভুলের ব্যাগ), তা হাতে
        $this->app['request']->setLaravelSession($this->app['session.store']);
        view()->share('errors', new ViewErrorBag);

        $html = view('accounts::voucher.partials.expense-bill-tag', [
            'voucher' => new Voucher,
            'taggableBills' => collect([(object) [
                'id' => 1, 'document_no' => 'PB-1', 'goods_names' => ['চাল'], 'goods_summary' => 'চাল', 'goods_folded' => false,
                'total_qty' => '10', 'already_charged' => '123456.78',
            ]]),
        ])->render();

        $this->assertStringContainsString('1,23,456.78', $html, '⛔ "আগে বসেছে" লাখের কমায় নয়।');
        $this->assertStringNotContainsString('123,456.78', $html);
    }

    public function test_the_custody_page_reads_each_balance_once(): void
    {
        // ⓘ কয়েকটা ব্যাংক খাত — সারি বাড়লে দুইবার-পড়া স্পষ্ট দেখা যায়
        foreach ([1, 2, 3] as $n) {
            Account::query()->create([
                'code' => StandardChart::BANK.'-C'.$n, 'name_en' => 'Custody Bank '.$n, 'parent_id' => StandardChart::find(StandardChart::BANK)->id,
                'type' => 'asset', 'nature' => 'debit', 'money_kind' => 'bank', 'is_active' => true,
            ]);
        }

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'ledger_entries')) {
                $reads++;
            }
        });

        $response = $this->get(route('accounts.custody'))->assertOk();
        $rows = count($response->viewData('rows'));

        $this->assertGreaterThan(0, $rows, 'পর্দায় একটাও সারি নেই — দাবি অন্ধ।');
        // ⓘ প্রতিটা সারিতে একবার, আর পথের টাকার জের একবার; দুইবার পড়লে সংখ্যাটা দ্বিগুণ
        $this->assertLessThanOrEqual($rows + 1, $reads, "⛔ {$rows}টা সারির জন্য খাতা পড়া হলো {$reads} বার।");
    }
}

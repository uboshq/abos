<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ খোঁজায় লিখলে ছাঁকনির প্যানেল খোলে না — মালিক, ২৪ অক্টোবর ২০২৬ (ADI, SL-Super Group শাখা, ছবিসহ): গ্রাহকের তালিকায় কেবল
 * "kona" লিখলেন, আর পুরো প্যানেল খুলে গেল, সঙ্গে "অবস্থা: চালু" চিপ — *"শুধু kona লিখতে কী হলো?"*
 *
 * কারণ: খোঁজা পুরো ফর্ম জমা দেয়, আর তাতে লুকানো `status=active` (ডিফল্ট) যায়; টুলবার সেটাকে চালু ছাঁকনি ধরত। এখন তালিকা নিজের
 * ডিফল্ট বলে দেয় ([[ui.toolbar]]-এর `defaults`), আর ডিফল্টে থাকা ঘর প্যানেল খোলে না, চিপ হয় না। সত্যিকারের ছাঁকনি দিলে তবেই।
 * সঙ্গে: সারির কাজে চোখের আইকন একবারই (গ্রাহক আর সরবরাহকারীর তালিকায় চোখ, কলম, চোখ ছিল)।
 */
class OnlyARealFilterOpensTheFilterPanelTest extends TestCase
{
    use RefreshDatabase;

    private const SHUT = 'filtersOpen: false';

    private const OPEN = 'filtersOpen: true';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    private function page(array $query): string
    {
        return (string) $this->get(route('customer.index', $query))->assertOk()->getContent();
    }

    public function test_a_search_alone_keeps_the_panel_shut_and_shows_no_chip(): void
    {
        $page = $this->page(['q' => 'kona']);

        $this->assertStringContainsString(self::SHUT, $page, '⛔ কেবল "kona" খুঁজতেই ছাঁকনির প্যানেল খুলে গেল');
        $this->assertSame(0, substr_count($page, 'data-facet'), '⛔ কেবল খোঁজায় ছাঁকনির চিপ উঠল ("অবস্থা: চালু")');
    }

    /** খোঁজা যেভাবে পুরো ফর্ম জমা দেয় — ডিফল্টে থাকা লুকানো অবস্থা আর ফাঁকা ঘরগুলো সহ */
    public function test_the_whole_form_at_its_defaults_still_keeps_the_panel_shut(): void
    {
        $page = $this->page(['q' => 'kona', 'status' => 'active', 'created_from' => '', 'created_to' => '', 'due_min' => '',
            'due_max' => '', 'advance_min' => '', 'advance_max' => '', 'area' => '', 'point' => '', 'rank' => '', 'rank_n' => '',
            'rank_by' => '', 'sales_from' => '', 'sales_to' => '']);

        $this->assertStringContainsString(self::SHUT, $page, '⛔ ডিফল্টে থাকা ঘরসহ জমায় প্যানেল খুলে গেল — status=active চালু ছাঁকনি ধরা হলো');
        $this->assertSame(0, substr_count($page, 'data-facet'), '⛔ ডিফল্ট "চালু" অবস্থা চিপ হলো — দ্রুত-দৃশ্যের বাছাই আর চিপ একই কথা দুবার বলে');
    }

    public function test_a_real_filter_opens_the_panel_with_its_chip(): void
    {
        $due = $this->page(['q' => 'kona', 'status' => 'active', 'due_min' => '100']);
        $this->assertStringContainsString(self::OPEN, $due, '⛔ "বকেয়া অন্তত ১০০" দিলেও প্যানেল বন্ধ — চালু ছাঁকনি লুকাল');
        $this->assertSame(1, substr_count($due, 'data-facet'), '⛔ "বকেয়া অন্তত ১০০"-র চিপ একটা হওয়ার কথা (ডিফল্ট অবস্থা চিপ নয়)');

        // ⓘ ডিফল্ট ছাড়া অন্য অবস্থা সত্যিকারের ছাঁকনি — quiet-এর মতো চিরকাল চুপ নয়
        $inactive = $this->page(['status' => 'inactive']);
        $this->assertStringContainsString(self::OPEN, $inactive, '⛔ "নিষ্ক্রিয়" বাছলেও প্যানেল বন্ধ — সারি কম কেন, পর্দা বলল না');
        $this->assertSame(1, substr_count($inactive, 'data-facet'), '⛔ "নিষ্ক্রিয়" অবস্থার চিপ নেই');
    }

    public function test_each_row_action_shows_once_on_the_customer_and_supplier_lists(): void
    {
        // ⓘ সারির কাজের চোখ (row-actions) আর টেবিলের "দেখুন" চোখ (data-row-view) আলাদা আঁকা — প্রথমটার রেখা দিয়ে গোনা। দুই তালিকাতেই
        //   চোখ কাজের কলামে (সরবরাহকারীতে মালিকের ২১ সেপ্টেম্বরের ছক: দেখা আর সম্পাদনা; গ্রাহকে এক নজরের সারাংশ), টেবিলের চোখ নেই
        $rowEye = 'M12 5c-5 0-9 4.5-10 7';
        foreach (['customer.index' => 'গ্রাহকের', 'supplier.index' => 'সরবরাহকারীর'] as $route => $list) {
            $page = (string) $this->get(route($route))->assertOk()->getContent();
            $this->assertGreaterThan(0, substr_count($page, $rowEye), "⛔ {$list} তালিকার কাজের কলামে চোখ নেই — সারির পাতা খোলার পথ হারাল");
            $this->assertSame(0, substr_count($page, 'data-row-view'),
                "⛔ {$list} তালিকায় একই সারিতে চোখের আইকন দুবার (সারির কাজের চোখ আর টেবিলের \"দেখুন\" চোখ)");
        }
    }
}

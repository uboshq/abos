<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Contracts\SettledByAVoucher;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * টাকা এসে গেল, অথচ মূলধনের সারিটা খসড়াই থেকে গেল।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ১৪ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"ক্যাপিটাল থেকেই টাকা রিসিভ করার ব্যবস্থা করো।"*
 * আর তার আগের দিন: *"অর্থে মূলধন লিখে হিসাবে রিসিভ করলেই তো সমাধান।"*
 *
 * ⓘ অর্থাৎ শুরুটা মূলধনের তালিকা থেকে, গ্রহণটা রসিদে — এক জায়গায়
 * সব প্রশ্ন (কোন খাত, কোন ব্যাংক, লেনদেন নম্বর, চার্জ)।
 *
 * ── ⛔ কিন্তু ভাগটা তখনই কাজ করে যখন সারিটাও নিষ্পন্ন হয় ─────────────
 * না হলে **এক সত্যের দুইটা উৎস**: অর্থের পর্দা বলত "টাকা আসেনি",
 * খাতা বলত এসে গেছে। ⚠️ আর ভুলটা নীরব — দুইটা পাতাই খুলত, কিছুই
 * ছুঁড়ত না।
 *
 * ── ⚠️ আর যে জিনিসটা এই পরীক্ষার আসল কারণ ───────────────────────────
 * শিকলটার একটা কড়ি `Finance/module.php`-এর `drill_sources`, আর সেই
 * ব্লকটা **১৪ সেপ্টেম্বর পর্যন্ত ছিলই না**। ⛔ ওটা ছাড়া হুকটা কোনো
 * ব্যতিক্রম ছুঁড়ত না — চুপচাপ `return` করত। ⓘ অর্থাৎ সবকিছু সবুজ
 * দেখাত আর কাজটা হত না, যা এই কাজের সবচেয়ে খারাপ ফল।
 */
final class TheMoneyArrivedButTheRowStayedADraftTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($user);
    }

    /**
     * একটা আসল ভাউচার — ⛔ বানানো নম্বর চলে না।
     *
     * ── ⚠️ ১৫ সেপ্টেম্বর ২০২৬-এ চারটা দাবি এখানেই মরত ────────────────
     * আগে সরাসরি `settleWith(4242)` লেখা ছিল, অর্থাৎ হাওয়া থেকে তোলা
     * একটা নম্বর। ⛔ `acc_capital_entries.voucher_id`-তে আসল বিদেশি চাবি
     * আছে (`acc_capital_entries_voucher_id_foreign` → `vouchers`), তাই
     * ডাটাবেজ সেটা নিতে অস্বীকার করত।
     *
     * ⓘ দাবিগুলো ভুল ছিল না — **ফিক্সচারটা ভুল ছিল**। আর এটাও আজকের
     * সেই একই শ্রেণি: টেস্টটা লেখা হয়েছিল, চালানো হয়নি।
     */
    private function voucherNo(string $no): int
    {
        $year = DB::table('financial_years')
            ->where('company_id', $this->company->id)
            ->value('id');

        $this->assertNotNull($year, 'কোম্পানির কোনো অর্থবছর নেই — ভাউচার বানানো যাচ্ছে না।');

        return (int) Voucher::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => $year,
            'type' => 'receipt',
            'document_no' => $no,
            'trx_date' => now()->toDateString(),
            'amount' => '1.0000',
            'status' => DocumentStatus::CONFIRMED,
        ])->id;
    }

    /**
     * ⛔ নামটা সত্যিই ক্লাসে পৌঁছায় — `drill_sources` ব্লকটা আছে।
     *
     * ⓘ এটা আলাদা করে মাপা হয়, কারণ এই একটা কড়ি খসে পড়লে নিচের সব
     * দাবি **সবুজ থাকতে পারত** (হুকটা নীরবে কিছুই করত না), আর তখন
     * ব্যর্থতাটা ধরা পড়ত কেবল লাইভে, মাস পরে।
     */
    public function test_the_type_name_resolves_to_the_capital_entry_class(): void
    {
        $class = app(DrillResolver::class)->map()['capital_entry'] ?? null;

        $this->assertSame(CapitalEntry::class, $class, implode("\n", [
            '`capital_entry` কোনো ক্লাসে পৌঁছায় না — Finance/module.php-এর',
            '`drill_sources` ব্লকটা দেখুন।',
            '',
            '⛔ ছাড়া চললে রসিদ পোস্ট হলে হুকটা চুপচাপ কিছুই করবে না, আর',
            'মূলধনের সারি চিরকাল খসড়া থেকে যাবে।',
        ]));

        $this->assertTrue(is_subclass_of(CapitalEntry::class, SettledByAVoucher::class),
            'CapitalEntry চুক্তিটা প্রয়োগ করে না — তাহলে হুকটা তাকে এড়িয়ে যাবে।');
    }

    /**
     * ⭐ রসিদ পোস্ট হলে সারিটা নিষ্পন্ন, আর ভাউচারের নম্বরটাও বসে।
     */
    public function test_settling_marks_the_row_posted_and_keeps_the_voucher(): void
    {
        $entry = $this->draft();

        $this->assertSame(CapitalEntry::DRAFT, $entry->status);

        $mine = $this->voucherNo('RV-TEST-4242');

        $entry->settleWith($mine);

        $this->assertSame(CapitalEntry::POSTED, $entry->status);
        $this->assertSame($mine, $entry->voucher_id);
        $this->assertNotNull($entry->posted_at);
    }

    /**
     * ⛔ দুইবার ডাকলেও তারিখটা বদলায় না — চুক্তির idempotent শর্ত।
     *
     * ⚠️ একটা রসিদ বাতিল করে আবার পোস্ট করা যায়। শর্ত ছাড়া লিখলে
     * দ্বিতীয়বারে `posted_at` বদলে যেত, অর্থাৎ **টাকাটা কবে এসেছিল
     * সেই তারিখটাই মিথ্যা হত** — আর ফিরে পাওয়ার উপায় থাকত না।
     */
    public function test_settling_twice_does_not_move_the_date(): void
    {
        $entry = $this->draft();

        $first_v = $this->voucherNo('RV-TEST-0011');
        $second_v = $this->voucherNo('RV-TEST-0022');

        $entry->settleWith($first_v);
        $first = $entry->posted_at;

        $this->travel(2)->minutes();

        $entry->settleWith($second_v);

        $this->assertEquals($first, $entry->posted_at,
            'দ্বিতীয় ডাকে তারিখটা নড়ে গেছে — টাকাটা কবে এসেছিল তা আর বলা যাবে না।');
        $this->assertSame($first_v, $entry->voucher_id,
            'দ্বিতীয় ভাউচারটা প্রথমটাকে সরিয়ে দিয়েছে।');
    }

    /**
     * বাতিল হলে সারিটা আবার খসড়া।
     *
     * ⚠️ এটা না থাকলে ভুল করে কাটা একটা রসিদ বাতিল করার পরেও সারিটা
     * "পাওয়া গেছে" বলে বসে থাকত, আর টাকাটা দ্বিতীয়বার চাওয়া হত না।
     */
    public function test_cancelling_the_voucher_opens_the_row_again(): void
    {
        $entry = $this->draft();

        $v = $this->voucherNo('RV-TEST-0077');

        $entry->settleWith($v);
        $entry->unsettle($v);

        $this->assertSame(CapitalEntry::DRAFT, $entry->status);
        $this->assertNull($entry->voucher_id);
        $this->assertNull($entry->posted_at);
    }

    /**
     * ⛔ অন্য কারো ভাউচার বাতিল হলে এই সারিটা খোলে না।
     *
     * ⓘ শর্তটা না থাকলে একটা ভুল `against_id` লেখা ভাউচার বাতিল করলে
     * সম্পূর্ণ অন্য কারো মূলধন আবার "আসেনি" হয়ে যেত। ⚠️ আর তখন ঐ
     * টাকাটা দ্বিতীয়বার চাওয়া হত — একজন অংশীদারের কাছে।
     */
    public function test_another_vouchers_cancellation_leaves_this_row_alone(): void
    {
        $entry = $this->draft();

        $mine = $this->voucherNo('RV-TEST-0100');
        $other = $this->voucherNo('RV-TEST-0999');

        $entry->settleWith($mine);
        $entry->unsettle($other);

        $this->assertSame(CapitalEntry::POSTED, $entry->status,
            'অন্য একটা ভাউচারের বাতিল এই সারিটা খুলে দিয়েছে।');
        $this->assertSame($mine, $entry->voucher_id);
    }

    /**
     * ⭐ তালিকার বোতামটা সত্যিই রসিদে নিয়ে যায়, আর সাথে নামটা বহন করে।
     *
     * ⓘ `against_type` ছাড়া লিংকটা কাজ করত — রসিদ পোস্টও হত — কেবল
     * সারিটা কোনোদিন নিষ্পন্ন হত না। **এটাই নীরব ব্যর্থতাটা**, তাই
     * ঠিকানাটা চোখে দেখে নয়, মেপে দেখা হয়।
     */
    public function test_the_list_button_carries_the_link_back_to_the_row(): void
    {
        $entry = $this->draft();

        $html = $this->get(route('finance.capital.index'))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('against_type=capital_entry', $html,
            'বোতামটা `against_type` বহন করে না — রসিদ পোস্ট হলেও সারিটা খসড়াই থাকবে।');
        $this->assertStringContainsString('against_id='.$entry->getKey(), $html);

        /*
         * ⛔ আর পুরনো পথটা সত্যিই গেছে কি না।
         *
         * ⚠️ দুইটা পথ একসাথে থাকলে একদিন একটায় চার্জের ঘর বসত আর
         * অন্যটায় না — মালিকের আপত্তিটা ঠিক এটাই ছিল।
         */
        $this->assertStringNotContainsString('received_into_account_id', $html,
            'টেবিলের ভিতরের পুরনো খাত-বাছাইয়ের ঘরটা এখনো আছে।');
    }

    private function draft(string $amount = '2500000.00'): CapitalEntry
    {
        $who = Person::query()->first() ?? Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'P0001',
            'name_en' => 'Owner One',
            'is_active' => true,
        ]);

        return app(CapitalService::class)->record([
            'person_id' => $who->id,
            'contributor_type' => CapitalEntry::OWNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
        ]);
    }
}

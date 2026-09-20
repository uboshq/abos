<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Security\LedgerChain;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রকল্পের নিজের কোনো খতিয়ান ছিল না — মানচিত্র §১৮।
 *
 * ── ⚠️ "কোন কেন্দ্রে কত" দিয়ে কাজ চলত না ────────────────────────────
 * ওটা **যোগফল**: প্রকল্পে মোট কত খরচ, কত আয়। ⓘ কিন্তু প্রকল্পের হিসাব
 * নিয়ে যে প্রশ্নটা সত্যিই ওঠে সেটা "মোট কত" নয় — **"টাকাটা কোথায় গেল"**,
 * আর তার উত্তর সারিগুলোতে, যোগফলে নয়।
 *
 * ⛔ আর এই ফাইলের শেষ পরীক্ষাটা নতুন পর্দার নয়, একটা **সিদ্ধান্তের**:
 * খতিয়ানে আলাদা `project_id` কলাম বসানো হয়নি, কারণ তাতে হ্যাশ-শিকলের
 * সই করা ঘরের তালিকা বদলাত আর আগের প্রতিটা সারির হ্যাশ অবৈধ হত।
 */
final class TheProjectHadNoLedgerOfItsOwnTest extends TestCase
{
    use RefreshDatabase;

    private CostCenter $dhaka;

    private CostCenter $khulna;

    private Account $cash;

    private Account $fuel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->dhaka = CostCenter::query()->create([
            'code' => 'PRJ-DHK', 'name_en' => 'Dhaka route', 'name_bn' => 'ঢাকা রুট', 'is_active' => true,
        ]);

        $this->khulna = CostCenter::query()->create([
            'code' => 'PRJ-KHL', 'name_en' => 'Khulna route', 'name_bn' => 'খুলনা রুট', 'is_active' => true,
        ]);

        $this->cash = Account::query()->money()->postable()->active()->firstOrFail();
        $this->fuel = Account::query()->where('code', StandardChart::BANK_CHARGES)->postable()->firstOrFail();
    }

    /**
     * ⭐ এক প্রকল্পের খতিয়ান কেবল সেই প্রকল্পের সারিগুলো দেখায়।
     */
    public function test_a_projects_ledger_shows_only_that_projects_lines(): void
    {
        $this->spend($this->dhaka, '1200', 'ঢাকার তেল');
        $this->spend($this->khulna, '900', 'খুলনার তেল');

        $page = $this->get(route('accounts.report.show', [
            'slug' => 'project-ledger',
            'cost_center_id' => $this->dhaka->id,
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]));

        $page->assertOk();
        $page->assertSee('ঢাকার তেল');

        // ⛔ অন্য প্রকল্পের সারিটা এখানে থাকতে পারে না
        $page->assertDontSee('খুলনার তেল');
    }

    /**
     * ⛔ প্রকল্প না বাছলে পর্দা খালি — গোটা খতিয়ান নয়।
     *
     * ⚠️ ছাঁকনিটা না থাকলে এটা নীরবে গোটা কোম্পানির খতিয়ান হয়ে যেত, আর
     * "প্রকল্পভিত্তিক" নামের সাথে পর্দার কোনো সম্পর্ক থাকত না।
     */
    public function test_without_a_project_the_screen_stays_empty(): void
    {
        $this->spend($this->dhaka, '1200', 'ঢাকার তেল');

        $this->get(route('accounts.report.show', [
            'slug' => 'project-ledger',
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]))
            ->assertOk()
            ->assertDontSee('ঢাকার তেল');
    }

    /**
     * ⭐ টাকার খাতের সারিটাও থাকে — কেবল খরচ নয়।
     *
     * ⓘ "কোন কেন্দ্রে কত" ইচ্ছাকৃতভাবে কেবল আয়-ব্যয় গোনে। ⚠️ কিন্তু
     * খতিয়ানে "টাকাটা কোন বাক্স থেকে বেরোল" সারিটা বাদ দিলে জেরটাই
     * মিলত না — খতিয়ান তখন আধখানা।
     */
    public function test_the_money_side_is_in_the_ledger_too(): void
    {
        $this->spend($this->dhaka, '1200', 'ঢাকার তেল');

        $page = $this->get(route('accounts.report.show', [
            'slug' => 'project-ledger',
            'cost_center_id' => $this->dhaka->id,
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]));

        $page->assertOk();

        // ⓘ দুই পাশই — খরচের খাত আর টাকার খাত
        $page->assertSee($this->fuel->code);
        $page->assertSee($this->cash->code);
    }

    /**
     * ⛔ খতিয়ানের হ্যাশ-শিকলে হাত পড়েনি — আর এটাই এই কাজের মূল সিদ্ধান্ত।
     *
     * ── ⚠️ কেন এটা পরীক্ষা করার মতো জিনিস ───────────────────────────
     * প্রকল্পকে আলাদা একটা `project_id` কলাম বানানোই ছিল স্বাভাবিক পথ।
     * ⓘ কিন্তু খতিয়ানের প্রতিটা সারি হ্যাশ-শিকলে বাঁধা, আর সই করা ঘরের
     * তালিকায় নতুন নাম ঢোকালে **আগের প্রতিটা সারির হ্যাশ অবৈধ** হয়ে
     * যেত — খাতা "বদলানো হয়েছে" বলে চিৎকার করত, অথচ কিছুই বদলায়নি।
     *
     * ⭐ তাই বিদ্যমান `cost_center_id` মাত্রাটাই ব্যবহার করা হলো, আর এই
     * পরীক্ষাটা পাহারা দেয় যে কেউ যেন পরে চুপচাপ তালিকাটা বদলে না ফেলে।
     */
    public function test_the_ledger_chain_was_not_touched(): void
    {
        $signed = (new \ReflectionClass(LedgerChain::class))->getConstant('SIGNED');

        $this->assertNotContains('project_id', $signed,
            'খতিয়ানের সই করা ঘরের তালিকায় project_id বসেছে — আগের প্রতিটা সারির হ্যাশ এখন অবৈধ।');

        $this->assertContains('cost_center_id', $signed,
            'প্রকল্পের মাত্রাটাই সই করা নয় — তাহলে প্রকল্পের ট্যাগ বদলে দিলেও খাতা টের পাবে না।');
    }

    /** ঐ প্রকল্পের নামে একটা খরচ বসানো। */
    private function spend(CostCenter $centre, string $amount, string $note): Voucher
    {
        $vouchers = app(VoucherService::class);

        return $vouchers->post($vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => $note],
            [
                ['account_id' => $this->fuel->id, 'debit' => $amount, 'credit' => '0',
                 'cost_center_id' => $centre->id, 'narration' => $note],
                ['account_id' => $this->cash->id, 'debit' => '0', 'credit' => $amount,
                 'cost_center_id' => $centre->id, 'narration' => $note],
            ],
        ));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Module\ModuleDefinition;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * এক ঘরে তিনটা দরজা ছিল।
 *
 * ── ⓘ মালিকের সিদ্ধান্ত, ২০ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * ⛔ মেনুতে ছিল **ব্যাংক আমানত · সঞ্চয়পত্র · বন্ড** — তিনটা সারি, আর
 * তিনটাই একই পর্দা, কেবল `issuer` আলাদা। ⚠️ তাতে অর্থের তালিকাটা লম্বা
 * হত আর তিনটা প্রায়-একই নাম পাশাপাশি বসত।
 *
 * ⭐ এখন একটাই সারি — **আমানত** — আর তিনটা নাম পর্দার উপরের ট্যাব,
 * প্রতিটার পাশে গোনা সহ। ⓘ ইস্যুকারীটা পথেই থাকে, তাই পুরনো বুকমার্ক
 * আগের জায়গাতেই নামে।
 *
 * ── ⚠️ আর যেটা এই পরীক্ষার আসল পাহারা ────────────────────────────────
 * ⛔ মালিকের ছয়টা ভাগের নাম (দেখা · মালিকানা · দায় · সঞ্চয় · চুক্তি ·
 * সেটআপ) **দল** হিসেবে বসানো যায় না: দলের নাম কোরে বাঁধা, আর অচেনা নাম
 * দিলে অ্যাপ বুটেই থামে। ⓘ তাই ওগুলো লেনদেনের ভিতরের ভাঁজ, আর এই
 * পরীক্ষা সেটাই ধরে রাখে।
 */
final class TheMenuHadThreeDoorsToOneRoomTest extends TestCase
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

        // ⓘ ধরনগুলো সিডারে নেই, ডিপ্লয়ের ইনস্টলারে — তাই এখানেই বসাতে হয়
        app(DepositKindInstaller::class)->install();
    }

    /**
     * ⭐ মেনুতে জমার সারি একটাই, আর তার নাম "আমানত"।
     */
    public function test_the_three_deposit_rows_became_one(): void
    {
        $rows = $this->rowsOf('transactions');

        $deposit = array_values(array_filter(
            $rows,
            fn (array $row) => ($row['route'] ?? null) === 'finance.deposit.index',
        ));

        $this->assertCount(1, $deposit,
            'জমার সারি একের বেশি — তিনটা দরজা এখনো একই ঘরে খুলছে।');

        $this->assertSame('finance::menu.deposits', $deposit[0]['label']);

        // ⓘ ইস্যুকারীটা পথেই থাকে, নাহলে সারিটা কোন পাতায় নামবে ঠিক থাকত না
        $this->assertSame(['issuer' => DepositKind::BANK], $deposit[0]['route_params'] ?? null);
    }

    /**
     * ⭐ মালিকের ছয় ভাগ — পাঁচটা লেনদেনের ভাঁজ, ছয় নম্বরটা সেটিংস।
     *
     * ⚠️ দাবিটা ক্রম ধরে: মালিক নিজে ক্রমটা লিখে দিয়েছিলেন, আর পর্দায়
     * ভাঁজগুলো ঘোষণার ক্রমেই বসে।
     */
    public function test_the_owners_six_folds_are_in_his_order(): void
    {
        $folds = [];

        foreach ($this->rowsOf('transactions') as $row) {
            $fold = $row['cluster'] ?? null;

            if ($fold !== null && ! in_array($fold, $folds, true)) {
                $folds[] = $fold;
            }
        }

        $this->assertSame(
            ['overview', 'ownership', 'liability', 'savings', 'contracts'],
            $folds,
            'লেনদেনের ভাঁজগুলো আর মালিকের ক্রমে নেই।',
        );

        // ⓘ ছয় নম্বর ভাগ "সেটআপ" — কোরের সেটিংস দলেই বসে
        $setup = array_map(fn (array $row) => $row['route'] ?? null, $this->rowsOf('settings'));

        $this->assertContains('finance.deposit_kind.index', $setup);
        $this->assertContains('finance.institution.index', $setup);
    }

    /**
     * ⛔ দলের নাম কোরে বাঁধা — নতুন নাম বসালে অ্যাপ বুটেই থামত।
     */
    public function test_no_group_name_was_invented(): void
    {
        foreach (array_keys($this->menu()) as $group) {
            $this->assertContains($group, ModuleDefinition::MENU_GROUPS,
                "অচেনা দল '{$group}' — এতে অ্যাপ চালু হওয়ার সময়েই ভাঙত।");
        }
    }

    /**
     * ⭐ পর্দার উপরে তিনটা ইস্যুকারীর ট্যাব, প্রতিটার পাশে গোনা।
     */
    public function test_the_three_names_are_now_tabs_with_a_count(): void
    {
        $this->openADeposit(DepositKind::BANK);

        $page = $this->get(route('finance.deposit.index', ['issuer' => 'bank']))->assertOk();

        foreach (DepositKind::ISSUERS as $issuer) {
            $page->assertSee(route('finance.deposit.index', ['issuer' => $issuer]), escape: false);
        }

        $page->assertSee(__('finance::menu.deposit_savings'))
            ->assertSee(__('finance::menu.deposit_bond'))
            // ⓘ শিরোনামটা এখন এক নামেই — "আমানত"
            ->assertSee(__('finance::menu.deposits'));
    }

    /**
     * ⭐ বুকমার্ক করা পুরনো লিংক নিজের ট্যাবেই নামে।
     */
    public function test_an_old_bookmark_still_lands_on_its_own_tab(): void
    {
        $this->openADeposit(DepositKind::BANK);

        // ⓘ বন্ডের পাতায় ব্যাংকের কাগজটা নেই — ট্যাবটা সত্যিই ছাঁকছে
        $this->get(route('finance.deposit.index', ['issuer' => 'bond']))
            ->assertOk()
            ->assertDontSee('সোনালী ব্যাংক');

        $this->get(route('finance.deposit.index', ['issuer' => 'bank']))
            ->assertOk()
            ->assertSee('সোনালী ব্যাংক');
    }

    /**
     * ⚠️ ইস্যুকারী বদলালে অবস্থার ট্যাবটা হারায় না।
     *
     * ⛔ হারালে "মেয়াদ আসছে" দেখতে দেখতে বন্ডে গেলে মানুষ চালু তালিকায়
     * ফিরে যেতেন, আর ভাবতেন বন্ডের কোনো মেয়াদ সামনে নেই।
     */
    public function test_the_state_tab_travels_with_the_issuer(): void
    {
        $this->get(route('finance.deposit.index', ['issuer' => 'bank', 'tab' => 'pledged']))
            ->assertOk()
            ->assertSee(
                route('finance.deposit.index', ['issuer' => 'bond', 'tab' => 'pledged']),
                escape: false,
            );
    }

    /**
     * ⭐ "কোন প্রতিষ্ঠানে" ট্যাব — মালিকের সংশোধন, ২০ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ আমানত মানুষের সাথে নয় — প্রতিষ্ঠানে রাখা হয়। ⚠️ আর যে পুরনো
     * আমানতে প্রতিষ্ঠান বসানো হয়নি, সেগুলো লুকায় না — নাহলে যোগফল
     * কম দেখাত আর কেউ ধরত না।
     */
    public function test_the_deposits_tab_counts_by_institution(): void
    {
        $this->openADeposit(DepositKind::BANK);

        $page = $this->get(route('finance.deposit.index', ['issuer' => 'bank', 'tab' => 'institution']))
            ->assertOk();

        $rows = $page->viewData('institutions');

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['count']);
        $this->assertSame('100000.0000', $rows[0]['total']);
        $this->assertNotNull($rows[0]['next']);

        $this->assertSame(count($rows), $page->viewData('counts')['institution']);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function menu(): array
    {
        $module = require base_path('app/Modules/Finance/module.php');

        return $module['menu'];
    }

    /** @return list<array<string, mixed>> */
    private function rowsOf(string $group): array
    {
        return $this->menu()[$group] ?? [];
    }

    private function openADeposit(string $issuer): void
    {
        $kind = DepositKind::query()->where('issuer', $issuer)->firstOrFail();

        app(DepositService::class)->open([
            'kind_id' => $kind->id,
            'institution' => 'সোনালী ব্যাংক',
            'held_by' => Deposit::BUSINESS,
            'principal' => '100000',
            'return_word' => 'interest',
            'opened_on' => now()->toDateString(),
            'matures_on' => now()->addMonths(2)->toDateString(),
            'funded_from_account_id' => app(CashTillService::class)->ensurePrimaryTill()->account_id,
        ]);
    }
}

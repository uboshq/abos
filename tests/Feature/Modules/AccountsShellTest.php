<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyTransferService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Accounts মডিউলের নিজের দুই পর্দা — ড্যাশবোর্ড ও সেটিংস।
 *
 * ড্যাশবোর্ডের প্রতিটা সংখ্যা ক্লিকযোগ্য হতে হবে (নিয়ম ১), আর সেটিংসের
 * সুইচগুলো module.php থেকে আসতে হবে (নিয়ম ৭) — দুইটাই এখানে যাচাই হয়।
 */
class AccountsShellTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        app(StandardChart::class)->install();
    }

    // ── ড্যাশবোর্ড ─────────────────────────────────────────────────────

    public function test_the_dashboard_opens_on_a_company_with_nothing_in_it_yet(): void
    {
        // নতুন কোম্পানিতে কোনো কাউন্টার, ভাউচার বা লেনদেন নেই — তবু
        // পর্দাটা খুলতে হবে। শূন্য দিয়ে ভাগ বা খালি অ্যারের প্রথম সদস্য
        // চাওয়া — দুইটাই এখানেই ধরা পড়ত।
        $this->get(route('accounts.dashboard'))->assertOk();
    }

    public function test_the_dashboard_shows_cash_in_hand_across_every_counter(): void
    {
        $tills = app(CashTillService::class);

        $main = $tills->ensurePrimaryTill();
        $rider = $tills->create(['code' => 'RIDER-A', 'name_en' => 'Rider A', 'name_bn' => 'রাইডার']);

        Account::query()->whereKey($main->account_id)
            ->update(['opening_balance' => '12000', 'opening_date' => '2026-07-01']);
        Account::query()->whereKey($rider->account_id)
            ->update(['opening_balance' => '3500', 'opening_date' => '2026-07-01']);

        $this->get(route('accounts.dashboard'))
            ->assertOk()
            // ১২,০০০ + ৩,৫০০
            ->assertSee('15,500.00')
            ->assertSee('RIDER-A');
    }

    /**
     * ড্যাশবোর্ডের প্রতিটা সংখ্যা কোথাও নিয়ে যায় — নিয়ম ১।
     *
     * যে সংখ্যায় ক্লিক করা যায় না সেটা ব্যবহারকারীকে বিশ্বাস করতে বলে,
     * যাচাই করতে দেয় না।
     */
    public function test_every_figure_on_the_dashboard_links_somewhere(): void
    {
        app(CashTillService::class)->ensurePrimaryTill();

        $html = $this->get(route('accounts.dashboard'))->assertOk()->getContent();

        foreach ([
            route('accounts.till.index'),
            route('accounts.report.show', ['slug' => 'bank-book']),
            route('accounts.report.show', ['slug' => 'ledger']),
        ] as $target) {
            // ক্যোয়ারি স্ট্রিং বাদ দিয়ে পথটুকু — ড্যাশবোর্ড তারিখের
            // পরিসরও জুড়ে দেয়, আর সেটা এখানে যাচাইয়ের বিষয় নয়
            $path = parse_url($target, PHP_URL_PATH);

            $this->assertStringContainsString($path, $html, "No link to {$path}.");
        }
    }

    public function test_the_dashboard_flags_drafts_and_transfers_nobody_finished(): void
    {
        $till = app(CashTillService::class)->ensurePrimaryTill();

        Account::query()->whereKey($till->account_id)
            ->update(['opening_balance' => '20000', 'opening_date' => '2026-07-01']);

        // একটা খসড়া ভাউচার — কোনো হিসাবে নেই
        $vouchers = app(VoucherService::class);
        $vouchers->create(
            ['type' => 'journal', 'trx_date' => now()->toDateString()],
            [
                ['account_id' => $till->account_id, 'debit' => '100', 'credit' => '0'],
                ['account_id' => StandardChart::find('5299')->id, 'debit' => '0', 'credit' => '100'],
            ],
        );

        // একটা হস্তান্তর, এখনো গ্রহণ হয়নি — টাকাটা এখনো দাতার হাতে
        $other = app(CashTillService::class)->create([
            'code' => 'RIDER-B', 'name_en' => 'Rider B', 'name_bn' => 'রাইডার বি',
        ]);

        app(MoneyTransferService::class)->initiate([
            'trx_date' => now()->toDateString(),
            'from_till_id' => $till->id,
            'to_till_id' => $other->id,
            'amount' => '500',
        ]);

        $this->get(route('accounts.dashboard'))
            ->assertOk()
            ->assertSee(__('accounts::message.needs_attention'), false)
            ->assertSee(trans_choice('accounts::message.draft_vouchers', 1, ['count' => 1]), false)
            ->assertSee(trans_choice('accounts::message.pending_transfers', 1, ['count' => 1]), false);

        $this->assertSame(1, Voucher::query()->draft()->count());
        $this->assertSame(1, MoneyTransfer::query()->pending()->count());
    }

    /**
     * এই মাসের আয় ধনাত্মক দেখায়।
     *
     * আয় ক্রেডিট প্রকৃতির, তাই কাঁচা যোগফল ঋণাত্মক আসে — "এই মাসের আয়
     * −২০,০০০" দেখানোর কোনো মানে নেই।
     */
    public function test_income_this_month_is_shown_as_a_positive_number(): void
    {
        $till = app(CashTillService::class)->ensurePrimaryTill();
        $vouchers = app(VoucherService::class);

        $vouchers->post($vouchers->create(
            ['type' => 'receipt', 'trx_date' => now()->toDateString()],
            $vouchers->twoLineEntry(
                'receipt',
                (int) StandardChart::find(StandardChart::SALES)->id,
                (int) $till->account_id,
                '20000.00',
            ),
        ));

        $this->get(route('accounts.dashboard'))
            ->assertOk()
            ->assertSee('20,000.00')
            ->assertDontSee('-20,000.00');
    }

    public function test_a_user_without_accounts_view_cannot_open_the_dashboard(): void
    {
        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($stranger)->get(route('accounts.dashboard'))->assertForbidden();
    }

    // ── সেটিংস ─────────────────────────────────────────────────────────

    /**
     * সুইচগুলো module.php থেকে আসে, পর্দায় হাতে লেখা নয় — নিয়ম ৭।
     *
     * এই পরীক্ষাটা তালিকাটা রেজিস্ট্রি থেকে নেয়, হাতে লেখা তালিকা থেকে
     * নয়: নতুন সুইচ যোগ করলে সেটাও এখানে ধরা পড়বে।
     */
    public function test_every_declared_switch_appears_on_the_settings_screen(): void
    {
        $declared = collect(app(SettingsService::class)->definitions())
            ->filter(fn (array $d) => ($d['module'] ?? null) === 'accounts');

        $this->assertGreaterThan(0, $declared->count(), 'Accounts declares no settings at all.');

        $response = $this->get(route('accounts.settings'))->assertOk();

        foreach ($declared as $key => $definition) {
            $response->assertSee("settings[{$key}]", false);
            $response->assertSee(__($definition['label']), false);
        }
    }

    public function test_saving_a_switch_changes_what_the_settings_service_returns(): void
    {
        $settings = app(SettingsService::class);

        $this->assertTrue($settings->enabled('accounts.require_narration'));

        $this->put(route('accounts.settings.update'), [
            'settings' => [
                // চেকবক্স তুলে নেওয়া মানে ব্রাউজার কিছুই পাঠায় না —
                // তাই require_narration এখানে অনুপস্থিত
                'accounts.backdate_days' => 30,
                'accounts.cash_ceiling_enabled' => '1',
            ],
        ])->assertRedirect();

        $settings->flush();

        $this->assertSame(30, $settings->get('accounts.backdate_days'));
        $this->assertFalse($settings->enabled('accounts.require_narration'));
        $this->assertTrue($settings->enabled('accounts.cash_ceiling_enabled'));
    }

    /**
     * একটা কোম্পানির সেটিং অন্যটার নয়।
     *
     * সেটিংস কোম্পানি-ভিত্তিক না হলে এক ডিপোতে ভ্যাট চালু করলে অন্য
     * ডিপোতেও চালু হয়ে যেত — আর সেটা কেউ খেয়ালও করত না।
     */
    public function test_settings_do_not_leak_between_companies(): void
    {
        $settings = app(SettingsService::class);

        $settings->set('accounts.backdate_days', 45);

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);
        $settings->flush();

        // অন্য কোম্পানিতে ঘোষিত ডিফল্টটাই থাকে
        $this->assertSame(7, $settings->get('accounts.backdate_days'));
    }

    public function test_only_someone_who_can_manage_accounts_may_change_settings(): void
    {
        $clerk = User::factory()->create();
        $clerk->companies()->attach($this->company, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();
        $clerk->givePermissionTo(Permission::findOrCreate('accounts.view', 'web'));

        $this->actingAs($clerk);

        $this->get(route('accounts.settings'))->assertForbidden();
        $this->put(route('accounts.settings.update'), ['settings' => []])->assertForbidden();
    }
}

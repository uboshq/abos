<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

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
 * সংখ্যাগুলো নিষ্প্রাণ লেখা হয়ে বসে ছিল।
 *
 * ── ⓘ মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * *"sob jaygay hyper link dewar kotha but notun kaje kotaw hyperlink
 * dicche na"* — নতুন পর্দাগুলোয় নথির নম্বর, নাম আর গোনা সবই কেবল লেখা।
 *
 * ── ⚠️ নিয়মটা ─────────────────────────────────────────────────────────
 * ঘরে যদি কোনো **নথি, মানুষ, খাত বা গোনা** থাকে, ঘরটা সেটাই খোলে।
 * ⛔ আর যেখানে সত্যিই যাওয়ার জায়গা নেই, সেখানে লিংক নয় — ফাঁকা তালিকায়
 * নামানো লেখার চেয়েও খারাপ।
 */
final class TheNumbersWereDeadTextTest extends TestCase
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
        app(DepositKindInstaller::class)->install();
    }

    /**
     * ⭐ জমার নম্বরে ক্লিক করলে জমাটাই খোলে।
     */
    public function test_the_deposit_number_opens_the_deposit(): void
    {
        $deposit = $this->openADeposit();

        $this->get(route('finance.deposit.index', ['issuer' => 'bank']))
            ->assertOk()
            ->assertSee(route('finance.deposit.show', [
                'issuer' => 'bank',
                'deposit' => $deposit->id,
            ]), escape: false);
    }

    /**
     * ⭐ "মেয়াদ আসছে" টালির সংখ্যাটা তার নিজের তালিকায় নামে।
     */
    public function test_the_maturing_tile_lands_on_its_own_list(): void
    {
        $this->openADeposit();

        $this->get(route('finance.deposit.index', ['issuer' => 'bank']))
            ->assertOk()
            ->assertSee(route('finance.deposit.index', [
                'issuer' => 'bank',
                'tab' => 'maturing',
            ]), escape: false);
    }

    /**
     * ⭐ ধরনের তালিকায় "কয়টায় ব্যবহৃত" সংখ্যাটা ঐ ধরনের জমাগুলোয় নামে।
     *
     * ⚠️ আর নামে **সব জমা**র পাতায়, ইস্যুকারীর পাতায় নয়: সংখ্যাটা সব
     * অবস্থার জমা গোনে, আর ইস্যুকারীর পাতা অবস্থার ট্যাবে ছাঁকা।
     */
    public function test_the_used_count_lands_on_that_kinds_deposits(): void
    {
        $deposit = $this->openADeposit();

        $this->get(route('finance.deposit_kind.index'))
            ->assertOk()
            ->assertSee(route('finance.deposit.all', ['kind' => $deposit->kind_id]), escape: false);

        // ⓘ আর ছাঁকনিটা সত্যিই ছাঁকে — অন্য ধরনের কাগজ ওখানে থাকে না
        $other = DepositKind::query()
            ->where('id', '!=', $deposit->kind_id)
            ->firstOrFail();

        $this->get(route('finance.deposit.all', ['kind' => $other->id]))
            ->assertOk()
            ->assertDontSee($deposit->document_no);

        $this->get(route('finance.deposit.all', ['kind' => $deposit->kind_id]))
            ->assertOk()
            ->assertSee($deposit->document_no);
    }

    /**
     * ⛔ শূন্য হলে লিংক নয় — ফাঁকা তালিকায় নামানোর চেয়ে চুপ থাকা ভালো।
     */
    public function test_a_kind_nobody_used_is_not_a_link(): void
    {
        $unused = DepositKind::query()->where('code', 'DPS')->firstOrFail();

        $this->get(route('finance.deposit_kind.index'))
            ->assertOk()
            ->assertDontSee(route('finance.deposit.all', ['kind' => $unused->id]), escape: false);
    }

    private function openADeposit(): Deposit
    {
        $kind = DepositKind::query()->where('code', 'FDR')->firstOrFail();

        return app(DepositService::class)->open([
            'kind_id' => $kind->id,
            'institution' => 'সোনালী ব্যাংক',
            'held_by' => Deposit::BUSINESS,
            'principal' => '100000',
            'return_word' => 'interest',
            'opened_on' => now()->toDateString(),
            'matures_on' => now()->addDays(20)->toDateString(),
            'funded_from_account_id' => app(CashTillService::class)->ensurePrimaryTill()->account_id,
        ]);
    }
}

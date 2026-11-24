<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Services\DepositClaimService;
use App\Modules\Sales\Services\DepositMatchService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ এক ব্যাংক-সারি, কয়েকটা জমার খবর — মালিক, ২১ নভেম্বর ২০২৬ (আন্তর্জাতিক one-to-many reconciliation)।
 *
 * ⓘ লাইভে: ব্যাংকে এক সারি ৳১,০০,০০০, রেফারেন্স 175300556 — দুই ডিলারের ৳৫০,০০০ করে দুই খবর।
 *
 * দাবি:
 *   ১. এক সারি দুই খবরে — চলে (মোট হুবহু); দুইটাই "যাচাই চলছে"
 *   ২. মোট না মিললে — থামে, কোনো খবর মেলে না
 *   ৩. একই খবর দুইবার — থামে; ভরা সারিতে বাড়তি খবর যা মোট ভাঙে — থামে
 *   ৪. মিল খোলায় দলের সব খোলা খবর ছাড়া পায়; একজন ফেরত গেলেও বাকিরা ছাড়া পায়
 *   ৫. দলের প্রস্তাব — একই রেফারেন্স, মোট হুবহু; মোট না মিললে প্রস্তাব নেই
 *   ৬. "অনুমোদন দিন" — দলের প্রতিটা খবরের রসিদ তার নিজের অঙ্কে, সারির পুরো অঙ্কে নয়
 *   ৭. পুরনো এক-এক মিল টিকে থাকে; unique সূচি গেছে, সাধারণ সূচি আছে
 *   ৮. পর্দা: দলের প্রস্তাব আর টিকের ফর্ম দেখায়; টিক দিয়ে মেলানো যায়
 */
final class OneBankLineHoldsSeveralDepositReportsTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = '175300556';

    private const DAY = '2026-11-16';

    private Company $company;

    private User $clerk;

    private Account $bank;

    private Customer $dealerA;

    private Customer $dealerB;

    protected function setUp(): void
    {
        parent::setUp();
        // ⓘ দাবিগুলোর তারিখ ১৬ নভেম্বর ২০২৬ হাতে লেখা — ঘড়ি স্থির, নইলে পেছনের তারিখের ৭ দিনের জানালা পেরোলেই সব লাল ([[OpenPeriod]])
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-20 10:00:00'));
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->clerk->givePermissionTo(['sales.claim.view', 'sales.claim.decide', 'sales.claim.match']);

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-SHARED', 'name_en' => 'Shared bank', 'name_bn' => 'Shared bank',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);

        $customers = Customer::query()->orderBy('id')->take(2)->get();
        $this->assertCount(2, $customers, 'প্রস্তুতিটাই ভুল — দুইজন গ্রাহক নেই');
        [$this->dealerA, $this->dealerB] = [$customers[0], $customers[1]];
        foreach ([$this->dealerA, $this->dealerB] as $dealer) {
            $dealer->forceFill(['branch_id' => $this->company->defaultBranch()?->id])->save();
        }

        // ⓘ [[TheBankStatementHasTheFinalWordTest]]-এর একই প্রস্তুতি — রসিদের ছক বন্ধ, লেখক ≠ পাকাকারী বন্ধ
        ApprovalFlow::query()->where('module', VoucherApproval::MODULE)->where('action', Voucher::RECEIPT)->update(['is_active' => false]);
        ApprovalEngine::flowsChanged();
        app(\App\Core\Services\SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);

        $this->actingAs($this->clerk->fresh());
    }

    /** ১ */
    public function test_one_bank_line_is_matched_to_two_reports(): void
    {
        $a = $this->claim($this->dealerA, '50000');
        $b = $this->claim($this->dealerB, '50000');
        $line = $this->line('100000');

        app(DepositMatchService::class)->matchMany($line, [$a, $b]);

        foreach ([$a, $b] as $claim) {
            $this->assertSame((int) $line->id, (int) $claim->fresh()->statement_line_id, '⛔ এক সারি দুই খবরে মিলল না');
            $this->assertSame(DepositClaim::VERIFYING, $claim->fresh()->status);
        }
        $this->assertSame(2, DepositMatchService::reportsOnLine((int) $line->id));
        $this->assertNotContains((int) $line->id, app(DepositMatchService::class)->freeLines()->pluck('id')->map(fn ($i) => (int) $i)->all(),
            '⛔ মেলা সারি আবার খালি তালিকায়');
    }

    /** ২ */
    public function test_a_total_that_does_not_equal_the_line_is_refused(): void
    {
        $a = $this->claim($this->dealerA, '50000');
        $b = $this->claim($this->dealerB, '40000');
        $line = $this->line('100000');

        $this->assertSame('line', $this->refusedField(fn () => app(DepositMatchService::class)->matchMany($line, [$a, $b])),
            '⛔ ৯০,০০০-এর দুই খবর ১,০০,০০০-এর সারিতে মিলে গেল');
        $this->assertNull($a->fresh()->statement_line_id, '⛔ থামার পরেও একটা খবর মেলা রইল');
        $this->assertNull($b->fresh()->statement_line_id);
    }

    /** ৩ */
    public function test_a_report_is_matched_only_once(): void
    {
        $a = $this->claim($this->dealerA, '50000');
        $b = $this->claim($this->dealerB, '50000');
        $line = $this->line('100000');
        $spare = $this->line('50000', 'SPARE-1');
        $service = app(DepositMatchService::class);

        $service->matchMany($line, [$a, $b]);

        $this->assertSame('claim', $this->refusedField(fn () => $service->matchMany($spare, [$a->fresh()])),
            '⛔ একই খবর দ্বিতীয় সারিতেও মিলল');
        $this->assertSame('claim', $this->refusedField(fn () => $service->match($a->fresh(), $line->fresh())),
            '⛔ একই খবর একই সারিতে দুইবার মিলল');

        // ⓘ ভরা সারিতে তৃতীয় খবর — মোট ভাঙে, থামে
        $c = $this->claim($this->dealerA, '1000', 'EXTRA-1');
        $this->assertSame('line', $this->refusedField(fn () => $service->match($c, $line->fresh())),
            '⛔ ভরা সারিতে বাড়তি খবর বসল — খাতায় ব্যাংকের চেয়ে বেশি টাকা');
    }

    /** ৪ */
    public function test_unmatch_releases_every_report_of_the_line(): void
    {
        $a = $this->claim($this->dealerA, '50000');
        $b = $this->claim($this->dealerB, '50000');
        $line = $this->line('100000');
        $service = app(DepositMatchService::class);
        $service->matchMany($line, [$a, $b]);

        $service->unmatch($a->fresh(), 'ভুল সারি');

        $this->assertNull($a->fresh()->statement_line_id, '⛔ মিল খোলা খবর ছাড়া পেল না');
        $this->assertNull($b->fresh()->statement_line_id, '⛔ দলের অন্য খবর সারিতে আটকে রইল — বাকিদের মোট আর সারির অঙ্ক মেলে না');
        $this->assertSame('ভুল সারি', $b->fresh()->unmatch_reason);
        $this->assertContains((int) $line->id, $service->freeLines()->pluck('id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_returning_one_report_releases_the_others_of_the_line(): void
    {
        $a = $this->claim($this->dealerA, '50000');
        $b = $this->claim($this->dealerB, '50000');
        $line = $this->line('100000');
        app(DepositMatchService::class)->matchMany($line, [$a, $b]);

        app(DepositClaimService::class)->reject($a->fresh(), 'ভুল দোকান');

        $this->assertSame(DepositClaim::REJECTED, $a->fresh()->status);
        $this->assertNull($b->fresh()->statement_line_id, '⛔ একজন ফেরত গেল, অন্যজন অর্ধেক সারিতে আটকে রইল');
        $this->assertTrue($b->fresh()->isOpen());
    }

    /** ৫ */
    public function test_reports_sharing_the_line_reference_are_suggested_as_one_group(): void
    {
        $a = $this->claim($this->dealerA, '50000');
        $b = $this->claim($this->dealerB, '50000');
        $line = $this->line('100000');

        $groups = app(DepositMatchService::class)->groupSuggestions();

        $this->assertCount(1, $groups, '⛔ একই রেফারেন্সের দুই খবরের দল প্রস্তাবে এল না');
        $this->assertSame((int) $line->id, (int) $groups[0]['line']->id);
        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id], $groups[0]['claims']->pluck('id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_no_group_is_suggested_when_the_total_differs(): void
    {
        $this->claim($this->dealerA, '50000');
        $this->claim($this->dealerB, '45000');
        $this->line('100000');

        $this->assertSame([], app(DepositMatchService::class)->groupSuggestions(), '⛔ মোট না মিলেও দলের প্রস্তাব এল');
    }

    /** ৬ */
    public function test_each_report_of_a_shared_line_is_booked_at_its_own_amount(): void
    {
        $a = $this->claim($this->dealerA, '50000');
        $b = $this->claim($this->dealerB, '50000');
        $line = $this->line('100000');
        app(DepositMatchService::class)->matchMany($line, [$a, $b]);

        app(DepositClaimService::class)->accept($a->fresh(), null);
        app(DepositClaimService::class)->accept($b->fresh(), null);

        foreach ([$a, $b] as $claim) {
            $voucher = Voucher::query()->withoutGlobalScopes()->findOrFail($claim->fresh()->voucher_id);
            $this->assertTrue($voucher->isPosted(), '⛔ ভাগের খবরের রসিদ পাকা হলো না');
            $this->assertSame(0, bccomp('50000', (string) $voucher->amount, 4), '⛔ ভাগের খবরের রসিদ সারির পুরো অঙ্কে বসল — একই টাকা দুইবার');
        }
    }

    /** ৭ */
    public function test_an_old_one_to_one_match_survives_the_migration(): void
    {
        $claim = $this->claim($this->dealerA, '9000', 'OLD-1');
        $line = $this->line('9000', 'OLD-1');
        app(DepositMatchService::class)->match($claim, $line);

        $this->assertSame((int) $line->id, (int) $claim->fresh()->statement_line_id, '⛔ পুরনো এক-এক মিল টিকল না');
        // ⓘ একা খবর, অঙ্ক আলাদা — আগের নিয়ম বহাল (ব্যাংক চার্জ)
        $short = $this->claim($this->dealerB, '20000', 'OLD-2');
        $charged = $this->line('19950', 'OLD-2');
        app(DepositMatchService::class)->match($short, $charged);
        $this->assertSame((int) $charged->id, (int) $short->fresh()->statement_line_id, '⛔ এক-এক মিলে চার্জের ছাড় হারাল');

        $indexes = collect(Schema::getIndexes('sal_deposit_claims'))->keyBy('name');
        $this->assertFalse($indexes->has('sal_claim_stmt_line_once'), '⛔ এক সারি এক খবরের unique সূচি রয়ে গেল');
        $this->assertTrue($indexes->has('sal_claim_stmt_line'), 'খোঁজার সাধারণ সূচি নেই');
    }

    /** ৮ */
    public function test_the_screen_shows_the_group_and_ticks_several_reports(): void
    {
        $a = $this->claim($this->dealerA, '50000');
        $b = $this->claim($this->dealerB, '50000');
        $line = $this->line('100000');

        $this->get(route('sales.claim.match.index'))->assertOk()
            ->assertSee('data-group-suggestions', false)
            ->assertSee('data-match-many="'.$line->id.'"', false)
            ->assertSee('data-match-total', false);

        $this->post(route('sales.claim.match.many', $line->id), ['claims' => [$a->id, $b->id]])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame((int) $line->id, (int) $a->fresh()->statement_line_id);
        $this->assertSame((int) $line->id, (int) $b->fresh()->statement_line_id);

        $this->get(route('sales.claim.match.index'))->assertOk()->assertSee('data-shared-line="2"', false);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function claim(Customer $dealer, string $amount, string $reference = self::NUMBER): DepositClaim
    {
        return app(DepositClaimService::class)->raise($dealer, [
            'claimed_on' => self::DAY, 'amount' => $amount, 'method' => DepositClaim::BANK, 'reference' => $reference,
            'bank_account_id' => $this->bank->id,
        ]);
    }

    private function line(string $amount, string $reference = self::NUMBER): BankStatementLine
    {
        return BankStatementLine::query()->create([
            'company_id' => $this->company->id, 'bank_account_id' => $this->bank->id, 'trx_date' => self::DAY,
            'description' => 'BEFTN '.$reference, 'reference' => $reference, 'debit' => '0', 'credit' => $amount,
            'fingerprint' => hash('sha256', $this->bank->id.$amount.$reference.microtime()),
        ]);
    }

    private function refusedField(\Closure $do): ?string
    {
        try {
            $do();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        return null;
    }
}

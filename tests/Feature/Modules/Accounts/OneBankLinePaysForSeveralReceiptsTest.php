<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Models\BankStatementMatch;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use App\Modules\Accounts\Services\BankStatementService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ এক ব্যাংক-সারি, কয়েকটা রসিদ — মালিক, ২১ নভেম্বর ২০২৬ (আন্তর্জাতিক one-to-many reconciliation)।
 *
 * ⓘ লাইভে: ব্যাংকে এক সারি ৳১,০০,০০০, রেফারেন্স 175300556 — দুই ডিলারের ৳৫০,০০০ করে, RCV-0537 আর RCV-0538।
 *
 * দাবি:
 *   ১. এক সারি দুই রসিদে — চলে, মোট হুবহু; দুই খাতার সারিই মিলের টেবিলে, সারির চিহ্নে প্রথমটা
 *   ২. মোট না মিললে — থামে, কিছুই লেখা হয় না
 *   ৩. একই রসিদ দুই সারিতে — থামে (প্রতিটা কাগজ একবার)
 *   ৪. মিল খোলায় দলের সবগুলো ছাড়া পায়
 *   ৫. খসড়া রসিদ গোনা হয় না (কেবল পাকা কাগজ)
 *   ৬. নিজে থেকে মেলানো: রেফারেন্স = কয়েকটা রসিদের লেনদেন নম্বর, মোট হুবহু → পুরো দল; মোট না মিললে কিছুই নয়
 *   ৭. পুরনো এক-এক মিল মাইগ্রেশনে টিকে থাকে — মিলের টেবিলে এক সারি, আবার চালালেও দ্বিগুণ নয়
 *   ৮. পর্দা: টিক দিয়ে মেলানো আর মিল খোলা
 */
final class OneBankLinePaysForSeveralReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = '175300556';

    private const DAY = '2026-08-10';

    private Company $company;

    private User $owner;

    private Account $bank;

    private Customer $dealerA;

    private Customer $dealerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-MANY', 'name_en' => 'Many bank', 'name_bn' => 'Many bank',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);

        $customers = Customer::query()->orderBy('id')->take(2)->get();
        $this->assertCount(2, $customers, 'প্রস্তুতিটাই ভুল — দুইজন গ্রাহক নেই');
        [$this->dealerA, $this->dealerB] = [$customers[0], $customers[1]];
    }

    /** ১ */
    public function test_one_bank_line_is_matched_to_two_receipts(): void
    {
        $a = $this->bankSide($this->posted($this->dealerA));
        $b = $this->bankSide($this->posted($this->dealerB));
        $line = $this->line('100000');

        app(BankStatementService::class)->matchBooks($line, [$a->id, $b->id]);

        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id],
            BankStatementMatch::query()->where('statement_line_id', $line->id)->pluck('voucher_line_id')->map(fn ($i) => (int) $i)->all(),
            '⛔ এক সারি দুই রসিদে মিলল না');
        $this->assertSame(0, bccomp('100000', (string) BankStatementMatch::query()->where('statement_line_id', $line->id)->sum('amount'), 4),
            '⛔ দলের মোট ব্যাংক-সারির অঙ্ক নয়');
        $this->assertSame((int) $a->id, (int) $line->fresh()->matched_line_id, 'সারির "মিলেছে" চিহ্নে দলের প্রথমটা নেই');
        $this->assertTrue(BankStatementMatch::holdsAnyOf([$b->id]), '⛔ দ্বিতীয় রসিদ মেলা বলে ধরা পড়ে না — বাতিল ঠেকবে না');
    }

    /** ২ */
    public function test_a_total_that_does_not_equal_the_line_is_refused(): void
    {
        $a = $this->bankSide($this->posted($this->dealerA));
        $b = $this->bankSide($this->posted($this->dealerB, amount: '40000.00'));
        $line = $this->line('100000');

        $this->assertSame('lines', $this->refusedField(fn () => app(BankStatementService::class)->matchBooks($line, [$a->id, $b->id])),
            '⛔ ৯০,০০০-এর দুই রসিদ ১,০০,০০০-এর সারিতে মিলে গেল');
        $this->assertSame(0, BankStatementMatch::query()->count(), '⛔ থামার পরেও মিলের সারি বসে রইল');
        $this->assertNull($line->fresh()->matched_line_id);
    }

    /** ৩ */
    public function test_a_receipt_is_matched_only_once(): void
    {
        $a = $this->bankSide($this->posted($this->dealerA));
        $b = $this->bankSide($this->posted($this->dealerB));
        $first = $this->line('100000');
        $second = $this->line('50000', 'OTHER-REF');

        app(BankStatementService::class)->matchBooks($first, [$a->id, $b->id]);

        $this->assertSame('lines', $this->refusedField(fn () => app(BankStatementService::class)->matchBooks($second, [$a->id])),
            '⛔ একই রসিদ দ্বিতীয় ব্যাংক-সারিতেও মিলল — একই টাকা দুইবার');
        $this->assertSame('line', $this->refusedField(fn () => app(BankStatementService::class)->matchBooks($first->fresh(), [$a->id])),
            '⛔ মেলা সারি আবার মেলানো গেল');
        $this->assertSame(1, BankStatementMatch::query()->where('voucher_line_id', $a->id)->count());
    }

    /** ৪ */
    public function test_unmatch_releases_every_receipt_of_the_line(): void
    {
        $a = $this->bankSide($this->posted($this->dealerA));
        $b = $this->bankSide($this->posted($this->dealerB));
        $line = $this->line('100000');
        $service = app(BankStatementService::class);
        $service->matchBooks($line, [$a->id, $b->id]);

        $service->unmatchBooks($line->fresh());

        $this->assertSame(0, BankStatementMatch::query()->where('statement_line_id', $line->id)->count(), '⛔ মিল খোলায় দলের সবগুলো ছাড়া পেল না');
        $this->assertNull($line->fresh()->matched_line_id);
        $this->assertFalse(BankStatementMatch::holdsAnyOf([$a->id, $b->id]));

        // ⓘ ছাড়া পাওয়া কাগজ আবার মেলানো যায়
        $service->matchBooks($line->fresh(), [$a->id, $b->id]);
        $this->assertSame(2, BankStatementMatch::query()->where('statement_line_id', $line->id)->count());
    }

    /** ৫ */
    public function test_a_draft_receipt_does_not_count(): void
    {
        $a = $this->bankSide($this->posted($this->dealerA));
        $draft = $this->bankSide($this->receipt($this->dealerB));
        $line = $this->line('100000');

        $this->assertSame('lines', $this->refusedField(fn () => app(BankStatementService::class)->matchBooks($line, [$a->id, $draft->id])),
            '⛔ খসড়া রসিদ ব্যাংকের টাকার ভাগ হিসেবে গোনা হলো');
    }

    /** ৬ */
    public function test_matching_on_its_own_proposes_the_whole_group_by_reference(): void
    {
        $a = $this->bankSide($this->posted($this->dealerA));
        $b = $this->bankSide($this->posted($this->dealerB));
        $line = $this->line('100000');

        $this->assertSame(1, app(BankStatementService::class)->matchAgainstBooks($this->bank, '2026-08-31'), '⛔ একই নম্বরের দুই রসিদ নিজে থেকে মিলল না');
        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id],
            BankStatementMatch::query()->where('statement_line_id', $line->id)->pluck('voucher_line_id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_matching_on_its_own_leaves_a_group_whose_total_differs(): void
    {
        $this->posted($this->dealerA);
        $this->posted($this->dealerB, amount: '40000.00');
        $line = $this->line('100000');

        $this->assertSame(0, app(BankStatementService::class)->matchAgainstBooks($this->bank, '2026-08-31'), '⛔ মোট না মিলেও দল বসল');
        $this->assertNull($line->fresh()->matched_line_id);
    }

    /** ৭ */
    public function test_an_old_one_to_one_match_survives_the_migration(): void
    {
        $a = $this->bankSide($this->posted($this->dealerA));
        $line = $this->line('50000');
        // ⓘ মাইগ্রেশনের আগের ছক — কেবল সারির ঘরে, মিলের টেবিলে কিছু নেই
        $line->forceFill(['matched_line_id' => $a->id, 'matched_at' => now(), 'matched_by' => $this->owner->id])->save();
        $this->assertSame(0, BankStatementMatch::query()->count());

        $migration = require base_path('app/Modules/Accounts/Database/Migrations/2027_05_03_100000_one_bank_line_can_pay_for_several_papers.php');
        $migration->up();

        $moved = BankStatementMatch::query()->where('statement_line_id', $line->id)->get();
        $this->assertCount(1, $moved, '⛔ পুরনো এক-এক মিল মিলের টেবিলে উঠল না');
        $this->assertSame((int) $a->id, (int) $moved->first()->voucher_line_id);
        $this->assertSame(0, bccomp('50000', (string) $moved->first()->amount, 4), 'মিলের অঙ্ক খাতার সারির অঙ্ক নয়');
        $this->assertSame((int) $a->id, (int) $line->fresh()->matched_line_id, '⛔ সারির পুরনো চিহ্ন মুছে গেল');

        $migration->up();
        $this->assertSame(1, BankStatementMatch::query()->where('statement_line_id', $line->id)->count(), '⛔ আবার চালালে মিল দ্বিগুণ হলো');

        // ⓘ টিকে থাকা মিল এখনো বাতিল ঠেকায়
        $this->assertTrue(BankStatementMatch::holdsAnyOf([$a->id]));
    }

    /** ৮ */
    public function test_the_screen_ticks_several_receipts_and_unmatches_them(): void
    {
        $a = $this->bankSide($this->posted($this->dealerA));
        $b = $this->bankSide($this->posted($this->dealerB));
        $line = $this->line('100000');
        $recon = BankReconciliation::query()->create([
            'company_id' => $this->company->id, 'bank_account_id' => $this->bank->id,
            'statement_date' => '2026-08-31', 'statement_balance' => '100000', 'status' => BankReconciliation::DRAFT,
        ]);

        $this->get(route('accounts.reconciliation.show', $recon))->assertOk()
            ->assertSee('data-match-papers="'.$line->id.'"', false)
            ->assertSee('data-match-total', false);

        $this->post(route('accounts.reconciliation.match_books', [$recon, $line->id]), ['lines' => [$a->id, $b->id]])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, BankStatementMatch::query()->where('statement_line_id', $line->id)->count());

        $this->get(route('accounts.reconciliation.show', $recon))->assertOk()
            ->assertSee('data-matched-papers="'.$line->id.'"', false);

        $this->post(route('accounts.reconciliation.unmatch_books', [$recon, $line->id]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0, BankStatementMatch::query()->count());
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function service(): VoucherService
    {
        return app(VoucherService::class);
    }

    private function posted(Customer $party, string $amount = '50000.00'): Voucher
    {
        return $this->service()->post($this->receipt($party, $amount));
    }

    private function receipt(Customer $party, string $amount = '50000.00'): Voucher
    {
        $receivable = (int) StandardChart::accountFor(StandardChart::RECEIVABLE)->id;

        return $this->service()->create(
            [
                'type' => Voucher::RECEIPT, 'trx_date' => self::DAY, 'narration' => 'জমার ভাগ',
                'party_type' => 'customer', 'party_id' => $party->id,
                'instrument' => 'transfer', 'instrument_no' => self::NUMBER,
            ],
            $this->service()->twoLineEntry(Voucher::RECEIPT, $receivable, (int) $this->bank->id, $amount, 'জমার ভাগ'),
        );
    }

    /** রসিদের ব্যাংকের সারি — ব্যাংকে টাকা এল, খাতায় ডেবিট */
    private function bankSide(Voucher $voucher): VoucherLine
    {
        return VoucherLine::query()->where('voucher_id', $voucher->id)->where('account_id', $this->bank->id)->firstOrFail();
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

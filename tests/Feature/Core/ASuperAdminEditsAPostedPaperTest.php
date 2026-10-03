<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\PaperTrail;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\RevisionKeeper;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Models\DocumentRevision;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * সুপার অ্যাডমিন পোস্ট হওয়া কাগজ সংশোধন করেন — মাস বন্ধের আগ পর্যন্ত (মালিক, ৩ অক্টোবর ২০২৬)।
 *
 * *"মাস ক্লোজ না হওয়া পর্যন্ত সুপার অ্যাডমিন সব পোস্টেড কাগজ এডিট করতে পারবেন। ক্লোজের পরে নয়,
 * যদি না মাসটা খুলে দেন। প্রত্যেক এডিটের আগে-পরে থাকবে। নম্বর একই, খাতা উল্টে আবার বসবে।"*
 *
 * ⓘ কোর ([[PostedEdit]] · [[RevisionKeeper]] · [[DocumentRevision]] · [[x-ui.revisions]]) এখানে
 * যাচাই হয় একটা আসল কাগজ দিয়ে — জাবেদা ভাউচার, [[VoucherService::editPosted()]] পথে।
 *
 * ⚠️ দরজার প্রতিটা দাবি একই মানুষকে দিয়ে দুইবার: চাবি ছাড়া থামেন, চাবি পেলে ঢোকেন — নাহলে
 * "থামলেন" প্রমাণ করত কেবল যে দুইজন আলাদা মানুষ।
 */
class ASuperAdminEditsAPostedPaperTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        app(StandardChart::class)->install();
    }

    // ── সহায়ক ────────────────────────────────────────────────────────────

    private function receivable(): int
    {
        return (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
    }

    private function payable(): int
    {
        return (int) StandardChart::find(StandardChart::PAYABLE)->id;
    }

    /** মালিকের হাতে পোস্ট হওয়া একটা জাবেদা — দেওয়া তারিখে, দেওয়া অঙ্কে। */
    private function journal(Carbon|string $date, string $amount = '500'): Voucher
    {
        $this->actingAs($this->owner);

        $voucher = app(VoucherService::class)->create(
            [
                'type' => Voucher::JOURNAL,
                'trx_date' => $date instanceof Carbon ? $date->toDateString() : $date,
                'narration' => 'আসল বিবরণ',
            ],
            [
                ['account_id' => $this->receivable(), 'debit' => $amount],
                ['account_id' => $this->payable(), 'credit' => $amount],
            ],
        );

        return app(VoucherService::class)->post($voucher);
    }

    private function revise(Voucher $voucher, User $user, string $amount, string $reason = 'অঙ্ক ভুল লেখা হয়েছিল'): DocumentRevision
    {
        $this->actingAs($user);

        return app(VoucherService::class)->editPosted(
            Voucher::query()->findOrFail($voucher->id),
            $user,
            $reason,
            ['trx_date' => $voucher->trx_date->toDateString(), 'narration' => 'সংশোধিত বিবরণ'],
            [
                ['account_id' => $this->receivable(), 'debit' => $amount],
                ['account_id' => $this->payable(), 'credit' => $amount],
            ],
        );
    }

    /** এই ভাউচারের খাতায় একটা খাতের নিট (ডেবিট − ক্রেডিট) — মূল আর উল্টো দুই নামেই। */
    private function net(Voucher $voucher, int $accountId): string
    {
        $type = Voucher::SOURCE_TYPES[$voucher->type];

        return LedgerEntry::query()
            ->whereIn('source_type', [$type, $type.':reversal'])
            ->where('source_id', $voucher->id)
            ->where('account_id', $accountId)
            ->get()
            ->reduce(fn ($c, $e) => bcadd($c, bcsub((string) $e->debit, (string) $e->credit, 4), 4), '0');
    }

    private function rowsOf(Voucher $voucher): int
    {
        $type = Voucher::SOURCE_TYPES[$voucher->type];

        return LedgerEntry::query()->whereIn('source_type', [$type, $type.':reversal'])->where('source_id', $voucher->id)->count();
    }

    private function revisionsOf(Voucher $voucher): int
    {
        return DocumentRevision::query()->forDocument($voucher)->count();
    }

    /** থামল তো — আর ঠিক এই বার্তায়। */
    private function assertRefused(callable $attempt, string $field, string $message): void
    {
        try {
            $attempt();
            $this->fail('⛔ থামার কথা ছিল, অথচ সংশোধন হয়ে গেল: '.$message);
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->errors()[$field][0] ?? null, json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }

    private function lastMonthDay(): Carbon
    {
        return Carbon::today()->subMonthNoOverflow()->startOfMonth()->addDays(4);
    }

    private function closeMonth(Carbon $date): PeriodLock
    {
        return PeriodLock::query()->create([
            'company_id' => $this->company->id,
            'year' => (int) $date->year,
            'month' => (int) $date->month,
            'reason' => 'রিপোর্ট পাঠানো হয়ে গেছে',
            'locked_by' => $this->owner->id,
            'locked_at' => now(),
        ]);
    }

    /** সারির অঙ্ক সরাসরি বদলানো — কোরের সাধারণ পথ ডাকনেওয়ালার নিজের বদল নিয়ে। */
    private function setLineAmounts(Voucher $voucher, string $debit, string $credit): void
    {
        foreach ($voucher->lines()->get() as $line) {
            /** @var VoucherLine $line */
            $isDebit = bccomp((string) $line->debit, '0', 4) > 0;
            $line->forceFill(['debit' => $isDebit ? $debit : '0', 'credit' => $isDebit ? '0' : $credit])->save();
        }
    }

    // ── দাবি ─────────────────────────────────────────────────────────────

    /**
     * খোলা মাসে সুপার অ্যাডমিন সংশোধন করেন — নম্বর একই, খাতা উল্টে নতুন অঙ্কে বসে, একটা সংশোধনের সারি।
     */
    public function test_the_super_admin_edits_a_posted_voucher_and_the_books_follow(): void
    {
        $voucher = $this->journal(Carbon::today());
        $number = $voucher->document_no;

        $revision = $this->revise($voucher, $this->owner, '750');

        $fresh = $voucher->fresh();
        $this->assertSame($number, $fresh->document_no, 'সংশোধনে নম্বর বদলে গেছে।');
        $this->assertSame(DocumentStatus::CONFIRMED, $fresh->status);
        $this->assertSame(0, bccomp((string) $fresh->amount, '750', 4));

        // ⭐ খাতা: পুরনো ৫০০ উল্টো, নতুন ৭৫০ বসেছে — নিট ঠিক ৭৫০, দ্বিগুণ নয়
        $this->assertSame('750.0000', $this->net($voucher, $this->receivable()));
        $this->assertSame('-750.0000', $this->net($voucher, $this->payable()));
        $this->assertSame(2, LedgerEntry::query()->where('source_type', 'journal_voucher:reversal')->where('source_id', $voucher->id)->count());
        $this->assertSame(
            [$voucher->trx_date->toDateString()],
            LedgerEntry::query()->where('source_type', 'journal_voucher:reversal')->where('source_id', $voucher->id)
                ->pluck('trx_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values()->all(),
            'উল্টো সারি কাগজের নিজের তারিখে বসেনি।',
        );

        $live = app(RevisionKeeper::class)->openLedgerRows('journal_voucher', $voucher->id);
        $this->assertCount(2, $live);
        $this->assertSame($number, LedgerEntry::query()->where('source_type', 'journal_voucher')->where('source_id', $voucher->id)->latest('id')->value('document_no'));

        // ⭐ পুরো খাতা তবু মেলে
        $totals = LedgerEntry::query()->selectRaw('SUM(debit) d, SUM(credit) c')->first();
        $this->assertSame(0, bccomp((string) $totals->d, (string) $totals->c, 4), 'সংশোধনের পরে খাতা মেলে না।');

        // ⭐ একটা সংশোধন, আগে আর পরে দুইটাই
        $this->assertSame(1, $this->revisionsOf($voucher));
        $this->assertSame(1, $revision->revision_no);
        $this->assertSame($this->owner->id, (int) $revision->edited_by);
        $this->assertSame($number, $revision->document_no);
        $this->assertSame('অঙ্ক ভুল লেখা হয়েছিল', $revision->reason);
        $this->assertSame(0, bccomp((string) $revision->before['header']['amount'], '500', 4));
        $this->assertSame(0, bccomp((string) $revision->after['header']['amount'], '750', 4));
        $this->assertSame('আসল বিবরণ', $revision->before['header']['narration']);
        $this->assertSame('সংশোধিত বিবরণ', $revision->after['header']['narration']);
        $this->assertSame(0, bccomp((string) $revision->before['lines'][0]['debit'], '500', 4));
        $this->assertSame(0, bccomp((string) $revision->after['lines'][0]['debit'], '750', 4));
        $this->assertNotEmpty($revision->before['lines'][0]['account'], 'সারির পাশে খাতের নাম নেই।');
        $this->assertSame(0, bccomp((string) $revision->before['ledger'][0]['debit'], '500', 4));
        $this->assertSame(0, bccomp((string) $revision->after['ledger'][0]['debit'], '750', 4));
        $this->assertNull($revision->printed_before, 'ছাপা না হওয়া কাগজে "আগে ছাপা হয়েছিল" বসেছে।');
    }

    /** দ্বিতীয় সংশোধন কেবল এখনো-চলতি দাখিলা উল্টায় — প্রথমবারের উল্টানো আবার নয়। */
    public function test_a_second_revision_reverses_only_what_is_still_live(): void
    {
        $voucher = $this->journal(Carbon::today());

        $this->revise($voucher, $this->owner, '750');
        $second = $this->revise($voucher, $this->owner, '600', 'আবার ঠিক করা');

        $this->assertSame(2, $second->revision_no);
        $this->assertSame('600.0000', $this->net($voucher, $this->receivable()));
        $this->assertSame(0, bccomp((string) $second->before['ledger'][0]['debit'], '750', 4));
        $this->assertSame(0, bccomp((string) $second->after['ledger'][0]['debit'], '600', 4));
        $this->assertSame(2, $this->revisionsOf($voucher));
    }

    /**
     * সুপার অ্যাডমিন নন — থামেন; একই মানুষ সুপার অ্যাডমিন হলে — ঢোকেন।
     */
    public function test_someone_who_is_not_super_admin_is_refused_until_made_one(): void
    {
        $voucher = $this->journal(Carbon::today());
        $rows = $this->rowsOf($voucher);

        $this->assertRefused(
            fn () => $this->revise($voucher, $this->accountant, '750'),
            'edit',
            __('revision.not_super_admin', ['no' => $voucher->document_no]),
        );

        $this->assertSame(0, $this->revisionsOf($voucher));
        $this->assertSame($rows, $this->rowsOf($voucher), 'থামার পরেও খাতায় সারি বসেছে।');
        $this->assertSame('500.0000', $this->net($voucher, $this->receivable()));

        CompanyContext::forCompany($this->company->id, fn () => $this->accountant->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE));

        $this->revise($voucher, $this->accountant, '750');

        $this->assertSame(1, $this->revisionsOf($voucher));
        $this->assertSame('750.0000', $this->net($voucher, $this->receivable()));
    }

    /** অন্য কোম্পানির সুপার অ্যাডমিন এখানে কেউ নন — এখানে সুপার অ্যাডমিন হলে তবেই। */
    public function test_a_super_admin_of_another_company_is_refused_until_made_one_here(): void
    {
        $voucher = $this->journal(Carbon::today());
        $other = User::factory()->create(['email' => 'other-owner@abos.test']);
        $fmart = Company::query()->where('code', 'FMART')->firstOrFail();

        $other->companies()->attach([$fmart->id]);
        CompanyContext::forCompany($fmart->id, fn () => $other->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE));
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->assertRefused(
            fn () => $this->revise($voucher, $other, '750'),
            'edit',
            __('revision.not_super_admin', ['no' => $voucher->document_no]),
        );
        $this->assertSame(0, $this->revisionsOf($voucher));

        $other->companies()->attach([$this->company->id]);
        CompanyContext::forCompany($this->company->id, fn () => $other->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE));
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->revise($voucher, $other, '750');

        $this->assertSame(1, $this->revisionsOf($voucher));
    }

    /**
     * বন্ধ মাস থামায় — খুললে চলে — আবার বন্ধ করলে আবার থামায়। একই মানুষ, তিনবার।
     */
    public function test_a_closed_month_refuses_until_opened_and_again_once_closed(): void
    {
        $date = $this->lastMonthDay();
        $voucher = $this->journal($date);
        $lock = $this->closeMonth($date);

        $closed = __('revision.month_closed', ['no' => $voucher->document_no, 'month' => $lock->label()]);
        $this->assertStringContainsString('আগে মাসটা খুলুন', __('revision.month_closed', ['no' => 'X', 'month' => 'Y'], 'bn'));

        $this->assertRefused(fn () => $this->revise($voucher, $this->owner, '750'), 'edit', $closed);
        $this->assertSame(0, $this->revisionsOf($voucher));
        $this->assertSame('500.0000', $this->net($voucher, $this->receivable()));

        // ⓘ মাস খোলা = তালার সারি তোলা — [[PeriodLockController::reopen()]] ঠিক এটাই করে
        $lock->delete();

        $this->revise($voucher, $this->owner, '750');
        $this->assertSame(1, $this->revisionsOf($voucher));
        $this->assertSame('750.0000', $this->net($voucher, $this->receivable()));
        $this->assertSame(
            [$date->toDateString()],
            LedgerEntry::query()->where('source_type', 'journal_voucher:reversal')->where('source_id', $voucher->id)
                ->pluck('trx_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values()->all(),
            'খোলা মাসের সংশোধনে উল্টো সারি ঐ মাসে বসেনি।',
        );

        $again = $this->closeMonth($date);

        $this->assertRefused(
            fn () => $this->revise($voucher, $this->owner, '900'),
            'edit',
            __('revision.month_closed', ['no' => $voucher->document_no, 'month' => $again->label()]),
        );
        $this->assertSame(1, $this->revisionsOf($voucher));
        $this->assertSame('750.0000', $this->net($voucher, $this->receivable()));
    }

    /**
     * ডাকনেওয়ালার নিজের নিয়ম সুপার অ্যাডমিনের প্রশ্নের জায়গা নেয় — মাসের প্রশ্নের নয় (সমন্বয়ক, ৩ অক্টোবর ২০২৬)।
     */
    public function test_a_callers_own_rule_replaces_the_super_admin_question_but_never_the_month(): void
    {
        $keeper = app(RevisionKeeper::class);
        $letEveryoneIn = fn () => null;

        // ⓵ নিজের নিয়ম "হ্যাঁ" বললে সুপার অ্যাডমিন না হয়েও চলে
        $today = $this->journal(Carbon::today());
        $this->actingAs($this->accountant);
        $keeper->edit($today, $this->accountant, 'কাউন্টারের সংশোধন', fn (Voucher $v) => $this->setLineAmounts($v, '650', '650'), $letEveryoneIn);
        $this->assertSame('650.0000', $this->net($today, $this->receivable()));

        // ⓶ নিজের নিয়ম "না" বললে তার নিজের বার্তায় থামে
        $this->assertRefused(
            fn () => $keeper->edit($today, $this->accountant, 'কারণ', fn (Voucher $v) => $this->setLineAmounts($v, '700', '700'),
                fn () => throw ValidationException::withMessages(['edit' => 'গেট পাস হয়ে গেছে'])),
            'edit',
            'গেট পাস হয়ে গেছে',
        );

        // ⓷ বন্ধ মাস — নিজের নিয়ম সবাইকে ঢুকতে দিলেও থামে
        $date = $this->lastMonthDay();
        $old = $this->journal($date);
        $lock = $this->closeMonth($date);

        $this->assertRefused(
            fn () => $keeper->edit($old, $this->owner, 'কারণ', fn (Voucher $v) => $this->setLineAmounts($v, '650', '650'), $letEveryoneIn),
            'edit',
            __('revision.month_closed', ['no' => $old->document_no, 'month' => $lock->label()]),
        );
        $this->assertSame('500.0000', $this->net($old, $this->receivable()));
        $this->assertSame(0, $this->revisionsOf($old));
    }

    /** কারণ ছাড়া নয় — খালি বা কেবল ফাঁকা জায়গা, দুইটাই থামে। */
    public function test_a_reason_is_required(): void
    {
        $voucher = $this->journal(Carbon::today());
        $message = __('revision.reason_required', ['no' => $voucher->document_no]);

        $this->assertRefused(fn () => $this->revise($voucher, $this->owner, '750', ''), 'reason', $message);
        $this->assertRefused(fn () => $this->revise($voucher, $this->owner, '750', "   \n "), 'reason', $message);

        $this->assertSame(0, $this->revisionsOf($voucher));
        $this->assertSame('500.0000', $this->net($voucher, $this->receivable()));

        $this->revise($voucher, $this->owner, '750', 'কারণ আছে');
        $this->assertSame(1, $this->revisionsOf($voucher));
    }

    /** সংশোধনের সারি প্রমাণ — বদলানো যায় না, মোছা যায় না। */
    public function test_a_revision_can_be_neither_changed_nor_deleted(): void
    {
        $voucher = $this->journal(Carbon::today());
        $revision = $this->revise($voucher, $this->owner, '750');

        try {
            $revision->update(['reason' => 'অন্য কারণ', 'after' => ['header' => ['amount' => '500.0000']]]);
            $this->fail('⛔ সংশোধনের সারি বদলে গেল।');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('⛔', $e->getMessage());
        }

        try {
            $revision->fresh()->delete();
            $this->fail('⛔ সংশোধনের সারি মুছে গেল।');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('⛔', $e->getMessage());
        }

        $stored = DocumentRevision::query()->findOrFail($revision->id);
        $this->assertSame('অঙ্ক ভুল লেখা হয়েছিল', $stored->reason);
        $this->assertSame(0, bccomp((string) $stored->after['header']['amount'], '750', 4));
    }

    /** কাগজটা আগে ছাপা হয়ে থাকলে সংশোধন সেই ঘটনাটা ধরে রাখে — না ছাপা হলে কিছু নয়। */
    public function test_a_printed_voucher_keeps_the_reference_to_what_went_out(): void
    {
        $voucher = $this->journal(Carbon::today());

        $first = $this->revise($voucher, $this->owner, '750');
        $this->assertNull($first->printed_before);

        $this->actingAs($this->owner);
        $delivery = app(PaperTrail::class)->record('accounts_voucher', (int) $voucher->id, 'a4', DocumentDelivery::PRINTED, $voucher->document_no);

        $second = $this->revise($voucher, $this->owner, '800');

        $this->assertIsArray($second->printed_before, 'ছাপা কাগজের সংশোধনে "আগে ছাপা হয়েছিল" নেই।');
        $this->assertSame((int) $delivery->id, $second->printed_before['delivery_id']);
        $this->assertSame((string) $delivery->public_id, $second->printed_before['delivery_public_id']);
        $this->assertSame(DocumentDelivery::PRINTED, $second->printed_before['how']);
        $this->assertSame(1, $second->printed_before['times']);
        $this->assertSame('accounts_voucher', $second->printed_before['document_type']);
        // ⓘ বাইরে যাওয়া রূপটাই "আগে" — ৭৫০
        $this->assertSame(0, bccomp((string) $second->before['header']['amount'], '750', 4));
    }

    /** কাজের মাঝে ব্যতিক্রম — সব ফেরত: সংশোধনের সারি নেই, খাতা আর কাগজ আগের মতো। */
    public function test_a_failing_change_takes_everything_back(): void
    {
        $voucher = $this->journal(Carbon::today());
        $rows = $this->rowsOf($voucher);
        $this->actingAs($this->owner);

        try {
            app(RevisionKeeper::class)->edit($voucher, $this->owner, 'ভেঙে যাবে', function (Voucher $v) {
                $this->setLineAmounts($v, '999', '999');

                throw new \RuntimeException('মাঝপথে ভাঙল');
            });
            $this->fail('ব্যতিক্রমটা গিলে ফেলা হয়েছে।');
        } catch (\RuntimeException $e) {
            $this->assertSame('মাঝপথে ভাঙল', $e->getMessage());
        }

        // ⓘ মডিউলের নিজের পোস্টিং-যাচাইয়ে আটকালেও একই — ডেবিট ৯৯৯, ক্রেডিট ৫০০ মেলে না
        try {
            app(RevisionKeeper::class)->edit($voucher, $this->owner, 'মেলে না', fn (Voucher $v) => $this->setLineAmounts($v, '999', '500'));
            $this->fail('না-মেলা ভাউচার আবার বসে গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }

        $fresh = $voucher->fresh(['lines']);
        $this->assertSame(0, $this->revisionsOf($voucher));
        $this->assertSame($rows, $this->rowsOf($voucher), 'ফেরতের পরেও খাতায় উল্টো বা নতুন সারি রয়ে গেছে।');
        $this->assertSame('500.0000', $this->net($voucher, $this->receivable()));
        $this->assertSame(0, bccomp((string) $fresh->lines->first()->debit, '500', 4));
        $this->assertSame(0, bccomp((string) $fresh->amount, '500', 4));
    }

    /** খসড়া এই দরজায় আসে না; আর সংশোধনে নম্বর বা "কিছুই না" — দুইটাই থামে। */
    public function test_a_draft_a_new_number_and_no_change_are_all_refused(): void
    {
        $this->actingAs($this->owner);
        $draft = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => Carbon::today()->toDateString()],
            [['account_id' => $this->receivable(), 'debit' => '100'], ['account_id' => $this->payable(), 'credit' => '100']],
        );

        $this->assertRefused(
            fn () => $this->revise($draft, $this->owner, '200'),
            'edit',
            __('revision.not_posted', ['no' => $draft->document_no]),
        );

        $voucher = $this->journal(Carbon::today());
        $keeper = app(RevisionKeeper::class);

        $this->assertRefused(
            fn () => $keeper->edit($voucher, $this->owner, 'নম্বর বদল', fn (Voucher $v) => $v->forceFill(['document_no' => 'JV-NEW-1'])->save()),
            'edit',
            __('revision.number_changed', ['no' => $voucher->document_no]),
        );

        $this->assertRefused(
            fn () => $keeper->edit($voucher, $this->owner, 'কিছুই না', fn () => null),
            'edit',
            __('revision.nothing_changed', ['no' => $voucher->document_no]),
        );

        $this->assertSame($voucher->document_no, $voucher->fresh()->document_no);
        $this->assertSame(0, $this->revisionsOf($voucher));
        $this->assertSame('500.0000', $this->net($voucher, $this->receivable()));
    }

    /**
     * ইতিহাসের অংশে বদলানো ঘর রঙে আলাদা, আগে আর পরে পাশাপাশি — আর কেবল যিনি কাগজটা দেখতে পারেন তিনিই দেখেন।
     */
    public function test_the_history_shows_what_changed_and_only_to_who_may_see_the_paper(): void
    {
        $voucher = $this->journal(Carbon::today());
        $this->revise($voucher, $this->owner, '750', 'ইতিহাসের কারণ');

        // ⓵ ভাউচারের নিজের পাতায় — হিসাবরক্ষক কাগজটা দেখতে পারেন
        $this->actingAs($this->accountant);
        $page = $this->get(route('accounts.voucher.show', $voucher));
        $page->assertOk();
        $page->assertSee('data-revisions', false);
        $page->assertSee('data-revision-field="amount" data-revision-changed="1"', false);
        $page->assertSee('data-revision-field="narration" data-revision-changed="1"', false);
        $page->assertSee('data-revision-row="changed"', false);
        $page->assertSee(Money::format('500.0000'));
        $page->assertSee(Money::format('750.0000'));
        $page->assertSee('ইতিহাসের কারণ');
        $page->assertSee('আসল বিবরণ');
        $page->assertSee('সংশোধিত বিবরণ');

        // ⓶ একই মানুষ: কাগজ দেখার চাবি নেই — অংশটা আঁকাই হয় না; চাবি পেলে আঁকা হয়
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($salesman);
        $this->assertFalse($salesman->can('view', $voucher), 'পরীক্ষার ধরে নেওয়াটা ভুল — বিক্রয়কর্মী আগে থেকেই ভাউচার দেখেন।');
        $this->assertStringNotContainsString('data-revisions', Blade::render('<x-ui.revisions :document="$d" />', ['d' => $voucher]));

        CompanyContext::forCompany($this->company->id, fn () => $salesman->givePermissionTo(Permission::findOrCreate('accounts.report', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $salesman = $salesman->fresh();
        $this->actingAs($salesman);

        $html = Blade::render('<x-ui.revisions :document="$d" />', ['d' => $voucher]);
        $this->assertStringContainsString('data-revisions', $html);
        $this->assertStringContainsString('data-revision-field="amount" data-revision-changed="1"', $html);
    }
}

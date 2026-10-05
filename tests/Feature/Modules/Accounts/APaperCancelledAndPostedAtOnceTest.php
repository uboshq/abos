<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ম২ — ভাউচার আর নোটের অবস্থা বদলে তালা (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ পোস্ট আর বাতিল হাতে ধরা কপির অবস্থা দেখত। একই মুহূর্তে দুইজন চাপলে দুজনেই "খসড়া" দেখতেন: বাতিল কিছু ফেরাত না,
 * পোস্ট খাতায় বসাত — শেষে কাগজ "বাতিল", অথচ টাকা খাতায়। ⓘ একই প্রক্রিয়ায় দুই অনুরোধ এভাবেই মাপা হয়: একই কাগজের
 * দুই বাসি কপি, একটা আগে কাজ করে, তারপর অন্যটা ([[ASecondClickDoesTheWorkOnceTest]]-এর নিয়ম)।
 */
final class APaperCancelledAndPostedAtOnceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_voucher_cancelled_first_cannot_then_be_posted_from_a_stale_copy(): void
    {
        [$first, $second] = $this->twoCopies($this->draftJournal());

        app(VoucherService::class)->cancel($first, 'ভুল');

        $this->assertRefused(fn () => app(VoucherService::class)->post($second), '⛔ বাতিল ভাউচার বাসি কপি থেকে খাতায় বসল।');

        $this->assertSame(DocumentStatus::CANCELLED, $first->fresh()->status);
        $this->assertSame(0, $this->rowsOf($first), '⛔ বাতিল ভাউচারের দাখিলা খাতায় আছে।');
    }

    public function test_a_voucher_posted_first_is_reversed_when_a_stale_copy_cancels_it(): void
    {
        [$first, $second] = $this->twoCopies($this->draftJournal());

        app(VoucherService::class)->post($first);
        app(VoucherService::class)->cancel($second, 'ভুল');

        $this->assertSame(DocumentStatus::CANCELLED, $first->fresh()->status);
        $this->assertSame(0, bccomp($this->netOn(StandardChart::ENTERTAINMENT), '0', 4),
            '⛔ কাগজ "বাতিল", অথচ খাতায় দাখিলা রয়ে গেল — বাসি কপি "খসড়া" দেখে উল্টায়নি।');
    }

    public function test_a_voucher_is_cancelled_or_posted_once_however_many_stale_copies(): void
    {
        [$first, $second] = $this->twoCopies($this->draftJournal());
        app(VoucherService::class)->post($first);
        $this->assertRefused(fn () => app(VoucherService::class)->post($second), '⛔ একই ভাউচার দুইবার পোস্ট।');

        [$third, $fourth] = $this->twoCopies($first);
        app(VoucherService::class)->cancel($third, 'ভুল');
        $this->assertRefused(fn () => app(VoucherService::class)->cancel($fourth, 'আবার'), '⛔ একই ভাউচার দুইবার বাতিল।');

        $this->assertSame(0, bccomp($this->netOn(StandardChart::ENTERTAINMENT), '0', 4), '⛔ দাখিলা দুইবার উল্টানো হয়েছে।');
    }

    public function test_a_note_cancelled_first_cannot_then_be_confirmed_from_a_stale_copy(): void
    {
        $note = $this->draftNote();
        $first = Note::query()->findOrFail($note->id);
        $second = Note::query()->findOrFail($note->id);

        app(NoteService::class)->cancel($first, 'ভুল');

        $this->assertRefused(fn () => app(NoteService::class)->confirm($second), '⛔ বাতিল নোট বাসি কপি থেকে খাতায় বসল।');
        $this->assertSame(DocumentStatus::CANCELLED, $note->fresh()->status);
        $this->assertSame(0, LedgerEntry::query()->where('source_type', $note->sourceType())->where('source_id', $note->id)->count());
    }

    public function test_a_note_confirmed_first_is_reversed_when_a_stale_copy_cancels_it(): void
    {
        $note = $this->draftNote();
        $first = Note::query()->findOrFail($note->id);
        $second = Note::query()->findOrFail($note->id);
        $before = $this->balances();

        app(NoteService::class)->confirm($first);
        $this->assertNotEquals($before, $this->balances(), 'প্রস্তুতি: নোট খাতায় বসেনি।');

        app(NoteService::class)->cancel($second, 'ভুল');

        $this->assertSame(DocumentStatus::CANCELLED, $note->fresh()->status);
        $this->assertEquals($before, $this->balances(),
            '⛔ নোট "বাতিল", অথচ খাতায় দাখিলা রয়ে গেল — বাসি কপি "খসড়া" দেখে উল্টায়নি।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array<int, string> খাতপ্রতি ডেবিট − ক্রেডিট, শূন্য বাদ */
    private function balances(): array
    {
        return LedgerEntry::query()->where('company_id', CompanyContext::id())
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit) - SUM(credit) as n')
            ->pluck('n', 'account_id')
            ->map(fn ($n) => bcadd((string) $n, '0', 4))
            ->reject(fn (string $n) => bccomp($n, '0', 4) === 0)
            ->sortKeys()
            ->all();
    }

    /** @return array{0: Voucher, 1: Voucher} */
    private function twoCopies(Voucher $voucher): array
    {
        return [Voucher::query()->findOrFail($voucher->id), Voucher::query()->findOrFail($voucher->id)];
    }

    private function draftJournal(): Voucher
    {
        return app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'ম২'],
            [
                ['account_id' => StandardChart::find(StandardChart::ENTERTAINMENT)->id, 'debit' => '300'],
                ['account_id' => StandardChart::find(StandardChart::GIFTS_AND_DONATIONS)->id, 'credit' => '300'],
            ],
        );
    }

    private function draftNote(): Note
    {
        return app(NoteService::class)->create([
            'direction' => Note::CREDIT,
            'party_type' => 'customer',
            'party_id' => (int) Customer::query()->value('id'),
            'trx_date' => now()->toDateString(),
            'amount' => '500',
            'tax_amount' => '0',
            'reason' => 'price_correction',
            'against_no' => 'INV-M2-1',
            'narration' => 'M2-NOTE',
        ]);
    }

    private function rowsOf(Voucher $voucher): int
    {
        return LedgerEntry::query()->where('source_type', Voucher::SOURCE_TYPES[$voucher->type])->where('source_id', $voucher->id)->count();
    }

    private function netOn(string $code): string
    {
        return (string) LedgerEntry::query()->where('account_id', StandardChart::find($code)?->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
    }

    private function assertRefused(\Closure $act, string $why): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors(), 'আটকেছে, কিন্তু অন্য কারণে: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->fail($why);
    }
}

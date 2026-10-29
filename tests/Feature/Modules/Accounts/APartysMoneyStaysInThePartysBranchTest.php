<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ পক্ষের টাকা পক্ষের শাখায় — মালিক, ১৯ অক্টোবর ২০২৬: *"ei party super er tai eta lion e keno zabe eta bag eta age tik koro"*।
 *
 * ⓘ লাইভে ADI-র Muskan Enterprise (Super শাখার গ্রাহক) — ১৭ অক্টোবরের রসিদ RCV-0143 লেখকের উপরের শাখা-বাছাই ধরে Lion-এ বসেছিল।
 * Super দেখলে গ্রাহক ৪৭,৮৩৫.৫৭ বাকি, অথচ আসলে ৬৮.৪৩ অগ্রিম। একই ধরনের আরও দুটো (ADI RCV-0133, UB RCV-10230)।
 */
final class APartysMoneyStaysInThePartysBranchTest extends TestCase
{
    use RefreshDatabase;

    private Branch $home;

    private Branch $other;

    private Customer $customer;

    private Account $expense;

    private Account $receivable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->home = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $this->other = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();

        // ⓘ লেখক উপরে "MMS" বেছে রেখেছেন — লাইভের Lion
        CompanyContext::set($company->id, $this->home->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        // ⓘ গ্রাহক অন্য শাখার — লাইভের Super
        $this->customer = Customer::query()->create(['code' => 'PB-1', 'name_en' => 'Branch Dealer', 'name_bn' => 'Branch Dealer',
            'branch_id' => $this->other->id, 'is_active' => true]);

        $this->receivable = Account::query()->postable()->active()->where('code', '1110')->firstOrFail();
        $this->expense = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->firstOrFail();
    }

    public function test_a_hand_written_voucher_lands_in_the_partys_branch_not_the_header_branch(): void
    {
        $voucher = $this->service()->create($this->data(), $this->lines(), byHand: true);

        $this->assertSame((int) $this->other->id, (int) $voucher->branch_id, '⛔ গ্রাহকের টাকা লেখকের উপরের শাখায় বসল');

        $this->service()->post($voucher, byHand: true);

        $branches = LedgerEntry::query()->withoutGlobalScopes()->where('source_type', 'journal_voucher')
            ->where('source_id', $voucher->id)->pluck('branch_id')->map(fn ($b) => (int) $b)->unique()->values()->all();

        $this->assertSame([(int) $this->other->id], $branches, '⛔ খাতার সারি গ্রাহকের শাখার বাইরে');
    }

    public function test_choosing_another_branch_for_the_party_is_refused(): void
    {
        try {
            $this->service()->create([...$this->data(), 'branch_id' => $this->home->id], $this->lines(), byHand: true);
            $this->fail('⛔ অন্য শাখার গ্রাহকের টাকা এই শাখায় লেখা গেল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('branch_id', $e->errors());
        }
    }

    public function test_an_old_draft_in_the_wrong_branch_is_stopped_at_posting(): void
    {
        // ⓘ নিয়মের আগের খসড়া — সিস্টেমের পথে লেখা, তাই শাখা উপরের বাছাই থেকে
        $draft = $this->service()->create($this->data(), $this->lines());
        $this->assertSame((int) $this->home->id, (int) $draft->branch_id);

        try {
            $this->service()->post($draft, byHand: true);
            $this->fail('⛔ অন্য শাখায় লেখা খসড়া হাতে পাকা হয়ে গেল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('branch_id', $e->errors());
        }

        $this->assertSame(0, LedgerEntry::query()->withoutGlobalScopes()->where('source_type', 'journal_voucher')
            ->where('source_id', $draft->id)->count());
    }

    public function test_a_party_of_the_header_branch_stays_where_it_was(): void
    {
        $this->customer->forceFill(['branch_id' => $this->home->id])->save();

        $voucher = $this->service()->create($this->data(), $this->lines(), byHand: true);

        $this->assertSame((int) $this->home->id, (int) $voucher->branch_id);
    }

    public function test_no_path_into_the_books_writes_a_partys_row_in_another_branch(): void
    {
        // ⓘ মালিক: "kono vabei" — বিক্রি, POS, ফোন, অর্থ, সবাই এই এক দরজা দিয়ে খাতায় যায়
        try {
            app(PostingEngine::class)->post('pb_test', 1, now(), $this->postingLines(), 'PB-1', $this->home->id);
            $this->fail('⛔ সিস্টেমের পথে অন্য শাখার গ্রাহকের সারি খাতায় বসল');
        } catch (PostingException $e) {
            $this->assertSame(__('core.posting.party_in_other_branch', ['branch' => $this->other->name(), 'here' => $this->home->name()]), $e->forPeople());
        }

        $this->assertSame(0, LedgerEntry::query()->withoutGlobalScopes()->where('source_type', 'pb_test')->count());

        // ⓘ নিজের শাখায় আগের মতোই
        app(PostingEngine::class)->post('pb_test', 2, now(), $this->postingLines(), 'PB-2', $this->other->id);
        $this->assertSame(2, LedgerEntry::query()->withoutGlobalScopes()->where('source_type', 'pb_test')->where('source_id', 2)->count());
    }

    public function test_an_old_wrong_branch_row_can_still_be_reversed(): void
    {
        // ⓘ নিয়মের আগে ভুল শাখায় বসা সারি (লাইভের RCV-0143) — উল্টানো মূলের শাখাতেই বসে, তাই আটকায় না
        $this->customer->forceFill(['branch_id' => $this->home->id])->save();
        app(PostingEngine::class)->post('pb_old', 7, now(), $this->postingLines(), 'PB-7', $this->home->id);
        $this->customer->forceFill(['branch_id' => $this->other->id])->save();

        $reversed = app(PostingEngine::class)->reverse('pb_old', 7, now());

        $this->assertCount(2, $reversed);
        $this->assertSame([(int) $this->home->id], collect($reversed)->pluck('branch_id')->map(fn ($b) => (int) $b)->unique()->values()->all());
    }

    public function test_a_collection_lands_in_the_customers_branch_not_the_collectors(): void
    {
        // ⓘ a4-এর পর্যালোচনা: সব শাখা দেখা SR-এর ফোন অন্য শাখার গ্রাহকের আদায় নিজের শাখায় বসাত — এখন গ্রাহকের শাখায়
        $collection = app(\App\Modules\Sales\Services\CollectionService::class)->create(
            ['customer_id' => $this->customer->id, 'trx_date' => now()->toDateString(), 'amount' => '50', 'instrument' => 'cash'], [],
        );

        $this->assertSame((int) $this->other->id, (int) $collection->branch_id, '⛔ আদায় আদায়কারীর উপরের শাখায় বসল');
    }

    public function test_a_refusal_from_the_books_is_an_answer_to_the_phone_not_a_silent_retry(): void
    {
        // ⓘ খাতার "না" (PostingException) আগে সিঙ্কের Throwable-এ পড়ত — ফোন চিরকাল আবার পাঠাত। এখন REJECTED, বাংলা কারণসহ।
        $source = file_get_contents(app_path('Core/Engines/Sync/SyncService.php'));

        $this->assertMatchesRegularExpression('/catch \(\\\\App\\\\Core\\\\Engines\\\\Posting\\\\PostingException \$refused\)\s*\{.*?refuse\(.*?forPeople\(\)/s', (string) $source);
    }

    /** @return list<array<string, mixed>> */
    private function postingLines(): array
    {
        return [
            ['account_id' => $this->expense->id, 'debit' => '100', 'credit' => '0'],
            ['account_id' => $this->receivable->id, 'debit' => '0', 'credit' => '100', 'party_type' => 'customer', 'party_id' => $this->customer->id],
        ];
    }

    private function service(): VoucherService
    {
        return app(VoucherService::class);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'শাখার পরীক্ষা'];
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        return [
            ['account_id' => $this->expense->id, 'debit' => '500', 'credit' => '0'],
            ['account_id' => $this->receivable->id, 'debit' => '0', 'credit' => '500', 'party_type' => 'customer', 'party_id' => $this->customer->id],
        ];
    }
}

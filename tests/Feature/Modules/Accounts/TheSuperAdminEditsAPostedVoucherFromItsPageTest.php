<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\DocumentRevision;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ পোস্ট হওয়া ভাউচার পাতা থেকেই সম্পাদনা — সুপার অ্যাডমিন (মালিকের আদেশ, ৫ অক্টোবর ২০২৬)।
 *
 * ⛔ ইঞ্জিন ([[VoucherService::editPosted()]], [[RevisionKeeper]]) তৈরি ছিল, কিন্তু কোনো দরজা ডাকত না — বোতামই ছিল না।
 * ⓘ দাবিগুলো একই মানুষ ধরে: সুইচ বন্ধে নেই, চালুতে আছে; অ-সুপার-অ্যাডমিনের ৪০৩; বন্ধ মাসে থামে; বাঁধা ভাউচারে কারণ
 * দেখায়; আর সম্পাদনার পরে খাতা নতুন অঙ্কে, আগেরটা উল্টো সারিতে।
 */
final class TheSuperAdminEditsAPostedVoucherFromItsPageTest extends TestCase
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
        $this->actingAs($this->owner);
    }

    /** ⓘ একই মানুষ, সুইচ বন্ধ তারপর চালু */
    public function test_the_button_and_the_door_follow_the_company_switch(): void
    {
        $voucher = $this->journal('500');

        $this->switch(false);
        $this->get(route('accounts.voucher.show', $voucher))->assertOk()->assertDontSee('data-revise', false);
        $this->get(route('accounts.voucher.revise', $voucher))->assertForbidden();

        $this->switch(true);
        $this->get(route('accounts.voucher.show', $voucher))->assertOk()->assertSee('data-revise', false)
            ->assertSee(route('accounts.voucher.revise', $voucher));
        $this->get(route('accounts.voucher.revise', $voucher))->assertOk()
            ->assertSee('name="revision_reason"', false)
            ->assertSee(route('accounts.voucher.revise.save', $voucher));
    }

    public function test_saving_reverses_the_old_entries_and_books_the_new_amount(): void
    {
        $this->switch(true);
        $voucher = $this->journal('500');
        $before = LedgerEntry::query()->where('document_no', $voucher->document_no)->count();

        $this->put(route('accounts.voucher.revise.save', $voucher), $this->journalForm($voucher, '750', 'অঙ্ক ভুল লেখা হয়েছিল'))
            ->assertRedirect(route('accounts.voucher.show', $voucher))->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp($this->net(StandardChart::ENTERTAINMENT), '750', 4), '⛔ সম্পাদনার পরে খাতা নতুন অঙ্কে মেলে না।');
        $this->assertSame($before * 3, LedgerEntry::query()->where('document_no', $voucher->document_no)->count(),
            '⛔ আগের দাখিলা উল্টো সারিতে থাকেনি — মূল, উল্টো আর নতুন, তিন জোড়া থাকার কথা।');

        $revision = DocumentRevision::query()->forDocument($voucher)->sole();
        $this->assertSame('অঙ্ক ভুল লেখা হয়েছিল', $revision->reason);

        $this->get(route('accounts.voucher.index', Voucher::JOURNAL))->assertOk()->assertSee('data-revised', false);
        $this->get(route('accounts.voucher.show', $voucher))->assertOk()->assertSee('data-revisions', false);
    }

    public function test_a_reason_is_required(): void
    {
        $this->switch(true);
        $voucher = $this->journal('500');

        $this->from(route('accounts.voucher.revise', $voucher))
            ->put(route('accounts.voucher.revise.save', $voucher), $this->journalForm($voucher, '750', ''))
            ->assertSessionHasErrors('revision_reason');

        $this->assertSame(0, bccomp($this->net(StandardChart::ENTERTAINMENT), '500', 4));
        $this->assertSame(0, DocumentRevision::query()->forDocument($voucher)->count());
    }

    /** ⓘ একই হিসাবরক্ষক, সুপার অ্যাডমিন নন — পাতায় বোতাম নেই, দরজায় ৪০৩ */
    public function test_someone_who_is_not_super_admin_gets_a_403(): void
    {
        $this->switch(true);
        $voucher = $this->journal('500');
        $this->actingAs($this->accountant);

        $this->get(route('accounts.voucher.show', $voucher))->assertOk()->assertDontSee('data-revise', false);
        $this->get(route('accounts.voucher.revise', $voucher))->assertForbidden();
        $this->put(route('accounts.voucher.revise.save', $voucher), $this->journalForm($voucher, '750', 'চেষ্টা'))->assertForbidden();

        $this->assertSame(0, bccomp($this->net(StandardChart::ENTERTAINMENT), '500', 4));
    }

    public function test_a_closed_month_stops_the_edit(): void
    {
        $this->switch(true);
        $date = Carbon::today()->subMonthNoOverflow()->startOfMonth()->addDays(4);
        $voucher = $this->journal('500', $date->toDateString());

        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => (int) $date->year, 'month' => (int) $date->month,
            'reason' => 'রিপোর্ট গেছে', 'locked_by' => $this->owner->id, 'locked_at' => now(),
        ]);

        $this->get(route('accounts.voucher.show', $voucher))->assertOk()->assertDontSee('data-revise', false);
        $this->get(route('accounts.voucher.revise', $voucher))->assertForbidden();
        $this->put(route('accounts.voucher.revise.save', $voucher), $this->journalForm($voucher, '750', 'বন্ধ মাসে'))->assertForbidden();
    }

    /** ⓘ কাউন্টারের রসিদ — বোতামের জায়গায় কারণ, চুপচাপ লুকায় না */
    public function test_a_counter_voucher_shows_why_instead_of_the_button(): void
    {
        $this->switch(true);
        $voucher = $this->journal('500');
        $voucher->forceFill(['origin' => Voucher::ORIGIN_COUNTER])->save();

        $this->get(route('accounts.voucher.show', $voucher))->assertOk()
            ->assertSee('data-revise-blocked', false)
            ->assertSee(__('accounts::revision.from_counter', ['no' => $voucher->document_no]))
            ->assertDontSee(route('accounts.voucher.revise', $voucher));

        $this->get(route('accounts.voucher.revise', $voucher))->assertForbidden();
    }

    /** ⓘ অন্য কাগজের সাথে বাঁধা বা ব্যাংকে মেলানো — প্রতিটা নিজের কারণ বলে, আর সার্ভিসও নিজে থামে */
    public function test_a_bound_voucher_names_its_reason_and_the_service_refuses_too(): void
    {
        $this->switch(true);

        $against = $this->journal('500');
        $against->forceFill(['against_type' => 'capital_entry', 'against_id' => 987654])->save();
        $this->get(route('accounts.voucher.show', $against))->assertOk()
            ->assertSee(__('accounts::revision.settles_a_paper', ['no' => $against->document_no, 'paper' => 'capital_entry']));

        $matched = $this->journal('500');
        $line = $matched->lines()->orderBy('id')->firstOrFail();
        \App\Modules\Accounts\Models\BankStatementLine::query()->forceCreate([
            'company_id' => $this->company->id, 'bank_account_id' => $line->account_id, 'trx_date' => now()->toDateString(),
            'description' => 'M', 'debit' => '0', 'credit' => '500', 'fingerprint' => 'rv-'.$matched->id, 'matched_line_id' => $line->id,
        ]);
        $this->get(route('accounts.voucher.show', $matched))->assertOk()
            ->assertSee(__('accounts::revision.reconciled', ['no' => $matched->document_no]));

        $counter = $this->journal('500');
        $counter->forceFill(['origin' => Voucher::ORIGIN_COUNTER])->save();

        try {
            app(VoucherService::class)->editPosted($counter->fresh(), $this->owner, 'সরাসরি', ['narration' => 'x'], []);
            $this->fail('⛔ সার্ভিস নিজে কাউন্টারের ভাউচার সম্পাদনা থামায়নি — দরজা এড়ালেই খোলা।');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('edit', $e->errors());
        }
    }

    /** ⓘ পাঁচ ধরনেই ফর্ম খোলে, কারণের ঘরসহ */
    public function test_all_five_kinds_open_the_revision_form(): void
    {
        $this->switch(true);

        // ⓘ ফর্ম আঁকার দাবি — পোস্ট হওয়া একটা কাগজ, ধরন বদলে বদলে (প্রতিটা ধরনের পোস্টের নিয়ম এখানে মাপার বিষয় নয়)
        foreach (Voucher::TYPES as $type) {
            $voucher = $this->journal('500');
            $voucher->forceFill(['type' => $type])->save();

            $this->get(route('accounts.voucher.revise', $voucher))->assertOk()
                ->assertSee('name="revision_reason"', false)
                ->assertSee(__('accounts::revision.save'))
                ->assertDontSee('name="save_as_draft"', false);
        }
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function switch(bool $on): void
    {
        app(SettingsService::class)->set('system.edit_posted_papers', $on);
    }

    private function journal(string $amount, ?string $date = null): Voucher
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date ?? now()->toDateString(), 'narration' => 'সম্পাদনার নমুনা'],
            [
                ['account_id' => StandardChart::find(StandardChart::ENTERTAINMENT)->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => StandardChart::find(StandardChart::GIFTS_AND_DONATIONS)->id, 'debit' => '0', 'credit' => $amount],
            ],
        );

        return app(VoucherService::class)->post($voucher);
    }

    /** @return array<string, mixed> */
    private function journalForm(Voucher $voucher, string $amount, string $reason): array
    {
        return [
            'type' => Voucher::JOURNAL,
            'trx_date' => $voucher->trx_date->toDateString(),
            'narration' => 'সম্পাদনার নমুনা',
            'revision_reason' => $reason,
            'lines' => [
                ['account_id' => StandardChart::find(StandardChart::ENTERTAINMENT)->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => StandardChart::find(StandardChart::GIFTS_AND_DONATIONS)->id, 'debit' => '0', 'credit' => $amount],
            ],
        ];
    }

    private function net(string $code): string
    {
        return (string) LedgerEntry::query()->where('account_id', StandardChart::find($code)?->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n');
    }
}

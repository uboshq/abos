<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ম৪ — চার্জসহ কাগজ সম্পাদনায় অঙ্ক কমে না (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ টাকা ঢোকায় চার্জ ভেতর থেকে কাটে (Dr ব্যাংক অঙ্ক − চার্জ · Dr চার্জ / Cr অন্য পাশ অঙ্ক)। সম্পাদনার পর্দা "অঙ্ক" ঘরে
 * প্রথম ডেবিট বসাত — অর্থাৎ চার্জ বাদ দেওয়া অঙ্ক — তাই কিছু না বদলে সংরক্ষণ করলেও প্রতিবার অঙ্ক চার্জের সমান কমত।
 *
 * ⓘ দাবিটা মানুষের কাজের হুবহু: পাতা খোলা, ঘরে যা আছে তাই নিয়ে সংরক্ষণ — খাতার সারি এক চুলও বদলাবে না।
 */
final class AnEditShavedTheBankChargeOffAgainTest extends TestCase
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

    public function test_saving_a_charged_receipt_untouched_leaves_it_untouched(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $voucher = $this->draft(Voucher::RECEIPT, StandardChart::find(StandardChart::RECEIVABLE)->id, $this->bank()->id, [
            'party_type' => 'customer', 'party_id' => $customer->id,
        ]);

        $before = $this->lines($voucher);
        $form = $this->formOf($voucher);

        $this->assertSame(0, bccomp($form['amount'], '1000', 4), '⛔ সম্পাদনার ঘরে চার্জ বাদ দেওয়া অঙ্ক — '.$form['amount']);
        $this->assertSame(0, bccomp((string) $form['charge_amount'], '20', 4), 'রসিদের পর্দায় চার্জের ঘরে আগের চার্জ নেই।');

        $this->resave($voucher, $form, ['party_type' => 'customer', 'party_id' => $customer->id]);
        $this->resave($voucher, $this->formOf($voucher), ['party_type' => 'customer', 'party_id' => $customer->id]);

        $this->assertSame($before, $this->lines($voucher), '⛔ কিছু না বদলে দুইবার সংরক্ষণ — খাতার সারি বদলে গেল।');
    }

    public function test_saving_a_charged_contra_untouched_leaves_it_untouched(): void
    {
        $cash = Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('code')->firstOrFail();
        $voucher = $this->draft(Voucher::CONTRA, $cash->id, $this->bank()->id);

        $before = $this->lines($voucher);
        $form = $this->formOf($voucher);

        $this->assertSame(0, bccomp($form['amount'], '1000', 4), '⛔ কন্ট্রার ঘরে চার্জ বাদ দেওয়া অঙ্ক — '.$form['amount']);

        $this->resave($voucher, $form);
        $this->resave($voucher, $this->formOf($voucher));

        $this->assertSame($before, $this->lines($voucher), '⛔ কন্ট্রা সম্পাদনায় প্রতিবার চার্জের সমান কমল।');
    }

    /** ⓘ পরিশোধে চার্জ বাইরে যোগ হয় — আগেও ঠিক ছিল, এখনো ঠিক */
    public function test_a_charged_payment_still_shows_what_was_typed(): void
    {
        $voucher = $this->draft(Voucher::PAYMENT, $this->bank()->id, StandardChart::find(StandardChart::ENTERTAINMENT)->id);

        $this->assertSame(0, bccomp($this->formOf($voucher)['amount'], '1000', 4));
    }

    /** ⓘ চার্জ ছাড়া কাগজে কিছুই বদলায়নি */
    public function test_an_uncharged_receipt_is_as_before(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'amount' => '700',
                'party_type' => 'customer', 'party_id' => $customer->id],
            app(VoucherService::class)->twoLineEntry(Voucher::RECEIPT, StandardChart::find(StandardChart::RECEIVABLE)->id, $this->bank()->id, '700'),
        );

        $form = $this->formOf($voucher);
        $this->assertSame(0, bccomp($form['amount'], '700', 4));
        $this->assertSame(0, bccomp((string) ($form['charge_amount'] ?: '0'), '0', 4));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra */
    private function draft(string $type, int $from, int $to, array $extra = []): Voucher
    {
        return app(VoucherService::class)->create(
            ['type' => $type, 'trx_date' => now()->toDateString(), 'amount' => '1000', 'charge_amount' => '20', ...$extra],
            app(VoucherService::class)->twoLineEntry($type, $from, $to, '1000', null, '20'),
        );
    }

    /** @return array{amount: string, charge_amount: ?string} সম্পাদনার পাতায় ঘর দুটোয় যা বসে */
    private function formOf(Voucher $voucher): array
    {
        $html = $this->get(route('accounts.voucher.edit', $voucher))->assertOk()->getContent();

        return ['amount' => (string) $this->valueOf($html, 'amount'), 'charge_amount' => $this->valueOf($html, 'charge_amount')];
    }

    private function valueOf(string $html, string $name): ?string
    {
        if (! preg_match('/<input\b[^>]*\bname="'.preg_quote($name, '/').'"[^>]*>/', $html, $tag)) {
            return null;
        }

        return preg_match('/\bvalue="([^"]*)"/', $tag[0], $value) ? $value[1] : null;
    }

    /**
     * @param  array{amount: string, charge_amount: ?string}  $form
     * @param  array<string, mixed>  $extra
     */
    private function resave(Voucher $voucher, array $form, array $extra = []): void
    {
        $voucher = $voucher->fresh(['lines']);
        $debit = $voucher->lines->firstWhere(fn ($l) => bccomp((string) $l->debit, '0', 4) > 0);
        $credit = $voucher->lines->firstWhere(fn ($l) => bccomp((string) $l->credit, '0', 4) > 0);

        $this->put(route('accounts.voucher.update', $voucher), array_filter([
            'type' => $voucher->type,
            'trx_date' => $voucher->trx_date->toDateString(),
            'narration' => 'পরীক্ষার বিবরণ', // ⓘ বিবরণ বাধ্যতামূলক (ভাউচারের পরিকল্পনা ৩খ, ৭ অক্টোবর ২০২৬)
            'amount' => $form['amount'],
            'charge_amount' => $form['charge_amount'],
            'from_account_id' => $credit->account_id,
            'to_account_id' => $debit->account_id,
            /*
             * ⓘ খসড়া হিসেবে আবার রাখা — প্রশ্নটা সারি নিয়ে, পোস্ট নিয়ে নয় (ব্যাংকের পোস্টে লেনদেন নম্বর লাগে)। ⚠️ ৭ অক্টোবর
             * ২০২৬ পর্যন্ত এই PUT পরীক্ষায় ৫০০ দিত (`from_account_id` মাথায় ঢালা হতো) আর কিছুই বদলাত না — তাই "সারি অপরিবর্তিত"
             * ফাঁকা সবুজ ছিল। নিচের assertRedirect সেটা আর লুকাতে দেয় না ([[AJournalDraftCouldNotBeEditedTest]])।
             */
            'save_as_draft' => '1',
            ...$extra,
        ], fn ($v) => $v !== null))->assertSessionHasNoErrors()->assertRedirect(route('accounts.voucher.show', $voucher));
    }

    /** @return list<string> খাত · ডেবিট · ক্রেডিট */
    private function lines(Voucher $voucher): array
    {
        return $voucher->fresh(['lines'])->lines
            ->map(fn ($l) => $l->account_id.' · '.bcadd((string) $l->debit, '0', 2).' · '.bcadd((string) $l->credit, '0', 2))
            ->sort()->values()->all();
    }

    /** ⓘ ডেমোর ছকে ব্যাংকের খাত নেই — চার্জ কেবল ব্যাংক বা MFS-এ হয়, তাই বানিয়ে নেওয়া */
    private function bank(): Account
    {
        return Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? app(AccountService::class)->create([
                'code' => StandardChart::BANK.'-01',
                'name_en' => 'Test bank account',
                'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            ]);
    }
}

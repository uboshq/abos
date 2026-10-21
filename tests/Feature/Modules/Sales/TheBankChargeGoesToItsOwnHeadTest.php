<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DepositClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ ব্যাংক চার্জ নিজের খাতে, ডিলারের পাওনা কমে পুরো অঙ্কে — টাকার পরিকল্পনা ৫ (মালিক, ৭ অক্টোবর ২০২৬)।
 *
 * ⓘ ডিলার ৫০,০০০ পাঠালেন, ব্যাংকে এল ৪৯,৯৫০। আগে গ্রহণে অঙ্ক ৪৯,৯৫০ করলে পাওনাও ৪৯,৯৫০ কমত — ৫০ টাকা তাঁর নামে বকেয়া
 * থেকে যেত, অথচ তিনি পুরোটাই পাঠিয়েছেন; আর চার্জটা কোনো খরচের খাতে আসত না। এখন খাতায়:
 * ব্যাংক ৪৯,৯৫০ Dr · ব্যাংক চার্জ ৫০ Dr · ডিলারের পাওনা ৫০,০০০ Cr।
 */
final class TheBankChargeGoesToItsOwnHeadTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $decider;

    private Account $bank;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->decider = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->decider->companies()->attach($this->company->id, ['is_active' => true]);
        $this->decider->givePermissionTo('sales.claim.decide');

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-CHARGE', 'name_en' => 'Charge bank', 'name_bn' => 'চার্জের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);

        $this->dealer = Customer::query()->firstOrFail();
    }

    public function test_the_dealer_is_credited_in_full_and_the_charge_lands_on_its_own_head(): void
    {
        $claim = $this->claim('50000');
        $dueBefore = $this->dealer->outstanding();

        $this->actingAs($this->decider->fresh())
            ->post(route('sales.claim.accept', $claim), ['account_id' => $this->bank->id, 'amount' => '49950', 'bank_charge' => '50'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $collection = Collection::query()->findOrFail($claim->fresh()->collection_id);
        $this->assertSame(0, bccomp((string) $collection->amount, '50000', 4), '⛔ আদায়টা পুরো পাঠানো অঙ্কের নয়');
        $this->assertSame(0, bccomp((string) $collection->bank_charge, '50', 4));

        $this->assertSame(bcsub($dueBefore, '50000', 4), $this->dealer->fresh()->outstanding(),
            '⛔ ডিলারের পাওনা পুরো ৫০,০০০ কমেনি — চার্জের ৫০ তাঁর নামে বকেয়া থেকে গেল');

        $this->assertSame(0, bccomp($this->net($this->bank->id, $collection), '49950', 4), '⛔ ব্যাংকে যা সত্যিই এসেছে, খাতায় তা নয়');
        $this->assertSame(0, bccomp($this->net(StandardChart::find(StandardChart::BANK_CHARGES)->id, $collection), '50', 4),
            '⛔ ব্যাংক চার্জ নিজের খাতে বসেনি');

        // ⓘ বাতিল করলে চার্জও ফেরে — উল্টো দাখিলা হুবহু
        $this->actingAs($this->decider->fresh());
        app(CollectionService::class)->cancel($collection->fresh(), 'ভুল ব্যাংকে মঞ্জুর');
        $charges = LedgerEntry::query()->where('account_id', StandardChart::find(StandardChart::BANK_CHARGES)->id)
            ->where('source_id', $collection->id)->selectRaw('COALESCE(SUM(debit - credit), 0) as n')->value('n');
        $this->assertSame(0, bccomp((string) $charges, '0', 4), '⛔ বাতিলের পরেও চার্জ খাতে থেকে গেল');
    }

    /**
     * ⭐ দফা #৯ — MFS-এ আসা টাকার চার্জ MFS চার্জের খাতে (৫২১১), ব্যাংক চার্জে (৫২১০) নয় (fe, ২১ অক্টোবর ২০২৬)।
     * ⛔ আগে আদায়ের সব চার্জ ৫২১০-এ যেত, তাই বিকাশের খরচ "ব্যাংক চার্জ"-এর রিপোর্টে বসত। পক্ষের খাতায় তবু পুরো টাকা।
     */
    public function test_item_9_an_mfs_charge_lands_on_the_mfs_head_not_the_banks(): void
    {
        $wallet = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1105-CHARGE', 'name_en' => 'Charge wallet', 'name_bn' => 'চার্জের ওয়ালেট',
            'parent_id' => StandardChart::find(StandardChart::MOBILE_MONEY)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::MFS,
        ]);
        // ⓘ ওয়ালেটে আসা টাকার দাবি MFS পদ্ধতিতে — নগদের দাবি ওয়ালেটে নেওয়া পদ্ধতি-খাত মিলের নিয়মে ঠিকই থামে
        $claim = $this->claim('20000', DepositClaim::MFS, 'TRX9MFS20000');
        $dueBefore = $this->dealer->outstanding();

        $this->actingAs($this->decider->fresh())
            ->post(route('sales.claim.accept', $claim), ['account_id' => $wallet->id, 'amount' => '19820', 'bank_charge' => '180'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $collection = Collection::query()->findOrFail($claim->fresh()->collection_id);
        $this->assertSame(0, bccomp($this->net(StandardChart::find(StandardChart::MFS_CHARGES)->id, $collection), '180', 4),
            '⛔ #৯: MFS-এর চার্জ MFS চার্জের খাতে (৫২১১) বসেনি');
        $this->assertSame(0, bccomp($this->net(StandardChart::find(StandardChart::BANK_CHARGES)->id, $collection), '0', 4),
            '⛔ #৯: MFS-এর চার্জ ব্যাংক চার্জে (৫২১০) গেল');
        $this->assertSame(0, bccomp($this->net($wallet->id, $collection), '19820', 4), '⛔ ওয়ালেটে যা সত্যিই এসেছে, খাতায় তা নয়');
        $this->assertSame(bcsub($dueBefore, '20000', 4), $this->dealer->fresh()->outstanding(), '⛔ ডিলারের পাওনা পুরো অঙ্কে কমেনি');
    }

    public function test_without_a_charge_nothing_changes(): void
    {
        $claim = $this->claim('30000');

        $this->actingAs($this->decider->fresh())
            ->post(route('sales.claim.accept', $claim), ['account_id' => $this->bank->id])
            ->assertSessionHasNoErrors();

        $collection = Collection::query()->findOrFail($claim->fresh()->collection_id);
        $this->assertSame(0, bccomp($this->net($this->bank->id, $collection), '30000', 4));
        $this->assertSame(0, LedgerEntry::query()->where('account_id', StandardChart::find(StandardChart::BANK_CHARGES)->id)
            ->where('source_id', $collection->id)->count(), 'চার্জ ছাড়া চার্জের সারি বসেছে');
    }

    public function test_a_charge_that_eats_the_whole_amount_is_refused(): void
    {
        $this->actingAs($this->decider->fresh());

        try {
            app(CollectionService::class)->create([
                'customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(), 'amount' => '100',
                'bank_charge' => '100', 'account_id' => $this->bank->id,
            ], []);
            $this->fail('⛔ পুরো অঙ্ক চার্জ — টাকার খাতে কিছুই আসত না, তবু আদায় তৈরি হল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bank_charge', $e->errors());
        }
    }

    /**
     * ⛔ সম্পাদনাতেও একই পাহারা — Sales লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬। ⚠️ আগে খসড়া আদায়ের অঙ্ক সম্পাদনায় শূন্য বা চার্জের
     * সমান-কম করা যেত (নতুন বানানোয় যা আটকায়); পাকা করার সময় টাকার খাতে ঋণাত্মক ডেবিট গিয়ে খাতার ইঞ্জিন ভাঙত।
     */
    public function test_an_edit_cannot_shrink_the_amount_under_the_charge_or_to_zero(): void
    {
        $this->actingAs($this->decider->fresh());
        $service = app(CollectionService::class);

        $draft = $service->create([
            'customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(), 'amount' => '50000',
            'bank_charge' => '900', 'account_id' => $this->bank->id,
        ], []);

        foreach (['500' => 'amount', '900' => 'amount', '0' => 'amount'] as $amount => $field) {
            try {
                $service->update($draft->fresh(), [
                    'trx_date' => now()->toDateString(), 'amount' => (string) $amount, 'account_id' => $this->bank->id,
                ], []);
                $this->fail("⛔ সম্পাদনায় অঙ্ক {$amount} — চার্জ ৯০০ বা শূন্য, তবু সংরক্ষণ হল");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $this->assertSame(0, bccomp((string) $draft->fresh()->amount, '50000', 4), '⛔ ফেরানো সম্পাদনা তবু অঙ্ক বদলে দিল');
    }

    private function claim(string $amount, string $method = DepositClaim::CASH, ?string $reference = null): DepositClaim
    {
        return app(DepositClaimService::class)->raise($this->dealer, [
            'claimed_on' => now()->subDay()->toDateString(),
            'amount' => $amount,
            'method' => $method,
            'reference' => $reference,
            'bank_account_id' => $this->bank->id,
        ]);
    }

    /** এই আদায়ের নামে একটা খাতের নিট ডেবিট */
    private function net(int $accountId, Collection $collection): string
    {
        return (string) LedgerEntry::query()
            ->where('source_type', Collection::drillSourceType())->where('source_id', $collection->id)
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as n')->value('n');
    }
}

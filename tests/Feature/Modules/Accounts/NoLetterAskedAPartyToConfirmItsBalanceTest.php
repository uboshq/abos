<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\BalanceConfirmation;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ⛔ বছর শেষে গ্রাহক বা সরবরাহকারীকে জের নিশ্চিত করতে বলার কোনো চিঠি ছিল না (ERP ১০০%-এর হিসাবের ফাঁক #১৯, ২০ অক্টোবর ২০২৬)।
 *
 * <p>ISA 505: নিরীক্ষক পাওনা-দেনা পক্ষের কাছ থেকে নিশ্চিত করান। এখন গ্রাহক আর সরবরাহকারীর পাতায় চিঠি — একটা তারিখের জের, কার
 * দিকে, আর উত্তরের অংশ। ⛔ শর্ত (fe): নাম · পয়েন্ট · শাখা; A4, দুই ভাষায়; জের খাতার সাথে পয়সা পর্যন্ত; কে কবে ছাপলেন নিরীক্ষায়।
 */
final class NoLetterAskedAPartyToConfirmItsBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $till;

    /** @var array<string, mixed> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        $this->till = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;

        // ⓘ ছাঁচ যা পায় আর কোন ভাষায় আঁকে — PDF-এর ভেতরে না ঢুকে
        View::composer('print.balance-confirmation', function ($view) {
            $this->seen = [...$view->getData(), 'locale' => app()->getLocale()];
        });
    }

    public function test_a_customers_letter_names_the_customer_and_says_the_ledger_balance_to_the_paisa_in_english(): void
    {
        // ⓘ নমুনার গ্রাহকেরা শাখাহীন — শাখা বসিয়ে নেওয়া, যাতে নামের "· শাখা" অংশটা দেখা যায়
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $customer->forceFill(['branch_id' => CompanyContext::branchId() ?? Company::query()->findOrFail(CompanyContext::id())->defaultBranch()->id])->saveQuietly();
        $customer = $customer->fresh(['location.parent', 'branch']);
        $party = ['party_type' => Customer::drillSourceType(), 'party_id' => $customer->id];
        $receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $sales = (int) StandardChart::find(StandardChart::SALES)->id;
        $asOf = now()->subDay()->toDateString();

        $this->book(now()->subDays(3)->toDateString(), [$receivable, $party], [$sales, []], '10000.75');
        $this->book(now()->subDays(2)->toDateString(), [$this->till, []], [$receivable, $party], '2500');
        $this->book(now()->toDateString(), [$receivable, $party], [$sales, []], '999'); // চিঠির তারিখের পরে — চিঠিতে নয়

        $response = $this->get(route('customer.balance_confirmation', ['customer' => $customer, 'as_of' => $asOf, 'lang' => 'en']))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $expected = $this->ledgerNet(Customer::drillSourceType(), (int) $customer->id, $asOf);
        $this->assertSame(Money::format(ltrim($expected, '-')), $this->seen['amount'], '⛔ চিঠির জের খাতার সাথে পয়সা পর্যন্ত মেলে না');
        $this->assertSame('dr', $this->seen['side'], '⛔ গ্রাহকের কাছে পাওনা "আমাদের পাওনা" বলে না');
        $this->assertSame('en', $this->seen['locale'], '⛔ ইংরেজি চাইলাম, অন্য ভাষায় এল');
        $this->assertSame($asOf, $this->seen['asOf']);

        $label = collect([$customer->nameWithPoint(), $customer->branch?->name()])->filter()->implode(' · ');
        $this->assertSame($label, $this->seen['party'], '⛔ চিঠিতে নাম · পয়েন্ট · শাখা নেই');
        $this->assertNotNull($customer->branch, 'প্রস্তুতিটাই ভুল — গ্রাহকের শাখা নেই');
        $this->assertStringContainsString((string) $customer->branch->name(), $this->seen['party']);

        $audit = AuditTrail::query()->where('auditable_type', $customer->getMorphClass())->where('auditable_id', $customer->id)
            ->where('action', BalanceConfirmation::ACTION)->latest('id')->first();
        $this->assertNotNull($audit, '⛔ কে কবে চিঠি ছাপলেন নিরীক্ষায় নেই');
        $this->assertSame((int) $this->owner->id, (int) $audit->user_id);
        $this->assertStringContainsString($asOf, (string) $audit->reason);
    }

    public function test_a_suppliers_letter_says_what_we_owe_without_goods_not_yet_billed_and_in_bengali(): void
    {
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $party = ['party_type' => Supplier::drillSourceType(), 'party_id' => $supplier->id];
        $payable = (int) StandardChart::find(StandardChart::PAYABLE)->id;
        $purchases = (int) \App\Modules\Accounts\Models\Account::query()->where('code', '5202')->value('id');
        $grni = Supplier::goodsNotBilledAccountIds()[0] ?? null;
        $this->assertNotNull($grni, 'প্রস্তুতিটাই ভুল — GRNI-র খাত নেই');
        $asOf = now()->subDay()->toDateString();

        $this->book(now()->subDays(3)->toDateString(), [$purchases, []], [$payable, $party], '8000');
        $this->book(now()->subDays(2)->toDateString(), [$payable, $party], [$this->till, []], '3000.40');
        $this->book(now()->subDays(2)->toDateString(), [$purchases, []], [(int) $grni, $party], '500'); // বিল আসেনি — দেনা নয়

        $this->get(route('supplier.balance_confirmation', ['supplier' => $supplier, 'as_of' => $asOf, 'lang' => 'bn']))->assertOk();

        $expected = $this->ledgerNet(Supplier::drillSourceType(), (int) $supplier->id, $asOf, exceptAccounts: Supplier::goodsNotBilledAccountIds());
        $withGrni = $this->ledgerNet(Supplier::drillSourceType(), (int) $supplier->id, $asOf);
        $this->assertSame(0, bccomp(bcsub($expected, $withGrni, 4), '500', 4), 'প্রস্তুতিটাই ভুল — GRNI-র ৫০০ খাতায় বসেনি');
        $this->assertSame(Money::format(ltrim($expected, '-')), $this->seen['amount'], '⛔ চিঠির দেনা খাতার সাথে মেলে না (GRNI ঢুকল?)');
        $this->assertSame(bccomp($expected, '0', 4) < 0 ? 'cr' : 'dr', $this->seen['side'], '⛔ সরবরাহকারীর দেনার দিক ভুল');
        $this->assertSame('cr', $this->seen['side'], 'প্রস্তুতিটাই ভুল — সরবরাহকারীর কাছে আমাদের দেনা নেই');
        $this->assertSame('bn', $this->seen['locale']);
        $this->assertStringContainsString($supplier->name(), $this->seen['party']);
        $this->assertTrue(AuditTrail::query()->where('auditable_id', $supplier->id)->where('auditable_type', $supplier->getMorphClass())
            ->where('action', BalanceConfirmation::ACTION)->exists(), '⛔ সরবরাহকারীর চিঠি নিরীক্ষায় নেই');
    }

    public function test_someone_who_may_not_see_the_customer_gets_no_letter_and_no_audit_row(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $stranger = User::factory()->create();
        $stranger->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => CompanyContext::id()])->save();

        $this->actingAs($stranger)->get(route('customer.balance_confirmation', $customer))->assertForbidden();
        $this->assertFalse(AuditTrail::query()->where('action', BalanceConfirmation::ACTION)->exists());
    }

    /** খাতার নিজের যোগ — পক্ষের সব সারি, তারিখ পর্যন্ত (চিঠির হিসাব থেকে আলাদা পথে) */
    /** @param  list<int>  $exceptAccounts */
    private function ledgerNet(string $type, int $id, string $asOf, array $exceptAccounts = []): string
    {
        return bcadd((string) LedgerEntry::query()->where('party_type', $type)->where('party_id', $id)
            ->whereDate('trx_date', '<=', $asOf)
            ->when($exceptAccounts !== [], fn ($q) => $q->whereNotIn('account_id', $exceptAccounts))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as n')->value('n'), '0', 4);
    }

    /** @param  array{0: int, 1: array<string, mixed>}  $debit  @param  array{0: int, 1: array<string, mixed>}  $credit */
    private function book(string $date, array $debit, array $credit, string $amount): void
    {
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: $date, lines: [
            ['account_id' => $debit[0], 'debit' => $amount, ...$debit[1]],
            ['account_id' => $credit[0], 'credit' => $amount, ...$credit[1]],
        ]);
    }
}

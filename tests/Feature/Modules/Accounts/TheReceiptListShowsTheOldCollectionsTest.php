<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ রসিদের তালিকায় আগের আদায় (COL-…), "আদায় (পুরনো)" দাগে — মালিক, ২০ নভেম্বর ২০২৬ (টাকা ঢোকার কাগজ একটাই, রসিদ)।
 *
 * ⛔ আদায় আর লেখা হয় না, কিন্তু আগের দশটা কাগজ খোঁজা লাগে — মালিক অনুমোদন দিয়ে খাতায় টাকা দেখলেন, অথচ রসিদের তালিকায় কাগজটা
 * পেলেন না। দাবি: রসিদের তালিকা আগের আদায় দেখায়, কাগজের পাতার লিংকসহ, একই তারিখ আর পক্ষের ছাঁকনিতে; বাতিল নয়; অন্য ধরনের
 * ভাউচারের তালিকায় নয়; আদায় দেখার চাবি না থাকলে নয়।
 */
final class TheReceiptListShowsTheOldCollectionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->dealer = Customer::query()->orderBy('id')->firstOrFail();
    }

    public function test_the_receipt_list_shows_an_old_collection_marked_old_with_its_page(): void
    {
        $old = $this->collection('COL-OLD-0001', '2026-11-10', '12500');
        $gone = $this->collection('COL-OLD-0002', '2026-11-10', '900', DocumentStatus::CANCELLED);

        $html = (string) $this->get(route('accounts.voucher.index', ['type' => 'receipt']))->assertOk()->getContent();

        $this->assertStringContainsString('data-old-papers', $html, '⛔ রসিদের তালিকায় আগের আদায়ের অংশ নেই');
        $this->assertStringContainsString('data-old-paper="COL-OLD-0001"', $html, '⛔ আগের আদায় COL-OLD-0001 রসিদের তালিকায় নেই');
        $this->assertStringContainsString(e(route('sales.collection.show', $old)), $html, '⛔ আগের আদায়ের সারি তার পাতা খোলে না');
        $this->assertStringContainsString(e(__('accounts::voucher.old_papers')), $html, '⛔ "আদায় (পুরনো)" দাগ নেই');
        $this->assertStringNotContainsString('data-old-paper="COL-OLD-0002"', $html, '⛔ বাতিল আদায় তালিকায় এল');
        $this->assertNotNull($gone);

        // ⓘ একই ছাঁকনি — অন্য দিনের তারিখে আগের আদায় নেই, সেই দিনে আছে
        $later = (string) $this->get(route('accounts.voucher.index', ['type' => 'receipt', 'from' => '2026-11-15', 'to' => '2026-11-20']))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-old-paper="COL-OLD-0001"', $later, '⛔ তারিখের ছাঁকনি আগের আদায়ে খাটল না');
        $same = (string) $this->get(route('accounts.voucher.index', ['type' => 'receipt', 'from' => '2026-11-10', 'to' => '2026-11-10',
            'party' => 'customer:'.$this->dealer->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-old-paper="COL-OLD-0001"', $same, '⛔ সেই দিনের, সেই গ্রাহকের আগের আদায় দেখা গেল না');

        // ⓘ কেবল রসিদের তালিকায় — পরিশোধের তালিকায় আদায় কেন
        $payments = (string) $this->get(route('accounts.voucher.index', ['type' => 'payment']))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-old-papers', $payments, '⛔ পরিশোধের তালিকায় আগের আদায় এল');
    }

    public function test_without_the_collection_key_the_old_papers_are_not_shown(): void
    {
        $this->collection('COL-OLD-0003', '2026-11-10', '4000');

        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo(['accounts.report']);

        $html = (string) $this->actingAs($clerk->fresh())->get(route('accounts.voucher.index', ['type' => 'receipt']))->assertOk()->getContent();

        $this->assertStringNotContainsString('COL-OLD-0003', $html, '⛔ আদায় দেখার চাবি ছাড়া আগের আদায় রসিদের তালিকায় দেখা গেল');
    }

    private function collection(string $no, string $on, string $amount, string $status = DocumentStatus::CONFIRMED): Collection
    {
        $bank = Account::query()->where('money_kind', Account::BANK)->where('is_group', false)->orderBy('id')->firstOrFail();

        return Collection::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no,
            'customer_id' => $this->dealer->id, 'account_id' => $bank->id, 'trx_date' => $on, 'amount' => $amount, 'status' => $status,
        ]);
    }
}

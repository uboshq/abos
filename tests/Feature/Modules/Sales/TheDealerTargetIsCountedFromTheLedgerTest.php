<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Imports\CustomerTargetImporter;
use App\Modules\Sales\Services\CustomerTargetService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ডিলারের মাসিক আদায়ের লক্ষ্য — বিলের "টার্গেট রিমাইন্ডার"। মালিক, ৩ অক্টোবর ২০২৬ ([[CustomerTargetService]])।
 *
 * নতুন ডিলার, অক্টোবর ২০২৬, লক্ষ্য ৩,০০,০০০, শেষ ২৫ তারিখ। মাসের ভেতরে খাতায়:
 *   · আদায় ১,০০,০০০ আর রসিদ ভাউচার ৭০,০০০ — গোনা হয়;
 *   · ফেরত মাল ৫০,০০০ — খাতা কমায়, টাকা আসেনি: গোনা হয় না;
 *   · চেক ২০,০০০ হাতে আসার দিনই জমা, পরে ফেরত — গোনা হয় না;
 *   · চেক ১৫,০০০ হাতে আসার দিনই জমা, এখনো পাশ হয়নি — গোনা হয় না (চেক কেবল ক্লিয়ার হলে)।
 * অর্জন তাই ১,৭০,০০০, বাকি ১,৩০,০০০; ৩ থেকে ২৫ অক্টোবর রবি–বৃহস্পতি = ১৬ দিন।
 */
final class TheDealerTargetIsCountedFromTheLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-03 11:00:00'));

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->dealer = Customer::query()->create(['code' => 'TGT-1', 'name_en' => 'Target Dealer', 'name_bn' => 'Target Dealer', 'is_active' => true]);
    }

    public function test_the_reminder_counts_money_in_only_and_the_bank_days_left(): void
    {
        app(CustomerTargetService::class)->setOne((int) $this->dealer->id, Carbon::parse('2026-10-01'), '300000', '2026-10-25');

        $this->credit('collection', '100000');
        $this->credit('receipt_voucher', '70000');
        $this->credit('sales_return', '50000');

        $bounced = $this->cheque('20000', 'bounced');
        $this->credit('cheque', '20000', $bounced);
        $this->ledgerPost('cheque:bounced', [['account_id' => $this->receivable(), 'debit' => '20000', ...$this->party()], ['account_id' => StandardChart::find(StandardChart::CHEQUES_IN_HAND)->id, 'credit' => '20000']], $bounced);

        $held = $this->cheque('15000', 'pending');
        $this->credit('cheque', '15000', $held);

        $r = app(CustomerTargetService::class)->reminderFor((int) $this->dealer->id);

        $this->assertNotNull($r, 'প্রস্তুতিটাই ভুল — লক্ষ্য পাওয়া যায়নি।');
        $this->assertSame(0, bccomp($r['target'], '300000', 4));
        $this->assertSame(0, bccomp($r['achieved'], '170000', 4), "⛔ অর্জন ১,৭০,০০০ হওয়ার কথা — ফেরত মাল, ফেরত চেক বা পাশ না হওয়া চেক ঢুকেছে? এল {$r['achieved']}");
        $this->assertSame(0, bccomp($r['remaining'], '130000', 4), '⛔ বাকি ১,৩০,০০০ হওয়ার কথা।');
        $this->assertSame('2026-10-25', $r['closes_on']->toDateString());
        $this->assertSame(16, $r['bank_days'], '⛔ ৩–২৫ অক্টোবর রবি–বৃহস্পতি ১৬ দিন।');
    }

    public function test_no_target_means_no_box(): void
    {
        $this->credit('collection', '100000');

        $this->assertNull(app(CustomerTargetService::class)->reminderFor((int) $this->dealer->id), '⛔ লক্ষ্য নেই, অথচ বাক্সের অঙ্ক এসেছে।');
    }

    public function test_the_page_needs_its_key_and_saves_through_the_service_same_person_off_then_on(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($clerk)->get(route('sales.customer_target.index'))->assertForbidden();

        $clerk->givePermissionTo(['sales.customer_target.view', 'sales.customer_target.manage']);
        $this->actingAs($clerk->fresh())->get(route('sales.customer_target.index'))->assertOk()->assertSee('TGT-1');

        $this->actingAs($clerk->fresh())->post(route('sales.customer_target.store'), [
            'month' => '2026-10-01',
            'target' => [$this->dealer->id => ['amount' => '250000', 'closes_on' => '2026-10-25']],
        ])->assertRedirect();

        $this->assertSame(0, bccomp(app(CustomerTargetService::class)->reminderFor((int) $this->dealer->id)['target'], '250000', 4), '⛔ পাতা থেকে লক্ষ্য বসেনি।');
    }

    public function test_the_import_takes_the_same_road_and_refuses_a_closing_date_outside_the_month(): void
    {
        $importer = app(CustomerTargetImporter::class);

        $this->assertNotSame([], $importer->check(['code' => 'TGT-1', 'month' => '2026-10', 'amount' => '1000', 'closes_on' => '2026-11-02']), '⛔ মাসের বাইরের শেষ তারিখ ধরা পড়েনি।');
        $this->assertNotSame([], $importer->check(['code' => 'NOBODY', 'month' => '2026-10', 'amount' => '1000']), '⛔ অজানা কোড ধরা পড়েনি।');

        $row = ['code' => 'TGT-1', 'month' => '2026-10', 'amount' => '120000', 'closes_on' => ''];
        $this->assertSame([], $importer->check($row));
        $importer->import($row);

        $r = app(CustomerTargetService::class)->reminderFor((int) $this->dealer->id);
        $this->assertSame(0, bccomp($r['target'], '120000', 4), '⛔ ইমপোর্টে লক্ষ্য বসেনি।');
        $this->assertSame('2026-10-31', $r['closes_on']->toDateString(), '⛔ শেষ তারিখ ফাঁকা হলে মাসের শেষ দিন।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function credit(string $source, string $amount, ?int $sourceId = null): void
    {
        $cash = DB::table('accounts')->where('company_id', $this->company->id)->where('money_kind', 'cash')->where('is_group', false)->orderBy('id')->value('id');

        $this->ledgerPost($source, [['account_id' => $cash, 'debit' => $amount], ['account_id' => $this->receivable(), 'credit' => $amount, ...$this->party()]], $sourceId);
    }

    private function ledgerPost(string $source, array $lines, ?int $sourceId = null): void
    {
        app(PostingEngine::class)->post(
            sourceType: $source, sourceId: $sourceId ?? random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: $lines,
            branchId: $this->company->defaultBranch()?->id,
        );
    }

    private function cheque(string $amount, string $status): int
    {
        return (int) DB::table('acc_cheques')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::uuid7(),
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'direction' => 'received',
            'cheque_date' => now()->toDateString(),
            'received_on' => now()->toDateString(),
            'cheque_no' => 'TGT-'.random_int(1000, 9999),
            'amount' => $amount,
            'party_type' => 'customer',
            'party_id' => $this->dealer->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function receivable(): int
    {
        return (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
    }

    /** @return array{party_type: string, party_id: int} */
    private function party(): array
    {
        return ['party_type' => 'customer', 'party_id' => (int) $this->dealer->id];
    }
}

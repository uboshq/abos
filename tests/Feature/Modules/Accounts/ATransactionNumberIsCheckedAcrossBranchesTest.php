<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ ব্যাংক/MFS-এর লেনদেন নম্বর (TrxID) দুইবার ঢোকার পাহারা — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️১৩)।
 *
 * ⓘ দুইটা ফাঁক ছিল [[VoucherService::assertBankReferenceIsFree()]]-এ:
 *  · খোঁজ দেখার শাখার দেয়ালে — অন্য শাখার ভাউচারে একই TrxID থাকলে পরিষ্কার বার্তা নয়, ডেটাবেসের ভাঙা ভুল।
 *  · যাচাইটা খাতা-জোড়া লিখেও দেয়, অথচ ছিল লেনদেনের বাইরে — পরে টাকা না থাকায় পোস্ট থামলেও খসড়ায় জোড়াটা রয়ে যেত, আর সঠিক
 *    ভাউচারে একই TrxID আর বসত না।
 */
final class ATransactionNumberIsCheckedAcrossBranchesTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Account $bkash;

    private int $rent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->bkash = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1105-TRX', 'name_en' => 'bKash Agent', 'name_bn' => 'বিকাশ এজেন্ট',
            'parent_id' => StandardChart::find(StandardChart::MOBILE_MONEY)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::MFS, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
        $this->rent = (int) Account::query()->where('code', '5202')->firstOrFail()->id;
    }

    public function test_a_number_used_in_another_branch_is_refused_in_plain_words(): void
    {
        $this->putMoneyIn($this->bkash, '100000', '2026-08-01');

        $this->choose($this->branch('NTK')->id);
        $first = $this->service()->post($this->draft('TRX-778899'));

        $this->choose($this->branch('MMS')->id);
        $this->assertNull(Voucher::query()->find($first->id), 'দৃশ্যটাই বানানো যায়নি — MMS বাছা অবস্থায় NTK-র ভাউচার দেখা যায়');

        $second = $this->draft('TRX-778899');

        try {
            $this->service()->post($second);
            $this->fail('⛔ অন্য শাখায় বসা TrxID দিয়ে একই টাকা দ্বিতীয়বার খাতায় উঠল');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.bank_reference_used', ['reference' => 'TRX-778899', 'no' => $first->document_no]),
                $e->errors()['instrument_no'][0] ?? null, '⛔ অন্য শাখার জোড়া পরিষ্কার কথায় ধরা পড়েনি');
        }

        $this->assertSame(DocumentStatus::DRAFT, $second->fresh()->status);
    }

    public function test_a_post_that_stops_does_not_keep_the_number(): void
    {
        // ⓘ বিকাশে টাকা নেই — পোস্ট লেনদেনের ভেতরে থামে, TrxID যাচাই তার আগেই হয়ে গেছে
        $wrong = $this->draft('TRX-445566');

        try {
            $this->service()->post($wrong);
            $this->fail('প্রস্তুতিটাই ভুল — খালি বিকাশ থেকে পরিশোধ হয়ে গেল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors(), 'প্রস্তুতিটাই ভুল — অন্য কারণে থামল');
        }

        $this->assertNull($wrong->fresh()->money_account_id, '⛔ থেমে যাওয়া পোস্টের খসড়া TrxID আটকে রাখল');

        $this->putMoneyIn($this->bkash, '100000', '2026-08-01');
        $right = $this->service()->post($this->draft('TRX-445566'));

        $this->assertTrue($right->isPosted(), '⛔ সঠিক ভাউচারে একই TrxID বসল না');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function service(): VoucherService
    {
        return app(VoucherService::class);
    }

    private function draft(string $reference): Voucher
    {
        return $this->service()->create(
            ['type' => Voucher::PAYMENT, 'trx_date' => '2026-08-10', 'narration' => 'ভাড়া', 'instrument' => 'mfs', 'instrument_no' => $reference],
            $this->service()->twoLineEntry(Voucher::PAYMENT, $this->bkash->id, $this->rent, '5000.00', 'ভাড়া'),
        );
    }

    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => (string) $branch])->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}

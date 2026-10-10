<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AdjustingReversals;
use App\Modules\Accounts\Services\MonthEndChecklist;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * উল্টোর মাস বন্ধ থাকলে নিজে-উল্টো প্রতি ঘণ্টায় চুপচাপ ব্যর্থ হত — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (হিসাব, ঠিক ২)।
 *
 * ⛔ আগে: কেবল শিডিউলারের লগে একটা সারি, প্রতি ঘণ্টায়; হিসাবরক্ষক কিছুই জানতেন না।
 * ⭐ এখন: যিনি মাস বন্ধ/খোলা করেন, তিনি ঘণ্টায় একবারই খবর পান ([[AdjustingReversals::tellStuck()]]), আর মাস-শেষের তালিকায়
 *   "আটকে থাকা উল্টো" সারি লাল থাকে যতদিন না বসে ([[MonthEndChecklist]])।
 */
final class AStuckReversalFailedSilentlyEveryHourTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
    }

    public function test_a_locked_month_tells_the_owner_once_and_stays_visible_as_stuck(): void
    {
        $month = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
        $original = $this->adjusting($month->toDateString(), $month->endOfMonth()->toDateString());

        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => (int) $month->year, 'month' => (int) $month->month,
            'reason' => 'মাস বন্ধ', 'locked_by' => $this->owner->id, 'locked_at' => now(),
        ]);

        // ⓘ শিডিউলারের মতো — কেউ লগইন নয়
        auth()->logout();

        $first = app(AdjustingReversals::class)->run();
        $second = app(AdjustingReversals::class)->run();

        $this->assertCount(1, $first['failed']);
        $this->assertCount(1, $second['failed'], 'বন্ধ মাসে উল্টো বসে গেল।');
        $this->assertFalse(Voucher::acrossBranches()->where('reversal_of_id', $original->id)->exists());

        $told = Notification::query()->where('user_id', $this->owner->id)->where('type', AdjustingReversals::STUCK)->get();
        $this->assertCount(1, $told, '⛔ আটকে থাকা উল্টোর খবর মালিক পাননি, নয়তো প্রতি ঘণ্টায় আবার পেলেন।');
        $this->assertStringContainsString((string) $original->document_no, (string) $told->first()->title);

        // ⓘ খবর পড়ে ফেললেও আটকে থাকাটা মাস-শেষের তালিকায় লাল
        $row = collect(app(MonthEndChecklist::class)->run($month))->keyBy('key')['reversals_stuck'];
        $this->assertSame(MonthEndChecklist::PENDING, $row['state'], '⛔ মাস-শেষের তালিকায় আটকে থাকা উল্টো দেখা যায় না।');
        $this->assertSame(1, $row['count']);

        // ⓘ মাস খুললে পরের ঘণ্টায় বসে, আর সারিটা সবুজ
        PeriodLock::query()->delete();
        $this->assertSame(1, app(AdjustingReversals::class)->run()['reversed']);
        $row = collect(app(MonthEndChecklist::class)->run($month))->keyBy('key')['reversals_stuck'];
        $this->assertSame(MonthEndChecklist::OK, $row['state']);
    }

    private function adjusting(string $on, string $reverseOn): Voucher
    {
        $receivable = StandardChart::find(StandardChart::RECEIVABLE);
        $receivable = $receivable->is_group
            ? Account::query()->postable()->whereKey($receivable->selfAndDescendants()->pluck('id'))->orderBy('code')->firstOrFail()
            : $receivable;

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $on, 'reverse_on' => $reverseOn, 'narration' => 'মাসশেষের বকেয়া আয়', 'is_adjusting' => true],
            [['account_id' => $receivable->id, 'debit' => '300', 'credit' => '0', 'party_type' => 'customer', 'party_id' => Customer::query()->orderBy('id')->value('id')],
                ['account_id' => StandardChart::find(StandardChart::RENT_INCOME)->id, 'debit' => '0', 'credit' => '300']],
        );
        app(VoucherService::class)->post($voucher);

        return $voucher->fresh(['lines']);
    }
}

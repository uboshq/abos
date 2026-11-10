<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ সম্পাদনায় ফাঁকা কাটা-করের ঘর ৫০০ দিত — লাইভ, ১০ নভেম্বর ২০২৬, ১৭:১১: RCV-0441-এর লেনদেন নম্বর ঠিক করতে গিয়ে মালিক আর
 * হিসাবরক্ষক তিনবার "Column 'ait_amount' cannot be null"। ⭐ এখন তৈরির মতোই ফাঁকা = শূন্য ([[VoucherService::writeHeaderAndLines()]])।
 */
final class AnEmptyTaxBoxDoesNotBreakAnEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_edit_with_empty_tax_and_difference_boxes_saves_them_as_zero(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $lines = [
            ['account_id' => $till->id, 'debit' => '500', 'credit' => '0'],
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => '0', 'credit' => '500'],
        ];
        $service = app(VoucherService::class);
        $voucher = $service->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'ফাঁকা ঘরের দাবি'], $lines);

        $service->update($voucher, ['trx_date' => now()->toDateString(), 'narration' => 'নম্বর ঠিক করা হলো',
            'ait_amount' => null, 'vds_amount' => '', 'difference_amount' => null], $lines);

        $fresh = $voucher->fresh();
        $this->assertSame('নম্বর ঠিক করা হলো', $fresh->narration, '⛔ সম্পাদনা রাখা হয়নি।');
        $this->assertSame('0.0000', (string) $fresh->ait_amount, '⛔ ফাঁকা AIT শূন্য হয়নি।');
        $this->assertSame('0.0000', (string) $fresh->vds_amount);
    }
}

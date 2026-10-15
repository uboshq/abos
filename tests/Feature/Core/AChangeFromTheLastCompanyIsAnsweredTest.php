<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Sync\SyncService;
use App\Core\Services\SettingsService;
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
 * ⛔ আগের কোম্পানিতে বসা বদল আবার এলে উত্তর পায় — ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬ ([[SyncService]] `applyOne`)।
 *
 * ⓘ "আগেই এসেছে কি না" পড়াটা কোম্পানির দেয়ালে আটকা ছিল, অথচ অনন্য চাবি (যন্ত্র + changeId) কোম্পানি মানে না। কোম্পানি বদলের
 * পরে আগের কোম্পানিতে বসা বদল আবার গেলে পড়াটা কিছু পেত না, বসাতে গিয়ে চাবিতে ধাক্কা, আর উত্তর ছাড়া — ফোনে সারিটা চিরকাল
 * "অপেক্ষায়", আর অপেক্ষার সারি থাকায় কোম্পানি বদলও আটকে থাকত।
 */
final class AChangeFromTheLastCompanyIsAnsweredTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_change_already_applied_in_another_company_comes_back_as_a_duplicate(): void
    {
        $this->seed(DemoSeeder::class);
        $depot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mart = Company::query()->where('code', 'FMART')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($depot->id, $depot->defaultBranch()?->id);
        $this->actingAs($owner);
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        $change = ['changeId' => 'switch-1', 'entityType' => 'Voucher', 'operation' => 'CREATE',
            'payloadJson' => json_encode(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'বদলের আগে',
                'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '100']]])];

        $first = app(SyncService::class)->push($owner, 'phone-switch', 'accounts', [$change]);
        $this->assertSame('APPLIED', $first[0]['status'] ?? null, 'দৃশ্যটাই বানানো যায়নি — '.json_encode($first, JSON_UNESCAPED_UNICODE));

        // ⓘ ফোন অন্য কোম্পানিতে গেল, আর উত্তর হারানো বদলটা আবার পাঠাল
        CompanyContext::set($mart->id, $mart->defaultBranch()?->id);
        $again = app(SyncService::class)->push($owner, 'phone-switch', 'accounts', [$change]);

        $this->assertCount(1, $again, '⛔ আগের কোম্পানিতে বসা বদলের কোনো উত্তর নেই — ফোনে চিরকাল অপেক্ষায়');
        $this->assertSame('DUPLICATE', $again[0]['status']);
    }
}

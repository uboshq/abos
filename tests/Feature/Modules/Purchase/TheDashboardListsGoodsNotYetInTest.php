<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Purchase\Dashboard\PurchaseDashboard;
use App\Modules\Purchase\Models\PurchaseOrder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * মাল আসেনি — খোলা ক্রয়াদেশ (নতুন ড্যাশবোর্ড, ৩ অক্টোবর ২০২৬)।
 *
 * ⭐ কেবল নিশ্চিত আদেশ; সবচেয়ে দেরিরটা উপরে, "N দিন দেরি" লেখা; আসার তারিখ না লেখা আদেশ শেষে।
 * ⛔ খসড়া আর বন্ধ আদেশ তালিকায় নেই। ⛔ সুইচ বন্ধে তালিকাই নেই।
 */
final class TheDashboardListsGoodsNotYetInTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_orders_stand_latest_first_and_drafts_stay_out(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ ডেমোর নিজের খোলা আদেশ সরিয়ে রাখা — তালিকার প্রথম আটটা যেন এই পরীক্ষারই হয়
        PurchaseOrder::query()->where('status', DocumentStatus::CONFIRMED)->update(['status' => DocumentStatus::CLOSED]);

        $supplier = (int) DB::table('suppliers')->where('company_id', $company->id)->value('id');

        foreach ([
            ['ZPO-SOON', DocumentStatus::CONFIRMED, now()->addDays(5)],
            ['ZPO-LATE', DocumentStatus::CONFIRMED, now()->subDays(4)],
            ['ZPO-NODATE', DocumentStatus::CONFIRMED, null],
            ['ZPO-DRAFT', DocumentStatus::DRAFT, now()->subDays(9)],
            ['ZPO-CLOSED', DocumentStatus::CLOSED, now()->subDays(9)],
        ] as [$no, $status, $expected]) {
            PurchaseOrder::query()->forceCreate([
                'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'document_no' => $no,
                'supplier_id' => $supplier, 'trx_date' => now()->subDays(10)->toDateString(),
                'expected_on' => $expected?->toDateString(), 'subtotal' => '100', 'total' => '100', 'status' => $status,
            ]);
        }

        config(['abos.dashboards_v2' => true]);
        $listing = collect(PurchaseDashboard::dashboard()->listings)->firstWhere('label', __('purchase::dashboard.goods_not_in'));
        $this->assertNotNull($listing, 'খোলা ক্রয়াদেশের তালিকা নেই।');

        $this->assertSame(['ZPO-LATE', 'ZPO-SOON', 'ZPO-NODATE'], $listing->rows->pluck('document_no')->all(),
            '⛔ ক্রম ভুল, বা খসড়া/বন্ধ আদেশ তালিকায়।');

        $expected = collect($listing->columns)->firstWhere('key', 'expected');
        $this->assertSame(__('purchase::dashboard.late_by', ['days' => 4]), ($expected['render'])($listing->rows->first()),
            'দেরির আদেশে "৪ দিন দেরি" লেখা নেই।');

        config(['abos.dashboards_v2' => false]);
        $this->assertNull(collect(PurchaseDashboard::dashboard()->listings)->firstWhere('label', __('purchase::dashboard.goods_not_in')),
            '⛔ সুইচ বন্ধ, তবু তালিকা — পুরনো ড্যাশবোর্ড বদলে গেছে।');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\DealerScope;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ ফোনের আজকের পাতায় আদায়ের গোনা ডিলার-দেয়াল মানত না — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️৯।
 *
 * অঙ্কটা দেয়ালসহ ([[SalesMetrics::collectionTotal()]]), অথচ গোনায় রসিদ-ভাউচার দেয়াল ছাড়া — বাঁধা বিক্রয়কর্মী শাখার সব ডিলারের
 * রসিদ-সংখ্যা দেখতেন। এখন গোনাও একই জায়গা থেকে ([[SalesMetrics::collectionCount()]])।
 */
final class TheTodayCountSawEveryDealerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_walled_salesman_counts_only_their_own_dealers_receipts(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $sr = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->assertTrue(app(DealerScope::class)->walled($sr), 'প্রস্তুতিটাই ভুল — ডেমোর বিক্রয়কর্মী দেয়ালে নেই।');
        $own = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $stranger = Customer::acrossDealers()->where('name_en', 'Niloy Store')->firstOrFail();

        $before = $this->today($sr)['count'];
        $this->receipt($owner, $own, 'TRX-OWN-1');
        $this->receipt($owner, $stranger, 'TRX-NOT-1');

        $after = $this->today($sr);
        $this->assertSame($before + 1, $after['count'], '⛔ বাঁধা বিক্রয়কর্মীর গোনায় অন্য ডিলারের রসিদ।');
    }

    /** @return array{count: int, amount: string} */
    private function today(User $user): array
    {
        $this->app['auth']->forgetGuards();
        app(DealerScope::class)->forget();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/dashboard/today')->assertOk()->json('collections');
    }

    private function receipt(User $owner, Customer $customer, string $trx): void
    {
        $this->app['auth']->forgetGuards();
        app(DealerScope::class)->forget();
        $this->actingAs($owner->fresh());
        // ⓘ ফোনের অনুরোধের পরে প্রসঙ্গ আবার — দরজার মিডলওয়্যার নিজের মতো বসায়
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        // ⓘ নগদ নয় এমন টাকার খাত (নগদে টিলের নিয়ম এই দাবির বিষয় নয়) — ডেমোতে ব্যাংক খাত নেই, তাই ব্যাংকের দলে একটা
        $bank = Account::query()->where('code', '110299')->first() ?? tap(
            Account::query()->where('code', StandardChart::BANK)->firstOrFail()->replicate(['public_id']),
            function (Account $a): void {
                $a->forceFill(['code' => '110299', 'name_en' => 'Test Bank', 'name_bn' => 'পরীক্ষার ব্যাংক', 'is_group' => false,
                    'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'), 'money_kind' => Account::BANK])->save();
            });
        $way = 'transfer';
        // ⓘ প্রাপ্য (১১১০) নিজে, নয়তো তার পোস্টযোগ্য সন্তান
        $receivable = Account::query()->postable()->active()
            ->where(fn ($q) => $q->where('code', StandardChart::RECEIVABLE)
                ->orWhereIn('parent_id', Account::query()->where('code', StandardChart::RECEIVABLE)->select('id')))
            ->orderBy('code')->firstOrFail();

        $service = app(VoucherService::class);
        $voucher = $service->create([
            'type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'party_type' => 'customer', 'party_id' => $customer->id,
            'instrument' => $way, 'instrument_no' => $trx, 'narration' => 'আদায়',
        ], $service->twoLineEntry(Voucher::RECEIPT, (int) $receivable->id, (int) $bank->id, '100', null, null));
        $service->post($voucher);
    }
}

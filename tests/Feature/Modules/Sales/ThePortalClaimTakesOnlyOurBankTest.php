<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ⛔ পোর্টালের জমার দাবিতে কেবল এই কোম্পানির চালু ব্যাংক খাত — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা।
 *
 * ⓘ বিপজ্জনক ইনপুট: অন্য কোম্পানির খাত, আর নিজের কোম্পানির ব্যাংক-নয় এমন খাত (নগদ)। আগে নিয়ম ছিল
 * `exists:accounts,id` — দুটোই চলত, আর হিসাব বিভাগ দাবিটা খুলে দেখত অচেনা বা ভুল ধরনের খাত।
 * কর্মীর দরজা ([[DepositRequestController]]) আগে থেকেই পর্দার তালিকা ধরে যাচাই করে।
 */
final class ThePortalClaimTakesOnlyOurBankTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $dealer;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->dealer = Customer::query()->firstOrFail();
        $this->dealer->forceFill(['portal_enabled' => true, 'portal_password' => 'dealer-pass-1'])->save();
        $this->bank = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-PORTALBANK',
            'name_en' => 'Portal bank',
            'name_bn' => 'পোর্টালের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);
    }

    public function test_another_companys_account_and_a_non_bank_account_are_refused_and_our_bank_is_taken(): void
    {
        $foreign = Account::query()->withoutGlobalScopes()->where('company_id', '!=', $this->company->id)
            ->where('is_group', false)->firstOrFail();
        $cash = Account::query()->postable()->where('name_en', 'like', '%Cash%')
            ->whereKeyNot($this->bank->id)->firstOrFail();

        foreach (['অন্য কোম্পানির খাত' => $foreign->id, 'ব্যাংক-নয় খাত' => $cash->id] as $what => $id) {
            $this->actingAs($this->dealer->fresh(), 'portal')
                ->post(route('sales.portal.claim.store'), $this->form($id, 'TRX-'.$id))
                ->assertSessionHasErrors('bank_account_id');

            $this->assertSame(0, DepositClaim::query()->where('customer_id', $this->dealer->id)->count(),
                "⛔ {$what} নিয়ে পোর্টালের দাবি উঠে গেল।");
        }

        $this->actingAs($this->dealer->fresh(), 'portal')
            ->post(route('sales.portal.claim.store'), $this->form($this->bank->id, 'TRX-OK'))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, DepositClaim::query()->where('customer_id', $this->dealer->id)->count(),
            'নিজের চালু ব্যাংক খাতও আটকে গেল।');
    }

    /** @return array<string, mixed> */
    private function form(int $accountId, string $reference): array
    {
        return [
            'claimed_on' => now()->toDateString(), 'amount' => '2500', 'method' => 'bank',
            'reference' => $reference, 'bank_account_id' => $accountId,
            'slip' => UploadedFile::fake()->image('slip.png'),
        ];
    }
}

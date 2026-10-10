<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Services\DepositClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই ব্যাংক খাত আর একই রেফারেন্সে দুইটা জমার বিজ্ঞপ্তি নয় (টাকার পরিকল্পনা ৪, সমন্বয়ক, ৭ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে একই স্লিপ দুইবার পাঠানো যেত — SR একবার, দোকানি পোর্টালে আবার — আর হিসাবরক্ষক দুইটাই গ্রহণ করলে একই টাকা দুইবার
 * গ্রাহকের খাতায় জমা হত।
 *
 * দাবি — একই মানুষ, একই খাত, একই রেফারেন্স (বড়-ছোট হাত আর ফাঁকা জায়গা আলাদা করে লিখলেও): দ্বিতীয়টা থামে; প্রথমটা
 * প্রত্যাখ্যাত হলে আবার পাঠানো যায়; অন্য খাতে একই রেফারেন্স চলে; অন্য দোকানের নামেও একই স্লিপ থামে।
 */
final class OnePaymentAdviceForOneBankReferenceTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $otherBank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->bank = $this->bank('1102-ADV-A');
        $this->otherBank = $this->bank('1102-ADV-B');
    }

    public function test_the_same_slip_cannot_be_advised_twice_until_the_first_is_rejected(): void
    {
        $shop = Customer::query()->orderBy('id')->firstOrFail();
        $first = $this->advise($shop, $this->bank, 'TRX-7788');

        $this->assertRefused(fn () => $this->advise($shop, $this->bank, ' trx-7788 '), '⛔ একই খাতে একই রেফারেন্স দ্বিতীয়বার পাঠানো গেল।');

        $other = Customer::query()->whereKeyNot($shop->id)->orderBy('id')->firstOrFail();
        $this->assertRefused(fn () => $this->advise($other, $this->bank, 'TRX-7788'), '⛔ অন্য দোকানের নামে একই স্লিপ চলে গেল।');

        // ⭐ অন্য খাতে একই রেফারেন্স — আলাদা টাকা
        $this->assertNotNull($this->advise($shop, $this->otherBank, 'TRX-7788'));

        // ⭐ প্রথমটা প্রত্যাখ্যাত — ভুল ধরে আবার পাঠানো যায়
        app(DepositClaimService::class)->reject($first, 'অঙ্ক ভুল');
        $again = $this->advise($shop, $this->bank, 'TRX-7788');
        $this->assertSame(DepositClaim::PENDING, $again->status, '⛔ প্রত্যাখ্যাত বিজ্ঞপ্তির রেফারেন্সও আবার পাঠানো গেল না।');
    }

    private function assertRefused(callable $send, string $why): void
    {
        $before = DepositClaim::query()->count();

        try {
            $send();
            $this->fail($why);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference', $e->errors());
        }

        $this->assertSame($before, DepositClaim::query()->count(), '⛔ থেমেছে, তবু সারিটা লেখা হয়ে গেছে।');
    }

    private function advise(Customer $shop, Account $bank, string $reference): DepositClaim
    {
        return app(DepositClaimService::class)->raise($shop, [
            'claimed_on' => now()->subDay()->toDateString(), 'amount' => '5000', 'method' => DepositClaim::BANK,
            'reference' => $reference, 'bank_account_id' => $bank->id,
        ]);
    }

    private function bank(string $code): Account
    {
        return Account::query()->create([
            'company_id' => CompanyContext::id(), 'code' => $code, 'name_en' => $code, 'name_bn' => $code,
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);
    }
}

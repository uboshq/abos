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
use Tests\TestCase;

/**
 * জমার বিজ্ঞপ্তি / Payment Advice — পাঠানো → যাচাই চলছে → গৃহীত বা প্রত্যাখ্যাত (টাকার পরিকল্পনা ১, সমন্বয়ক, ৭ অক্টোবর ২০২৬)।
 *
 * দাবি — একই হিসাবরক্ষক, একই বিজ্ঞপ্তি:
 *   চাবি ছাড়া "যাচাই শুরু" ৪০৩, অবস্থা বদলায় না; চাবি দিলে "যাচাই চলছে";
 *   দ্বিতীয়বার চাপলে থামে, অবস্থা একই;
 *   ডেস্কের "অপেক্ষমাণ" ট্যাবে বিজ্ঞপ্তিটা থাকে, নাম "যাচাই চলছে";
 *   ফোন সার্ভারের নামটা পায় (`status_label`);
 *   যাচাই চলছে থেকেও গ্রহণ করা যায়; গৃহীত বিজ্ঞপ্তি আর "যাচাই চলছে" হয় না।
 */
final class APaymentAdviceIsSubmittedThenVerifiedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->clerk->givePermissionTo('sales.claim.view');

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-ADVICE', 'name_en' => 'Advice bank', 'name_bn' => 'বিজ্ঞপ্তির ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);
    }

    public function test_an_advice_moves_from_submitted_to_verified_once_and_can_still_be_accepted(): void
    {
        $advice = app(DepositClaimService::class)->raise(Customer::query()->orderBy('id')->firstOrFail(), [
            'claimed_on' => now()->subDay()->toDateString(), 'amount' => '5000', 'method' => DepositClaim::BANK,
            'reference' => 'TRX-ADVICE-1', 'bank_account_id' => $this->bank->id,
        ]);
        $this->assertSame('পাঠানো', __('sales::portal.pending', [], 'bn'));

        // ⛔ চাবি ছাড়া — দরজা বন্ধ, কিছুই বদলায় না
        $this->actingAs($this->clerk)->post(route('sales.claim.verify', $advice))->assertForbidden();
        $this->assertSame(DepositClaim::PENDING, $advice->fresh()->status, '⛔ ৪০৩ ফিরেছে, তবু অবস্থা বদলেছে।');

        // ⭐ একই মানুষ, চাবি দিলে — যাচাই চলছে
        $this->clerk->givePermissionTo('sales.claim.decide');
        $this->actingAs($this->clerk->fresh())->post(route('sales.claim.verify', $advice))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(DepositClaim::VERIFYING, $advice->fresh()->status, '⛔ "যাচাই শুরু" চাপার পরেও বিজ্ঞপ্তি "পাঠানো"-তেই।');

        // ⛔ দ্বিতীয়বার — থামে
        $this->actingAs($this->clerk->fresh())->post(route('sales.claim.verify', $advice))->assertSessionHasErrors('status');
        $this->assertSame(DepositClaim::VERIFYING, $advice->fresh()->status);

        // ⭐ ডেস্কের খোলা ট্যাবে থাকে, নাম সার্ভারের
        $this->actingAs($this->clerk->fresh())->get(route('sales.claim.index'))->assertOk()
            ->assertSee('TRX-ADVICE-1')
            ->assertSee(__('sales::portal.verifying'));
        $this->assertSame(__('sales::portal.verifying'), $this->phoneFacts($advice->fresh())['status_label'], '⛔ ফোন অবস্থার নাম পায়নি।');

        // ⭐ যাচাই চলছে থেকেও গ্রহণ
        $this->actingAs($this->clerk->fresh())
            ->post(route('sales.claim.accept', $advice), ['account_id' => $this->bank->id])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(DepositClaim::ACCEPTED, $advice->fresh()->status, '⛔ যাচাই চলছে এমন বিজ্ঞপ্তি গ্রহণ করা গেল না।');

        // ⛔ গৃহীত বিজ্ঞপ্তি পুরনো পাতা থেকে আবার "যাচাই চলছে" হয় না
        $this->actingAs($this->clerk->fresh())->post(route('sales.claim.verify', $advice))->assertSessionHasErrors('status');
        $this->assertSame(DepositClaim::ACCEPTED, $advice->fresh()->status);
    }

    /** @return array<string, mixed> */
    private function phoneFacts(DepositClaim $advice): array
    {
        return (new \ReflectionMethod(\App\Modules\Sales\Http\Controllers\DepositRequestController::class, 'facts'))
            ->invoke(app(\App\Modules\Sales\Http\Controllers\DepositRequestController::class), $advice);
    }
}

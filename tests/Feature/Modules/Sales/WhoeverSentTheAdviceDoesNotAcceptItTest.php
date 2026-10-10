<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Services\DepositClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * জমার বিজ্ঞপ্তি যিনি পাঠালেন তিনি নিজে গ্রহণ করেন না — টাকার পরিকল্পনা ৩ (সমন্বয়ক, ৭ অক্টোবর ২০২৬)।
 *
 * দাবি:
 *   একই কর্মী পাঠালেন, নিজে গ্রহণ করতে গেলেন → থামে, কোনো আদায় নয়; অন্য একজন গ্রহণ করলে চলে, আর বিজ্ঞপ্তিতে পাঠানেওয়ালার
 *   নাম থাকে (ফোনেও); মালিক নিজে পাঠিয়ে নিজে গ্রহণ করলে আটকায় না, নিরীক্ষায় "একই মানুষ" দাগ — এই দুই সুইচ চালু করে; সুইচ
 *   ডিফল্টে বন্ধ, আর বন্ধে আজকের আচরণ।
 */
final class WhoeverSentTheAdviceDoesNotAcceptItTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-FOUREYES', 'name_en' => 'Four eyes bank', 'name_bn' => 'চার চোখের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);
    }

    public function test_the_sender_cannot_accept_their_own_advice_and_another_hand_can(): void
    {
        app(SettingsService::class)->set(DepositClaim::FOUR_EYES, true);
        $clerk = $this->clerk();
        $advice = $this->sentBy($clerk, 'TRX-4E-1');
        $this->assertSame($clerk->id, (int) $advice->submitted_by, '⛔ কে পাঠালেন, লেখা হয়নি।');

        $this->actingAs($clerk)
            ->post(route('sales.claim.accept', $advice), ['account_id' => $this->bank->id])
            ->assertSessionHasErrors('status');
        $this->assertSame(DepositClaim::PENDING, $advice->fresh()->status, '⛔ পাঠানেওয়ালা নিজেই গ্রহণ করে ফেললেন।');
        $this->assertSame(0, Collection::query()->count(), '⛔ থেমেছে, অথচ আদায় খাতায় বসে গেছে।');

        // ⭐ অন্য হাত — চলে, আর পাঠানেওয়ালার নাম ফোনেও
        $this->actingAs($this->clerk())
            ->post(route('sales.claim.accept', $advice), ['account_id' => $this->bank->id])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(DepositClaim::ACCEPTED, $advice->fresh()->status);
        $this->assertSame($clerk->name, $this->phoneFacts($advice->fresh())['submitted_by_name']);
    }

    public function test_the_owner_may_do_both_and_the_audit_marks_it(): void
    {
        app(SettingsService::class)->set(DepositClaim::FOUR_EYES, true);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $advice = $this->sentBy($owner, 'TRX-4E-2');

        $this->actingAs($owner)
            ->post(route('sales.claim.accept', $advice), ['account_id' => $this->bank->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(DepositClaim::ACCEPTED, $advice->fresh()->status, '⛔ মালিককেও আটকানো হলো।');
        $this->assertTrue(AuditTrail::query()->where('auditable_type', $advice->getMorphClass())->where('auditable_id', $advice->id)
            ->where('action', 'own_advice_accepted')->exists(), '⛔ মালিক নিজের বিজ্ঞপ্তি নিজে গ্রহণ করলেন, নিরীক্ষায় দাগ নেই।');
    }

    /** ⓘ ডিফল্ট বন্ধ, সব কোম্পানিতে (সমন্বয়ক, ১০ অক্টোবর ২০২৬) — সুইচে হাত না দিলে আজকের আচরণ অবিকল */
    public function test_by_default_the_switch_is_off_and_the_sender_may_accept(): void
    {
        $this->assertFalse((bool) app(SettingsService::class)->get(DepositClaim::FOUR_EYES), '⛔ সুইচ ডিফল্টে চালু।');
        $clerk = $this->clerk();
        $advice = $this->sentBy($clerk, 'TRX-4E-3');

        $this->actingAs($clerk)
            ->post(route('sales.claim.accept', $advice), ['account_id' => $this->bank->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(DepositClaim::ACCEPTED, $advice->fresh()->status, '⛔ সুইচ বন্ধ, তবু আটকাল — আজকের আচরণ ভাঙল।');
    }

    private function clerk(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->givePermissionTo(['sales.claim.view', 'sales.claim.decide']);

        return $user->fresh();
    }

    private function sentBy(User $who, string $reference): DepositClaim
    {
        $this->actingAs($who);

        return app(DepositClaimService::class)->raise(Customer::query()->orderBy('id')->firstOrFail(), [
            'claimed_on' => now()->subDay()->toDateString(), 'amount' => '5000', 'method' => DepositClaim::BANK,
            'reference' => $reference, 'bank_account_id' => $this->bank->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function phoneFacts(DepositClaim $advice): array
    {
        return (new \ReflectionMethod(\App\Modules\Sales\Http\Controllers\DepositRequestController::class, 'facts'))
            ->invoke(app(\App\Modules\Sales\Http\Controllers\DepositRequestController::class), $advice);
    }
}

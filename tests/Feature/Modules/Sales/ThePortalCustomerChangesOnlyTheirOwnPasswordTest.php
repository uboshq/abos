<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ⭐ গ্রাহকের প্রোফাইল আর নিজের পাসওয়ার্ড — গ্রাহক পোর্টাল, ধাপ ১ (১১ অক্টোবর ২০২৬)।
 *
 * ── দাবিগুলো ─────────────────────────────────────────────────────────────
 *   · প্রোফাইলে নিজের তথ্য (বদল কেবল অনুরোধে — আলাদা দাবি)
 *   · এখনকার পাসওয়ার্ড না জানলে বদল নয়
 *   · নতুন পাসওয়ার্ড অফিসের সেই একই নিয়মে (১২ অক্ষর, অক্ষর + সংখ্যা)
 *   · বদল নিরীক্ষার খাতায় ওঠে — কর্মীর ঘর খালি, অর্থাৎ গ্রাহক নিজে
 */
final class ThePortalCustomerChangesOnlyTheirOwnPasswordTest extends TestCase
{
    use RefreshDatabase;

    private Customer $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->me = Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => 'PROFILE-1',
            'name_en' => 'Profile Shop', 'owner_name' => 'Rahman Mia', 'phone' => '01711000111',
            'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $this->me->forceFill(['portal_enabled' => true, 'portal_password' => 'old-shop-pass-2026'])->save();
    }

    public function test_the_profile_shows_my_details_read_only(): void
    {
        $this->asMe()->get(route('sales.portal.profile'))->assertOk()
            ->assertSee('data-portal-profile-contact', false)
            ->assertSee('Rahman Mia')->assertSee('01711000111')->assertSee('PROFILE-1');
        // ⓘ বদলের ঘরগুলো অনুরোধের ফর্মে — রেকর্ড নিজে বদলায় না ([[ThePortalCustomerAsksTheOfficeToChangeTheirDetailsTest]])
    }

    public function test_a_wrong_current_password_or_a_weak_new_one_changes_nothing(): void
    {
        $this->asMe()->post(route('sales.portal.profile.password'), [
            'current_password' => 'not-it', 'password' => 'new-shop-pass-2027', 'password_confirmation' => 'new-shop-pass-2027',
        ])->assertSessionHasErrors('current_password');

        $this->asMe()->post(route('sales.portal.profile.password'), [
            'current_password' => 'old-shop-pass-2026', 'password' => 'short1', 'password_confirmation' => 'short1',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('old-shop-pass-2026', (string) $this->me->fresh()->portal_password), '⛔ পাসওয়ার্ড বদলে গেল।');
    }

    public function test_the_right_current_password_changes_it_and_the_change_is_audited(): void
    {
        $this->asMe()->post(route('sales.portal.profile.password'), [
            'current_password' => 'old-shop-pass-2026', 'password' => 'new-shop-pass-2027', 'password_confirmation' => 'new-shop-pass-2027',
        ])->assertSessionHasNoErrors()->assertRedirect(route('sales.portal.profile'));

        $this->assertTrue(Hash::check('new-shop-pass-2027', (string) $this->me->fresh()->portal_password));

        $trail = AuditTrail::query()->withoutGlobalScopes()->where('action', 'portal_password_changed')->sole();
        $this->assertSame([Customer::class, (int) $this->me->id, null], [$trail->auditable_type, (int) $trail->auditable_id, $trail->user_id]);

        // ⓘ নতুনটা দিয়ে ঢোকা যায়
        auth('portal')->logout();
        $this->post(route('sales.portal.login.attempt'), ['code' => 'PROFILE-1', 'password' => 'new-shop-pass-2027'])
            ->assertRedirect(route('sales.portal.home'));
    }

    private function asMe(): static
    {
        return $this->actingAs($this->me->fresh(), 'portal');
    }
}

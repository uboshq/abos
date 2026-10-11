<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একজন সহকর্মীর মোবাইল নম্বর নিজের ঘরে বসিয়ে তাঁর লগইন আটকে দেওয়া যেত — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভুল ছিল ────────────────────────────────────────────────────
 * লগইন মোবাইল দিয়েও হয়, আর একই নম্বরে দুইজন মিললে কাউকেই ঢোকায় না
 * ([[CredentialCheck]], ইচ্ছাকৃত)। অথচ প্রোফাইল আর ব্যবহারকারীর পাতা মোবাইলের
 * ঘরে যেকোনো লেখা নিত, অনন্যতা ছাড়া। ⓘ বিপজ্জনক মানুষটা এখানে একজন সাধারণ কর্মী
 * (নিজের প্রোফাইল), আর একজন ব্যবহারকারী-প্রশাসক (অন্যের পাতা)।
 */
final class AColleaguesMobileCouldLockThemOutTest extends TestCase
{
    use RefreshDatabase;

    private User $victim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->victim = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->victim->forceFill(['mobile' => '01711000111'])->save();
    }

    public function test_a_colleague_cannot_take_your_number_on_their_profile(): void
    {
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        foreach (['01711000111', '০১৭১১-০০০১১১', '01711 000 111'] as $same) {
            $this->actingAs($sales)->put(route('profile.update'), [
                'name' => $sales->name, 'login_id' => 'salesman1', 'mobile' => $same,
            ])->assertSessionHasErrors(['mobile' => __('validation.login_mobile_taken_by', ['name' => $this->victim->name])]);
        }

        $this->assertNotSame('01711000111', $sales->fresh()->mobile);
        $this->assertSignsInWithMobile('01711000111');
    }

    public function test_the_user_admin_cannot_put_one_persons_number_on_another(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($owner)->put(route('system_admin.user.update', $sales), [
            'name' => $sales->name, 'email' => $sales->email, 'locale' => 'bn', 'is_active' => '1',
            'mobile' => '01711-000111',
            'roles' => ['salesman'], 'companies' => [CompanyContext::id()],
        ])->assertSessionHasErrors(['mobile' => __('validation.login_mobile_taken_by', ['name' => $this->victim->name])]);

        $this->assertSignsInWithMobile('01711000111');
    }

    public function test_a_number_is_kept_in_the_shape_the_sign_in_reads(): void
    {
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($sales)->put(route('profile.update'), [
            'name' => $sales->name, 'login_id' => 'salesman1', 'mobile' => '০১৮১১-২২২ ৩৩৩',
        ])->assertSessionHasNoErrors();

        $this->assertSame('01811222333', $sales->fresh()->mobile);

        $this->actingAs($sales)->put(route('profile.update'), [
            'name' => $sales->name, 'login_id' => 'salesman1', 'mobile' => 'call me',
        ])->assertSessionHasErrors(['mobile' => __('validation.login_mobile_format')]);
    }

    /** ⓘ ড্যাশসহ লিখলেও লগইন মেলে — নম্বর এখন এক ছাঁদে বসে। */
    public function test_signing_in_with_a_dashed_number_still_works(): void
    {
        $this->assertSignsInWithMobile('01711-000111');
    }

    private function assertSignsInWithMobile(string $typed): void
    {
        auth()->logout();
        $this->flushSession();

        $this->post(route('login'), ['identifier' => $typed, 'password' => 'password']);

        $this->assertSame($this->victim->id, auth()->id(), "⛔ {$typed} দিয়ে নম্বরের মালিক ঢুকতে পারলেন না।");

        auth()->logout();
    }
}

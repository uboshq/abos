<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Core\Services\ProfileFacts;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ "আমার প্রোফাইল" — ওয়েবের পাতা আর অ্যাপের API একই তথ্য (মালিক, ২০ নভেম্বর ২০২৬, প্রোফাইলের নমুনা ক/গ-তে "ok";
 *   [[ProfileFacts]], [[ProfilePageController]], [[ProfileApiController]])।
 *
 * ⛔ পাহারা: সহকর্মীর চোখে ফোন, ইমেইল, কাজের সংখ্যা, লগইন করা ফোন, নিরাপত্তা লুকানো; সহকর্মী না হলে ৪০৪; বার্তা আর কল "শীঘ্রই"।
 */
final class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);

        $this->me = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->colleague = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->me->forceFill(['mobile' => '01711234567'])->save();
    }

    public function test_my_web_profile_shows_cover_head_about_phones_security_month_and_recent_work(): void
    {
        $page = $this->actingAs($this->me)->get('/me')->assertOk();

        foreach (['data-me-cover', 'data-me-avatar', 'data-me-about', 'data-me-phones', 'data-me-security', 'data-me-month', 'data-me-recent', 'data-me-as-others'] as $mark) {
            $page->assertSee($mark, false);
        }

        $page->assertSee($this->me->name)
            ->assertSee('01711234567')
            ->assertSee(__('profile.edit'))
            ->assertSee(__('profile.soon'));

        // ⛔ বিবরণের লাইন নয় (মালিক, ১৯ নভেম্বর ২০২৬ রাত) — মাথায় কেবল নাম আর বোতাম
        $page->assertDontSee('data-page-subtitle', false);
    }

    public function test_a_colleague_sees_name_and_work_but_not_phone_counts_phones_or_security(): void
    {
        $page = $this->actingAs($this->colleague)->get('/people/'.$this->me->public_id)->assertOk();

        $page->assertSee($this->me->name)
            ->assertSee('data-me-hidden', false)
            ->assertDontSee('01711234567')
            ->assertDontSee('data-me-about', false)
            ->assertDontSee('data-me-phones', false)
            ->assertDontSee('data-me-security', false)
            ->assertDontSee('data-me-month', false)
            ->assertDontSee(__('profile.edit'));
    }

    public function test_as_others_see_it_hides_my_own_private_parts_too(): void
    {
        $this->actingAs($this->me)->get('/people/'.$this->me->public_id.'?as=other')->assertOk()
            ->assertSee('data-me-hidden', false)
            ->assertDontSee('01711234567');

        // ⓘ "?as=other" ছাড়া নিজের লিংক নিজের পাতায় ফেরে
        $this->actingAs($this->me)->get('/people/'.$this->me->public_id)->assertRedirect(route('me.profile'));
    }

    public function test_someone_outside_the_company_gets_404_on_the_web_and_the_api(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get('/people/'.$this->me->public_id)->assertNotFound();

        Sanctum::actingAs($stranger, [AuthController::APP]);
        $this->getJson('/api/v1/users/'.$this->me->public_id.'/profile')->assertNotFound();
    }

    public function test_the_app_reads_my_profile_in_the_agreed_shape(): void
    {
        Sanctum::actingAs($this->me, [AuthController::APP]);

        $json = $this->getJson('/api/v1/me/profile?limit=4')->assertOk()
            ->assertJsonStructure([
                'public_id', 'name', 'initial', 'designation', 'company', 'branch', 'company_colour', 'photo_url', 'cover_url',
                'self', 'can_edit', 'hidden',
                'about' => ['phone', 'email', 'employee_no', 'joined_on', 'role', 'branches'],
                'month' => ['label', 'stats'],
                'recent',
            ])
            ->json();

        $this->assertTrue($json['self']);
        $this->assertSame([], $json['hidden']);
        $this->assertLessThanOrEqual(ProfileFacts::MAX_STATS, count($json['month']['stats']));
        $this->assertLessThanOrEqual(4, count($json['recent']));

        foreach ($json['month']['stats'] as $stat) {
            $this->assertSame(['key', 'label', 'value', 'kind', 'icon', 'tone'], array_keys($stat));
            // ⛔ পর্দার অঙ্ক ইংরেজি (মালিক, ১৬ নভেম্বর ২০২৬)
            $this->assertDoesNotMatchRegularExpression('/[০-৯]/u', $stat['value']);
        }

        foreach ($json['recent'] as $item) {
            foreach (['no', 'action', 'verb', 'summary', 'at', 'ago', 'kind', 'url'] as $key) {
                $this->assertArrayHasKey($key, $item);
            }
        }
    }

    public function test_the_app_sees_a_colleague_with_private_fields_null(): void
    {
        Sanctum::actingAs($this->colleague, [AuthController::APP]);

        $json = $this->getJson('/api/v1/users/'.$this->me->public_id.'/profile')->assertOk()->json();

        $this->assertFalse($json['self']);
        $this->assertFalse($json['can_edit']);
        $this->assertNull($json['about']);
        $this->assertNull($json['month']);
        $this->assertSame(ProfileFacts::HIDDEN, $json['hidden']);
        $this->assertStringNotContainsString('01711234567', json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    public function test_removing_a_phone_that_is_not_mine_is_404(): void
    {
        $this->actingAs($this->me)->delete('/me/devices/'.str_repeat('a', 32))->assertNotFound();
    }

    public function test_the_profile_menu_opens_the_new_page(): void
    {
        $this->actingAs($this->me)->get('/')->assertSee(route('me.profile'), false);
    }
}

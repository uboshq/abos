<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Auth\LoginController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ⭐ "মনে রাখুন" মানে আইডিও — মালিক, ২ অক্টোবর ২০২৬: *"mone rakun butam thakar poreo id aber likte hoy keno?"*
 *
 * দাবি: বাক্সে টিক দিয়ে ঢুকলে, বের হয়ে আবার এলে আইডির ঘরে আইডি বসানো আর বাক্সে টিক;
 * ⛔ পাসওয়ার্ড কখনো পাতায় নয়; বাক্স খালি রেখে ঢুকলে আগের মনে রাখা আইডি মুছে যায়।
 */
final class RememberMeForgotTheLoginIdTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'RM', 'name_en' => 'Remember Co']);
        $this->user = User::factory()->create([
            'name' => 'Rahim', 'email' => 'rahim@abos.test', 'login_id' => 'rahim',
            'password' => Hash::make('rahim-chabi-1'),
        ]);
        $this->user->companies()->attach($company, ['is_active' => true]);
        $this->user->forceFill(['current_company_id' => $company->id])->save();
    }

    public function test_ticking_the_box_keeps_the_id_across_a_sign_out_and_never_the_password(): void
    {
        $in = $this->post(route('login'), ['identifier' => 'rahim', 'password' => 'rahim-chabi-1', 'remember' => '1']);
        $in->assertRedirect();
        $in->assertCookie(LoginController::REMEMBERED_ID, 'rahim');

        $this->post(route('logout'));
        $this->app['auth']->forgetGuards();

        $page = $this->withCookie(LoginController::REMEMBERED_ID, 'rahim')->get(route('login.calm'))->assertOk();
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/id="identifier"[^>]*value="rahim"/s', $html, '⛔ মনে রাখা আইডি ঘরে বসেনি।');
        $this->assertMatchesRegularExpression('/name="remember"[^>]*checked/s', $html, 'বাক্সে টিক নেই।');
        $this->assertStringNotContainsString('rahim-chabi-1', $html, '⛔ পাসওয়ার্ড পাতায় এসেছে।');
    }

    public function test_signing_in_without_the_box_forgets_the_id(): void
    {
        $in = $this->withCookie(LoginController::REMEMBERED_ID, 'rahim')
            ->post(route('login'), ['identifier' => 'rahim', 'password' => 'rahim-chabi-1']);

        $in->assertRedirect();
        $cookie = collect($in->headers->getCookies())->first(fn ($c) => $c->getName() === LoginController::REMEMBERED_ID);
        $this->assertNotNull($cookie, 'বাক্স খালি — অথচ আগের আইডি মোছার কুকি পাঠানো হয়নি।');
        $this->assertTrue($cookie->isCleared(), '⛔ বাক্স খালি রেখে ঢুকলেও আগের আইডি থেকে গেল।');
    }
}

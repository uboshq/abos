<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * প্রোফাইল পাতার নতুন ঘরগুলো — সত্যিই আছে, আর সত্যিই জমা হয়।
 *
 * ── ⭐ কেন এই পরীক্ষা, ১৪ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * মালিক পাতাটা খুলে সাতটা জিনিস অনুপস্থিত বলেছেন। ⓘ তিনটার ঘর ডাটাবেজে
 * ছিল না, একটার (পাসওয়ার্ড) কোনো পথই ছিল না।
 *
 * ⚠️ পর্দার কাজ "চোখে দেখে" যাচাই করা যায় না এখান থেকে, আর সেটাই আজকের
 * বারবার ফেরা শিক্ষা: সবুজ ফল যা দেখেনি, তা প্রমাণ করে না। ⭐ তাই এখানে
 * প্রতিটা দাবি **ঘরের নাম ধরে** মাপা হয় — পাতায় ঘরটা আছে কি না, আর জমা
 * দিলে মানটা সত্যিই সারিতে বসে কি না।
 */
final class TheProfilePageCouldNotSayWhoYouAreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'PR', 'name_en' => 'Profile Co']);

        $this->user = User::factory()->create([
            'name' => 'Md. Al-Amin',
            'login_id' => 'alamin',
            'password' => Hash::make('purono-chabi-1'),
        ]);

        $this->user->companies()->attach($company, ['is_active' => true]);
        $this->user->forceFill(['current_company_id' => $company->id])->save();
    }

    /**
     * ⛔ ছয়টা ঘরের একটাও অনুপস্থিত নয়।
     */
    public function test_the_page_shows_every_field_the_owner_asked_for(): void
    {
        $page = $this->actingAs($this->user)->get(route('profile'))->assertOk();

        /*
         * ⓘ ঘরের `name` ধরে মাপা, লেবেলের লেখা ধরে নয় — ⚠️ লেবেল
         * অনুবাদে বদলায়, আর ভাষা বদলালে পরীক্ষাটা মিথ্যা লাল হত।
         */
        foreach (['name', 'login_id', 'mobile', 'mobile_alt', 'address'] as $field) {
            $page->assertSee('name="'.$field.'"', escape: false);
        }

        $page->assertSee(route('profile.password'), escape: false);

        /*
         * ⛔ ভেতরের স্থায়ী নম্বরটা (`public_id`) পর্দায় **নেই** —
         * ১৯ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ এই দাবিটা আগে উল্টো ছিল: নম্বরটা দেখা যেতেই হবে। ⚠️ মালিক
         * পাতাটা দেখে জিজ্ঞেস করলেন *"User ID diye kaj ki ekane?"* — ঐ
         * নম্বর কেউ মুখে বলতে পারে না, আর তা দিয়ে কোথাও ঢোকাও যায় না।
         * তিনি চেয়েছিলেন লগইন আইডি, আর সেটা উপরের দাবিতে আছে।
         *
         * ⭐ দাবিটা মুছে না দিয়ে উল্টে দেওয়া হলো — নাহলে কেউ ঘরটা আবার
         * বসালে কোনো টেস্ট টেরই পেত না।
         */
        $page->assertDontSee($this->user->public_id);
    }

    /**
     * ⛔ যোগাযোগের তিনটা ঘর সত্যিই সারিতে বসে।
     */
    public function test_contact_details_are_saved(): void
    {
        $this->actingAs($this->user)
            ->put(route('profile.update'), [
                'name' => 'Md. Al-Amin',
                'login_id' => 'alamin',
                'mobile' => '01712-345678',
                'mobile_alt' => '+880 1811-223344',
                'address' => "বাড়ি ১২, রোড ৫\nবনশ্রী, ঢাকা-১২১৯",
            ])
            ->assertRedirect();

        $this->user->refresh();

        $this->assertSame('01712-345678', $this->user->mobile);
        $this->assertSame('+880 1811-223344', $this->user->mobile_alt);
        $this->assertStringContainsString('বনশ্রী', (string) $this->user->address);
    }

    /**
     * ⛔ পুরনো পাসওয়ার্ড ভুল হলে চাবি বদলায় না।
     *
     * ⚠️ এটাই এই দলটার সবচেয়ে জরুরি দাবি: ডিপোতে একটা কম্পিউটার কয়েকজন
     * ভাগ করে ব্যবহার করেন, আর কেউ লগআউট না করে উঠে যান।
     */
    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $before = $this->user->password;

        $this->actingAs($this->user)
            ->put(route('profile.password'), [
                'current_password' => 'eta-vul',
                'password' => 'notun-chabi-9',
                'password_confirmation' => 'notun-chabi-9',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame($before, $this->user->refresh()->password);
    }

    /**
     * ⛔ পুরনোটাই আবার বসানো যায় না।
     *
     * ⓘ নাহলে "বদলান" বলার পর কেউ একই চাবি বসিয়ে দিতেন, আর ব্যবস্থাটা
     * বলত "সংরক্ষিত" — অর্থাৎ কিছুই না করে সফল হত।
     */
    public function test_the_old_password_cannot_be_set_again(): void
    {
        $this->actingAs($this->user)
            ->put(route('profile.password'), [
                'current_password' => 'purono-chabi-1',
                'password' => 'purono-chabi-1',
                'password_confirmation' => 'purono-chabi-1',
            ])
            ->assertSessionHasErrors('password');
    }

    /**
     * ⭐ আর সঠিক তথ্য দিলে চাবিটা সত্যিই বদলায়।
     */
    public function test_the_password_changes(): void
    {
        $this->actingAs($this->user)
            ->put(route('profile.password'), [
                'current_password' => 'purono-chabi-1',
                'password' => 'notun-chabi-9',
                'password_confirmation' => 'notun-chabi-9',
            ])
            ->assertRedirect()
            ->assertSessionHas('password_saved');

        $this->assertTrue(Hash::check('notun-chabi-9', $this->user->refresh()->password));
    }

    /**
     * ⛔ ছবির ফর্মে JavaScript ছাড়াও জমা দেওয়ার পথ আছে।
     *
     * ⚠️ মালিকের অভিযোগ ছিল *"Profile pic uploads kora zay na"*, আর ঐ
     * ফর্মটা জমা দেওয়ার একমাত্র পথ ছিল স্ক্রিপ্ট। ⓘ লোকালে ভাঙাটা ধরা
     * যায়নি, তাই রোগ সারানো নয় — রোগটাকে অসম্ভব করা হয়েছে।
     */
    public function test_the_photo_form_can_be_submitted_without_javascript(): void
    {
        $page = $this->actingAs($this->user)->get(route('profile'))->assertOk();

        $html = $page->getContent();
        $start = strpos((string) $html, route('profile.avatar'));

        $this->assertNotFalse($start, 'ছবির ফর্মটাই পাতায় পাওয়া গেল না।');

        /*
         * ⓘ ফর্মটার শুরু থেকে তার শেষ পর্যন্ত টুকরোটা কেটে নিয়ে দেখা হয়
         * — ⚠️ পুরো পাতায় খুঁজলে অন্য কোনো ফর্মের জমা-বোতাম গুনে ফেলত,
         * আর দাবিটা সবুজ থাকত ভুল কারণে।
         */
        $form = substr((string) $html, $start, 2500);

        $this->assertStringContainsString('type="submit"', $form,
            'ছবির ফর্মে হাতে চাপার কোনো বোতাম নেই — স্ক্রিপ্ট না চললে আপলোড করার পথ থাকে না।');
    }
}

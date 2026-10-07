<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সুইচটা দেখতে বোতামের মতো ছিল, কিন্তু বোতাম ছিল না।
 *
 * ── ⛔ মালিকের প্রশ্ন, ২২ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"কোম্পানি লিস্টে ইন এক্টিভ/ডিলেট নাই কেন?"* — আর জিনিসটা **ছিলই**,
 * কেবল কাজ করত না।
 *
 * ⓘ [[x-ui.state-toggle]]-এর ডিফল্ট `method` হলো `DELETE`, আর কোম্পানির
 * রুটটা `POST` চায়। ⚠️ পিলে চাপলে যেত `DELETE`, সার্ভার বলত **৪০৫**,
 * আর পর্দায় কিছুই বদলাত না।
 *
 * ⛔ লাইভে মেপে দেখা হয়েছে: `DELETE → 405`, `POST → 419` (কেবল CSRF)।
 *
 * ⭐ ব্যর্থতাটা নীরব ছিল, আর সেটাই সবচেয়ে খারাপ দিক: পর্দা একটা সুইচ
 * দেখায়, মানুষ চাপেন, কিছু হয় না, আর কোথাও কোনো বার্তা নেই। ⓘ মালিক
 * ধরে নিয়েছিলেন **ফিচারটাই নেই**।
 */
final class TheCompanySwitchLookedLikeAButtonAndWasNotTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $here;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->here = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->here->id, $this->here->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /**
     * ⭐ সুইচটা সত্যিই কাজ করে।
     *
     * ⓘ পর্দা যে পথে পাঠায়, সেই পথেই পাঠিয়ে দেখা হয় — অর্থাৎ দাবিটা
     * ব্রাউজারের আচরণ নকল করে, কল্পনা নয়।
     */
    public function test_pressing_the_pill_really_switches_a_company_off(): void
    {
        $other = $this->anotherCompany();

        $this->actingAs($this->owner)
            ->post(route('system_admin.company.toggle', $other->id))
            ->assertRedirect();

        $this->assertFalse((bool) $other->fresh()->is_active, implode("\n", [
            '⛔ সুইচে চাপার পরেও কোম্পানিটা সচলই রয়ে গেছে।',
            '',
            '⚠️ এটাই নীরব ব্যর্থতা: পর্দা সুইচ দেখায়, মানুষ চাপেন, কিছু',
            'হয় না, আর কোথাও বার্তা নেই। ⓘ মালিক ভেবেছিলেন ফিচারটাই নেই।',
        ]));
    }

    /** ⭐ আর আবার চাপলে ফিরে আসে — সুইচ দুই দিকেই ঘোরে। */
    public function test_and_switches_it_back_on(): void
    {
        $other = $this->anotherCompany();
        $other->forceFill(['is_active' => false])->save();

        $this->actingAs($this->owner)
            ->post(route('system_admin.company.toggle', $other->id))
            ->assertRedirect();

        $this->assertTrue((bool) $other->fresh()->is_active,
            '⛔ নিষ্ক্রিয় কোম্পানিটা আর সচল করা যাচ্ছে না — সুইচটা একমুখী।');
    }

    /**
     * ⛔ পর্দা যে পথে পাঠায়, সেটাই রুটের পথ হতে হবে।
     *
     * ── ⚠️ এটাই আসল রিগ্রেশন-পাহারা ────────────────────────────────
     * উপরের দাবিগুলো সবুজ থাকত যদি পর্দা ভুল পথে পাঠাত — কারণ ওরা
     * সরাসরি `post()` করে, পর্দা পড়ে নয়। ⓘ ঠিক এই ফাঁকটাই আসল
     * বাগটাকে এতদিন ঢেকে রেখেছিল।
     *
     * ⭐ তাই পর্দার HTML পড়া হয়: ফর্মটা `POST`, আর ভিতরে `DELETE`
     * লুকানো নেই।
     */
    public function test_the_screen_sends_the_method_the_route_accepts(): void
    {
        $html = (string) $this->actingAs($this->owner)
            ->get(route('system_admin.company.index'))
            ->assertOk()
            ->getContent();

        $action = route('system_admin.company.toggle', $this->anotherCompany()->id);

        $this->assertStringContainsString($action, $html,
            'ⓘ সুইচের ফর্মটাই পর্দায় নেই — বাকি দাবিগুলো তখন কিছুই প্রমাণ করে না।');

        /*
         * ⓘ Laravel `PUT`/`DELETE` পাঠায় একটা লুকানো `_method` ঘরে।
         * ⚠️ ঐ ঘরটা থাকলে অনুরোধটা `POST` নয়, আর রুট ৪০৫ দেবে।
         */
        $form = substr($html, (int) strpos($html, $action));
        $form = substr($form, 0, (int) strpos($form, '</form>'));

        /*
         * ── ⛔ প্রথম চালে দাবিটা ভুল জিনিস মাপছিল ───────────────────
         * লেখা ছিল `assertStringNotContainsString('_method')`। ⚠️ কিন্তু
         * `@method('POST')`-ও একটা `_method` ঘর বসায় — মান `POST`, যা
         * সম্পূর্ণ নিরীহ। ⓘ অর্থাৎ দাবিটা **ঘরের নাম** দেখছিল, আর আসল
         * প্রশ্নটা হলো **তার মান**।
         *
         * ⭐ এখন মান ধরে দেখা: `DELETE`/`PUT` থাকলেই কেবল লাল — উপস্থিতি
         * নয়, **যা সত্যিই পাঠানো হচ্ছে**।
         */
        foreach (['DELETE', 'PUT', 'PATCH'] as $wrong) {
            $this->assertStringNotContainsString('value="'.$wrong.'"', $form, implode("\n", [
                '⛔ সুইচের ফর্ম `'.$wrong.'` পাঠাচ্ছে।',
                '',
                '⚠️ `company.toggle` রুট কেবল `POST` চেনে — চাপলে ৪০৫,',
                'আর পর্দায় কিছুই বদলাবে না, কোনো বার্তাও নয়।',
            ]));
        }
    }

    /**
     * ⛔ শেষ সচল কোম্পানিটা নিষ্ক্রিয় করা যায় না।
     *
     * ── ⚠️ কেন এটা সবচেয়ে দামি দাবি ────────────────────────────────
     * ⓘ পুরনো পাহারাটা কেবল **চলতি** কোম্পানিকে বাঁচাত। কিন্তু কেউ
     * A-তে দাঁড়িয়ে B বন্ধ করে, তারপর B-তে গিয়ে A — দুই ধাপে দুইটাই।
     *
     * ⛔ তখন কেউ কোথাও ঢুকতে পারতেন না, আর ফেরার একমাত্র পথ ডাটাবেজ।
     */
    public function test_you_cannot_switch_off_the_last_one_and_lock_everyone_out(): void
    {
        foreach (Company::query()->where('id', '!=', $this->here->id)->get() as $other) {
            $other->forceFill(['is_active' => false])->save();
        }

        /* ⓘ চলতি কোম্পানি সরিয়ে নেওয়া, যাতে পুরনো পাহারাটা নয় — নতুনটাই মাপা হয় */
        $last = Company::query()->where('is_active', true)->firstOrFail();
        CompanyContext::set($last->id, $last->defaultBranch()?->id);

        $another = Company::query()->where('is_active', false)->firstOrFail();
        CompanyContext::set($another->id, $another->defaultBranch()?->id);

        $this->actingAs($this->owner)
            ->post(route('system_admin.company.toggle', $last->id))
            ->assertSessionHasErrors('is_active');

        $this->assertTrue((bool) $last->fresh()->is_active, implode("\n", [
            '⛔ শেষ সচল কোম্পানিটাও নিষ্ক্রিয় হয়ে গেছে।',
            '',
            '⚠️ এখন কেউ কোথাও ঢুকতে পারবেন না, আর ফেরার একমাত্র পথ',
            'ডাটাবেজ — এক ক্লিকে নিজেকে বাইরে তালাবদ্ধ করা।',
        ]));
    }

    private function anotherCompany(): Company
    {
        return Company::query()->where('id', '!=', $this->here->id)->firstOrFail();
    }
}

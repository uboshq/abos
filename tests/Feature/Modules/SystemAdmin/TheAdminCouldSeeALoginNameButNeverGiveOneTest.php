<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * প্রশাসক লগইন নাম দেখতে পেতেন, দিতে পারতেন না।
 *
 * ── ⛔ মালিকের প্রশ্ন, ২২ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"Login ID kothay?"* — ফর্মটা খুলে ঘরটা নেই।
 *
 * ── ⚠️ আর অংশগুলো সবই ছিল ──────────────────────────────────────────
 * `users.login_id` ঘরটা বহুদিনের। লগইনের পর্দা সেটা **মেনেও নেয়**
 * ([[CredentialCheck]] — ইমেইল, লগইন নাম বা মোবাইল)। তালিকায় কলামটাও
 * দেখানো হত। ⛔ কেবল **বসানোর জায়গাটা** ছিল শুধু নিজের প্রোফাইলে।
 *
 * ⓘ ফলে নতুন কর্মীকে ইমেইল দিয়ে ঢুকিয়ে তারপর বলতে হত *"এবার নিজের
 * প্রোফাইলে গিয়ে একটা আইডি বসান"* — আর প্রশাসকের তালিকায় ঐ ঘরে
 * সারাজীবন একটা ড্যাশ বসে থাকত।
 *
 * ⭐ ABOS-এর চেনা আকৃতি: তিনটা অংশ, দুইটা জোড়া, আর কোথাও কিছু লাল নয়।
 */
final class TheAdminCouldSeeALoginNameButNeverGiveOneTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** ⭐ ঘরটা পর্দায় আছে — নাহলে বাকি দাবিগুলো কাগজে সত্যি, পর্দায় নয়। */
    public function test_the_form_has_somewhere_to_put_it(): void
    {
        $html = $this->get(route('system_admin.user.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="login_id"', (string) $html, implode("\n", [
            '⛔ ফর্মে লগইন নামের ঘরটা নেই।',
            '',
            '⚠️ তালিকায় কলামটা আছে আর লগইনের পর্দা আইডিটা মেনে নেয় —',
            'কেবল প্রশাসকের বসানোর জায়গাটা নেই। ⓘ মালিকের প্রশ্নটা ঠিক',
            'এটাই ছিল।',
        ]));
    }

    /**
     * ⭐ আর বসালে সেটা **সত্যিই সংরক্ষিত হয়** — এটাই আসল দাবি।
     *
     * ⛔ কেবল যাচাইয়ের নিয়ম যোগ করে থামলে ঘরটা থাকত, ব্যবহারকারী
     * লিখতেন, "সংরক্ষিত হয়েছে" দেখতেন — আর ঘরটা খালিই থাকত। ⚠️
     * কারণ [[UserController::store()]] ঘরগুলো **হাতে বেছে** বসায়,
     * `$data` ঢেলে দেয় না।
     */
    public function test_a_login_name_typed_on_the_form_is_really_saved(): void
    {
        $this->post(route('system_admin.user.store'), $this->paperwork('notun@abos.test', 'notun.kormi'))
            ->assertRedirect();

        $fresh = User::query()->where('email', 'notun@abos.test')->first();

        $this->assertNotNull($fresh, 'ব্যবহারকারীই তৈরি হয়নি — দাবিটা তখন কিছুই প্রমাণ করে না।');

        $this->assertSame('notun.kormi', $fresh->login_id, implode("\n", [
            '⛔ ফর্মে লেখা লগইন নামটা সংরক্ষিত হয়নি।',
            '',
            '⚠️ পর্দা "সংরক্ষিত হয়েছে" বলে, আর ঘরটা খালি — যে ব্যর্থতা',
            'সফল দেখায়, সেটাই সবচেয়ে দেরিতে ধরা পড়ে।',
        ]));
    }

    /** ⭐ সম্পাদনাতেও — তৈরির পথ আর বদলানোর পথ দুইটা আলাদা কোড। */
    public function test_it_can_also_be_changed_later(): void
    {
        $them = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->put(route('system_admin.user.update', $them),
            $this->paperwork((string) $them->email, 'bikroy.kormi', password: null))
            ->assertRedirect();

        $this->assertSame('bikroy.kormi', $them->fresh()->login_id,
            '⛔ সম্পাদনার পথে লগইন নামটা বসেনি — তৈরির পথে বসলেও।');
    }

    /**
     * ⭐ খালি রাখলে `null` বসে, খালি স্ট্রিং নয়।
     *
     * ⛔ `''` বসলে **দ্বিতীয় জন আর সংরক্ষণই করতে পারতেন না** — unique
     * সূচক দুইটা `''`-কে এক ধরত, অথচ কেউ কোনো আইডি বসায়ইনি। ⚠️ আর
     * ভুলটা দেখা যেত কেবল দ্বিতীয়জনের সময়, তাই প্রথম পরীক্ষায় সবুজ।
     */
    public function test_two_people_may_both_leave_it_empty(): void
    {
        foreach (['ek@abos.test', 'dui@abos.test'] as $email) {
            $this->post(route('system_admin.user.store'), $this->paperwork($email, null))
                ->assertRedirect();
        }

        $made = User::query()->whereIn('email', ['ek@abos.test', 'dui@abos.test'])->get();

        $this->assertCount(2, $made, implode("\n", [
            '⛔ দুইজনের কেউ একজন তৈরি হয়নি।',
            '',
            '⚠️ খালি ঘর `""` হয়ে বসলে দ্বিতীয়জন unique সূচকে আটকাতেন —',
            'অথচ দুইজনের কেউই কোনো আইডি বসাননি।',
        ]));

        foreach ($made as $one) {
            $this->assertNull($one->login_id, '⛔ খালি ঘরটা `null` নয় — খালি স্ট্রিং বসেছে।');
        }
    }

    /**
     * ⭐ আর দুইজন একই লগইন নাম পান না।
     *
     * ⓘ এটা কেবল পরিচ্ছন্নতা নয়: [[CredentialCheck]] আইডি ধরে মানুষ
     * খোঁজে, আর দুইজন মিললে সে **কাকে ঢোকাবে তা জানেই না**।
     */
    public function test_two_people_never_share_a_login_name(): void
    {
        $this->post(route('system_admin.user.store'), $this->paperwork('prothom@abos.test', 'ekjon'))
            ->assertRedirect();

        $this->post(route('system_admin.user.store'), $this->paperwork('ditiyo@abos.test', 'ekjon'))
            ->assertSessionHasErrors('login_id');

        $this->assertNull(User::query()->where('email', 'ditiyo@abos.test')->first(),
            '⛔ একই লগইন নামে দ্বিতীয়জন তৈরি হয়ে গেছে।');
    }

    /**
     * ⭐ আর নিয়মগুলো প্রোফাইলের পর্দার সাথে এক।
     *
     * ⛔ দুই পর্দায় দুই নিয়ম থাকলে প্রশাসক এমন একটা আইডি বসাতে পারতেন
     * যেটা ব্যবহারকারী নিজে বসাতে পারতেন না — আর কেউ বলতে পারত না
     * কোনটা ঠিক। ⓘ `Admin` বড় হাতের অক্ষরে শুরু, তাই দুই পর্দাতেই না।
     */
    public function test_the_two_screens_agree_on_what_a_login_name_may_look_like(): void
    {
        $this->post(route('system_admin.user.store'), $this->paperwork('boro@abos.test', 'Admin'))
            ->assertSessionHasErrors('login_id');

        $this->post(route('system_admin.user.store'), $this->paperwork('choto@abos.test', 'ab'))
            ->assertSessionHasErrors('login_id');
    }

    /**
     * ⭐ একই ফর্মের আরও দুইটা ঘর — মোবাইল আর মন্তব্য।
     *
     * ── ⛔ একই রোগ, একই ফর্মে, তিনবার ──────────────────────────────
     * তিনটা ঘরই **তালিকায় কলাম হিসেবে দেখানো হয়**, আর তিনটারই বসানোর
     * জায়গা ছিল না। ⓘ মন্তব্যের কলামটা প্রতিটা সারিতে খালি ছিল, আর
     * সেটাই মালিকের চোখে পড়ল।
     *
     * ⚠️ দাবিটা আলাদা করে দরকার কারণ mass-assignment **চুপচাপ ঘর ফেলে
     * দেয়**: [[User]]-এ `$fillable` নেই, তাই আজ কাজ করে — কিন্তু কেউ
     * একদিন একটা `$fillable` বসালে এই ঘরগুলো নীরবে বাদ পড়ত আর ফর্ম
     * তবু "সংরক্ষিত হয়েছে" বলত।
     */
    public function test_the_other_two_columns_can_be_filled_in_too(): void
    {
        $this->post(route('system_admin.user.store'), [
            ...$this->paperwork('tothyo@abos.test', 'tothyo.kormi'),
            'mobile' => '01711019292',
            'remarks' => 'ছুটিতে আছেন',
        ])->assertRedirect();

        $fresh = User::query()->where('email', 'tothyo@abos.test')->first();

        $this->assertNotNull($fresh, 'ব্যবহারকারীই তৈরি হয়নি।');

        $this->assertSame('01711019292', $fresh->mobile,
            '⛔ মোবাইল নম্বরটা সংরক্ষিত হয়নি — অথচ তালিকায় কলামটা আছে।');

        $this->assertSame('ছুটিতে আছেন', $fresh->remarks, implode("\n", [
            '⛔ মন্তব্যটা সংরক্ষিত হয়নি।',
            '',
            '⚠️ জায়গা না থাকলে মানুষ ওই কথাগুলো নামের ঘরে লেখেন,',
            'আর তখন নামটাই নষ্ট হয়।',
        ]));
    }

    /** @return array<string, mixed> */
    private function paperwork(string $email, ?string $loginId, ?string $password = 'Abos!2026Strong'): array
    {
        return array_filter([
            'name' => 'পরীক্ষার মানুষ',
            'email' => $email,
            'login_id' => $loginId,
            'password' => $password,
            'password_confirmation' => $password,
            'locale' => 'bn',
            'is_active' => '1',
            'companies' => [$this->company->id],
            'roles' => [Role::query()->value('name')],
        ], fn ($v) => $v !== null);
    }
}

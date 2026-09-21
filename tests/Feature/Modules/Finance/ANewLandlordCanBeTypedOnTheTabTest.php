<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ভাড়ার "কার সাথে" ট্যাবে নতুন নাম সত্যিই বসে।
 *
 * ── ⭐ মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"varar chukti o jamanot e কার সাথে creat er bebosta koro"*।
 *
 * ── ⛔ কেন এই পাহারাটা লেখা হলো, আর এটাই আজকের সবচেয়ে সৎ কারণ ───────
 * কাজটা করে আমি **ফর্মটা পর্দায় এসেছে কি না** দেখেছিলাম — এসেছিল, আর
 * আমি "হয়ে গেছে" বলেছিলাম। ⛔ মালিক নাম বসিয়ে **৫০০** পেলেন।
 *
 * ⚠️ কারণ [[PersonResolver::resolve()]] তার আর্গুমেন্ট রেফারেন্সে নেয়,
 * আর আমি সরাসরি একটা অ্যারে পাঠিয়েছিলাম। ⓘ হাতধারে ওটা একটা চলকে
 * রেখে পাঠানো — আমি "হুবহু একই ছাঁচ" বলে নকল করেও ঠিক ঐ একটা জিনিসই
 * বদলে ফেলেছিলাম।
 *
 * ⭐ শিক্ষাটা এক লাইনে: **ফর্ম আঁকা হওয়া আর ফর্ম কাজ করা দুইটা আলাদা
 * সত্য।** তাই এই ফাইলটা পর্দা দেখে না — সে নামটা **জমা দেয়**, তারপর
 * খাতায় খুঁজে দেখে।
 */
final class ANewLandlordCanBeTypedOnTheTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_typing_a_name_really_adds_the_person(): void
    {
        $before = Person::query()->count();

        $this->from(route('finance.rental.index', ['tab' => 'people']))
            ->post(route('finance.rental.person.store'), [
                'name_bn' => 'করিম মিয়া',
                'mobile' => '01710000000',
            ])
            ->assertRedirect(route('finance.rental.index', ['tab' => 'people']))
            ->assertSessionHasNoErrors();

        $this->assertSame($before + 1, Person::query()->count(),
            '⛔ নামটা জমা হলো, কিন্তু মানুষের তালিকায় কেউ যোগ হয়নি।');

        $this->assertDatabaseHas('mdm_people', ['mobile' => '01710000000']);
    }

    /**
     * ⭐ আর নামটা ভাড়ার নিজের কোনো টেবিলে যায় না — একটাই তালিকা।
     *
     * ⓘ হাতধার, ভাড়া আর মূলধন — তিনটা দরজা, একটাই ঘর (`mdm_people`)।
     * ⚠️ আলাদা টেবিল হলে "Karim", "Karim Mia" আর "করিম মিয়া" তিনজন
     * হয়ে যেতেন, আর একজনের জামানত তিন ভাগে ছিঁড়ত।
     */
    public function test_the_person_lands_in_the_one_shared_list(): void
    {
        $this->post(route('finance.rental.person.store'), ['name_bn' => 'করিম মিয়া']);

        $person = Person::query()->latest('id')->firstOrFail();

        $this->assertNotNull($person->id);

        /* ⓘ হাতধারের ট্যাবেও তিনিই আছেন — একই তালিকা পড়ে। */
        $this->get(route('finance.hand_loan.index', ['tab' => 'people']))->assertOk();
    }

    /**
     * ⛔ নাম ছাড়া কিছু বসে না।
     *
     * ⓘ খালি নামে একটা ফাঁকা সারি তৈরি হলে তালিকাটা দিন দিন আবর্জনায়
     * ভরত, আর কেউ মুছত না।
     */
    public function test_an_empty_name_is_refused(): void
    {
        $before = Person::query()->count();

        $this->from(route('finance.rental.index', ['tab' => 'people']))
            ->post(route('finance.rental.person.store'), ['name_bn' => ''])
            ->assertSessionHasErrors('name_bn');

        $this->assertSame($before, Person::query()->count());
    }

    /**
     * ⛔ একই নামে দ্বিতীয়বার — থামে, আর করণীয়টা বলে।
     *
     * ⓘ [[DuplicationEngine]] নাম মিললে থামায়: এক পক্ষের দুইটা সারি
     * হলে বকেয়াও দুই ভাগ হয়ে যায়।
     */
    public function test_the_same_name_twice_is_stopped(): void
    {
        $this->post(route('finance.rental.person.store'), ['name_bn' => 'করিম মিয়া']);

        $before = Person::query()->count();

        $this->from(route('finance.rental.index', ['tab' => 'people']))
            ->post(route('finance.rental.person.store'), ['name_bn' => 'করিম মিয়া'])
            ->assertSessionHasErrors();

        $this->assertSame($before, Person::query()->count(),
            '⛔ একই নামে দ্বিতীয় একটা সারি বসে গেছে।');
    }

    /**
     * ⭐ আর টিক দিলে সত্যিই এগোয় — এটাই আসল দাবি।
     *
     * ── ⛔ মালিক যেখানে আটকে গিয়েছিলেন ─────────────────────────────
     * বার্তাটা বলত *"ঘরটা টিক দিয়ে আবার সংরক্ষণ করুন"*, অথচ এই ফর্মে
     * **ঘরটাই ছিল না**। ⚠️ অর্থাৎ ব্যবস্থাটা একটা করণীয় বলত যা করা
     * যেত না — থামা আর দরজা বন্ধ করা এক জিনিস হয়ে গিয়েছিল।
     */
    public function test_ticking_the_box_lets_a_real_namesake_through(): void
    {
        $this->post(route('finance.rental.person.store'), ['name_bn' => 'করিম মিয়া']);

        $before = Person::query()->count();

        $this->post(route('finance.rental.person.store'), [
            'name_bn' => 'করিম মিয়া',
            'allow_duplicate' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame($before + 1, Person::query()->count(),
            '⛔ টিক দেওয়ার পরেও দ্বিতীয় নামটা বসেনি — বার্তাটা মিথ্যা করণীয় বলছে।');
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের দাবিগুলো সবুজ থাকত যদি রুটটাই না থাকত — তখন `route()`
     * ছুঁড়ত আর বার্তাটা হত অন্য কিছু। ⚠️ আর ফর্মটা পর্দায় না থাকলে
     * ব্যবহারকারী রুটটায় পৌঁছাতেই পারতেন না, তাই দুইটাই দেখা হয়।
     */
    public function test_the_tab_really_shows_the_form(): void
    {
        $html = (string) $this->get(route('finance.rental.index', ['tab' => 'people']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('finance.rental.person.store'), $html,
            'ট্যাবে নাম যোগ করার ফর্মটাই নেই — রুট থাকলেও কেউ পৌঁছাবে না।');

        $this->assertStringContainsString('name_bn', $html);
    }
}

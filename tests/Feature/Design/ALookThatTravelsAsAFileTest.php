<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Services\LookSkinService;
use App\Core\Support\CompanyContext;
use App\Core\Support\LookFile;
use App\Core\Support\LookRegistry;
use App\Models\Company;
use App\Models\LookSkin;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * রূপ যখন একটা ফাইল — থিম ইঞ্জিনের ধাপ ৪ (অংশ ৯)।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * একটা রূপ যে ডাটাবেজে জন্মেছে সেখানেই বন্দী ছিল। আমরা গ্রাহকের জন্য
 * রূপ বানিয়ে পাঠাতে পারতাম না, ডেভ মেশিনে বানিয়ে লাইভে নিতে পারতাম না,
 * আর চারটা টেন্যান্টে একই রূপ চাইলে চারবার হাতে লিখতে হত।
 *
 * ── এই ফাইলের সবচেয়ে জরুরি পরীক্ষা ──────────────────────────────────
 * প্রথমটা: **গোল ভ্রমণ**। রপ্তানি করে আবার আমদানি করলে ঠিক একই রং
 * ফিরে আসতে হবে।
 *
 * ওটাই একমাত্র প্রশ্ন যা সত্যিই গুরুত্বপূর্ণ, কারণ বাকি সবকিছু ঠিক
 * থেকেও ফাইলটা অকেজো হতে পারে — একটা টোকেন হারালে গ্রাহকের কাছে
 * পৌঁছানো রূপটা "প্রায় ঠিক" হত, আর সেটাই সবচেয়ে খারাপ ফল।
 */
class ALookThatTravelsAsAFileTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private LookSkinService $looks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->looks = app(LookSkinService::class);
    }

    private function skin(array $light, string $parent = 'navy', array $dark = []): LookSkin
    {
        return LookSkin::query()->create([
            'company_id' => $this->company->id,
            'name' => 'পরীক্ষার রূপ '.uniqid(),
            'parent' => $parent,
            'tokens' => ['light' => $light, 'dark' => $dark],
            'created_by' => $this->owner->id,
        ]);
    }

    /**
     * গোল ভ্রমণ — যা গেল, তাই ফিরল।
     *
     * ধাপে ধাপে নয়, **ফলাফল ধরে** মেলানো হয়: দুইটা রূপের চূড়ান্ত
     * টোকেন-সেট এক কি না। ফাইলের ভিতরের গড়ন বদলাতে পারে, কিন্তু
     * পর্দার রং বদলাতে পারে না — আর এই পরীক্ষাটা ঠিক সেটাই বাঁধে।
     */
    public function test_a_look_survives_the_round_trip(): void
    {
        $skin = $this->skin(
            ['--color-brand-500' => '#0b6e4f', '--radius-card' => '3px'],
            dark: ['--color-brand-500' => '#12b981'],
        );

        $back = LookFile::into(LookFile::from($skin), $this->owner->id);

        foreach (['light', 'dark'] as $theme) {
            $this->assertSame(
                $skin->tokens($theme),
                $back->tokens($theme),
                "{$theme} থিমে গোল ভ্রমণের পর রূপটা আর এক নেই।",
            );
        }
    }

    /**
     * ফাইলে কেবল পার্থক্যটুকু যায়, ষাটটা টোকেন নয়।
     *
     * ── কেন এটা মাপা হয় ─────────────────────────────────────────────
     * পুরো সেট লিখে দিলে গোল ভ্রমণের পরীক্ষাটাও পাশ করত। কিন্তু তখন
     * ফাইলটা মূল রূপ থেকে **বিচ্ছিন্ন** হয়ে যেত: আমরা Navy-র একটা রং
     * শোধরালে ওই ফাইল থেকে আসা রূপটা পুরনোই থেকে যেত, আর কোনটা
     * ইচ্ছাকৃত বদল আর কোনটা কেবল জমে থাকা কপি — বলা যেত না।
     */
    public function test_the_file_carries_only_what_changed(): void
    {
        $skin = $this->skin(['--color-brand-500' => '#0b6e4f']);

        $said = LookFile::from($skin);

        $this->assertSame(
            ['--color-brand-500' => '#0b6e4f'],
            $said['tokens']['light'],
            'ফাইলে পার্থক্যের বেশি কিছু লেখা হয়েছে।',
        );
    }

    /**
     * চেইনটা সমতল হয়ে যায়, আর গোড়ায় থাকে কোড-রূপের নাম।
     *
     * ── কেন `public_id` লেখা চলবে না ─────────────────────────────────
     * ওটা অন্য ইনস্টলে কিছুই বোঝায় না। লিখে রাখলে ফাইলটা হয় ভাঙত,
     * নয় নীরবে ভুল কিছুর উপর দাঁড়াত — আর দ্বিতীয়টা অনেক খারাপ,
     * কারণ রংগুলো তখন প্রায় ঠিক দেখাত।
     */
    public function test_a_chain_is_flattened_onto_a_look_that_exists_everywhere(): void
    {
        $base = $this->skin(['--radius-card' => '3px'], parent: 'apps');
        $this->looks->publish($base, null, $this->owner->id);

        $child = $this->skin(['--radius-field' => '2px'], parent: $base->public_id);

        $said = LookFile::from($child);

        $this->assertSame('apps', $said['stands_on'], 'গোড়ায় কোড-রূপের নাম বসেনি।');

        // মাঝের স্তরের বদলটাও ফাইলে আছে — নাহলে অন্য মেশিনে ওটা হারাত
        $this->assertSame('3px', $said['tokens']['light']['--radius-card']);
        $this->assertSame('2px', $said['tokens']['light']['--radius-field']);
    }

    /**
     * আমদানি করা রূপ সবসময় খসড়া।
     *
     * বাইরে থেকে আসা একটা ফাইল কখনো সরাসরি সবার পর্দায় যায় না।
     * গেলে একটা ফাইল দিয়েই গোটা ডিপোর লেখা অপঠনযোগ্য করে দেওয়া যেত,
     * আর প্রকাশের গেটটার কোনো মানে থাকত না।
     */
    public function test_an_imported_look_arrives_as_a_draft(): void
    {
        $skin = $this->skin(['--color-brand-500' => '#0b6e4f']);

        $back = LookFile::into(LookFile::from($skin), $this->owner->id);

        $this->assertNull($back->published_at);
        $this->assertSame(0, $back->versions()->count());
        $this->assertNull(LookRegistry::skin($back->public_id));
    }

    /**
     * একই নামে দ্বিতীয়টা এলে নামটা আলাদা হয় — আর সেটা বলা হয়।
     *
     * নামের জোড়া অনন্য, তাই কিছু একটা করতেই হয়। উপরে বসিয়ে দেওয়া
     * হয় না: তাতে কারো সপ্তাহখানেকের কাজ এক ক্লিকে মুছে যেত।
     */
    public function test_the_same_name_twice_does_not_overwrite(): void
    {
        $skin = $this->skin(['--color-brand-500' => '#0b6e4f']);
        $said = LookFile::from($skin);

        $first = LookFile::into($said, $this->owner->id);
        $second = LookFile::into($said, $this->owner->id);

        $this->assertNotSame($first->name, $second->name);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($skin->tokens('light'), $second->tokens('light'));
    }

    /** ভুল ফাইল স্পষ্ট কথায় ফেরে, অদ্ভুত ভুলে নয়। */
    public function test_a_file_that_is_not_a_look_is_refused_in_plain_words(): void
    {
        foreach ([
            ['rows' => [1, 2, 3]],                                   // অন্য কিছুর রপ্তানি
            ['kind' => LookFile::KIND, 'format' => 99],              // ভবিষ্যতের গড়ন
            ['kind' => LookFile::KIND, 'format' => 1, 'stands_on' => 'nosuchlook'],
        ] as $said) {
            try {
                LookFile::into($said, $this->owner->id);
                $this->fail('ভুল ফাইলটা মেনে নেওয়া হয়েছে।');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('file', $e->errors());
            }
        }
    }

    /**
     * ফাইলের ভিতরের বানান ভুলও ধরা পড়ে।
     *
     * ── কেন এটা আলাদা করে দরকার ──────────────────────────────────────
     * ফর্মের যাচাই কেবল ফর্মের পথটা পাহারা দেয়। ফাইলটা একটা **দ্বিতীয়
     * দরজা**, আর ওখানে যাচাই না থাকলে যে কেউ একটা JSON লিখে অচেনা
     * নাম ঢুকিয়ে দিতে পারত — যা নীরবে কিছুই করত না।
     */
    public function test_a_misspelled_token_inside_the_file_is_refused(): void
    {
        try {
            LookFile::into([
                'kind' => LookFile::KIND,
                'format' => 1,
                'name' => 'বানান ভুল',
                'stands_on' => 'navy',
                'tokens' => ['light' => ['--color-surfase-app' => '#ffffff'], 'dark' => []],
            ], $this->owner->id);

            $this->fail('বানান ভুল টোকেনটা মেনে নেওয়া হয়েছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
        }
    }

    /** পর্দা থেকে নামানো — আর যা নামে সেটা সত্যিই ফাইলটা। */
    public function test_the_screen_hands_over_a_file(): void
    {
        $skin = $this->skin(['--color-brand-500' => '#0b6e4f']);

        $res = $this->actingAs($this->owner)
            ->get(route('system_admin.look.export', $skin))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=UTF-8');

        $this->assertStringContainsString('attachment;', (string) $res->headers->get('Content-Disposition'));

        $said = json_decode((string) $res->getContent(), true);

        $this->assertSame(LookFile::KIND, $said['kind']);
        $this->assertSame('#0b6e4f', $said['tokens']['light']['--color-brand-500']);
    }

    /** পর্দা থেকে তোলা — আর তোলার পর সম্পাদনার পাতায় গিয়ে বসা। */
    public function test_the_screen_takes_a_file_in(): void
    {
        $skin = $this->skin(['--color-brand-500' => '#0b6e4f']);

        $json = (string) json_encode(LookFile::from($skin), JSON_UNESCAPED_UNICODE);

        $this->actingAs($this->owner)
            ->post(route('system_admin.look.import'), [
                'file' => UploadedFile::fake()->createWithContent('look.json', $json),
            ])
            ->assertRedirect();

        $this->assertSame(2, LookSkin::query()->count(), 'আমদানি করা রূপটা বসেনি।');
    }

    /**
     * কেবল অনুমতিওয়ালা মানুষই ফাইল তুলতে বা নামাতে পারেন।
     *
     * রূপের ফাইলে ব্যবসায়িক তথ্য নেই, কিন্তু আমদানি একটা সারি বসায়
     * আর রপ্তানি বলে দেয় কোম্পানি কী কী বানিয়েছে। দুইটাই প্রশাসনের কাজ।
     */
    public function test_the_doors_are_guarded(): void
    {
        $skin = $this->skin(['--color-brand-500' => '#0b6e4f']);

        $plain = User::query()->where('email', '!=', 'owner@abos.test')->firstOrFail();

        $this->actingAs($plain)
            ->get(route('system_admin.look.export', $skin))
            ->assertForbidden();

        $this->actingAs($plain)
            ->post(route('system_admin.look.import'), [
                'file' => UploadedFile::fake()->createWithContent('look.json', '{}'),
            ])
            ->assertForbidden();
    }
}

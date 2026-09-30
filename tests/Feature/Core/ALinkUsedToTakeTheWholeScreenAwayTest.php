<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\Peek;
use App\Http\Middleware\PeekVaries;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * লিংকে চাপলে মডিউল বদলে যেত, আর তালিকার জায়গাটা হারিয়ে যেত।
 *
 * ── ⭐ মালিকের নিয়ম, ২৮ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * *"হাইপার লিংকে চাপলে পপআপ এলেই ভালো, নাহলে মডিউল/মেনু পরিবর্তন হয়ে
 * যায়, সেটা অত্যন্ত বিরক্তিকর।"*
 *
 * ── ⛔ এই ফাইলের সবচেয়ে জরুরি দাবিটা নিরাপত্তার ───────────────────────
 * ⚠️ খোলস বাদ দেওয়া একটা **আঁকার** সিদ্ধান্ত। ⛔ যদি ওটা দরজাও খুলে
 * দিত, তাহলে একটা হেডার পাঠিয়েই অনুমতি ছাড়া নথি পড়া যেত — আর সেটা
 * নীরব, কারণ পর্দায় সবকিছু আগের মতোই দেখাত।
 */
final class ALinkUsedToTakeTheWholeScreenAwayTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'system_admin.user.manage';

    private User $admin;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->admin = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $clerk = User::query()
            ->where('email', '!=', $this->admin->email)
            ->get()
            ->first(fn (User $u) => ! $u->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE));

        $this->assertNotNull($clerk, 'ডেমোতে সুপার অ্যাডমিন ছাড়া আর কেউ নেই।');

        $this->clerk = $clerk;
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ এক ঠিকানা, দুইটা চেহারা ─────────────────────────────────────

    public function test_the_same_address_gives_the_page_and_just_its_inside(): void
    {
        $full = $this->actingAs($this->admin)->get(route('profile'));
        $full->assertOk();

        $peek = $this->actingAs($this->admin)
            ->withHeaders([Peek::HEADER => '1'])
            ->get(route('profile'));
        $peek->assertOk();

        $shell = $full->getContent();
        $inside = $peek->getContent();

        /*
         * ⓘ খোলসের চিহ্ন দুইটা: নথির ঘোষণা, আর নিচের নেভের সারি।
         * ⚠️ দুইটাই নেওয়া হয়েছে কারণ একটা যথেষ্ট নয় — টুকরোটা যদি ভুল
         * করে গোটা পাতা হয়, `<!DOCTYPE` ধরে; আর যদি কেবল খোলসের ভিতরের
         * অংশটা আসে, নেভটা ধরে।
         */
        $this->assertStringContainsString('<!DOCTYPE', $shell);
        $this->assertStringContainsString('bottom-nav-item', $shell);

        $this->assertStringNotContainsString('<!DOCTYPE', $inside,
            'পিকেও গোটা নথি আসছে — খোলসটা বাদ যায়নি।');
        $this->assertStringNotContainsString('bottom-nav-item', $inside,
            'পিকের ভিতরেও মেনু আছে — পপআপের ভিতরে আরেকটা গোটা অ্যাপ।');

        /*
         * ⛔ আর ভিতরটা সত্যিই এসেছে — নাহলে উপরের দুইটা দাবি একটা
         * **খালি** উত্তরেও সবুজ থাকত, আর এই প্রকল্পে ঠিক ঐ ভুলটাই
         * বারবার হয়।
         *
         * ⚠️ শিরোনামটা আমি টাইপ করছি না, মাপা পাতা থেকে তুলে নিচ্ছি —
         * নিজের টাইপ করা নামে বানানো পরীক্ষা কখনো ব্যর্থ হতে পারে না।
         */
        $heading = $this->headingOf($shell);

        $this->assertNotSame('', $heading, 'মাপার মতো কোনো শিরোনামই পাওয়া গেল না।');
        $this->assertStringContainsString($heading, $inside,
            'পিকে পাতার নিজের অংশটাই নেই।');
    }

    public function test_vary_tells_the_cache_that_one_address_has_two_shapes(): void
    {
        /*
         * ⛔ `Vary` ছাড়া প্রক্সি বা ব্রাউজার খোলসহীন টুকরোটাকেই আসল পাতা
         * ধরে পরিবেশন করত — আর মেনু-বিহীন একটা পাতা দেখতে হুবহু ভেঙে
         * যাওয়া সিস্টেমের মতো।
         *
         * ⚠️ দুইটা উত্তরেই দেখা হয়, কারণ ক্যাশ ভুক্তিটা তৈরি হয়
         * **প্রথম** উত্তরে — খোলসওয়ালাটাতে না থাকলে ওটাই জমা হত।
         */
        $answers = [
            'পাতার' => $this->actingAs($this->admin)->get(route('profile')),
            'পিকের' => $this->actingAs($this->admin)
                ->withHeaders([Peek::HEADER => '1'])
                ->get(route('profile')),
        ];

        foreach ($answers as $which => $res) {
            $this->assertStringContainsString(
                Peek::HEADER,
                (string) $res->headers->get('Vary'),
                $which.' উত্তরে Vary নেই।',
            );
        }
    }

    public function test_vary_is_added_to_what_was_already_there(): void
    {
        /*
         * ⛔ যোগ করা, বসিয়ে দেওয়া নয়। ⚠️ অন্য কোনো মিডলওয়্যারের বসানো
         * `Vary` মুছে গেলে ক্যাশ ঐ মাত্রাটা আর আলাদা করত না — আর ভুলটা
         * নীরব, কারণ `X-Peek` তখনো ঠিক জায়গাতেই থাকত।
         *
         * ⓘ মিডলওয়্যারটা সরাসরি ডাকা হয়, কারণ এই মুহূর্তে আর কেউ `Vary`
         * বসায় না — তাই পাতা দিয়ে মাপলে দাবিটা কিছুই ধরত না।
         */
        $already = new Response('ঠিক আছে');
        $already->setVary('Accept-Language');

        $out = app(PeekVaries::class)->handle(
            Request::create('/profile', 'GET'),
            fn () => $already,
        );

        $vary = (string) $out->headers->get('Vary');

        $this->assertStringContainsString('Accept-Language', $vary,
            'আগের Vary মুছে গেছে।');
        $this->assertStringContainsString(Peek::HEADER, $vary);
    }

    // ── ⛔ খোলস যায়, দরজা থাকে ─────────────────────────────────────────

    public function test_the_shell_goes_but_the_door_does_not(): void
    {
        /*
         * ⭐ একই মানুষ, একই ঠিকানা, একই হেডার — কেবল চাবিটা আলাদা।
         * ⚠️ দুইজন আলাদা মানুষ নিলে ৪০৩-টা ভূমিকার কারণেও হতে পারত, আর
         * তখন দাবিটা কিছুই প্রমাণ করত না।
         */
        CompanyContext::forCompany(
            CompanyContext::id(),
            fn () => $this->clerk->givePermissionTo(self::KEY),
        );

        $this->actingAs($this->clerk->fresh())
            ->withHeaders([Peek::HEADER => '1'])
            ->get(route('system_admin.user.index'))
            ->assertOk();

        CompanyContext::forCompany(
            CompanyContext::id(),
            fn () => $this->clerk->revokePermissionTo(self::KEY),
        );

        $this->actingAs($this->clerk->fresh())
            ->withHeaders([Peek::HEADER => '1'])
            ->get(route('system_admin.user.index'))
            ->assertForbidden();
    }

    // ── ⛔ যেসব অনুরোধ কখনো পিক নয় ─────────────────────────────────────

    public function test_a_post_is_never_a_peek(): void
    {
        /*
         * ⛔ POST-এর উত্তরে খোলস বাদ দিলে সফল সংরক্ষণের পর মানুষ একটা
         * মেনুহীন পাতায় আটকে যেতেন।
         */
        $post = Request::create('/profile', 'POST');
        $post->headers->set(Peek::HEADER, '1');

        $this->assertFalse(Peek::wanted($post));
    }

    public function test_a_request_that_wants_json_is_never_a_peek(): void
    {
        /*
         * ⚠️ দুইটা মিশলে fetch একটা HTML টুকরো পেয়ে সেটাকে সফল JSON ধরে
         * নিত — এই রিপোতে ঐ ভুলটা আগেও হয়েছে।
         */
        $json = Request::create('/profile', 'GET');
        $json->headers->set(Peek::HEADER, '1');
        $json->headers->set('Accept', 'application/json');

        $this->assertFalse(Peek::wanted($json));
    }

    public function test_a_plain_get_without_the_header_is_never_a_peek(): void
    {
        $this->assertFalse(Peek::wanted(Request::create('/profile', 'GET')));
    }

    // ── ⓘ জানালাটা সত্যিই পাতায় বসানো আছে ─────────────────────────────

    public function test_the_window_is_mounted_once_on_the_page(): void
    {
        /*
         * ⛔ এই প্রকল্পের সবচেয়ে চেনা ফাঁদ: কাজটা হয়ে যায়, জোড়াটা লাগে
         * না, আর কিছুই লাল হয় না। ⚠️ জানালাটা পাতায় না বসলে প্রতিটা
         * ক্লিক আগের মতোই মডিউল বদলাত।
         *
         * ⓘ "একবার" গোনা হয় কারণ দুইবার বসলে দুইটা শ্রোতা দাঁড়াত, আর
         * একটা ক্লিকে দুইটা অনুরোধ যেত।
         */
        $html = $this->actingAs($this->admin)->get(route('profile'))->getContent();

        $this->assertSame(1, substr_count($html, 'x-data="peek"'),
            'পিকের জানালাটা পাতায় একবার বসেনি।');
    }

    // ── সহায়ক ──────────────────────────────────────────────────────────

    /** মাপা পাতা থেকে প্রথম শিরোনামের লেখাটা — হাতে টাইপ করা নয়। */
    private function headingOf(string $html): string
    {
        foreach (['h1', 'h2'] as $tag) {
            if (preg_match('#<'.$tag.'[^>]*>(.*?)</'.$tag.'>#s', $html, $m) === 1) {
                $text = trim(html_entity_decode(strip_tags($m[1])));

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }
}

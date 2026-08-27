<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Core\Support\Ui;
use App\Models\Company;
use App\Models\SavedView;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সংরক্ষিত দৃশ্য ছাঁকনিগুলো ফিরিয়ে আনে।
 *
 * ── কী সমস্যা মেটাচ্ছে ───────────────────────────────────────────────
 * তালিকার ছাঁকনি কোয়েরি-স্ট্রিংয়ে থাকে — লিংকটা পাঠালে অন্যজন ঠিক সেটাই
 * দেখেন। কিন্তু **ফিরে আসার** উপায় ছিল না: যিনি রোজ "বকেয়া, ময়মনসিংহ"
 * তালিকাটা দেখেন, তাঁকে রোজ ঘরগুলো আবার ভরতে হত।
 *
 * ── আর একটা মৃত বোতাম ────────────────────────────────────────────────
 * টুলবারে শিরোনামের পাশে একটা `▾` চিহ্ন আঁকা হত, পাশে মন্তব্যে লেখা
 * "চিহ্নটা বলে এটা একটা দৃশ্য"। **ওর পেছনে কোনো মেনু ছিল না** — দশটা
 * রূপের একটাতেও ক্লিক করে কিছু হত না।
 *
 * ২৭ অগাস্ট ২০২৬-এ D365-এর নকল মিলিয়ে দেখতে গিয়ে ধরা পড়ে। তখন ঠিক
 * করা হয়েছিল আগে **জিনিসটা** বানানো হবে, তারপর তার চেহারা — কারণ
 * ড্রপডাউনে রাখার মতো কিছু না থাকলে সেটা আরেকটা মৃত বোতামই হত।
 *
 * পাশের পাহারা: [[AClonePromisedAShapeAndDrewAnotherTest]]।
 */
class ASavedViewBringsTheFiltersBackTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private const SCREEN = 'inventory.product.index';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    /**
     * একটা দৃশ্য রাখা হয়, আর তার ঠিকানায় ছাঁকনিগুলো ফিরে আসে।
     */
    public function test_a_view_keeps_the_filters_and_gives_them_back(): void
    {
        $this->post(route('views.store'), [
            'screen' => self::SCREEN,
            'name' => 'বকেয়া, ময়মনসিংহ',
            'query' => 'q=abc&status=due',
        ])->assertRedirect();

        $view = SavedView::query()->where('name', 'বকেয়া, ময়মনসিংহ')->firstOrFail();

        $this->assertSame('q=abc&status=due', $view->query);
        $this->assertSame($this->user->id, $view->user_id);

        /*
         * কোম্পানিটা কেউ পাঠায়নি — `BelongsToCompany` নিজেই বসিয়েছে।
         *
         * এটা আলাদা করে দেখা হয় কারণ আগে একবার `$request->user()->company_id`
         * ধরে কোম্পানি বসানোর চেষ্টা হয়েছিল, আর ওই ঘরটা `User`-এ নেই —
         * ফলে প্রতিটা সারিতে `null` বসত।
         */
        $this->assertSame($this->company->id, $view->company_id);

        // দৃশ্যটার নিজের ঠিকানায় ছাঁকনিগুলো ফেরত আসে
        $this->assertStringEndsWith('?q=abc&status=due', $view->url());
        $this->get($view->url())->assertOk();
    }

    /**
     * ছাঁকনি ছাড়া "সব সারি"ও একটা কাজের দৃশ্য।
     *
     * D365-এর "All Accounts" ঠিক এটাই। কোয়েরি বাধ্যতামূলক করলে ওই
     * দৃশ্যটা বানানোই যেত না।
     */
    public function test_a_view_with_no_filters_is_allowed(): void
    {
        $this->post(route('views.store'), [
            'screen' => self::SCREEN,
            'name' => 'সব পণ্য',
            'query' => '',
        ])->assertRedirect();

        $view = SavedView::query()->where('name', 'সব পণ্য')->firstOrFail();

        $this->assertSame('', $view->query);
        $this->assertSame(route(self::SCREEN), $view->url());
    }

    /**
     * এক পর্দায় একজনের একটাই ডিফল্ট।
     *
     * ── কেন এটা মেপে দেখা হয় ─────────────────────────────────────────
     * নিয়মটা ডাটাবেস বাঁধে না — MySQL আংশিক ইউনিক সূচক বোঝে না — তাই
     * এটা কেবল কোডের একটা প্রতিশ্রুতি। যে নিয়ম কেবল কোডে থাকে, তার
     * একটা পরীক্ষা না থাকলে সেটা একদিন চুপচাপ ভাঙে, আর তখন পর্দাটা
     * খুলত দুইটা "ডিফল্ট" দৃশ্যের যেকোনো একটা নিয়ে — প্রতিবার একই
     * নয়।
     */
    public function test_only_one_view_can_be_the_default_for_a_screen(): void
    {
        foreach (['প্রথম', 'দ্বিতীয়', 'তৃতীয়'] as $name) {
            $this->post(route('views.store'), [
                'screen' => self::SCREEN,
                'name' => $name,
                'query' => 'q='.$name,
                'is_default' => '1',
            ])->assertRedirect();
        }

        $defaults = SavedView::query()
            ->where('user_id', $this->user->id)
            ->where('screen', self::SCREEN)
            ->where('is_default', true)
            ->pluck('name');

        $this->assertCount(1, $defaults);
        $this->assertSame('তৃতীয়', $defaults->first());
    }

    /**
     * অন্যের দৃশ্যে হাত দেওয়া যায় না।
     */
    public function test_a_view_belongs_to_the_person_who_made_it(): void
    {
        $mine = SavedView::query()->create([
            'user_id' => $this->user->id,
            'screen' => self::SCREEN,
            'name' => 'আমার',
            'query' => 'q=1',
        ]);

        $other = User::query()->where('id', '!=', $this->user->id)->firstOrFail();
        $other->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($other);

        $this->delete(route('views.destroy', $mine))->assertForbidden();
        $this->post(route('views.default', $mine))->assertForbidden();

        $this->assertDatabaseHas('ui_saved_views', ['id' => $mine->id]);
    }

    /**
     * একই পর্দায় একই নামের দুইটা দৃশ্য নয়।
     *
     * ড্রপডাউনে পাশাপাশি দুইটা "বকেয়া" দেখে কেউ বুঝতেন না কোনটা কোনটা।
     */
    public function test_two_views_on_one_screen_cannot_share_a_name(): void
    {
        $payload = ['screen' => self::SCREEN, 'name' => 'বকেয়া', 'query' => 'a=1'];

        $this->post(route('views.store'), $payload)->assertRedirect();

        $this->post(route('views.store'), ['screen' => self::SCREEN, 'name' => 'বকেয়া', 'query' => 'b=2'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, SavedView::query()->where('name', 'বকেয়া')->count());
    }

    /**
     * অচেনা পর্দার নামে দৃশ্য রাখা যায় না।
     *
     * রাখা গেলে ড্রপডাউনটা `route()`-এ ছুড়ে ফেলত, আর গোটা টুলবারই
     * ভেঙে যেত — একটা ভুল সারির দাম গোটা পর্দা।
     */
    public function test_a_view_cannot_point_at_a_screen_that_does_not_exist(): void
    {
        $this->post(route('views.store'), [
            'screen' => 'this.route.does.not.exist',
            'name' => 'ভুয়া',
            'query' => '',
        ])->assertSessionHasErrors('screen');

        $this->assertDatabaseCount('ui_saved_views', 0);
    }

    /**
     * নিয়ন্ত্রণটা প্রতিটা রূপে আছে, আর ঠিক জায়গায়।
     *
     * ── কেন জায়গাটাও মাপা হয় ─────────────────────────────────────────
     * D365-এর তালিকার পর্দার সবচেয়ে চেনা জিনিসটাই হলো **শিরোনামটা
     * নিজেই ড্রপডাউন**। বোতামটা ডানে বসিয়ে দিলে জিনিসটা কাজ করত,
     * কিন্তু নকলটা আর চেনা যেত না।
     *
     * আর উল্টোটাও দেখা হয়: বাকি ন'টা রূপে শিরোনাম যেন শিরোনামই থাকে।
     * আগে ওখানে একটা `▾` আঁকা হত যার পেছনে কিছু ছিল না।
     */
    public function test_the_control_sits_where_each_look_puts_it(): void
    {
        $wrong = [];

        foreach (Ui::keys() as $look) {
            $this->user->forceFill(['ui' => $look])->save();

            $body = (string) $this->get(route(self::SCREEN))->assertOk()->getContent();

            $hasMenu = str_contains($body, 'data-view-menu');
            $inHeading = (bool) preg_match('/<h1[^>]*class="contents"[^>]*>\s*<div[^>]*>\s*<button[^>]*data-view-menu/s', $body);
            $deadChevron = str_contains($body, 'data-view-selector')
                && ! str_contains($body, 'data-view-menu');

            if (! $hasMenu) {
                $wrong[] = "{$look} — দৃশ্যের মেনুই নেই";
            }

            if (Ui::views($look) === 'dropdown' && ! $inHeading) {
                $wrong[] = "{$look} — শিরোনামটা ড্রপডাউন হওয়ার কথা, হয়নি";
            }

            if (Ui::views($look) !== 'dropdown' && $inHeading) {
                $wrong[] = "{$look} — শিরোনামটা ড্রপডাউন হওয়ার কথা নয়";
            }

            if ($deadChevron) {
                $wrong[] = "{$look} — শিরোনামে চিহ্ন আছে, পেছনে মেনু নেই";
            }
        }

        $this->assertSame([], $wrong, implode("\n", [
            'দৃশ্যের নিয়ন্ত্রণটা ঠিক জায়গায় বসছে না:',
            ...$wrong,
        ]));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Assistant;

use App\Core\Engines\Assistant\AssistantEngine;
use App\Core\Engines\Assistant\ModelClient;
use App\Core\Engines\Forecast\ForecastEngine;
use App\Core\Support\CompanyContext;
use App\Models\AssistantEvent;
use App\Models\Company;
use App\Models\User;
use App\Modules\Assistant\Watch\Watch;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ কোপাইলট (মালিকের "ok", পূর্ণ পরিকল্পনা 9a6nHmbFk5NF7YB5NbzCC2; ১১ নভেম্বর ২০২৬)।
 *
 * দাবি: খোঁজা টাইপের প্রতিটা অক্ষরে নয়, একবার লেখা হয়; পাতা খোলা লেখা হয়, পেছনের ডাক নয়; খাতা কেবল মালিক দেখেন আর তাতে তিন খাতা
 * (কোপাইলট, পরিবর্তন, লগইন) এক সময়-রেখায়; নজর চাবি মানে আর "এটা ঠিক আছে" কারণ ছাড়া চলে না; প্রশ্নের উত্তর নিজের শব্দ-তালিকায়
 * চলে, না বুঝলে আন্দাজ করে না, আর মডেল চাবির বাইরের দরজা বললেও দরজা খোলে না; পূর্বাভাস সপ্তাহের দিনের ধরন মেনে হাতে গোনা অঙ্কে মেলে।
 */
final class CopilotSeesOnlyWhatItsPersonMaySeeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        Cache::flush();
    }

    /** ⭐ "র", "রহ", "রহিম" — খাতায় একবার, শেষ লেখায়; ২০ সেকেন্ড পরে নতুন খোঁজা নতুন সারি */
    public function test_a_search_is_logged_once_with_the_last_text(): void
    {
        foreach (['রহ', 'রহি', 'রহিম'] as $t) {
            $this->getJson('/search?q='.urlencode($t))->assertOk();
        }

        $rows = AssistantEvent::query()->where('kind', AssistantEvent::SEARCH)->get();
        $this->assertCount(1, $rows, '⛔ প্রতিটা অক্ষরে আলাদা সারি।');
        $this->assertSame('রহিম', $rows->first()->text);
        $this->assertSame((int) $this->owner->id, (int) $rows->first()->user_id);
        $this->assertSame('web', $rows->first()->channel);
        $this->assertNotNull($rows->first()->ip_address, '⛔ কোথা থেকে খুঁজল, লেখা নেই।');

        Carbon::setTestNow(now()->addSeconds(AssistantEngine::SEARCH_MERGE_SECONDS + 1));
        $this->getJson('/search?q='.urlencode('রহিম'))->assertOk();
        Carbon::setTestNow();
        $this->assertSame(2, AssistantEvent::query()->where('kind', AssistantEvent::SEARCH)->count());
    }

    /** ⭐ পাতা খোলা লেখা হয়, একই পাতা এক মিনিটে আবার খুললে একই সারি; খোঁজার JSON ডাক পাতা নয় */
    public function test_page_opens_are_logged_and_background_calls_are_not(): void
    {
        $this->get(route('customer.index'))->assertOk();
        $this->get(route('customer.index'))->assertOk();
        $this->getJson('/search?q=xyz')->assertOk();

        $visits = AssistantEvent::query()->where('kind', AssistantEvent::VISIT)->get();
        $this->assertCount(1, $visits, '⛔ একই পাতা দুইবার খোলায় দুই সারি, বা পেছনের ডাকও পাতা হিসেবে লেখা।');
        $this->assertSame('customer.index', $visits->first()->text);
        $this->assertStringStartsWith('/', (string) $visits->first()->page);
    }

    /** ⛔ খাতা কেবল মালিকের চাবিতে; আর তাতে খোঁজা, পরিবর্তন আর লগইন এক সময়-রেখায় */
    public function test_the_log_is_the_owners_and_joins_the_three_logs(): void
    {
        $this->getJson('/search?q='.urlencode('নিউ স্টার'))->assertOk();

        $html = (string) $this->get(route('assistant.audit'))->assertOk()->getContent();
        $this->assertStringContainsString('data-audit-row="search"', $html, '⛔ খোঁজা খাতায় নেই।');
        $this->assertStringContainsString('data-audit-row="change"', $html, '⛔ পরিবর্তনের খাতা যোগ হয়নি (ডেমোতে বানানো রেকর্ড আছে)।');
        $this->assertStringContainsString('ID '.$this->owner->id, $html, '⛔ কার আইডি থেকে, লেখা নেই।');

        $clerk = $this->person(['assistant.use']);
        $this->actingAs($clerk)->get(route('assistant.audit'))->assertForbidden();
    }

    /** ⭐ নজর চাবি মানে — হিসাবের চাবি ছাড়া হিসাবের নজর নেই; "এটা ঠিক আছে" কারণ ছাড়া চলে না, কারণসহ চুপ করায় */
    public function test_the_watch_keeps_to_the_keys_and_quietening_needs_a_reason(): void
    {
        $clerk = $this->person(['assistant.use', 'sales.invoice.view']);
        $mine = app(Watch::class)->for($clerk);
        $this->assertSame([], array_values(array_filter($mine, fn (array $f): bool => $f['module'] === 'accounts')), '⛔ হিসাবের চাবি ছাড়াই হিসাবের নজর।');

        $owners = app(Watch::class)->for($this->owner);
        $this->assertNotEmpty($owners, 'প্রস্তুতিটাই ভুল — ডেমোতে মালিকের কোনো নজর নেই।');
        $first = $owners[0];

        $this->post(route('assistant.dismiss'), ['finding' => $first['id'], 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('assistant.dismiss'), ['finding' => $first['id'], 'reason' => 'জানা আছে, কাল ঠিক হবে'])->assertRedirect(route('assistant.today'));

        $this->assertNotContains($first['id'], array_column(app(Watch::class)->for($this->owner), 'id'), '⛔ চুপ করানো নজর আবার এল।');
        $this->assertSame('জানা আছে, কাল ঠিক হবে', AssistantEvent::query()->where('kind', AssistantEvent::DISMISS)->value('reason'));
    }

    /** ⭐ মডেল ছাড়া — নিজের শব্দ-তালিকায় বাংলিশও; না বুঝলে আন্দাজ নয় */
    public function test_without_a_model_the_word_list_answers_and_does_not_guess(): void
    {
        $answer = app(AssistantEngine::class)->ask($this->owner, 'aj koto bikri holo');
        $this->assertSame('answered', $answer['state']);
        $this->assertSame('sales.summary', $answer['tool']);
        $this->assertSame('words', $answer['model']);

        $this->assertSame('not_understood', app(AssistantEngine::class)->ask($this->owner, 'আকাশ কেন নীল')['state']);
        $this->assertSame(1, AssistantEvent::query()->where('kind', AssistantEvent::QUESTION)->where('outcome', 'not_understood')->count());
    }

    /** ⛔ মডেল চাবির বাইরের দরজা বললেও দরজা খোলে না — "আটকানো", খাতায় */
    public function test_the_key_is_checked_again_even_when_the_model_names_a_tool(): void
    {
        app(\App\Core\Services\SettingsService::class)->set('assistant.model_url', 'https://ai.office.test');
        Http::fake(['ai.office.test/*' => Http::response(['tool' => 'sales.summary', 'args' => ['period' => 'today'], 'model' => 'qwen-test'])]);
        // ⓘ মজুদ দেখার চাবি আছে (একটা দরজা), বিক্রির নেই — মডেল তবু বিক্রির দরজা বলে
        $clerk = $this->person(['assistant.use', 'inventory.stock.view']);

        $answer = app(AssistantEngine::class)->ask($clerk, 'সবার বেতন কত, আর আজ বিক্রি কত');
        $this->assertSame('blocked', $answer['state'], '⛔ চাবি ছাড়াই মডেলের কথায় বিক্রির দরজা খুলল।');
        $this->assertNull($answer['result']);
        $this->assertSame(1, AssistantEvent::query()->where('kind', AssistantEvent::BLOCKED)->where('user_id', $clerk->id)->count());

        Http::assertSent(fn ($request): bool => ! collect($request['tools'])->contains('name', 'sales.summary'));
    }

    /** ⭐ মডেলের কাছে লম্বা অঙ্ক (মোবাইল, NID) যায় না */
    public function test_long_numbers_are_masked_before_the_model(): void
    {
        $this->assertSame('রহিমের নম্বর •••• দেখাও', ModelClient::mask('রহিমের নম্বর 01712345678 দেখাও'));
    }

    /** ⭐ পূর্বাভাস — সপ্তাহের দিনের ধরন: শুক্রবার ২, বাকি দিন ১০ হলে আগামী ৭ দিন = ৬×১০ + ২ */
    public function test_the_forecast_follows_the_weekday_pattern(): void
    {
        $history = [];
        for ($d = Carbon::parse('2026-08-17'); $d->lte(Carbon::parse('2026-11-08')); $d->addDay()) {
            $history[$d->toDateString()] = $d->isFriday() ? 2.0 : 10.0;
        }

        $fit = ForecastEngine::fit($history);
        $week = 0.0;
        for ($i = 0; $i < 7; $i++) {
            $week += $fit['base'] * $fit['weekday'][$i];
        }

        $this->assertEqualsWithDelta(62.0, $week, 0.01, '⛔ সপ্তাহের দিনের ধরন ধরা হয়নি।');
        $this->assertEqualsWithDelta(0.0, $fit['sd'], 0.0001, '⛔ নিখুঁত ধরনে ছড়ানো শূন্য নয়।');
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $u = User::factory()->create(['current_company_id' => $this->company->id]);
        $u->companies()->attach($this->company->id);
        $u->givePermissionTo($keys);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }
}

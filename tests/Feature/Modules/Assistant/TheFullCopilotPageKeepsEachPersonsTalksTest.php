<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Assistant;

use App\Core\Support\CompanyContext;
use App\Models\AssistantConversation;
use App\Models\Company;
use App\Models\User;
use App\Modules\Assistant\Support\CopilotAnswer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ The full-screen Copilot page — owner's web sample copilot-web-sample (21 Nov 2026).
 *
 * Claims: the page draws the sample in the three ABOS looks (title row with "নতুন কথা" and "ইতিহাস", the live Ai mark,
 * the greeting with the first name, the ask box, chips, key hints — and NO "ভুল করতে পারে" line, no description line);
 * a talk is kept per person AND per company; every answer cites its sources; and the page never shows what the person's
 * keys do not open — not on asking, and not later when a key is taken away (CopilotSeesOnlyWhatItsPersonMaySee).
 */
final class TheFullCopilotPageKeepsEachPersonsTalksTest extends TestCase
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
        app()->setLocale('bn');
        Cache::flush();
    }

    /** ⭐ ক — the start screen of the sample, in ABOS Smart, Navy and Blue */
    public function test_the_page_draws_the_sample_in_the_abos_looks(): void
    {
        foreach (['rose', 'navy', 'blue'] as $look) {
            $this->owner->forceFill(['ui' => $look])->save();
            $html = (string) $this->actingAs($this->owner->fresh())->get(route('assistant.chat'))->assertOk()->getContent();
            $page = $this->pageOf($html);

            $this->assertStringContainsString('data-ai-mark', $page, "⛔ {$look}: no live Ai mark on the page.");
            $this->assertStringContainsString('<h1', $page);
            $this->assertStringContainsString(__('assistant::chat.new'), $page, "⛔ {$look}: no \"নতুন কথা\" in the title row.");
            $this->assertStringContainsString(__('assistant::chat.history'), $page, "⛔ {$look}: no \"ইতিহাস\" in the title row.");
            $this->assertStringContainsString('data-cpp-hello', $page);
            $first = strtok(trim((string) $this->owner->name), ' ');
            $this->assertStringContainsString(e((string) $first), $page, "⛔ {$look}: the greeting does not name the person.");
            $this->assertStringContainsString(__('assistant::chat.hello_ask'), $page);
            $this->assertStringContainsString('data-cpp-ask', $page);
            $this->assertStringContainsString('data-cpp-mic', $page, "⛔ {$look}: no mic in the ask box.");
            $this->assertStringContainsString('data-cpp-chips', $page);
            $this->assertStringContainsString('data-cpp-keys', $page);
            $this->assertStringContainsString('<kbd>Esc</kbd>', $page);
            // ⛔ owner removed the disclaimer and every description line / notice strip
            $this->assertStringNotContainsString('ভুল করতে পারে', $html, "⛔ {$look}: the disclaimer line came back.");
            $this->assertStringNotContainsString('data-page-subtitle', $page, "⛔ {$look}: a description line under the title.");
            $this->assertDoesNotMatchRegularExpression('/[০-৯]/u', strip_tags($page), "⛔ {$look}: a Bengali digit on the page.");
        }
    }

    /** ⭐ six chips at most, and only questions the person's own keys can answer */
    public function test_the_chips_are_only_the_persons_own_questions(): void
    {
        $owner = app(CopilotAnswer::class)->chips($this->owner);
        $this->assertNotEmpty($owner);
        $this->assertLessThanOrEqual(CopilotAnswer::CHIPS, count($owner));

        $storeman = $this->person(['assistant.use', 'inventory.stock.view']);
        $texts = array_column(app(CopilotAnswer::class)->chips($storeman), 'text');
        $this->assertContains(__('assistant::ask.example.stock_on_hand'), $texts);
        $this->assertNotContains(__('assistant::ask.example.sales_summary'), $texts, '⛔ a sales chip for someone without the sales key.');
        $this->assertNotContains(__('assistant::ask.example.credit_overdue'), $texts, '⛔ a dues chip for someone without the credit key.');
    }

    /** ⭐ খ — an answer cites its sources; the talk is kept and the history lists it under আজ */
    public function test_an_answer_cites_its_sources_and_the_talk_is_kept(): void
    {
        $r = $this->postJson(route('assistant.chat.ask'), ['q' => 'aj koto bikri holo'])->assertOk();
        $r->assertJsonPath('answer.state', 'answered');
        $r->assertJsonPath('answer.tool', 'sales.summary');
        $sources = (array) $r->json('answer.sources');
        $this->assertNotEmpty($sources, '⛔ an answer without its source.');
        $this->assertStringStartsWith('উৎস: ', (string) $sources[0]['label']);
        $this->assertStringContainsString(Carbon::today()->format('d-m-Y'), (string) $sources[0]['label'], '⛔ the source does not say when it was read.');
        $this->assertNotSame('', (string) $sources[0]['href'], '⛔ the source chip opens nothing.');
        $this->assertNotEmpty($r->json('answer.rows'));

        $id = (string) $r->json('conversation.id');
        $talk = AssistantConversation::query()->wherePublicId($id)->firstOrFail();
        $this->assertSame([(int) $this->owner->id, (int) $this->company->id], [(int) $talk->user_id, (int) $talk->company_id]);
        $this->assertSame('aj koto bikri holo', $talk->title);
        $this->assertCount(2, $talk->messages);

        // ⓘ a second question goes into the same talk; "আবার লিখুন" replaces, it does not add
        $this->postJson(route('assistant.chat.ask'), ['q' => 'cini koto ache', 'conversation' => $id])->assertOk();
        $this->postJson(route('assistant.chat.ask'), ['q' => 'cini koto ache', 'conversation' => $id, 'regenerate' => true])->assertOk();
        $this->assertCount(4, $talk->fresh()->messages, '⛔ "আবার লিখুন" added the answer twice.');

        $state = $this->stateOf((string) $this->get(route('assistant.chat.show', ['conversation' => $id]))->assertOk()->getContent());
        $this->assertSame($id, $state['talk']['id']);
        $this->assertSame('today', $state['history'][0]['key']);
        $this->assertTrue($state['history'][0]['items'][0]['on']);
        $this->assertNotEmpty($state['talk']['messages'][1]['sources'], '⛔ the kept answer lost its sources.');
    }

    /** ⛔ a talk is one person's — another person in the same company gets a 404, never the talk */
    public function test_a_talk_belongs_to_one_person(): void
    {
        $id = (string) $this->postJson(route('assistant.chat.ask'), ['q' => 'aj koto bikri holo'])->assertOk()->json('conversation.id');
        $other = $this->person(['assistant.use', 'sales.invoice.view']);

        $this->actingAs($other)->get(route('assistant.chat.show', ['conversation' => $id]))->assertNotFound();
        $this->actingAs($other)->postJson(route('assistant.chat.ask'), ['q' => 'aj koto bikri holo', 'conversation' => $id])->assertNotFound();

        $state = $this->stateOf((string) $this->actingAs($other)->get(route('assistant.chat'))->assertOk()->getContent());
        $this->assertSame([], $state['history'], '⛔ another person\'s talk in my history.');
        $this->assertCount(2, AssistantConversation::query()->wherePublicId($id)->firstOrFail()->messages, '⛔ someone else wrote into my talk.');
    }

    /** ⛔ a talk belongs to one company — the same person in another company does not see it */
    public function test_a_talk_belongs_to_one_company(): void
    {
        $id = (string) $this->postJson(route('assistant.chat.ask'), ['q' => 'aj koto bikri holo'])->assertOk()->json('conversation.id');

        $second = Company::query()->where('id', '!=', $this->company->id)->orderBy('id')->firstOrFail();
        $this->owner->companies()->syncWithoutDetaching([$second->id]);
        $this->owner->forceFill(['current_company_id' => $second->id, 'current_branch_id' => $second->defaultBranch()?->id])->save();
        CompanyContext::set($second->id, $second->defaultBranch()?->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->owner->fresh())->get(route('assistant.chat.show', ['conversation' => $id]))->assertNotFound();
        $state = $this->stateOf((string) $this->actingAs($this->owner->fresh())->get(route('assistant.chat'))->assertOk()->getContent());
        $this->assertSame([], $state['history'], '⛔ a talk from another company in the history.');
    }

    /** ⛔ nothing beyond the person's keys — even when the office model names a door the person has no key for */
    public function test_no_answer_reaches_beyond_the_persons_keys(): void
    {
        app(\App\Core\Services\SettingsService::class)->set('assistant.model_url', 'https://ai.office.test');
        Http::fake(['ai.office.test/*' => Http::response(['tool' => 'sales.summary', 'args' => ['period' => 'today'], 'model' => 'qwen-test'])]);
        $storeman = $this->person(['assistant.use', 'inventory.stock.view']);

        $r = $this->actingAs($storeman)->postJson(route('assistant.chat.ask'), ['q' => 'আজ কত বিক্রি হলো'])->assertOk();
        $r->assertJsonPath('answer.state', 'blocked');
        $this->assertSame([], $r->json('answer.rows'), '⛔ sales rows for someone without the sales key.');
        $this->assertSame([], $r->json('answer.sources'));
        $this->assertNull($r->json('answer.chart'));

        $talk = AssistantConversation::query()->wherePublicId((string) $r->json('conversation.id'))->firstOrFail();
        $this->assertSame([], $talk->messages[1]['rows'], '⛔ the kept talk holds rows the person may not see.');

        // ⓘ without the Copilot key there is no page and no door at all
        $clerk = $this->person(['sales.invoice.view']);
        $this->actingAs($clerk)->get(route('assistant.chat'))->assertForbidden();
        $this->actingAs($clerk)->postJson(route('assistant.chat.ask'), ['q' => 'aj koto bikri holo'])->assertForbidden();
    }

    /** ⛔ a key taken away later hides the old answer too — the kept talk never widens what the person may see today */
    public function test_a_key_taken_away_hides_the_old_answer(): void
    {
        $seller = $this->person(['assistant.use', 'sales.invoice.view']);
        $r = $this->actingAs($seller)->postJson(route('assistant.chat.ask'), ['q' => 'aj koto bikri holo'])->assertOk();
        $r->assertJsonPath('answer.state', 'answered');
        $id = (string) $r->json('conversation.id');

        $seller->revokePermissionTo('sales.invoice.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $state = $this->stateOf((string) $this->actingAs($seller->fresh())->get(route('assistant.chat.show', ['conversation' => $id]))->assertOk()->getContent());
        $answer = $state['talk']['messages'][1];
        $this->assertSame('blocked', $answer['state'], '⛔ the old sales answer is still shown after the key was taken away.');
        $this->assertSame([], $answer['rows']);
        $this->assertSame([], $answer['sources']);
    }

    /** ⓘ the small chart: only rows of one kind, bars scaled to the largest; "what · value" summaries get none */
    public function test_the_small_chart_follows_the_rows(): void
    {
        $chart = CopilotAnswer::chart(['customer', 'overdue', 'oldest'], [
            ['customer' => 'A', 'overdue' => '৳3,48,200', 'oldest' => '62 days'],
            ['customer' => 'B', 'overdue' => '৳1,74,100', 'oldest' => '45 days'],
        ]);
        $this->assertNotNull($chart);
        $this->assertSame([100, 50], array_column($chart['bars'], 'pct'));
        $this->assertSame('৳3,48,200', $chart['bars'][0]['value']);

        $this->assertNull(CopilotAnswer::chart(['what', 'value'], [['what' => 'a', 'value' => '1'], ['what' => 'b', 'value' => '2']]));
        $this->assertNull(CopilotAnswer::chart(['customer', 'overdue'], [['customer' => 'A', 'overdue' => '1']]), 'one row is no chart');
        $this->assertSame(348200.0, CopilotAnswer::number('৳3,48,200'));
        $this->assertSame(62.0, CopilotAnswer::number('৬২ দিন'));
        $this->assertNull(CopilotAnswer::number('—'));
    }

    /** ⓘ the greeting follows the hour */
    public function test_the_greeting_follows_the_time_of_day(): void
    {
        $a = app(CopilotAnswer::class);
        $this->owner->name = 'Shuvo Alam';
        $this->assertSame(__('assistant::chat.hello.morning', ['name' => 'Shuvo']), $a->greeting($this->owner, Carbon::parse('2026-11-21 09:00'))['hello']);
        $this->assertSame(__('assistant::chat.hello.noon', ['name' => 'Shuvo']), $a->greeting($this->owner, Carbon::parse('2026-11-21 13:30'))['hello']);
        $this->assertSame(__('assistant::chat.hello.night', ['name' => 'Shuvo']), $a->greeting($this->owner, Carbon::parse('2026-11-21 23:10'))['hello']);
    }

    /** ⭐ গ — in the ABOS looks the quick ask knows the full page (Ctrl+J again) and drops the notice line */
    public function test_the_quick_ask_leads_to_the_full_page_in_the_abos_looks(): void
    {
        $this->owner->forceFill(['ui' => 'rose'])->save();
        $html = (string) $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertOk()->getContent();
        $start = (int) strpos($html, 'data-copilot-quick');
        $quick = substr($html, $start, (int) strpos($html, 'data-shell-actions', $start) - $start);

        $this->assertStringContainsString("chatUrl: '".route('assistant.chat')."'", $quick, '⛔ Ctrl+J again does not lead to the full page.');
        $this->assertStringContainsString(__('assistant::quick.hint_page'), $quick);
        $this->assertStringContainsString('data-copilot-lis', $quick, '⛔ no "শুনছি… বলুন" over the wave.');
        $this->assertStringNotContainsString(__('assistant::quick.safe'), $quick, '⛔ the notice line is still in the quick ask.');
    }

    /** ⓘ the page part of the HTML — from the page's own root to the end of the main column */
    private function pageOf(string $html): string
    {
        $start = strpos($html, 'data-copilot-page');
        $this->assertNotFalse($start, '⛔ the Copilot page is not on the screen.');

        $end = strpos($html, 'data-cpp-keys', (int) $start);
        $this->assertNotFalse($end, '⛔ the key hints are not on the page.');

        return substr($html, (int) $start, (int) $end - (int) $start + 600);
    }

    /** @return array<string, mixed> the page's state, as the browser reads it */
    private function stateOf(string $html): array
    {
        $this->assertSame(1, preg_match('/<script type="application\/json" data-cpp-state>(.*?)<\/script>/s', $html, $m), '⛔ no page state.');

        return (array) json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
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

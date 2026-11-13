<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Assistant;

use App\Core\Engines\Assistant\AssistantEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Assistant\StockOnHandTool;
use App\Modules\Inventory\Assistant\StockSummaryTool;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ⭐ মালিক, ১৩ নভেম্বর ২০২৬: "কোপাইলটে ফুল স্টক, আজকের স্টক, সব স্টক দেখাও, আজকে কী স্টক আছে — এগুলো কিছুই বোঝে না … শুধু বলে
 *   জানি না, বুঝি নাই, এই নামে কিছু নাই"।
 *
 * দাবি: শব্দের তালিকায় (মডেল ছাড়া) এই প্রশ্নগুলো পুরো মজুদে যায়; মডেল এক-পণ্যের দরজায় "সব" পাঠালেও পুরো মজুদ আসে, "পাইনি" নয়;
 *   উত্তরে মজুদে থাকা পণ্যগুলো বেশি পরিমাণ আগে; একটা আসল পণ্যের নাম দিলে আগের মতো সেই পণ্যটাই।
 */
final class CopilotShowsTheWholeStockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app()->setLocale('bn');
    }

    public function test_the_owners_words_reach_the_whole_stock_without_the_model(): void
    {
        foreach (['সব স্টক দেখাও', 'আজকে কী স্টক আছে', 'ফুল স্টক', 'full stock'] as $q) {
            $answer = app(AssistantEngine::class)->ask($this->owner, $q);
            $this->assertSame('answered', $answer['state'], "⛔ \"{$q}\" — উত্তর নেই ({$answer['state']}), মালিক যা বলেছিলেন ঠিক তাই।");
            $this->assertSame(StockSummaryTool::name(), $answer['tool'], "⛔ \"{$q}\" পুরো মজুদে যায়নি — গেল {$answer['tool']}।");
        }
    }

    public function test_all_sent_as_a_product_name_shows_the_whole_stock_not_not_found(): void
    {
        $tool = app(StockOnHandTool::class);

        foreach (['সব', 'আজকের স্টক', 'all', 'ফুল'] as $word) {
            $result = $tool->run($this->owner, ['product' => $word]);
            $this->assertStringNotContainsString('পাইনি', $result['summary'], "⛔ \"{$word}\"-কে পণ্যের নাম ধরে \"পাইনি\" বলল।");
            $this->assertNotSame([], $result['rows'], "⛔ \"{$word}\" — পুরো মজুদের সারি নেই।");
        }

        // ⓘ আসল পণ্যের নাম দিলে আগের মতো সেই পণ্য — পুরো মজুদ নয়
        $one = $tool->run($this->owner, ['product' => 'কসমস বিস্কুট']);
        $this->assertStringContainsString('কসমস বিস্কুট', $one['summary'], '⛔ এক পণ্যের প্রশ্নেও পুরো মজুদ এল।');
    }

    public function test_the_model_sending_all_to_the_one_product_door_still_answers_the_whole_stock(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('assistant.model_url', 'https://ai.office.test');
        $settings->set('assistant.model_kind', 'chat');
        Http::fake(['ai.office.test/v1/chat/completions' => Http::response(['model' => 'qwen3:4b-instruct', 'choices' => [['message' => [
            'role' => 'assistant', 'content' => '',
            'tool_calls' => [['type' => 'function', 'function' => ['name' => 'stock__on_hand', 'arguments' => '{"product": "সব"}']]],
        ]]]])]);

        $answer = app(AssistantEngine::class)->ask($this->owner, 'আজকে কী স্টক আছে');

        $this->assertSame('answered', $answer['state']);
        $this->assertStringNotContainsString('পাইনি', (string) $answer['result']['summary'], '⛔ মডেলের "সব"-কে পণ্য ধরে "পাইনি"।');
        $qty = array_map(fn (array $r): float => (float) $r['qty'], $answer['result']['rows']);
        $sorted = $qty;
        rsort($sorted);
        $this->assertSame($sorted, $qty, '⛔ বেশি পরিমাণ আগে নয়।');
    }
}

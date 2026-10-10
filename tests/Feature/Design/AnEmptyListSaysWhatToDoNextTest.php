<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ⭐ খালি তালিকা পরের কাজ বলে — পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬: *"খালি অবস্থা: 'এখনো নেই' + পরের কাজের
 * বোতাম আর দুই লাইনের সাহায্য"*।
 *
 * দাবি:
 *  - খোঁজায় কিছু না মিললে প্রতিটা তালিকা (x-ui.table) নিজেই বলে "কিছু মেলেনি" আর "ছাঁকনি মুছে সব দেখুন" — বোতামটা
 *    খোঁজা-ছাড়া একই তালিকায় নিয়ে যায়।
 *  - কেবল দেখার পছন্দ (পাতা, সাজানো, ঘনত্ব) থাকলে সেটা "ছাঁকনি" নয় — ঐ কথা আসে না।
 *  - x-ui.empty-state নিজে সাহায্যের লাইন আর পরের কাজের বোতাম আঁকে।
 */
final class AnEmptyListSaysWhatToDoNextTest extends TestCase
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

    public function test_a_search_that_finds_nothing_offers_to_clear_it(): void
    {
        $html = (string) $this->get(route('customer.index', ['q' => 'জজজ-এমন-কেউ-নেই']))->assertOk()->getContent();

        $this->assertStringContainsString(e(__('core.empty.filtered_hint')), $html, '⛔ খালি তালিকা বলে না কেন খালি।');
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('customer.index'), '/').'"[^>]*data-empty-action/', $html,
            '⛔ "ছাঁকনি মুছে সব দেখুন" খোঁজা-ছাড়া তালিকায় নেয় না।');
    }

    public function test_view_choices_alone_are_not_a_filter(): void
    {
        $table = '<x-ui.table :rows="[]" :columns="[[\'key\' => \'name\', \'label\' => \'নাম\']]" />';
        $render = function (array $query) use ($table): string {
            $this->app->instance('request', Request::create('/customers', 'GET', $query));

            return Blade::render($table);
        };

        $this->assertStringContainsString('data-empty-action', $render(['status' => 'inactive']), '⛔ ছাঁকনিতে খালি তালিকা "ছাঁকনি মুছুন" বলে না।');
        $this->assertStringNotContainsString('data-empty-action', $render([]), '⛔ কোনো ছাঁকনি ছাড়াই "ছাঁকনি মুছুন"।');
        $this->assertStringNotContainsString('data-empty-action', $render(['sort' => 'name', 'compact' => '1', 'page' => '2', 'tab' => 'all']),
            '⛔ সাজানো/ঘনত্ব/পাতা/ট্যাবকে ছাঁকনি ধরা হল।');
        $this->assertStringNotContainsString('data-empty-action', $render(['q' => '']), '⛔ খালি খোঁজার ঘরকে ছাঁকনি ধরা হল।');
    }

    public function test_the_empty_state_draws_its_help_and_next_step(): void
    {
        $html = Blade::render('<x-ui.empty-state message="এখনো কোনো লিড নেই" hint="প্রথম লিডটা যোগ করুন।" :action="[\'label\' => \'নতুন লিড\', \'url\' => \'/leads/new\']" />');

        $this->assertStringContainsString('data-empty-hint', $html);
        $this->assertStringContainsString('প্রথম লিডটা যোগ করুন।', $html);
        $this->assertMatchesRegularExpression('/<a href="\/leads\/new"[^>]*data-empty-action[^>]*>\s*নতুন লিড/', $html, '⛔ পরের কাজের বোতাম নেই।');

        $bare = Blade::render('<x-ui.empty-state message="এখনো কিছু নেই" />');
        $this->assertStringNotContainsString('data-empty-hint', $bare);
        $this->assertStringNotContainsString('data-empty-action', $bare);
    }
}

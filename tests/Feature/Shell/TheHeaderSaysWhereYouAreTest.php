<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ⭐ পাতার মাথার এক ছাঁচ — পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬: *"উপরে 'কোথায় আছি' (যেমন বিক্রয় › অর্ডার),
 * তারপর শিরোনাম, এক লাইনের ব্যাখ্যা, ডান কোণে একটাই প্রধান বোতাম; বাকি কাজ '⋯'-এ"*।
 *
 * দাবি:
 *  - ABOS (navy) রূপে প্রতিটা মেনু-পাতার মাথার উপরে "মডিউল › পাতা" — মেনুর নাম থেকে, শিরোনামের আগে; শেষেরটা লিংক নয়।
 *  - অন্য রূপে এটা আঁকা হয় না (ওদের নিজের crumbbar, হিমায়িত)।
 *  - page-header-এর `more` "⋯" মেনুতে বসে, প্রধান বোতামের পাশে; না দিলে "⋯" নেই।
 */
final class TheHeaderSaysWhereYouAreTest extends TestCase
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
    }

    public function test_the_abos_look_says_module_and_page_above_the_title(): void
    {
        $this->owner->forceFill(['ui' => 'navy'])->save();

        $html = $this->actingAs($this->owner)->get(route('accounts.period.index'))->assertOk()->getContent();

        $start = strpos($html, 'data-page-path');
        $this->assertNotFalse($start, '⛔ ABOS রূপে পাতার মাথায় "কোথায় আছি" নেই।');
        $path = substr($html, $start, strpos($html, '</nav>', $start) - $start);

        $this->assertStringContainsString(e(__('accounts::menu.periods')), $path, '⛔ পথে পাতার নাম নেই।');
        $this->assertMatchesRegularExpression('/<a [^>]*href="[^"]+"[^>]*>[^<]+<\/a>/', $path, '⛔ মডিউলের নামটা লিংক নয় — উপরে ফেরা যায় না।');
        $this->assertStringContainsString('aria-current="page"', $path, '⛔ শেষেরটা "এই পাতা" বলে চিহ্নিত নয়।');
        $this->assertLessThan(strpos($html, '<h1', $start), $start, '⛔ পথটা শিরোনামের পরে বসেছে।');
    }

    public function test_another_look_keeps_its_own_crumbbar_and_gets_nothing_new(): void
    {
        $other = collect(\App\Core\Support\Ui::keys())->first(fn (string $k) => $k !== 'navy');
        $this->owner->forceFill(['ui' => $other])->save();

        $html = $this->actingAs($this->owner)->get(route('accounts.period.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-page-path', $html, "⛔ {$other} রূপে নতুন পথ আঁকা হল — হিমায়িত রূপ বদলাল।");
    }

    public function test_the_rest_of_the_actions_sit_behind_the_dots(): void
    {
        $this->actingAs($this->owner);

        $with = Blade::render(
            '<x-ui.page-header title="ব্র্যান্ড" subtitle="এক লাইনের ব্যাখ্যা" :more="$more"><x-slot:actions><a href="#new">নতুন</a></x-slot:actions></x-ui.page-header>',
            ['more' => [['label' => 'রপ্তানি', 'url' => '/x/export'], ['label' => 'মুছুন', 'url' => '/x/1', 'method' => 'delete', 'tone' => 'danger']]],
        );

        $actions = substr($with, (int) strpos($with, 'data-page-actions'));
        $this->assertStringContainsString('নতুন', $actions, '⛔ প্রধান বোতাম ডানের ঘরে নেই।');
        $this->assertStringContainsString('x-data="rowActions"', $actions, '⛔ "⋯" মেনু নেই।');
        $this->assertStringContainsString('রপ্তানি', $actions);
        $this->assertStringContainsStringIgnoringCase('name="_method" value="DELETE"', $actions, '⛔ "⋯"-এর মোছা নিজের ফর্মে যায় না।');
        $this->assertStringContainsString('এক লাইনের ব্যাখ্যা', $with);

        $without = Blade::render('<x-ui.page-header title="ব্র্যান্ড"><x-slot:actions><a href="#new">নতুন</a></x-slot:actions></x-ui.page-header>');
        $this->assertStringNotContainsString('rowActions', $without, '⛔ কাজ না দিলেও "⋯" আঁকা হল।');
    }
}

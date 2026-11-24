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

    /** ⭐ মালিক, ২৪ নভেম্বর ২০২৬: পথ · তারিখ · কোম্পানি · শাখার লাইন কোনো পাতাতেই নয় — তিন ABOS রূপে */
    public function test_no_abos_look_draws_the_path_or_the_date_company_line(): void
    {
        foreach (['rose', 'navy', 'blue'] as $look) {
            $this->owner->forceFill(['ui' => $look])->save();

            foreach (['accounts.period.index', 'customer.index', 'system_admin.control-panel'] as $name) {
                $html = (string) $this->actingAs($this->owner->fresh())->get(route($name))->assertOk()->getContent();
                $this->assertStringNotContainsString('data-page-path', $html, "⛔ {$look}: {$name}-এ পথের লাইন ফিরে এসেছে।");
                $this->assertStringNotContainsString('data-page-context', $html, "⛔ {$look}: {$name}-এ তারিখ · কোম্পানি · শাখার লাইন ফিরে এসেছে।");
            }
        }
    }

    public function test_another_look_keeps_its_own_crumbbar_and_gets_nothing_new(): void
    {
        // ⓘ "অন্য রূপ" মানে যে রূপ নিজের পথের পটি রাখে — ABOS-এর খোলস পরা রূপগুলো (navy, ১৮ নভেম্বরের ABOS Blue) আর নতুন "ABOS" বাদ
        $other = collect(\App\Core\Support\Ui::keys())->first(fn (string $k) => \App\Core\Support\Ui::pathBar($k) === 'crumbs');
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

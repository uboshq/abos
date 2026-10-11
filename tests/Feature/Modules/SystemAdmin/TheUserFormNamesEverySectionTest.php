<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ব্যবহারকারীর ফর্মে প্রতিটা অংশের নাম আছে, আর বাতিল · সংরক্ষণ নিচের স্থির পট্টিতে।
 *
 * ── ⭐ সিস্টেম পর্দার নকশা, ১০ অক্টোবর ২০২৬ (ধাপ C, ব্যবহারকারীর ফর্ম) ──────────
 * মালিক: *"ebar porda gulo plan onuzayi kaj suro koro"*। ⓘ রোল, কোম্পানি আর আসল ক্ষমতা মিলে ফর্মটা ১০৮০p-তে এক পর্দার
 * চেয়ে লম্বা — সংরক্ষণ নিচে হারাত; আর প্রথম অংশটার কোনো নাম ছিল না।
 */
final class TheUserFormNamesEverySectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($owner->fresh());
    }

    public function test_the_first_section_has_a_name_too(): void
    {
        foreach ([route('system_admin.user.create'), route('system_admin.user.edit', User::query()->where('email', 'owner@abos.test')->value('id'))] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $section = $this->between($html, 'data-form-section="identity"', '</section>');

            $this->assertStringContainsString('পরিচয় ও লগইন', $section, "⛔ {$url}: প্রথম অংশের মাথায় নাম নেই।");
            $this->assertStringContainsString('name="email"', $section, "⛔ {$url}: নামটা ভুল অংশের মাথায়।");
        }
    }

    /** ⭐ বাতিল আর সংরক্ষণ দুইটাই পট্টিতে, বাতিল তালিকায় ফেরে, আর ফর্মে সংরক্ষণ একটাই। */
    public function test_cancel_and_save_sit_on_the_sticky_bar(): void
    {
        $html = (string) $this->get(route('system_admin.user.create'))->assertOk()->getContent();

        $bar = $this->between($html, 'data-form-actions', '</form>');

        $this->assertMatchesRegularExpression('/\bsticky\b/', $bar, '⛔ পট্টিটা স্থির নয়।');
        $this->assertStringContainsString('href="'.route('system_admin.user.index').'"', $bar, '⛔ বাতিল পট্টিতে নেই, বা তালিকায় ফেরে না।');
        $this->assertStringContainsString('type="submit"', $bar, '⛔ সংরক্ষণ পট্টিতে নেই।');

        $form = $this->between($html, 'action="'.route('system_admin.user.store').'"', '</form>');
        $this->assertSame(1, substr_count($form, 'type="submit"'), '⛔ ফর্মে সংরক্ষণের বোতাম একটার বেশি — পুরনোটা রয়ে গেছে।');
    }

    private function between(string $html, string $from, string $to): string
    {
        $at = strpos($html, $from);
        $this->assertNotFalse($at, "⛔ পাতায় «{$from}» নেই।");
        $end = strpos($html, $to, $at);
        $this->assertNotFalse($end);

        return substr($html, $at, $end - $at);
    }
}

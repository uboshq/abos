<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ⭐ শিরোনামের নিচে ব্যাখ্যার লাইন নয় — লেখাটা নামের পাশে ⓘ-এ (মালিক, ১৯ নভেম্বর ২০২৬ রাত: "no description line on any screen;
 *   title+tabs+buttons one row"; ২০ নভেম্বর ২০২৬)।
 *
 * ⓘ যে পাতাগুলো [[ui/page-header]] না ধরে নিজে শিরোনাম আর ম্লান লাইন লিখত, আর foldPageNotes() ([[abos-list-head.js]]) ধরতে পারত না
 *   (লাইনটা টুলবারের পরে, পাতায় বাক্স নেই, বা নামই নেই) — সেগুলো এখন উৎসেই জোড়া দেয়: শিরোনামের ভিতরে `data-page-info` (লেখাটা
 *   title-এ), আর লাইনটায় `data-page-subtitle` — "ABOS"/"Dynamic"-এর CSS লাইনটা লুকায়, বাকি রূপে আগের মতো ([[ui/page-info]])।
 * ⛔ প্রতিটা পাতার জন্য: পাতা খোলে, ⓘ আছে আর তাতে ব্যাখ্যার লেখা, আর ব্যাখ্যার লাইনটা চিহ্ন ছাড়া (`<p class="… ink-muted">লেখা`) নেই।
 */
final class NoDescriptionLineUnderThePageTitleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app()->setLocale('bn');
    }

    /**
     * কাজ (কন্ট্রোলার@মেথড) => ব্যাখ্যার লেখা (null = লেখায় নাম/সংখ্যা বসে, তাই কেবল ⓘ আর চিহ্ন দেখা)
     *
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function pages(): array
    {
        $m = 'App\\Modules\\';

        return [
            'স্টকের বয়স' => [$m.'Inventory\\Http\\Controllers\\StockAnalysisController@age', 'inventory::analysis.age_note'],
            'লাভ বণ্টন' => [$m.'Finance\\Http\\Controllers\\ProfitDistributionController@index', 'finance::message.profit_flow'],
            'কোপাইলট — আজ' => [$m.'Assistant\\Http\\Controllers\\AssistantController@today', 'assistant::page.lede'],
            'কোপাইলট — জিজ্ঞাসা' => [$m.'Assistant\\Http\\Controllers\\AskController@show', null],
            'কোপাইলট — খাতা' => [$m.'Assistant\\Http\\Controllers\\AssistantController@audit', 'assistant::audit.note'],
            'কোপাইলট — তাগাদা' => [$m.'Assistant\\Http\\Controllers\\DraftController@dunning', null],
            'জমা মেলানো' => [$m.'Sales\\Http\\Controllers\\DepositMatchController@index', null],
            'বার্তার পথ' => [$m.'Notification\\Http\\Controllers\\NotificationChannelController@index', 'notification::channel.note'],
            'পাঠানোর স্বাস্থ্য' => [$m.'Notification\\Http\\Controllers\\NotificationDeliveryController@health', 'notification::delivery.health_note'],
            'চুপ থাকার সময়' => [$m.'Notification\\Http\\Controllers\\NotificationQuietController@index', 'notification::quiet.note'],
            'পক্ষের বার্তা' => [$m.'Notification\\Http\\Controllers\\PartyMessageController@messages', 'notification::party.messages_note'],
            'SMS ফোন' => [$m.'Notification\\Http\\Controllers\\SmsPhoneController@index', 'notification::sms.note'],
            // ⓘ page-header-এর `info` — ⓘ-এ ব্যাখ্যা আর পাতার নিজের বাক্সের লেখা একসাথে
            'নতুন ডেবিট নোট' => [$m.'Accounts\\Http\\Controllers\\NoteController@create', null],
            'আমার লগইনগুলো' => [$m.'Governance\\Http\\Controllers\\SessionController@index', 'governance::message.sessions_why'],
            'লয়্যালটি' => [$m.'Promotion\\Http\\Controllers\\PromotionLoyaltyController@index', 'promotion::loyalty_screen.balance_note'],
        ];
    }

    #[DataProvider('pages')]
    public function test_the_description_sits_behind_the_info_mark(string $action, ?string $note): void
    {
        $route = app('router')->getRoutes()->getByAction($action);
        $this->assertNotNull($route, "⛔ {$action}-এর রুট নেই");

        $html = $this->get('/'.ltrim($route->uri(), '/'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<(h1|h2)[^>]*>(?:(?!<\/\1>).)*data-page-info/s', $html, "⛔ {$action}: শিরোনামে ⓘ নেই");
        $this->assertStringContainsString('data-page-subtitle', $html, "⛔ {$action}: ব্যাখ্যার লাইনে চিহ্ন নেই — ABOS-এ লাইনটা দেখা যাবে");

        if ($note !== null) {
            $text = e(__($note));
            $this->assertStringContainsString('title="'.$text.'"', $html, "⛔ {$action}: ⓘ-এ ব্যাখ্যার লেখা নেই");
            $this->assertDoesNotMatchRegularExpression(
                '/<p(?![^>]*data-page-subtitle)[^>]*text-\(--color-ink-muted\)[^>]*>\s*'.preg_quote($text, '/').'/u',
                $html,
                "⛔ {$action}: চিহ্ন ছাড়া ব্যাখ্যার লাইন রয়ে গেছে",
            );
        }
    }

    /** ⓘ ⓘ-এর ছাঁচ নিজে — লেখা না থাকলে কিছুই আঁকে না; থাকলে বাকি রূপে `hidden` (পিক্সেল নেয় না) */
    public function test_the_info_mark_draws_nothing_without_text_and_hides_outside_its_looks(): void
    {
        $this->assertSame('', trim((string) $this->blade('<x-ui.page-info :text="null" />')));

        $mark = (string) $this->blade('<x-ui.page-info text="লেখা" />');
        $this->assertStringContainsString('data-page-info', $mark);
        $this->assertStringContainsString('class="hidden"', $mark);
        $this->assertStringContainsString('title="লেখা"', $mark);
    }
}

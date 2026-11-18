<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Dashboard\CopilotLine;
use App\Core\Dashboard\Widget;
use App\Core\Dashboard\WorkLevel;
use Tests\TestCase;

/**
 * ⭐ হোমের দুই কথা, ঘরের নিজের তথ্য থেকে — মালিক, ১৮ নভেম্বর ২০২৬ (নীল প্যানেলের নমুনা):
 *   · Copilot-এর এক লাইন ([[CopilotLine]]) — খসড়া ভাউচার, আজকের আদায় বনাম গত সপ্তাহের একই বার; পাতায় নয়, খোলার পপআপে বসবে
 *   · আজ যা করতে হবে-র তাড়া ([[WorkLevel]]) — জরুরি · আজই · এ সপ্তাহে, রং অর্থ ধরে, ক্রম ধরে নয়
 * ⓘ ডাটাবেস লাগে না — ঘরগুলো হাতে বানানো, তাই দাবি দ্রুত আর মিউট্যান্ট সস্তা।
 */
final class TheHomeWordsComeFromItsOwnFactsTest extends TestCase
{
    /**
     * ⓘ কথাগুলো নিজে — খসড়া ভাউচারের সংখ্যা, আজকের আদায়ের তুলনা (বেশি / কম), কিছু না থাকলে শান্ত কথা; "আজ" ছাড়া তুলনা নেই।
     */
    public function test_the_copilot_line_says_the_drafts_and_the_collection_against_last_week(): void
    {
        $collected = fn (string $delta) => new Widget(group: 'kpi', label: (string) __('sales::dashboard.collected_today'), value: '2,18,000.00',
            href: '/', permission: 'sales.collection.view', tone: 'money', delta: $delta);
        $drafts = new Widget(group: 'todo', label: (string) __('accounts::dashboard.draft_vouchers'), value: '11', href: '/',
            permission: 'accounts.view', tone: 'warn');
        $day = \Illuminate\Support\Carbon::today()->subWeek()->locale(app()->getLocale())->dayName;

        $line = CopilotLine::from(['kpi' => [$collected('+6.0%')], 'todo' => [$drafts]], 'today');
        $this->assertSame([
            (string) __('home.copilot_collected_up', ['day' => $day, 'pct' => '6']),
            trans_choice('home.copilot_drafts', 11, ['count' => 11]),
        ], $line->sentences, '⛔ Copilot আজকের আদায়ের বাড়া আর ১১টা খসড়া ভাউচার ঠিকমতো বলছে না।');

        $this->assertSame([(string) __('home.copilot_collected_down', ['day' => $day, 'pct' => '12.5'])],
            CopilotLine::from(['kpi' => [$collected('-12.5%')]], 'today')->sentences, '⛔ আদায় কমলে Copilot "কম" বলছে না।');
        $this->assertSame([(string) __('home.copilot_quiet')],
            CopilotLine::from(['kpi' => [$collected('+6.0%')], 'todo' => [new Widget(group: 'todo',
                label: (string) __('accounts::dashboard.draft_vouchers'), value: '0', href: '/', permission: 'accounts.view')]], 'month')->sentences,
            '⛔ "এ মাস" বাছা থাকলেও আজকের তুলনা, বা শূন্য খসড়াও বলা হচ্ছে।');
        $this->assertCount(2, $line->questions, 'Copilot-এর দুটো প্রশ্ন নেই।');
    }

    /** ⓘ আজ যা করতে হবে-র তাড়া ঘরের নিজের কথা থেকে — এক সপ্তাহের পুরনো জরুরি, সতর্কতা আজই, বাকি এ সপ্তাহে ([[WorkLevel]]) */
    public function test_each_piece_of_todays_work_says_how_urgent_it_is_from_its_own_facts(): void
    {
        $widget = fn (string $tone, ?string $hint) => new Widget(group: 'todo', label: 'x', value: '1', href: '/', permission: 'p',
            tone: $tone, hint: $hint);
        $days = fn (int $n) => (string) __('core.dashboard.oldest_is', ['days' => $n]);

        $this->assertSame('urgent', WorkLevel::of($widget('neutral', $days(10))), '⛔ দশ দিনের পুরনো খসড়া জরুরি নয়।');
        $this->assertSame('urgent', WorkLevel::of($widget('warn', $days(WorkLevel::OVERDUE_DAYS))), '⛔ ঠিক এক সপ্তাহের পুরনোটা জরুরি নয়।');
        $this->assertSame('today', WorkLevel::of($widget('warn', $days(WorkLevel::OVERDUE_DAYS - 1))), '⛔ ছয় দিনের সতর্কতা আজই নয়।');
        $this->assertSame('today', WorkLevel::of($widget('warn', null)), '⛔ মডিউলের সতর্কতা আজই নয়।');
        $this->assertSame('week', WorkLevel::of($widget('neutral', 'অন্য কথা')), '⛔ সাধারণ অপেক্ষা এ সপ্তাহে নয়।');
    }
}

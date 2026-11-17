<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Engines\Assistant\ToolRegistry;
use App\Modules\Sales\Http\Controllers\TransportAssignmentController;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * ⛔ লাইভ, ১৭ নভেম্বর ২০২৬ (মালিকের অডিট): পর্দায় কাঁচা চাবি —
 *   · পাতা ৪১৯, কোপাইলটকে প্রশ্ন: উদাহরণের বোতামে "assistant::ask.example.stock_summary"
 *     (১২ নভেম্বর মজুদের সরঞ্জাম এল, তার উদাহরণ-লেখা কেউ লেখেনি);
 *   · পাতা ৬২৭, পরিবহন বরাদ্দ → ভাড়া বাকি: খালি অবস্থায় "sales::transport_board.empty_fare_due"
 *     (চতুর্থ ট্যাব যোগ হলো, তার খালি-লেখা নয়)।
 *
 * ⓘ দুইটাই একই ভুল: তালিকায় নতুন জিনিস বসে, আর তার লেখা চাবি জোড়া লাগিয়ে বানানো হয় — তাই কোনো
 *   খোঁজে ধরা পড়ে না। এখানে তালিকা ধরে প্রতিটার লেখা দুই ভাষাতেই খোঁজা হয়।
 */
final class EveryShownWordHasItsTextTest extends TestCase
{
    public function test_every_copilot_tool_has_its_example_in_both_languages(): void
    {
        $tools = array_keys(app(ToolRegistry::class)->all());
        $this->assertContains('stock.summary', $tools, 'দৃশ্যটাই ঠিক নয় — মজুদের সারাংশের সরঞ্জাম তালিকায় নেই');

        $this->assertSame([], $this->missing(array_map(
            fn (string $tool): string => 'assistant::ask.example.'.str_replace('.', '_', $tool), $tools)),
            '⛔ এই সরঞ্জামগুলোর উদাহরণ-লেখা নেই — কোপাইলটের পাতায় কাঁচা চাবি দেখাবে');
    }

    public function test_every_transport_tab_has_its_empty_text_in_both_languages(): void
    {
        $this->assertContains('fare_due', TransportAssignmentController::TABS, 'দৃশ্যটাই ঠিক নয় — ভাড়া বাকির ট্যাব নেই');

        $this->assertSame([], $this->missing(array_merge(
            array_map(fn (string $tab): string => 'sales::transport_board.empty_'.$tab, TransportAssignmentController::TABS),
            array_map(fn (string $tab): string => 'sales::transport_board.tab_'.$tab, TransportAssignmentController::TABS),
        )), '⛔ এই ট্যাবগুলোর লেখা নেই — খালি অবস্থায় কাঁচা চাবি দেখাবে');
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function missing(array $keys): array
    {
        $out = [];

        foreach (['bn', 'en'] as $locale) {
            foreach ($keys as $key) {
                // ⚠️ `__()` নয় — বাংলা না থাকলে সে চুপচাপ ইংরেজি ফেরত দেয়, আর বাংলার ফাঁক ধরা পড়ে না
                if (! Lang::has($key, $locale, false) || ! is_string(__($key, [], $locale))) {
                    $out[] = $locale.': '.$key;
                }
            }
        }

        return $out;
    }
}

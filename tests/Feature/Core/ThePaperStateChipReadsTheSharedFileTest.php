<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\PaperStates;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ⭐ কাগজের অবস্থার চিপ — অ্যাপের একই ফাইল (`resources/design/paper_states.json`, a6-এর v4 fbff00bfe; মালিকের অনুমোদিত
 * নকশা, ২০ অক্টোবর ২০২৬)। প্রত্যাশা হাতে লেখা নয়, ফাইল থেকেই পড়া — তাই ফাইল বদলালে দাবি নিজেই নতুন লেখা মাপে।
 *
 * দাবি:
 *  - অপেক্ষার ধাপ ইংরেজি অঙ্কে ("2-এর 1"), বাংলা অঙ্ক নেই; ধাপ না জানলে ধাপছাড়া লেখা, "{level}" ফাঁকা থাকে না।
 *  - কেবল-অ্যাপের অবস্থা ("unsent") ওয়েবে কখনো আঁকা হয় না।
 *  - মজুদের কাগজে ফাইলের `_stock` লেখা, টাকার কাগজে সাধারণ লেখা।
 *  - v4-এর নতুন দুই অবস্থা (rejected, approved) নিজের আইকনে আঁকা হয়, অনুমানের ঘড়িতে নয়।
 */
final class ThePaperStateChipReadsTheSharedFileTest extends TestCase
{
    /** @return array<string, mixed> */
    private function file(): array
    {
        return json_decode((string) file_get_contents(resource_path('design/paper_states.json')), true);
    }

    public function test_the_waiting_step_is_in_english_digits_and_never_left_as_a_placeholder(): void
    {
        $chip = PaperStates::chip('waiting', 1, 2);
        $this->assertNotNull($chip);
        $this->assertStringContainsString('2-এর 1', $chip['label'], '⛔ ধাপ "2-এর 1" নয়।');
        $this->assertDoesNotMatchRegularExpression('/[০-৯]/u', $chip['label'].$chip['speech'], '⛔ চিপে বাংলা অঙ্ক।');

        $plain = PaperStates::chip('waiting');
        $this->assertStringNotContainsString('{', $plain['label'].$plain['speech'], '⛔ ধাপ না জেনে "{level}" ফাঁকা রয়ে গেল।');
    }

    public function test_an_app_only_state_never_reaches_the_web(): void
    {
        $appOnly = array_keys(array_filter($this->file()['states'], fn (array $s): bool => ($s['app_only'] ?? false) === true));
        $this->assertContains('unsent', $appOnly, 'প্রস্তুতিটাই ভুল — ফাইলে কেবল-অ্যাপের অবস্থা নেই।');

        foreach ($appOnly as $state) {
            $this->assertNull(PaperStates::chip($state), "⛔ কেবল-অ্যাপের {$state} ওয়েবে চিপ পেল।");
            $this->assertSame('', trim(Blade::render('<x-ui.paper-state :state="$s" />', ['s' => $state])), "⛔ {$state} পাতায় আঁকা হল।");
        }
    }

    public function test_a_stock_paper_says_its_own_words_and_a_money_paper_the_plain_ones(): void
    {
        $posted = $this->file()['states']['posted'];
        $this->assertArrayHasKey('label_bn_stock', $posted, 'প্রস্তুতিটাই ভুল — ফাইলে মজুদের লেখা নেই।');

        $this->assertSame($posted['label_bn_stock'], PaperStates::chip('posted', kind: 'stock')['label'], '⛔ মজুদের কাগজে মজুদের লেখা নেই।');
        $this->assertNotSame($posted['label_bn_stock'], PaperStates::chip('posted')['label'], '⛔ টাকার কাগজেও মজুদের লেখা।');
        $this->assertStringContainsString(e($posted['label_bn_stock']),
            Blade::render('<x-ui.paper-state state="posted" kind="stock" />'), '⛔ চিপ ধরনটা পাতায় পৌঁছায় না।');
    }

    public function test_the_new_states_draw_their_own_icons(): void
    {
        $states = $this->file()['states'];

        foreach (['rejected' => 'cancel', 'approved' => 'verified'] as $state => $icon) {
            $this->assertArrayHasKey($state, $states, "প্রস্তুতিটাই ভুল — ফাইলে {$state} নেই।");
            $chip = PaperStates::chip($state);
            $this->assertSame($icon, $chip['icon'], "⛔ {$state}-এর আইকন ফাইলের {$states[$state]['icon']} নয়।");
            $this->assertSame($states[$state]['label_bn_short_without_level'] ?? $states[$state]['label_bn_without_level'] ?? $states[$state]['label_bn'],
                $chip['label']);
        }
    }
}

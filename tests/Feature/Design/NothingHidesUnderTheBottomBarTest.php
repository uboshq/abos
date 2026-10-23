<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ⭐ নিচের স্থির ঘোষণার বার (web21) কিছু ঢাকে না — fe, ২২ অক্টোবর ২০২৬ (cb/hf-footer-pad)।
 *
 * ── ⛔ কী দেখা গিয়েছিল, ১৩৬৬×৭৬৮-এ ─────────────────────────────────────────────────────────
 *   · প্রতিটা পাতা অকারণে ১,৭০০px লম্বা: রেলের hover-তালিকাগুলো `invisible` (জায়গা নেয়), প্রতিটা ~৬০০px, টাইলের মাথা থেকে নিচে।
 *     ছোট পাতাতেও স্ক্রল, নিচে ফাঁকা, আর স্টিকি সাইডবার পাতার সাথে সরে গিয়ে পর্দার মাঝখানে শেষ হত।
 *   · মডিউল-মেনুর তালিকায় `min-h-0` নেই — flex-কলামে সে পুরো উচ্চতা নিয়ে বাক্সের বাইরে উপচে বারের নিচে যেত।
 *   · সাইডবার পুরো পর্দার উঁচু — মেনুর শেষ সারি ২৮px বারের নিচে চাপা।
 *   · টাকা দেওয়ার পর্দার বোতাম-সারি `fixed bottom-0`, বারের উপরে: বারটা ঢাকত, বোতামের নিচ ৭৫৩ (বার ৭৪০ থেকে), বাঁয়ের মেনুও ঢাকত।
 *
 * ⓘ বিন্যাস মাপা ব্রাউজারের কাজ — phpunit বা happy-dom বিন্যাস করে না। ⭐ তাই এখানে প্রতিটা কারণ উৎসে ধরা থাকে, আর মাপগুলো
 * কমিটে (Chromium, ১৩৬৬×৭৬৮: পাতা ১,২২০/১,৪২৮/১,৩৮৪px; সাইডবার ৫৬→৭৪০; মেনুর শেষ ৭২০; বোতাম ৬৮৯/৬৮৪/৫৯৪; বার ৭৪০ থেকে)।
 */
final class NothingHidesUnderTheBottomBarTest extends TestCase
{
    public function test_a_closed_rail_flyout_takes_no_room(): void
    {
        $css = File::get(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.rail-flyout\s*\{[^}]*display:\s*none/s', $css,
            '⛔ বন্ধ hover-তালিকা আবার জায়গা নেয় — অদৃশ্য তালিকা পাতাটাকে ১,৭০০px লম্বা করে, আর স্টিকি সাইডবার পাতার সাথে সরে যায়');
        $this->assertMatchesRegularExpression('/\.group\\\\\/rail:hover \.rail-flyout,\s*\.group\\\\\/rail:focus-within \.rail-flyout\s*\{[^}]*display:\s*block/s', $css,
            '⛔ hover বা কী-বোর্ডে hover-তালিকা আর খোলে না');
    }

    public function test_the_module_menu_scrolls_inside_a_sidebar_that_stops_above_the_bar(): void
    {
        $sidebar = File::get(resource_path('views/components/shell/sidebar.blade.php'));

        $this->assertMatchesRegularExpression('/<nav class="min-h-0 flex-1 [^"]*overflow-y-auto/', $sidebar,
            '⛔ মডিউল-মেনুর তালিকায় min-h-0 নেই — সে ভেতরে স্ক্রল না করে বাক্সের বাইরে বারের নিচে উপচে পড়ে');
        $this->assertStringContainsString('menu-panel hidden min-h-0', $sidebar, '⛔ মেনুর প্যানেলে min-h-0 নেই — তালিকা উপচে পড়ে');
        $this->assertStringContainsString('h-[calc(100dvh-var(--spacing-status-bar))]', $sidebar,
            '⛔ সাইডবার পুরো পর্দার উঁচু — মেনুর শেষ সারি নিচের বারের নিচে চাপা');
        $this->assertStringContainsString('calc(100dvh - var(--spacing-header) - var(--spacing-status-bar))', $sidebar,
            '⛔ পুরো-চওড়া বারের নিচের সাইডবার নিচের বার বাদ দেয় না');
    }

    public function test_no_screen_pins_its_own_bar_over_the_bottom_bar(): void
    {
        $pay = File::get(base_path('app/Modules/Purchase/Resources/views/payment/form.blade.php'));
        $this->assertMatchesRegularExpression('/data-pay-footer class="[^"]*sticky bottom-\(--spacing-bottom-nav\) md:bottom-\(--spacing-status-bar\)/', $pay,
            '⛔ টাকা দেওয়ার বোতাম-সারি নিচের বারের ঠিক উপরে আটকে থাকে না');

        // ⓘ খোলস নিজে ছাড়া কোনো পর্দা নিচে `fixed` পট্টি বসায় না — বসালে নিচের বার ঢাকে
        $allowed = ['shell/statusbar.blade.php', 'shell/bottom-nav.blade.php', 'shell/chrome/apps.blade.php', 'shell/chrome/salesforce.blade.php'];
        $offenders = collect(File::allFiles(base_path('app/Modules')))->merge(File::allFiles(resource_path('views')))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.blade.php'))
            ->filter(fn ($f) => ! collect($allowed)->contains(fn (string $a) => str_ends_with(str_replace('\\', '/', $f->getPathname()), $a)))
            ->filter(fn ($f) => preg_match('/class="[^"]*\bfixed inset-x-0 bottom-0\b/', File::get($f->getPathname())) === 1)
            ->map(fn ($f) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $f->getPathname()))
            ->values()->all();

        $this->assertSame([], $offenders, "⛔ এই পর্দাগুলো নিচে নিজের `fixed` পট্টি বসায় — নিচের ঘোষণার বার ঢাকে; ui.form-actions-এর মতো sticky দিন:\n".implode("\n", $offenders));
    }
}

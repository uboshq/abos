<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\Accent;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ⭐ ভাউচারের টাকার বাক্সে তিনটা ছোট দাগ — fe, ২২ অক্টোবর ২০২৬ (cb/voucher-method)।
 *
 * ── ⛔ কী মাপা গিয়েছিল (Chromium, ১৩৬৬ আর ১৯২০, আলো আর গাঢ়) ────────────────────────────────────────
 *   · বাছা পদ্ধতির চিপ: সাদা লেখা brand-600-এ। গাঢ় থিমে brand-600 হালকা নীল — ২.৬:১; আর বাছা চিপে মাউস রাখলে
 *     hover-এর প্রায়-সাদা জমিন জিতত — আলোয় ১.০৮:১, লেখাটা মুছে যেত।
 *   · পক্ষের লেবেল রসিদে "ডিপোজিটরের ধরন/নাম", পরিশোধে "প্রাপকের ধরন/নাম" — দুই পর্দায় এক ঘরের দুই নাম।
 *   · "কার মাধ্যমে"-র খালি লেখা "ব্যাংকে সরাসরি — কেউ যায়নি" ১৯১px, ঘরে জায়গা রসিদে ১৮৭, পরিশোধে ১৩৭ — কাটা পড়ত।
 * ⭐ পরে: চিপ আলোয় ৮.৬৩:১, গাঢ়ে ৪.৭৩:১, hover-এও তাই; লেখা ১৪৩px, জায়গা ১৮৭ / ১৯৩।
 * ⓘ বিন্যাস আর রং মাপা ব্রাউজারের কাজ — তাই এখানে প্রতিটা কারণ উৎসে ধরা, আর রঙের জোড়া প্রতিটা অ্যাকসেন্টে গুনে।
 */
final class TheChosenMethodAndThePartyReadPlainlyTest extends TestCase
{
    public function test_the_chosen_method_sits_on_a_colour_its_own_ink_reads_on_in_both_themes(): void
    {
        $box = File::get(resource_path('views/components/money/method.blade.php'));

        $this->assertStringContainsString('peer-checked:bg-(--color-brand-700)', $box, '⛔ বাছা চিপের জমিন আর brand-700 নয় — গাঢ় থিমে brand-600 হালকা নীল, সাদা লেখা ২.৬:১');
        $this->assertStringContainsString('peer-checked:text-(--color-brand-ink)', $box, '⛔ বাছা চিপের লেখা অ্যাকসেন্টের নিজের কালি নয় — হালকা অ্যাকসেন্টে সাদা লেখা পড়া যায় না');
        $this->assertStringContainsString('peer-checked:hover:bg-(--color-brand-700)', $box, '⛔ বাছা চিপে মাউস রাখলে hover-এর প্রায়-সাদা জমিন জেতে — সাদা লেখা ১.০৮:১');
        $this->assertStringNotContainsString('peer-checked:text-white', $box, '⛔ বাছা চিপে আবার বাঁধা সাদা লেখা');

        // ⓘ গাঢ় থিমে brand-700 অ্যাকসেন্টের গাঢ় ধাপ — তার জোড়া গাঢ় কালি
        $this->assertMatchesRegularExpression('/:root\[data-theme=\'dark\'\]\s*\{(?:(?!\n\}).)*--color-brand-700:\s*var\(--color-brand-500\)/s',
            File::get(resource_path('css/tokens.css')), '⛔ গাঢ় থিমে brand-700 আর অ্যাকসেন্টের গাঢ় ধাপ নয় — চিপের জোড়া মাপা নেই');

        foreach (Accent::all() as $key => $accent) {
            $light = $this->contrast($accent['scale']['700'], $accent['ink']);
            $dark = $this->contrast($accent['dark'], $accent['dark_ink']);
            $this->assertGreaterThanOrEqual(4.5, $light, sprintf('⛔ %s: আলোয় বাছা চিপ %.2f:১', $key, $light));
            $this->assertGreaterThanOrEqual(4.5, $dark, sprintf('⛔ %s: গাঢ়ে বাছা চিপ %.2f:১', $key, $dark));
        }
    }

    public function test_the_receipt_and_the_payment_call_the_other_side_the_party(): void
    {
        foreach (['depositor_type' => 'পক্ষের ধরন', 'payee_type' => 'পক্ষের ধরন', 'depositor_name' => 'পক্ষ', 'payee_name_party' => 'পক্ষ'] as $key => $bn) {
            $this->assertSame($bn, trans("accounts::field.{$key}", [], 'bn'), "⛔ {$key}: পক্ষের লেবেল আবার আলাদা নামে — রসিদ আর পরিশোধে এক ঘরের দুই নাম");
        }
        $this->assertSame('Party type', trans('accounts::field.depositor_type', [], 'en'), '⛔ ইংরেজিতে পক্ষের ধরন আলাদা নামে');
        $this->assertSame('Party', trans('accounts::field.payee_name_party', [], 'en'), '⛔ ইংরেজিতে পক্ষ আলাদা নামে');
    }

    /**
     * ⛔ খরচের ভাউচারে বাক্সটা অর্ধেক কলামে (৫১২px), অথচ ঘরের কলাম পর্দার চওড়া (`lg:`) ধরত — ট্রান্সফারের চার ঘর এক সারিতে,
     * TrxID "NP26102104" আর মূল্য-তারিখ "22-10-2" কাটা (চূড়ান্ত ছবি, ২২ অক্টোবর ২০২৬)। ⭐ এখন বাক্স নিজে container, আর কলাম
     * বাক্সের চওড়া ধরে; মাপা: খরচে দুই কলাম (২৪৮px), রসিদ-পরিশোধে চার (১,০৮৬px বাক্স), কোনো ঘরের লেখা কাটা নয়।
     */
    public function test_the_method_fields_count_their_own_room_not_the_screens(): void
    {
        $box = File::get(resource_path('views/components/money/method.blade.php'));

        $this->assertStringContainsString("'@container flex flex-col gap-3' => ! \$inline", $box,
            '⛔ টাকার বাক্স আর container নয় — অর্ধেক কলামেও ঘরগুলো পর্দার চওড়া ধরে চারটা এক সারিতে বসে, লেখা কাটা পড়ে');
        $this->assertStringContainsString("'grid gap-3 @xs:grid-cols-2 @3xl:grid-cols-4'", $box,
            '⛔ পদ্ধতির ঘরের কলাম আর বাক্সের চওড়া ধরে না');
        $this->assertSame(3, substr_count($box, '@class([$fourUp,'),
            '⛔ MFS, ট্রান্সফার আর নগদ জমার তিন বাক্স একই নিয়মে নেই — কোনোটা আবার পর্দার চওড়া ধরছে');
    }

    public function test_who_carried_it_fits_its_box(): void
    {
        $this->assertSame('সরাসরি — কেউ যায়নি', trans('accounts::field.carried_by_none', [], 'bn'),
            '⛔ "কার মাধ্যমে"-র খালি লেখা আবার লম্বা — ১৩৬৬-এ কাটা পড়ে (১৯১px লেখা, ১৩৭px ঘর)');

        $form = File::get(base_path('app/Modules/Accounts/Resources/views/voucher/simple-form.blade.php'));
        $this->assertStringContainsString("lg:grid-cols-[1.4fr_1.4fr_1fr_1.6fr_1.6fr]' => \$isPayment", $form,
            '⛔ পরিশোধে "কার মাধ্যমে"-র কলাম আবার সরু — ১৩৬৬-এ লেখা কাটা পড়ে');
    }

    /** @param string $a "r g b" */
    private function contrast(string $a, string $b): float
    {
        $lum = function (string $rgb): float {
            [$r, $g, $bl] = array_map(fn ($v) => ($v = (int) $v / 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, explode(' ', $rgb));

            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $bl;
        };
        [$x, $y] = [$lum($a), $lum($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }
}

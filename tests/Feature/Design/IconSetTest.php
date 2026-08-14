<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * এক আইকন সেট, আর কেউ ওটার বাইরে যায় না।
 *
 * ── কী ঠিক করা হয়েছে ────────────────────────────────────────────────
 * আগে "আইকন" বলে তিনটা আলাদা জিনিস ছিল: একত্রিশটা পর্দার "নতুন" বোতামে
 * টাইপ করা একটা যোগ চিহ্ন, টপবারে ইমোজি, আর সাইডবারে রঙিন ডুওটোন আঁকা।
 * তিনটা তিন ভাষা, আর একটাও পর্দার কালি নেয় না।
 *
 * ── কেন পরীক্ষাটা দরকার ─────────────────────────────────────────────
 * সেটটা বসানো সহজ; **সেটটার বাইরে না যাওয়াটা** কঠিন। পরের যে কেউ
 * তাড়াহুড়োয় একটা ইমোজি বসিয়ে দিতে পারেন — দেখতে কাজ করে, আর কেউ ধরার
 * আগেই পঞ্চাশটা পর্দায় ছড়িয়ে যায়। এই পরীক্ষাগুলো ঠিক সেই মুহূর্তে
 * ভাঙে।
 */
class IconSetTest extends TestCase
{
    /**
     * ব্লেডের মন্তব্য বাদ দিয়ে যা সত্যিই ব্রাউজারে যায়।
     *
     * মন্তব্যে ইমোজির নাম লেখা থাকতেই পারে — যেমন "এখানে একবার টিকের
     * ইমোজি বসানো হয়েছিল, কেন সরানো হলো" — আর সেটা ভুল নয়, সেটাই
     * ব্যাখ্যা। মন্তব্য না ছাঁটলে এই পরীক্ষা নিজের ব্যাখ্যাটাকেই
     * অপরাধ বলে ধরত, আর তখন লেখা বন্ধ করে দিতে হত।
     */
    private function markupOf(string $path): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/su', '', File::get($path));
    }

    /** @return list<string> প্রতিটা ব্লেড ফাইলের পথ */
    private function blades(): array
    {
        $roots = [base_path('app'), resource_path('views')];
        $out = [];

        foreach ($roots as $root) {
            foreach (File::allFiles($root) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php')) {
                    $out[] = $file->getPathname();
                }
            }
        }

        return $out;
    }

    public function test_the_set_draws_every_name_it_is_asked_for(): void
    {
        // সেটের ভেতরের একটা নমুনা — মডিউল, কাজ ও টাকা তিন ভাগ থেকেই
        foreach (['dashboard', 'accounts', 'plus', 'printer', 'wallet', 'handover'] as $name) {
            $svg = Blade::render('<x-ui.icon name="'.$name.'" />');

            $this->assertStringContainsString('<svg', $svg, "{$name}: কিছুই আঁকা হয়নি");
            $this->assertStringContainsString('viewBox="0 0 24 24"', $svg, "{$name}: ছক ২৪×২৪ নয়");
            // currentColor ছাড়া ডার্ক থিমে আইকনটা একই রঙে থেকে যেত
            $this->assertStringContainsString('stroke="currentColor"', $svg, "{$name}: কালি নেয় না");
            $this->assertStringContainsString('stroke-width="1.75"', $svg, "{$name}: স্ট্রোক আলাদা");
            $this->assertStringContainsString('aria-hidden="true"', $svg, "{$name}: স্ক্রিন রিডারে পড়া হবে");
            // ভরাট আকার নয় — সেটটার নিয়ম
            $this->assertStringContainsString('fill="none"', $svg, "{$name}: ভরাট");
        }
    }

    /**
     * অচেনা নাম মানে চুপ থাকা, ভাঙা নয়।
     *
     * একটা মেনু সারিতে ভুল নামের জন্য গোটা পাতা নামিয়ে দেওয়ার কোনো
     * কারণ নেই — আইকন ছাড়াও সারিটা পড়া যায়।
     */
    public function test_an_unknown_name_draws_nothing_instead_of_failing(): void
    {
        $this->assertSame('', trim(Blade::render('<x-ui.icon name="no-such-thing" />')));
    }

    /**
     * বোতামের আইকন সেটের নাম, লেখা নয়।
     *
     * এই পরীক্ষাটাই আসল পাহারা: কেউ `icon="+"` ফিরিয়ে আনলে সেটটা
     * থাকলেও থাকল, পর্দায় আবার টাইপ করা অক্ষরই দেখা যেত।
     */
    public function test_no_screen_passes_a_typed_character_as_an_icon(): void
    {
        $offenders = [];

        foreach ($this->blades() as $path) {
            $body = $this->markupOf($path);

            // icon="…" — ভেতরে যদি সেটের নামের বদলে অন্য কিছু থাকে
            preg_match_all('/\bicon="([^"]*)"/u', $body, $m);

            foreach ($m[1] as $value) {
                // সেটের নাম: শুধু ছোট হাতের অক্ষর ও আন্ডারস্কোর
                if ($value !== '' && preg_match('/^[a-z_]+$/', $value) !== 1) {
                    $offenders[] = basename($path).' → icon="'.$value.'"';
                }
            }
        }

        $this->assertSame([], $offenders,
            "আইকনের ঘরে সেটের নাম ছাড়া অন্য কিছু বসেছে:\n".implode("\n", $offenders));
    }

    /**
     * পর্দায় ইমোজি নয়।
     *
     * ইমোজি আমাদের আঁকা নয় — Windows-এর 🔔 আর Android-এর 🔔 এক ছবি নয়,
     * তাই একই পর্দা দুই মেশিনে দুই রকম দেখায়। আর ওরা পর্দার কালি নেয়
     * না, তাই ডার্ক থিমে একই উজ্জ্বল রঙে থেকে যায়।
     *
     * তালিকাটা যা আসলে বসানো ছিল তাই: টপবারের ✅ 🔔 🌐, পাসওয়ার্ডের 👁।
     */
    public function test_no_emoji_is_used_as_an_icon(): void
    {
        $banned = ['✅', '🔔', '🌐', '👁', '🔍', '⚙', '📊', '🏠', '❌', '⚠'];
        $offenders = [];

        foreach ($this->blades() as $path) {
            $body = $this->markupOf($path);

            foreach ($banned as $emoji) {
                if (str_contains($body, $emoji)) {
                    $offenders[] = basename($path).' → '.$emoji;
                }
            }
        }

        $this->assertSame([], $offenders,
            "পর্দায় ইমোজি বসেছে; এগুলো x-ui.icon-এর নাম হওয়ার কথা:\n".implode("\n", $offenders));
    }
}

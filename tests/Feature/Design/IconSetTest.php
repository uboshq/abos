<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Module\ModuleRegistry;
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
        $source = File::get($path);

        // Blade-এর মন্তব্য
        $source = (string) preg_replace('/\{\{--.*?--\}\}/su', '', $source);

        /*
         * PHP-র ব্লক মন্তব্যও — `@php`-র ভেতরে আর `@props`-এর ডকব্লকে।
         *
         * ── কেন এটা যোগ করতে হলো, ৩ সেপ্টেম্বর ২০২৬ ──────────────────
         * উপরের লাইনটা কেবল `{{-- --}}` ছাঁটত, তাই PHP-র ব্লক মন্তব্যে
         * একটা সতর্কতা-চিহ্ন লিখলেই পরীক্ষাটা "পর্দায় ইমোজি টাইপ করা
         * হয়েছে" বলে ভাঙত — অথচ ওই লেখা ব্রাউজারে কোনোদিন যায় না।
         *
         * এটা পাহারা আলগা করা নয়; এই ফাইলের নিজের ব্যাখ্যাই বলে
         * **মন্তব্য অপরাধ নয়** — "মন্তব্য না ছাঁটলে এই পরীক্ষা নিজের
         * ব্যাখ্যাটাকেই অপরাধ বলে ধরত, আর তখন লেখা বন্ধ করে দিতে হত"।
         * এতদিন সেটা কেবল এক রকমের মন্তব্যে খাটত।
         *
         * `//` ছাঁটা হয় না, ইচ্ছাকৃতভাবে: `https://…`-এর ভেতরেও ওটা
         * আছে, আর তখন অর্ধেক পর্দা মুছে যেত।
         */
        return (string) preg_replace('#/\*.*?\*/#su', '', $source);
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
        /*
         * কেবল **কাজের** আইকন — মডিউলেরগুলো আর আঁকা নয়।
         *
         * ── কেন নিয়মটা বদলাল, ২৮ আগস্ট ২০২৬ ─────────────────────────
         * মডিউলের চিহ্ন এখন ইমোজি। মালিক দুইটা পণ্য পাশাপাশি রেখে
         * দেখেছেন: ১৮px-এ একটা গুদামের আউটলাইন আর একটা বাক্সের
         * আউটলাইন একই ধূসর চতুর্ভুজ, আর এই মেনুতে এগারোটা মডিউল।
         *
         * কিন্তু **কাজের বোতাম আঁকাই থাকে** — যোগ, ছাপা, ছাঁকনি।
         * ওখানে "চেনা যাওয়া"-র সমস্যা নেই, আর ইমোজির টুলবার পড়ে
         * চ্যাট-উইন্ডোর মতো। নিচের `test_module_marks_are_emoji_and_unique`
         * উল্টো দিকটা পাহারা দেয়।
         */
        foreach (['plus', 'printer', 'wallet', 'handover', 'search', 'refresh'] as $name) {
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
     * ...কিন্তু আমরা যে নামগুলো পাঠাই, সেগুলো সত্যি হতে হবে।
     *
     * ── উপরের নিয়মটার উল্টো পিঠ ─────────────────────────────────────
     * অচেনা নামে পাতা না ভাঙা একটা **দয়া**, আর দয়াটা ঠিক আছে: একটা
     * ভুল নামের জন্য গোটা পর্দা নামিয়ে দেওয়ার মানে নেই।
     *
     * কিন্তু ঠিক ওই দয়াটার কারণেই ভুল নাম **নীরব**। ৩ সেপ্টেম্বর
     * ২০২৬-এ ধরা পড়ল ড্যাশবোর্ডের **২৬টা টাইল কিছুই আঁকছে না** —
     * `report`, `people`, `money`, `check`, `shield`, `transfer`,
     * `ledger` — একটাও সেটে ছিল না। সবগুলো পরীক্ষা সবুজ ছিল, কারণ
     * "কিছু না আঁকা"-ই ঘোষিত আচরণ।
     *
     * চোখে ধরা পড়েছিল স্ক্রিনশট দেখে: প্রথম টাইলে ছবি, পরের তিনটায়
     * ফাঁকা। **কোনো টেস্ট নয়, একটা ছবি।** তাই এই পরীক্ষাটা।
     *
     * PHP-তে লেখা নামগুলো দেখা হয় (`icon: 'x'`) — ব্লেডে লেখাগুলো
     * নিচের `test_no_screen_passes_a_typed_character_as_an_icon`
     * ইতিমধ্যেই ছোঁয়, আর সেখানে নামটা বেশিরভাগ সময় চলক।
     */
    public function test_every_name_we_actually_pass_exists_in_the_set(): void
    {
        /* `[a-z_-]` — আন্ডারস্কোরটা বাদ দিলে `master_data`, `system_admin`
           আর `check_circle` সেটের বাইরে পড়ে যায়, আর পরীক্ষাটা ঠিক
           সেগুলোকেই "নেই" বলত */
        preg_match_all(
            "/^\s+'([a-z_-]+)' =>/m",
            $this->markupOf(resource_path('views/components/ui/icon.blade.php')),
            $found,
        );

        $set = array_flip($found[1]);

        $this->assertGreaterThan(30, count($set), 'সেটটাই পড়া যায়নি — regex বদলে গেছে?');

        $missing = [];

        foreach (File::allFiles(base_path('app')) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            preg_match_all("/icon:\s*'([a-z_-]+)'/", File::get($file->getPathname()), $used);

            foreach ($used[1] as $name) {
                if (! isset($set[$name])) {
                    $missing[] = $file->getFilename().": '{$name}'";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), implode("\n", array_merge(
            ['এই নামগুলো আইকন সেটে নেই — পর্দায় ওখানে কিছুই আঁকা হবে না,',
                'আর কোনো ত্রুটিও দেখা যাবে না:'],
            array_unique($missing),
            ['সেটের নামগুলো: '.implode(', ', array_keys($set))],
        )));
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
     * ইমোজি কেবল আইকন সেটের ভেতরে — পর্দার কোথাও ছড়ানো নয়।
     *
     * ── নিয়মটা বদলেছে, কিন্তু আলগা হয়নি ────────────────────────────
     * আগে নিয়ম ছিল "পর্দায় ইমোজি নয়", আর কারণটা এখনো সত্যি: ইমোজি
     * আমাদের আঁকা নয়, Windows-এর 🔔 আর Android-এর 🔔 এক ছবি নয়, আর
     * ওরা পর্দার কালি নেয় না।
     *
     * ২৮ আগস্ট ২০২৬-এ মালিক মডিউলের চিহ্নের জন্য ইমোজি বেছেছেন —
     * ১৮px-এ এগারোটা আউটলাইন একই ধূসর চতুর্ভুজ, আর সেখানে চেনা যাওয়া
     * সঙ্গতির চেয়ে বড়।
     *
     * কিন্তু ছাড়টা **একটাই ফাইলে**: `ui/icon.blade.php`। ওখানে
     * ইমোজিগুলো একটা তালিকায় বসে, নাম ধরে ডাকা হয়, আর একবার বদলালে
     * সবখানে বদলায়।
     *
     * বাকি পর্দায় নিষেধ আগের মতোই কড়া। কেউ একটা বোতামে সরাসরি 🔔
     * টাইপ করলে সেটা আবার সেই পুরনো সমস্যা — ছড়ানো ইমোজি, যার কোনো
     * নাম নেই আর যেটা বদলাতে হলে খুঁজে বেড়াতে হয়।
     */
    public function test_no_emoji_is_typed_straight_into_a_screen(): void
    {
        $banned = ['✅', '🔔', '🌐', '👁', '🔍', '⚙', '📊', '🏠', '❌', '⚠'];
        $offenders = [];

        foreach ($this->blades() as $path) {
            // সেটের নিজের ফাইলই একমাত্র জায়গা যেখানে ইমোজি বসে
            if (basename($path) === 'icon.blade.php') {
                continue;
            }

            $body = $this->markupOf($path);

            foreach ($banned as $emoji) {
                if (str_contains($body, $emoji)) {
                    $offenders[] = basename($path).' → '.$emoji;
                }
            }
        }

        $this->assertSame([], $offenders, implode('
', [
            'পর্দায় সরাসরি ইমোজি টাইপ করা হয়েছে:',
            ...$offenders,
            '',
            'মডিউলের চিহ্ন হলে ui/icon.blade.php-এর তালিকায় নাম দিয়ে বসান;',
            'কাজের বোতাম হলে ওটার একটা আঁকা লাগবে।',
        ]));
    }

    /**
     * প্রতিটা মডিউলের চিহ্ন আছে, আর **কোনো দুইটা এক নয়**।
     *
     * ── কেন অনন্যতাটাই আসল দাবি ─────────────────────────────────────
     * দুইটা মডিউলে একই ইমোজি থাকলে সেটা চিহ্ন না থাকার চেয়েও খারাপ —
     * চোখ ভুল জিনিস শেখে, আর মানুষ ক্রয়ে ক্লিক করতে গিয়ে বিক্রয়ে
     * চলে যান।
     *
     * ইমোজি বাছার পুরো যুক্তিটাই "না পড়েই আলাদা করা যায়"। দুইটা এক
     * হলে ওই যুক্তিটাই ভেঙে যায়, আর তখন আঁকাগুলোই ভালো ছিল।
     */
    public function test_module_marks_are_emoji_and_unique(): void
    {
        $modules = collect(app(ModuleRegistry::class)->all())
            ->map(fn ($m) => $m->code)
            ->push('dashboard')
            ->all();

        $marks = [];
        $missing = [];

        foreach ($modules as $code) {
            $html = trim(Blade::render(
                '<x-ui.icon name="'.$code.'" />'
            ));

            if ($html === '') {
                $missing[] = $code;

                continue;
            }

            // মোড়কের ভেতরের অক্ষরটাই চিহ্ন
            $glyph = trim(strip_tags($html));

            $marks[$glyph][] = $code;
        }

        $this->assertSame([], $missing, implode('
', [
            'এই মডিউলগুলোর কোনো চিহ্ন নেই — মেনুতে সারিটা খালি বসবে:',
            ...$missing,
            '',
            'ui/icon.blade.php-এর $glyphs তালিকায় একটা সারি যোগ করুন।',
        ]));

        $shared = array_filter($marks, fn (array $codes) => count($codes) > 1);

        $this->assertSame([], $shared, implode('
', [
            'একই চিহ্ন একাধিক মডিউলে:',
            ...array_map(
                fn (string $g, array $codes) => "  {$g} — ".implode(', ', $codes),
                array_keys($shared),
                $shared,
            ),
            '',
            'দুইটা মডিউলে এক চিহ্ন মানে চোখ ভুল জিনিস শেখে।',
        ]));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use Tests\TestCase;

/**
 * প্রতিটা পর্দা থিম মানবে — আজ, আর ছয় সপ্তাহ পরেও।
 *
 * ── এই পরীক্ষাটা কীসের বিরুদ্ধে ──────────────────────────────────────
 * ABOS আটটা চেহারা পাচ্ছে (docs/Plan — আটটা থিম.md)। চেহারা বদলায়
 * টোকেন আর শেল ধরে, তাই কোনো পর্দাকে কিছু জানতে হয় না — **যদি** পর্দাটা
 * টোকেনই ব্যবহার করে।
 *
 * সমস্যাটা আজকের নয়, পরের মাসের। কেউ একটা নতুন পাতা লিখবেন, তাতে
 * একটা হার্ডকোড করা রং বা একটা `h-9` থাকবে, আর ওই পাতাটা থিম মানবে
 * না। **কেউ মাসের পর মাস টের পাবে না**, কারণ তিনি নিজে যে থিম
 * ব্যবহার করেন সেটাতে হয়তো ঠিকই দেখায়। ধরা পড়বে যেদিন অন্য কেউ
 * অন্য থিমে ওই পাতাটা খুলবেন — সম্ভবত কোনো গ্রাহক।
 *
 * নিয়ম লিখে রাখলে ভাঙে; পরীক্ষা লিখে রাখলে ভাঙে না।
 *
 * ── ছাড়ের তালিকা নিয়ে একটা কড়া কথা ──────────────────────────────────
 * প্রতিটা ছাড়ের পাশে **কারণ লেখা বাধ্যতামূলক**, আর সেটাও একটা টেস্ট
 * দিয়ে বাঁধা। কারণ ছাড়া নাম যোগ করা গেলে ছাড়ের তালিকাটাই আস্তে আস্তে
 * পুরো তালিকা হয়ে যেত, আর পরীক্ষাটা এমন এক পাহারা হত **যেটা সবকিছু
 * পাশ করায়**। এ প্রকল্পে ঠিক ওরকম তিনটা পাহারা আগে ধরা পড়েছে।
 */
class EveryScreenObeysTheThemeTest extends TestCase
{
    /**
     * হার্ডকোড রং যেখানে চলে — আর কেন।
     *
     * @var array<string, string>
     */
    private const COLOUR_ALLOWED = [
        'resources/views/workspace/partials/ui-card.blade.php' => 'চেহারার নমুনা — ওটা ছবি, নিয়ন্ত্রণ নয়; নিজের চেহারার রঙেই আঁকতে হয় নাহলে নমুনাটা মিথ্যা বলে',
        'resources/views/print/' => 'ছাপা থিম মানে না — বিল সবার জন্য এক, ব্যক্তির পছন্দ নয়',
        'resources/views/welcome.blade.php' => 'Laravel-এর নিজের স্বাগত পাতা; ব্যবহারকারী কোনোদিন দেখেন না',
    ];

    /**
     * কাঁচা `<table>` যেখানে চলে — আর কেন।
     *
     * @var array<string, string>
     */
    private const TABLE_ALLOWED = [
        'resources/views/components/ui/table.blade.php' => 'কম্পোনেন্টটাই — টেবিলটা এখানেই লেখা',
        'resources/views/print/' => 'ছাপার কাগজ নিজের ছাঁচে; থিমের সাথে সম্পর্ক নেই',
    ];

    /**
     * চেহারা জানা যেখানে চলে — আর কেন।
     *
     * ঠিক একটাই জায়গা: যে পর্দায় ব্যবহারকারী চেহারাটা **বাছেন**।
     * ওখানে আটটার নাম, রং আর নমুনা না দেখালে বাছাই করার উপায়ই
     * থাকত না — রেডিও বোতামের পাশে শুধু আটটা শব্দ।
     *
     * @var array<string, string>
     */
    private const THEME_AWARE = [
        'resources/views/workspace/appearance.blade.php' => 'এখানেই বাছাই হয় — না জানলে বাছার কিছু থাকে না',
        'resources/views/workspace/partials/ui-card.blade.php' => 'বাছাইয়ের নমুনা: চেহারাটার নিজের রঙে আঁকা হয়, তাই রংটা এখান থেকেই আসে',
        'resources/views/components/shell/' => 'শেলের অংশ — কোন খোলসে বসবে সেটা জানাই ওদের কাজ',
    ];

    /**
     * থিমের আটটা নাম। কোনো পর্দায় এগুলোর একটাও থাকবে না।
     *
     * @var list<string>
     */
    private const THEMES = [
        'classic', 'tiles', 'suite', 'apps', 'dynamic', 'rose', 'navy', 'redwood',
    ];

    /** @return array<string, string> path => source */
    private function blades(): array
    {
        $found = [];

        foreach (['app/Modules', 'resources/views'] as $root) {
            $dir = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($root)),
            );

            foreach ($dir as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());
                $path = ltrim(str_replace(str_replace('\\', '/', base_path()), '', $path), '/');

                $found[$path] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($found);

        return $found;
    }

    /** @param  array<string, string>  $allowed */
    private function isAllowed(string $path, array $allowed): bool
    {
        foreach (array_keys($allowed) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * কোনো পর্দায় হার্ডকোড করা রং নেই।
     *
     * ছয়-অঙ্ক, তিন-অঙ্ক আর `rgb()` — তিনটাই দেখা হয়। ২০ আগস্ট প্রথম
     * মাপায় কেবল ছয়-অঙ্ক গোনা হয়েছিল, আর সংখ্যাটা ভুল এসেছিল।
     *
     * মন্তব্যের ভেতরের রং বাদ: `ui/button.blade.php`-এ প্যালেটের
     * ব্যাখ্যায় তিনটা কোড লেখা আছে, আর ওগুলো কোনো পিক্সেল আঁকে না।
     */
    public function test_no_screen_hardcodes_a_colour(): void
    {
        $offenders = [];

        foreach ($this->blades() as $path => $source) {
            if ($this->isAllowed($path, self::COLOUR_ALLOWED)) {
                continue;
            }

            // ব্লেড ও HTML মন্তব্য সরিয়ে ফেলা — ওখানে কোড ব্যাখ্যা থাকে
            $code = preg_replace('/\{\{--.*?--\}\}|<!--.*?-->|\/\*.*?\*\//s', '', $source) ?? $source;

            if (preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $code)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এই পর্দাগুলোয় রং হাতে লেখা, তাই থিম বদলালে ওগুলো বদলাবে না।',
            'টোকেন ব্যবহার করুন: var(--color-…)। ইচ্ছাকৃত হলে COLOUR_ALLOWED-এ',
            'কারণসহ লিখুন।',
            ...$offenders,
        ]));
    }

    /**
     * কোনো **নিয়ন্ত্রণের** উচ্চতা হাতে লেখা নেই।
     *
     * ── কেন নিয়মটা সংকীর্ণ, আর সেটাই ইচ্ছাকৃত ───────────────────────
     * প্রথম খসড়ায় যেকোনো `h-<সংখ্যা>` ধরা হচ্ছিল। ওটা ধরে ফেলছিল
     * বিভাজক রেখা (`h-8 w-px`) আর লগইনের লোগো (`h-12 w-auto`) — যেগুলো
     * নিয়ন্ত্রণ নয়, আর যাদের উচ্চতা থিমের সাথে বদলানোর কথাও নয়।
     *
     * চওড়া পাহারার একটাই পরিণতি: বাড়তে থাকা ছাড়ের তালিকা। আর যে
     * তালিকা বাড়তেই থাকে, সে একদিন পুরো তালিকা হয়ে যায় — তখন
     * পরীক্ষাটা এমন এক পাহারা, যেটা সবকিছু পাশ করায়।
     *
     * তাই নিয়মটা ঠিক সেই জিনিসটাই ধরে যেটা ভুল ছিল: **একই `class`-এ
     * `h-<সংখ্যা>` আর `rounded-(--radius…)` একসাথে** — অর্থাৎ একটা ঘর
     * বা বোতাম। ২০ আগস্ট ওরকম ৭২ জায়গা ছিল, ২৩টা ফাইলে।
     *
     * ইনলাইন `height: <সংখ্যা>px`-ও ধরা হয়, তবে `min-height`,
     * `max-height` বা `line-height` নয় — ওই তিনটার শেষেও "height:"
     * থাকে, আর সেটাই প্রথমবার তিনটা নিরীহ ফাইলকে দোষী দেখিয়েছিল।
     */
    public function test_no_control_hardcodes_a_height(): void
    {
        $offenders = [];

        foreach ($this->blades() as $path => $source) {
            if ($this->isAllowed($path, self::COLOUR_ALLOWED)) {
                continue;
            }

            $code = preg_replace('/\{\{--.*?--\}\}|<!--.*?-->/s', '', $source) ?? $source;

            $control = preg_match(
                '/class="[^"]*(?:(?<![-\w])h-\d+\b[^"]*rounded-\(--radius|rounded-\(--radius[^"]*(?<![-\w])h-\d+\b)/s',
                $code,
            );

            $inline = preg_match('/(?<![-\w])height:\s*\d+px/', $code);

            if ($control || $inline) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এই পর্দাগুলোয় উচ্চতা হাতে লেখা। ঘন থিমে ওগুলো একা আগের',
            'মাপে বসে থাকবে, আর পর্দাটা অর্ধেক বদলে যাবে।',
            'টোকেন: h-(--spacing-field), h-(--spacing-field-compact),',
            'h-(--spacing-command)।',
            ...$offenders,
        ]));
    }

    /**
     * কোনো পর্দা তার থিমের নাম জানে না।
     *
     * `@if ($ui === 'apps')` একবার লেখা শুরু হলে ছয় মাসে আটটা আলাদা
     * পণ্য দাঁড়িয়ে যাবে — দেখতে নয়, কাজে।
     */
    public function test_no_screen_knows_which_theme_it_is_in(): void
    {
        $offenders = [];
        $pattern = '/\$ui\b|[\'"](?:'.implode('|', self::THEMES).')[\'"]/';

        foreach ($this->blades() as $path => $source) {
            if ($this->isAllowed($path, self::THEME_AWARE)) {
                continue;
            }

            $code = preg_replace('/\{\{--.*?--\}\}|<!--.*?-->/s', '', $source) ?? $source;

            if (preg_match($pattern, $code)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এই পর্দাগুলো জানে তারা কোন থিমে আছে। জানার কথা নয় —',
            'যা বদলায় তা টোকেন আর শেল, পাতার কোড নয়।',
            ...$offenders,
        ]));
    }

    /**
     * প্রতিটা টেবিল তিনটা নামের একটা পরে — নাহলে থিম ওটা ছুঁতে পারে না।
     *
     * ── এটাই ৭ নম্বর ঝুঁকির পাহারা ───────────────────────────────────
     * আটটা থিমের প্রতিশ্রুতি একটাই বাক্য: "Apps বাছলে গোটা ERP Odoo
     * হবে"। ওটা ভাঙার সবচেয়ে সহজ পথ কোনো থিমের ভুল নয় — একটা **নতুন
     * পর্দা**, যেটা ছ-মাস পরে কেউ লিখবে, আর অভ্যাসবশত `<table
     * class="w-full text-sm">` টাইপ করে ঘরে `px-3 py-2` বসাবে।
     *
     * সেটা কোথাও লাল হত না। পর্দাটা খুলত, দেখতে ঠিকই লাগত, আর কেবল
     * থিম বদলালে বোঝা যেত: চল্লিশটা পর্দা Odoo হয়ে গেছে আর এই একটা
     * আগের চেহারায় বসে আছে। একই ERP-তে দুই যুগ।
     *
     * ── কেন Tailwind-এর ইউটিলিটি এখানে বিষ ─────────────────────────
     * CSS-এর নিয়ম: utility স্তর সব `@layer`-কে হারায়। তাই ঘরে
     * `px-3 py-2` লেখা থাকলে থিমের কোনো নিয়ম ওতে পৌঁছয়ই না — মাপটা
     * পাথরে খোদাই হয়ে যায়। রং টোকেন থেকে এলেও ঘনত্ব আটকে থাকে,
     * আর অর্ধেক বদল পুরো না-বদলের চেয়ে খারাপ দেখায়।
     */
    public function test_every_table_wears_one_of_the_three_names(): void
    {
        $offenders = [];

        foreach ($this->blades() as $path => $source) {
            if ($this->isAllowed($path, self::TABLE_ALLOWED)) {
                continue;
            }

            if (! preg_match_all('/<table\b[^>]*>/s', $source, $m)) {
                continue;
            }

            foreach ($m[0] as $tag) {
                if (! preg_match('/\b(ui-list|ui-grid|ui-lines)\b/', $tag)) {
                    $offenders[] = $path.' — '.trim(preg_replace('/\s+/', ' ', $tag) ?? $tag);
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'এই টেবিলগুলো তিনটা নামের একটাও পরেনি।',
            '',
            '  ui-list   পড়ার তালিকা — <x-ui.table> নিজেই বসায়',
            '  ui-grid   সম্পাদনার ছক (ঘনত্ব: is-dense / is-compact / is-sheet / is-flush)',
            '  ui-lines  চালান-ভাউচারের লাইন-এডিটর',
            '',
            'নাম না থাকলে ছকের মাপ ও ধার কোনো থিম বদলাতে পারবে না,',
            'আর থিম বদলালে এই পর্দাটা একা আগের চেহারায় বসে থাকবে।',
            ...$offenders,
        ]));
    }

    /**
     * কোনো ঘরে হাতে লেখা প্যাডিং নেই।
     *
     * উপরের পরীক্ষাটা টেবিলটা ধরে; এটা ধরে **ঘরটা**। নাম পরার পরেও
     * ঘরে `px-3 py-2` থেকে গেলে ক্লাসটা কেবল সাজসজ্জা — মাপটা তখনও
     * ইউটিলিটির হাতে, আর ইউটিলিটি থিম শোনে না।
     */
    public function test_no_table_cell_hardcodes_its_padding(): void
    {
        $offenders = [];

        foreach ($this->blades() as $path => $source) {
            if ($this->isAllowed($path, self::TABLE_ALLOWED)) {
                continue;
            }

            $code = preg_replace('/\{\{--.*?--\}\}|<!--.*?-->/s', '', $source) ?? $source;

            if (! preg_match_all('/<(?:th|td)\b[^>]*>/s', $code, $m)) {
                continue;
            }

            foreach ($m[0] as $tag) {
                /*
                 * `colspan` ওয়ালা ঘর বাদ।
                 *
                 * ওটা "কিছু নেই" লেখার সারি — পুরো টেবিলের প্রস্থ জুড়ে
                 * একটা বার্তা, ছকের ঘর নয়। ওখানে `px-3 py-6` ইচ্ছাকৃত:
                 * খালি তালিকায় বার্তাটা একটু বাতাস পায়।
                 */
                if (str_contains($tag, 'colspan')) {
                    continue;
                }

                if (preg_match('/(?<![-\w])(p|px|py|pt|pb|ps|pe)-[\d.]+/', $tag, $hit)) {
                    $offenders[] = $path.' — '.$hit[0];
                }
            }
        }

        $offenders = array_values(array_unique($offenders));

        $this->assertSame([], $offenders, implode("\n", [
            'এই ঘরগুলো নিজের প্যাডিং নিজে ঠিক করেছে।',
            'মাপটা টোকেনে সরান (--grid-pad-*, --lines-pad*), নাহলে',
            'থিম বদলালে ঘনত্ব বদলাবে না।',
            ...$offenders,
        ]));
    }

    /**
     * পর্দা যে টোকেনটা চায়, সেটা সত্যিই সংজ্ঞায়িত আছে।
     *
     * ── এটা যা ধরে ──────────────────────────────────────────────────
     * CSS-এ অচেনা `var(--কিছু-একটা)` ভুল নয় — সে চুপচাপ **কিছুই নয়**
     * হয়ে যায়। `bg-(--color-surface-sunken)` লেখা একটা টেবিলের মাথা
     * তাই স্বচ্ছ হয়ে বসে থাকে, আর দেখে মনে হয় ডিজাইনটাই এমন।
     *
     * ২১ আগস্ট তিনটা পর্দায় ঠিক সেটাই ধরা পড়ল: টোকেনটা কোনোদিন লেখাই
     * হয়নি। কেউ ভাঙা কিছু দেখেনি, কারণ ভাঙা মানে লাল নয় — ভাঙা মানে
     * ফাঁকা, আর ফাঁকা দেখতে ইচ্ছাকৃত লাগে।
     *
     * ── কেন রঙের পরীক্ষাটা এটা ধরেনি ────────────────────────────────
     * ওটা খোঁজে **হার্ডকোড রং**, অর্থাৎ যা টোকেন নয়। এখানে ঠিক
     * উল্টোটা: টোকেনের নাম ঠিকঠাক লেখা, কেবল টোকেনটা নেই। একটা
     * পাহারা কেবল সেটাই ধরে যেটা সে খোঁজে — আর এই ফাঁকটার জন্য
     * নিজের একটা পাহারা লাগে।
     */
    public function test_every_token_a_screen_asks_for_actually_exists(): void
    {
        $defined = [];

        foreach (['resources/css/tokens.css', 'resources/css/themes.css', 'resources/css/app.css'] as $file) {
            preg_match_all('/(--[a-z0-9-]+)\s*:/', (string) file_get_contents(base_path($file)), $m);
            $defined = [...$defined, ...$m[1]];
        }

        $defined = array_flip($defined);
        $missing = [];

        foreach ($this->blades() as $path => $source) {
            // Laravel-এর নিজের স্বাগত পাতা Tailwind-এর টোকেন ব্যবহার
            // করে (--color-gray-500), আর ওগুলো এই ফাইলগুলোতে থাকে না।
            if ($this->isAllowed($path, self::COLOUR_ALLOWED)) {
                continue;
            }

            $code = preg_replace('/\{\{--.*?--\}\}|<!--.*?-->/s', '', $source) ?? $source;

            /*
             * দুইটা রূপই দেখা হয়: Tailwind-এর `bg-(--x)` আর সাদা
             * CSS-এর `var(--x)`। প্রথমটা পর্দায় বেশি, দ্বিতীয়টা
             * ইনলাইন স্টাইলে — আর দুইটাই একইভাবে নীরবে হারায়।
             */
            /*
             * নামটা যেখানে অর্ধেক লেখা, সেখানে বাদ।
             *
             * `bg-(--color-badge-{{ $tone }}-bg)` — এখানে টোকেনের নাম
             * চলার সময় তৈরি হয়, আর কোন কোনগুলো হতে পারে তা স্থিরভাবে
             * জানার উপায় নেই। ধরতে গেলে `--color-badge-` ধরা পড়ত,
             * যেটা কোনো টোকেনই নয় — একটা উপসর্গ।
             *
             * `(?![-\w{])` অংশটা ঠিক সেটাই ছাঁকে: নামের পরেই `{`
             * থাকলে বুঝতে হবে বাকিটা Blade বসাবে।
             */
            preg_match_all('/(?:\(|var\()\s*(--[a-z0-9-]+)(?![-\w{])/', $code, $m);

            foreach (array_unique($m[1]) as $token) {
                // Tailwind-এর নিজের টোকেন (`--spacing`, `--color-red-500`)
                // এই ফাইলগুলোতে থাকে না, ওগুলো ফ্রেমওয়ার্কের।
                if (! str_starts_with($token, '--color-') && ! str_starts_with($token, '--radius-')
                    && ! str_starts_with($token, '--row-') && ! str_starts_with($token, '--grid-')
                    && ! str_starts_with($token, '--lines-')) {
                    continue;
                }

                if (! isset($defined[$token])) {
                    $missing[] = $path.' — '.$token;
                }
            }
        }

        $missing = array_values(array_unique($missing));

        $this->assertSame([], $missing, implode("\n", [
            'এই টোকেনগুলো পর্দা চায়, কিন্তু কোথাও সংজ্ঞায়িত নেই।',
            'CSS চুপ থাকবে আর জায়গাটা ফাঁকা আঁকা হবে — ভুল বলে',
            'মনে হবে না, ডিজাইন বলে মনে হবে।',
            ...$missing,
        ]));
    }

    /** প্রতিটা ছাড়ের একটা লিখিত কারণ আছে। */
    public function test_every_exception_carries_a_reason(): void
    {
        foreach ([self::COLOUR_ALLOWED, self::TABLE_ALLOWED] as $list) {
            foreach ($list as $path => $reason) {
                $this->assertNotSame('', trim($reason), "কারণ ছাড়া ছাড়: {$path}");
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * ⭐ গ্রাহকের নাম যেখানে, তার পাশে পয়েন্ট আর শাখা — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম): *"গ্রাহকের নামের পাশে পয়েন্ট
 * দিতে হবে অবশ্যই, সব জায়গায়, আর শাখার নামও দিতে হবে।"*
 *
 * ── ⛔ কেন পাহারা ────────────────────────────────────────────────────
 * নিয়মটা এক জায়গায় ([[Customer::nameWithPoint()]] — "নাম · পয়েন্ট · শাখা", [[Customer::placeName()]] — "পয়েন্ট · শাখা"),
 * কিন্তু ডাকতে হয় প্রতিটা পর্দাকে। ⚠️ ২৮ সেপ্টেম্বর আর ৭ অক্টোবরের একই নিয়ম ("গ্রাহকের পরে পয়েন্ট") ছিল, তবু ১৯ অক্টোবর
 * মালিক অনেক তালিকায় খালি নাম পেলেন — নতুন পর্দা পুরনো নিয়ম জানত না। এই দাবি নতুন খালি নাম ধরে।
 *
 * ── ⓘ কী মাপা হয় ────────────────────────────────────────────────────
 * ওয়েবের সব Blade (resources/views আর মডিউলের views; ছাপার `print/` বাদ — কাগজের মাথার নিজের দাবি), ড্যাশবোর্ডের ক্লাস আর
 * ফোনের *ApiController: যে লাইনে গ্রাহকের খালি নাম (`customer->name()`, `customer?->name()`, `->name_bn`/`->name_en`,
 * `$customers->mapWithKeys(... ->name())`, `@foreach ($customers as $c) … $c->name()`), তার আশেপাশে (একই সারি/কলামের দল,
 * ±৮ লাইন) জায়গা থাকতে হবে — `nameWithPoint()`, `placeName()`, `pointName()`/`branchName()`, `pickerName()`, জায়গার ঘর
 * (`customer::partials.place`), `<x-ui.party-cell>`, বা ফোনের সারিতে `point`/`place`/`branch` চাবি।
 * ⚠️ জানালাটা ইচ্ছা করে ছোট — তালিকার নামের কলাম আর তার পাশের "পয়েন্ট · শাখা" কলাম একই দলে বসে; দূরের অন্য কোনো
 * জায়গার লেখা একটা খালি নামকে ঢাকতে পারে না।
 */
final class ACustomerNameAlwaysCarriesItsPlaceTest extends TestCase
{
    /**
     * ⓘ দেখানোর নাম নয় — তাই ছাড় নয়, গঠন (ফাইল::লাইনের টুকরা => কারণ)।
     *
     * @var array<string, string>
     */
    private const NOT_A_DISPLAY = [
        "app/Modules/Customer/Resources/views/form.blade.php::old('name_en', \$customer->name_en)" => 'ফর্মের ঘরের মান — নাম লেখার ঘর, দেখানো নয়',
        "app/Modules/Customer/Resources/views/form.blade.php::old('name_bn', \$customer->name_bn)" => 'ফর্মের ঘরের মান — নাম লেখার ঘর, দেখানো নয়',
        'app/Modules/Customer/Resources/views/show.blade.php::<x-ui.page-header :title="$customer->name()"' => 'গ্রাহকের নিজের পাতা — পরিচয়ের বাক্সে পয়েন্ট আর শাখা আলাদা ঘরে',
        'app/Modules/Customer/Resources/views/partials/row-actions.blade.php::aria-label="{{ __(\'core.action.view\') }} {{ $customer->name() }}"' => 'স্ক্রিন-রিডারের বোতামের নাম — সারির পয়েন্ট/এরিয়া/শাখার কলাম পাশেই',
        'app/Modules/Customer/Resources/views/partials/row-actions.blade.php::aria-label="{{ __(\'core.action.edit\') }} {{ $customer->name() }}"' => 'স্ক্রিন-রিডারের বোতামের নাম — সারির পয়েন্ট/এরিয়া/শাখার কলাম পাশেই',
        'app/Modules/Customer/Resources/views/partials/state-badge.blade.php::$customer->name()" />' => 'স্ক্রিন-রিডারের সুইচের নাম — সারির পয়েন্ট/এরিয়া/শাখার কলাম পাশেই',
        'app/Modules/Sales/Resources/views/components/portal/layout.blade.php::{{ $customer->name_bn ?: $customer->name_en }}' => 'গ্রাহকের নিজের পোর্টাল — পাঠক নিজেই গ্রাহক',
        "app/Modules/Sales/Resources/views/delivery/partials/actions.blade.php::old('receiver_name', \$challan->customer?->name())" => 'গ্রহীতার নামের ঘরের আগে-ভরা মান — লেখার ঘর, দেখানো নয়',
        'app/Modules/Sales/Resources/views/delivery/partials/row-action.blade.php::value="{{ $challan->customer?->name() }}"' => 'গ্রহীতার নামের ঘরের আগে-ভরা মান — লেখার ঘর, দেখানো নয়',
    ];

    /**
     * ⚠️ আসল ফাঁক — নিয়মটা এখনো বসেনি, আর কেন।
     *
     * @var array<string, string>
     */
    private const NOT_YET = [
        'app/Modules/Sales/Resources/views/pos/index.blade.php::[$c->id => $c->name()]' => 'POS — মালিকের আদেশে ২১ অক্টোবর পর্যন্ত বন্ধ (১৩ অক্টোবর ২০২৬); খুললে nameWithPoint() আর with(Customer::PLACE)',
    ];

    private const WINDOW = 8;

    public function test_no_screen_shows_a_customer_name_without_its_point_and_branch(): void
    {
        $bare = [];
        $seen = 0;

        foreach ($this->files() as $path) {
            $lines = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents(base_path($path))));

            foreach ($this->bareLines($lines) as $i) {
                $seen++;

                if ($this->excused($path, $lines[$i]) || $this->placed($lines, $i)) {
                    continue;
                }

                $bare[] = $path.':'.($i + 1).' — '.trim(mb_substr($lines[$i], 0, 140));
            }
        }

        // ⓘ নিয়ন্ত্রণ: খোঁজা যদি ভেঙে কিছুই না পায়, দাবিটা মিথ্যা সবুজ হত
        $this->assertGreaterThan(30, $seen, "⛔ খোঁজাটাই ভেঙেছে — গ্রাহকের নাম পাওয়া গেছে মাত্র {$seen}টা জায়গায়।");
        $this->assertSame([], $bare, implode("\n", [
            '⛔ এই জায়গাগুলোয় গ্রাহকের নাম আছে, পাশে পয়েন্ট আর শাখা নেই (মালিক, ১৯ অক্টোবর ২০২৬: "সব জায়গায়"):', '', ...$bare, '',
            '`nameWithPoint()` ("নাম · পয়েন্ট · শাখা") বসান, অথবা পাশে "পয়েন্ট · শাখা"-র কলাম (`customer::partials.place`);',
            'আর তালিকায় গোটা পাতার `with(Customer::PLACE)` / `Customer::placeVia(...)` — সারি ধরে প্রশ্ন নয়।',
            'দেখানো নাম না হলে (ফর্মের মান) কারণসহ NOT_A_DISPLAY-এ তুলুন।',
        ]));
    }

    /**
     * ⭐ মিউট্যান্ট: নতুন একটা খালি নাম লিখলে পাহারা ধরে — জানালার ভেতরে জায়গা না থাকলে।
     */
    public function test_a_new_bare_name_is_caught_and_a_placed_one_is_not(): void
    {
        $bare = ['<td>{{ $row->customer?->name() }}</td>', '<td>{{ $row->amount }}</td>'];
        $this->assertSame([0], $this->bareLines($bare), '⛔ নমুনার খালি নামটাই খোঁজায় এল না।');
        $this->assertFalse($this->placed($bare, 0), '⛔ জায়গাহীন খালি নাম পাহারা ধরল না।');

        $picker = ['<x-ui.select :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->name()])" />'];
        $this->assertSame([0], $this->bareLines($picker), '⛔ পিকারের খালি নাম খোঁজায় এল না।');
        $this->assertFalse($this->placed($picker, 0), '⛔ পিকারের খালি নাম পাহারা ধরল না।');

        $loop = ['@foreach ($customers as $shop)', '<option value="{{ $shop->id }}">{{ $shop->name() }}</option>', '@endforeach'];
        $this->assertSame([1], $this->bareLines($loop), '⛔ লুপের ভেতরের খালি নাম খোঁজায় এল না।');

        $column = ["'render' => fn (\$d) => \$d->customer?->name(),", '],', '[', "'render' => fn (\$d) => view('customer::partials.place', ['customer' => \$d->customer]),"];
        $this->assertTrue($this->placed($column, 0), 'পাশের "পয়েন্ট · শাখা" কলামসহ নাম — পাহারা মিথ্যা লাল।');
        $this->assertTrue($this->placed(['{{ $row->customer?->nameWithPoint() }}'], 0), 'জায়গাসহ নাম — পাহারা মিথ্যা লাল।');
    }

    public function test_no_excuse_is_stale(): void
    {
        foreach ([...array_keys(self::NOT_A_DISPLAY), ...array_keys(self::NOT_YET)] as $key) {
            [$path, $needle] = explode('::', $key, 2);
            $this->assertFileExists(base_path($path), "⛔ ছাড়ের ফাইল আর নেই: {$path} — নামটা সরান।");
            $this->assertStringContainsString($needle, (string) file_get_contents(base_path($path)),
                "⛔ ছাড়ের লাইনটা আর নেই ({$path}: {$needle}) — সারানো হলে ছাড়টাও সরান।");
        }
    }

    /**
     * যে লাইনগুলোয় গ্রাহকের নাম আছে — শূন্য থেকে গোনা লাইন নম্বর।
     *
     * @param  list<string>  $lines
     * @return list<int>
     */
    private function bareLines(array $lines): array
    {
        $found = [];
        $loopVars = [];

        foreach ($lines as $i => $line) {
            // ⓘ ব্রাউজারের ট্যাবের শিরোনাম (`<x-slot:title>`) — পাতার মাথাতেই নাম আর জায়গা
            if (str_contains($line, 'x-slot:title')) {
                continue;
            }

            if (preg_match('/@foreach\s*\(\s*\$\w*customers\w*\s+as\s+\$(\w+)\s*\)/', $line, $m) === 1) {
                $loopVars[$m[1]] = $i + 8;
            }

            $loopHit = false;

            foreach ($loopVars as $var => $until) {
                if ($i <= $until && preg_match('/\$'.$var.'->(name\(\)|name_bn\b|name_en\b)/', $line) === 1) {
                    $loopHit = true;
                }
            }

            if ($loopHit
                || preg_match('/customer\??->(name\(\)|name_bn\b|name_en\b)/', $line) === 1
                || preg_match('/\$customers->mapWithKeys\(fn \(\$\w+\) => \[\$\w+->id => [^\]]*->name\(\)/', $line) === 1) {
                $found[] = $i;
            }
        }

        return $found;
    }

    /** @param  list<string>  $lines */
    private function placed(array $lines, int $at): bool
    {
        $window = implode("\n", array_slice($lines, max(0, $at - self::WINDOW), self::WINDOW * 2 + 1));

        return preg_match("/nameWithPoint\(|placeName\(|pointName\(|branchName\(|pickerName\(|customer::partials\.place|x-ui\.party-cell|'(customer_)?(point|place|branch)'\s*=>/", $window) === 1;
    }

    private function excused(string $path, string $line): bool
    {
        foreach ([...array_keys(self::NOT_A_DISPLAY), ...array_keys(self::NOT_YET)] as $key) {
            [$file, $needle] = explode('::', $key, 2);

            if ($file === $path && str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ⓘ ওয়েবের Blade (ছাপা বাদ), ড্যাশবোর্ড আর ফোনের দরজা। ⛔ POS/রেস্টুরেন্ট মডিউল মালিকের আদেশে ২১ অক্টোবর পর্যন্ত বন্ধ।
     *
     * @return list<string>
     */
    private function files(): array
    {
        $root = str_replace('\\', '/', base_path()).'/';
        $files = [
            ...$this->walk(base_path('resources/views'), '.blade.php'),
            ...$this->walk(base_path('app/Modules'), '.blade.php'),
            ...glob(base_path('app/Modules/*/Dashboard/*.php')) ?: [],
            ...$this->walk(base_path('app'), 'ApiController.php'),
        ];

        $out = [];

        foreach ($files as $file) {
            $path = str_replace($root, '', str_replace('\\', '/', $file));

            if (str_contains($path, '/print/') || str_contains($path, 'Modules/Pos/') || str_contains($path, 'Modules/Restaurant/')) {
                continue;
            }

            $out[$path] = true;
        }

        $paths = array_keys($out);
        sort($paths);

        return $paths;
    }

    /** @return list<string> */
    private function walk(string $dir, string $suffix): array
    {
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}

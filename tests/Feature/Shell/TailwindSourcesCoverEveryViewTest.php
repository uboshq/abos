<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use Tests\TestCase;

/**
 * প্রতিটা ব্লেড ফাইল Tailwind-এর কোনো না কোনো `@source`-এ পড়তে হবে।
 *
 * ── যে ভুলটা এই পাহারা ধরে ───────────────────────────────────────────
 * `resources/css/app.css`-এ তিনটা `@source` লেখা ছিল, আর তাতে
 * `resources/views` ছিল না — অর্থাৎ শেল, লগইন পাতা, অ্যাপ লেআউট আর
 * তালিকার টুলবার কোথাও ঘোষিত ছিল না।
 *
 * ডেভ মেশিনে সেটা ধরা পড়েনি, কারণ Tailwind নিজে থেকেও ফাইল খোঁজে আর
 * সেখানে ওটা কাজ করছিল। Mac-এ করেনি। একই কমিট থেকে দুই মেশিনে দুই রকম
 * CSS বেরিয়েছে — ৮৬KB বনাম ৭১KB, ১৯০টা ক্লাসের পার্থক্য। ফল: লাইভ
 * সাইটের সাইডবার আর লগইন পাতা ভুল দেখাচ্ছিল, আর কোনো এরর কোথাও ছিল না।
 *
 * ── কেন CSS-এর আউটপুট দেখে পরীক্ষা করা হয় না ─────────────────────────
 * করলে পরীক্ষাটা `npm run build` চালানোর উপর নির্ভর করত, আর CI-তে
 * বিল্ড না চললে নীরবে পাশ করত — অর্থাৎ জিনিসটা অনুপস্থিত থাকলেও পাশ।
 * তাই ঘোষণাটাই যাচাই হয়: কোন ফাইলগুলো আছে, আর কোনগুলো ঘোষিত।
 */
class TailwindSourcesCoverEveryViewTest extends TestCase
{
    public function test_every_blade_file_falls_under_a_declared_source(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css, 'resources/css/app.css পড়া গেল না।');

        preg_match_all("/@source\s+'([^']+)'/", $css, $matches);

        $globs = $matches[1];

        $this->assertNotEmpty($globs, implode("\n", [
            'app.css-এ একটাও @source নেই।',
            'তখন Tailwind কেবল নিজের খোঁজার উপর চলবে, আর সেটা মেশিনভেদে',
            'আলাদা ফল দেয় — ঠিক যে সমস্যাটা এই পরীক্ষা ঠেকাতে এসেছে।',
        ]));

        /*
         * গ্লবগুলো app.css-এর ফোল্ডার থেকে আপেক্ষিক, আর মিলানো হয়
         * নিজের হাতে — PHP-র glob() `**` চেনে না (ওটাকে একটামাত্র স্তরের
         * `*` ধরে), অথচ Tailwind চেনে। ওটা ব্যবহার করলে এই পরীক্ষাটাই
         * ঠিক ঘোষিত ফাইলগুলোকে "অঘোষিত" বলত।
         */
        $base = $this->real(resource_path('css'));

        $patterns = array_map(
            /*
             * তারকাচিহ্ন ছাড়া লেখা মানে একটা ফোল্ডার, আর Tailwind ফোল্ডার
             * পেলে তার পুরোটা ঘুরে দেখে। গ্লব লেখার চেয়ে এটাই নিরাপদ:
             * `**` ভুল জায়গায় বসালে গ্লবটা নীরবে কিছুই মেলাত না।
             */
            fn (string $glob) => $this->toRegex(
                $base.'/'.$glob.(str_contains($glob, '*') ? '' : '/**/*'),
            ),
            $globs,
        );

        $uncovered = [];

        foreach ($this->bladeFiles() as $file) {
            $path = $this->real($file);

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    continue 2;
                }
            }

            $uncovered[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        }

        sort($uncovered);

        $this->assertSame([], $uncovered, implode("\n", [
            'এই ব্লেড ফাইলগুলো কোনো @source-এ পড়ে না।',
            'এগুলোর ক্লাসগুলো CSS-এ থাকবে কি না তা নির্ভর করবে Tailwind-এর',
            'স্বয়ংক্রিয় খোঁজার উপর, যেটা এক মেশিনে চলে আর অন্যটায় চলে না।',
            'resources/css/app.css-এ একটা @source যোগ করুন।',
        ]));
    }

    /**
     * `glob()` ** বোঝে না, তাই ফাইলগুলো নিজেরাই হাঁটা হয়।
     *
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        $files = [];

        foreach ([resource_path('views'), app_path('Modules')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $walker = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($walker as $entry) {
                if ($entry->isFile() && str_ends_with($entry->getFilename(), '.blade.php')) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        $this->assertNotEmpty($files, 'একটাও ব্লেড ফাইল পাওয়া গেল না — হাঁটাটাই ভুল।');

        return $files;
    }

    private function real(string $path): string
    {
        $resolved = realpath($path);

        // `..` থাকা পথ যদি না-ও থাকে, তুলনাটা যেন ভেঙে না পড়ে
        return str_replace('\\', '/', $resolved === false ? $path : $resolved);
    }

    /**
     * Tailwind-এর গ্লবকে রেগেক্সে — `**` মানে যত খুশি ফোল্ডার, `*` মানে এক ধাপ।
     */
    private function toRegex(string $glob): string
    {
        // `..` মিলিয়ে নেওয়া, নইলে '../views' কোনোদিন কোনো আসল পথে মিলত না
        $glob = str_replace('\\', '/', $glob);

        while (preg_match('#(^|/)[^/]+/\.\./#', $glob)) {
            $glob = preg_replace('#(^|/)[^/]+/\.\./#', '$1', $glob, 1);
        }

        $regex = '';

        for ($i = 0; $i < strlen($glob); $i++) {
            if (substr($glob, $i, 3) === '**/') {
                $regex .= '(?:[^/]+/)*';
                $i += 2;

                continue;
            }

            if ($glob[$i] === '*') {
                $regex .= '[^/]*';

                continue;
            }

            $regex .= preg_quote($glob[$i], '#');
        }

        return '#^'.$regex.'$#';
    }
}

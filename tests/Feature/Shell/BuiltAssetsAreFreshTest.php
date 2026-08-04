<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use Tests\TestCase;

/**
 * বিল্ড করা CSS কি সোর্সের চেয়ে পুরনো?
 *
 * Tailwind ক্লাসগুলো বিল্ডের সময় সোর্স ফাইল পড়ে তৈরি হয়। নতুন একটা ভিউতে
 * এমন ক্লাস লিখলে যা আগে কোথাও ছিল না, বিল্ড না চালানো পর্যন্ত ওই ক্লাসটা
 * CSS-এ থাকেই না — আর ব্রাউজারে স্ক্রিনটা ভাঙা দেখায়।
 *
 * দুইবার এটা হয়েছে: প্রোফাইলের ছবি ৯৬px-এর বদলে ২৫৬px বসেছিল (size-24
 * ছিল না), আর গ্রাহকের পাতায় শিরোনাম কার্ডের বর্ডারের উপর বসেছিল
 * (px-4 py-3 ছিল না)। দুইবারই কোড ঠিক ছিল, তাই কোড পড়ে ধরা যেত না, আর
 * কোনো assert-ও ভাঙত না — শুধু চোখে দেখলে বোঝা যেত।
 *
 * এই পরীক্ষাটা সেই সময়টুকু বাঁচায়: ভুলের বার্তাই বলে দেয় কী করতে হবে।
 */
class BuiltAssetsAreFreshTest extends TestCase
{
    public function test_the_built_css_is_newer_than_every_view_and_style_source(): void
    {
        $manifest = public_path('build/manifest.json');

        $this->assertFileExists($manifest, 'npm run build চালানো হয়নি।');

        $css = collect(json_decode((string) file_get_contents($manifest), true))
            ->pluck('file')
            ->first(fn (string $file) => str_ends_with($file, '.css'));

        $this->assertNotNull($css, 'manifest-এ কোনো CSS নেই।');

        $builtAt = filemtime(public_path('build/'.$css));

        $newer = [];

        foreach ($this->sourceFiles() as $file) {
            if (filemtime($file) > $builtAt) {
                $newer[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        sort($newer);

        $this->assertSame([], $newer, implode("\n", array_merge(
            ['বিল্ডের পরে এই ফাইলগুলো বদলেছে, তাই নতুন Tailwind ক্লাস CSS-এ নেই:'],
            $newer,
            ['npm run build চালান।'],
        )));
    }

    /**
     * যে ফাইলগুলোতে সত্যিই Tailwind ক্লাস থাকতে পারে।
     *
     * প্রথম চেষ্টায় app/Modules-এর সব .php ধরা হয়েছিল, আর তাতে সার্ভিস,
     * রুট ও ভাষার ফাইল বদলালেও পরীক্ষাটা ভাঙত — অথচ ওগুলোর সাথে CSS-এর
     * কোনো সম্পর্ক নেই। প্রতিটা ব্যাকএন্ড বদলে "npm run build চালান"
     * বলা মানে কিছুদিনের মধ্যেই কেউ আর বার্তাটা পড়বে না।
     *
     * ক্লাস থাকে দুই জায়গায়: Blade টেমপ্লেট, আর যে PHP কম্পোনেন্ট ক্লাস
     * হাতে ক্লাসের তালিকা বানায় (app/View)।
     *
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        $roots = [
            resource_path('views') => '/\.blade\.php$/',
            resource_path('css') => '/\.css$/',
            app_path('Modules') => '/\.blade\.php$/',
            app_path('View') => '/\.php$/',
        ];

        foreach ($roots as $root => $pattern) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match($pattern, $file->getFilename())) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}

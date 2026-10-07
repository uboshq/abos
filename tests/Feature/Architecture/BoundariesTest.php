<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Module\ModuleRegistry;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * সীমানা — কে কার নাম জানতে পারে।
 *
 * ── কেন এটা টেস্ট, নথি নয় ───────────────────────────────────────────
 * সীমানার নিয়ম নথিতে লেখা থাকলে সেটা ভাঙে নীরবে, আর ভাঙে ঠিক তখন যখন
 * তাড়া থাকে: একটা নাম দেখাতে গিয়ে একটা import, তারপর আরেকটা। ছয় মাস
 * পর মডিউলগুলো আর আলাদা থাকে না, অথচ কোনো একটা মুহূর্তে কেউ সিদ্ধান্ত
 * নেয়নি যে সীমানাটা তুলে দেওয়া হবে।
 *
 * এই দুইটা পরীক্ষা রেজিস্ট্রি ধরে হাঁটে, হাতে লেখা তালিকা ধরে নয় — নতুন
 * মডিউল যোগ হলে সেটাও প্রথম দিন থেকেই পাহারায় আসে। হাতে লেখা তালিকা
 * থাকলে নতুন মডিউলের দিনেই পরীক্ষাটা চুপ করে যেত, অর্থাৎ ঠিক যখন
 * দরকার তখন।
 */
class BoundariesTest extends TestCase
{
    /**
     * কোর কোনো মডিউলের নাম জানে না (§১৯.৭)।
     *
     * ── কেন এটাই সবচেয়ে গুরুত্বপূর্ণ সীমানা ─────────────────────────
     * সবাই কোরের উপর দাঁড়ায়। কোর যদি একটা মডিউলের নাম জানে, তাহলে ওই
     * মডিউল ছাড়া কোর চলে না — আর তখন "মডিউল" কথাটার আর কোনো মানে থাকে
     * না, পুরোটা একটা জিনিস হয়ে যায়।
     *
     * বাস্তবে এটা ভাঙে ছোট করে: একটা রিপোর্টের নাম, একটা মডেলের
     * ক্লাস। আজ রাতেই দুইবার এই সীমানায় থামতে হয়েছে — একবার রিপোর্টের
     * "as of date" পতাকাটা কোরে রিপোর্টের নাম ধরে লিখে ফেলার পর, আর
     * একবার আদায়ের তালিকায় গ্রাহকের টেবিলে জোড় লাগাতে গিয়ে।
     */
    public function test_the_core_names_no_module(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn([app_path('Core'), app_path('Providers')]) as $file) {
            $source = File::get($file);

            /*
             * মন্তব্য বাদ — সীমানাটা ব্যাখ্যা করতে গেলে নামটা লিখতেই হয়।
             *
             * "Accounts কারও উপর নির্ভর করে না" লেখা একটা মন্তব্য নিয়ম
             * ভাঙে না; ওটা নিয়মটা মনে করিয়ে দেয়।
             */
            $code = $this->withoutComments($source);

            if (preg_match('/App\\\\Modules\\\\/', $code) === 1) {
                $offenders[] = $this->relative($file);
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'কোরের এই ফাইলগুলো একটা মডিউলের নাম জানে।',
            'কোর সবার নিচে দাঁড়ায় — সে কারও নাম জানলে তাকে ছাড়া কোর চলে না।',
            'মডিউল নিজে ঘোষণা করুক (module.php), কোর শুধু নিয়মটা পড়ুক।',
        ]));
    }

    /**
     * একটা মডিউল অন্য মডিউলের ভেতরে হাত দেয় না — যাকে সে নির্ভরতা বলে
     * ঘোষণা করেনি।
     *
     * ── কেন ঘোষণাটা জরুরি ───────────────────────────────────────────
     * নির্ভরতা থাকা দোষ নয়; বিক্রয় ছাড়া মজুদের কোনো মানে নেই। দোষ
     * হলো **অঘোষিত** নির্ভরতা: module.php বলছে "আমি একা চলি", অথচ কোড
     * আরেকটা মডিউলের মডেল ডাকছে। তখন ওই মডিউল বন্ধ করলে বা সরালে কী
     * ভাঙবে তা কেউ বলতে পারে না।
     *
     * ঘোষিত থাকলে অন্তত প্রশ্নটা করা যায়: "এই নির্ভরতাটা কি সত্যিই
     * দরকার?" — আর আজ রাতে ঠিক সেই প্রশ্নেই আদায়ের তালিকা থেকে পক্ষের
     * নামের কলামটা বাদ পড়েছে।
     */
    public function test_no_module_reaches_into_one_it_did_not_declare(): void
    {
        $registry = app(ModuleRegistry::class);
        $codes = [];

        foreach ($registry->all() as $module) {
            $codes[$module->code] = $module->dependsOn;
        }

        $offenders = [];

        foreach ($registry->all() as $module) {
            $dir = $module->dir();

            if (! is_dir($dir)) {
                continue;
            }

            $allowed = array_map(
                fn (string $code) => $this->namespaceFor($code, $codes),
                [$module->code, ...$codes[$module->code]],
            );

            foreach ($this->phpFilesIn([$dir]) as $file) {
                $code = $this->withoutComments(File::get($file));

                preg_match_all('/App\\\\Modules\\\\([A-Za-z0-9_]+)\\\\/', $code, $matches);

                foreach (array_unique($matches[1]) as $namespace) {
                    if (! in_array($namespace, $allowed, true)) {
                        $offenders[] = $module->code.' → '.$namespace.'  ('.$this->relative($file).')';
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), implode("\n", [
            'এই মডিউলগুলো এমন মডিউলের ভেতরে হাত দিচ্ছে যাকে তারা',
            'depends_on-এ ঘোষণা করেনি। হয় ঘোষণা করুন, নয় নির্ভরতাটা সরান।',
        ]));
    }

    /**
     * মডিউলের কোড থেকে তার নেমস্পেস।
     *
     * 'master_data' → 'MasterData'. রেজিস্ট্রি কোডটাই রাখে, ফোল্ডারের
     * নাম নয়, তাই রূপান্তরটা এখানেই।
     *
     * @param  array<string, list<string>>  $codes
     */
    private function namespaceFor(string $code, array $codes): string
    {
        return str_replace('_', '', ucwords($code, '_'));
    }

    /** @param list<string> $roots @return list<string> */
    private function phpFilesIn(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $this->assertNotEmpty($files, 'কোনো ফাইলই দেখা হয়নি — পথটা কি ভুল?');

        return $files;
    }

    /**
     * মন্তব্য ছেঁটে ফেলা — কেবল কোডটাই দেখা হয়।
     *
     * token_get_all ব্যবহার করা হয়, regex নয়: একটা স্ট্রিংয়ের ভেতরে
     * "/*" থাকলে regex ওখান থেকে বাকিটা মুছে দিত, আর পরীক্ষাটা নীরবে
     * অর্ধেক ফাইল দেখা বন্ধ করত।
     */
    private function withoutComments(string $source): string
    {
        $kept = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $kept .= $token[1];

                continue;
            }

            $kept .= $token;
        }

        return $kept;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}

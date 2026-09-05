<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * প্রতিটা সম্পর্ক এমন একটা ক্লাসের দিকে দেখায় যেটা সত্যিই আছে।
 *
 * ── কেন এই পাহারাটা লাগল ────────────────────────────────────────────
 * ৫ সেপ্টেম্বর ২০২৬, মালিক লাইভে মূলধনের পাতা খুলে ৫০০ পেয়েছেন:
 *
 *     Class "App\Modules\Finance\Models\Account" not found
 *
 * `CapitalEntry::account()` লিখেছিল `belongsTo(Account::class, …)`,
 * কিন্তু ফাইলটায় `use App\Modules\Accounts\Models\Account;` লেখা ছিল না।
 * PHP তখন নামটা **নিজের namespace-এ** খোঁজে — আর সেখানে ওই ক্লাস নেই।
 *
 * ── কেন কোনো কিছুই এটা ধরেনি ────────────────────────────────────────
 * ফাইলটা সিনট্যাক্সে নিখুঁত, তাই `php -l` চুপ। ক্লাসটা লোডও হয়, কারণ
 * ভুলটা কেবল **ওই একটা মেথড ডাকলে** ঘটে। আর যে পর্দাটা ওটা ডাকে
 * সেটার কোনো টেস্ট ছিল না — তাই সুইট সবুজ, লাইভ ভাঙা।
 *
 * ⚠️ Finance-এর বাকি চারটা মডেল একই লাইন লেখে আর import-টাও লেখে।
 * অর্থাৎ ভুলটা নিয়মের নয়, একটা ফাইলের — আর ঠিক সেই ধরনের ভুলই
 * পাহারা ছাড়া নীরবে বেঁচে থাকে।
 *
 * ── কেন প্রতিটা সম্পর্ক সত্যিই ডাকা হয় ──────────────────────────────
 * নাম মিলিয়ে দেখা যেত না: ভুলটা লেখার সময় নয়, **সমাধানের** সময়।
 * `getRelated()` ডাকলে PHP নামটা মেলাতে বাধ্য হয়, আর না পেলে ঠিক
 * সেই ব্যতিক্রমটাই ছোড়ে যেটা মালিক লাইভে দেখেছেন।
 *
 * ⓘ ডাটাবেস লাগে না — সম্পর্কটা তৈরি হয়, কোনো কোয়েরি চলে না।
 */
class ARelationPointedAtAClassThatWasNotThereTest extends TestCase
{
    /**
     * প্রতিটা মডেলের প্রতিটা সম্পর্ক সমাধান হয়।
     */
    public function test_every_relation_resolves_to_a_class_that_exists(): void
    {
        $checked = 0;
        $broken = [];

        foreach ($this->models() as $class) {
            $model = new $class;

            foreach ($this->relationMethods($class) as $method) {
                $checked++;

                try {
                    $model->{$method}()->getRelated();
                } catch (\Throwable $e) {
                    $broken[] = "{$class}::{$method}() — ".$e->getMessage();
                }
            }
        }

        /*
         * ⚠️ আগে গুনতি, তারপর দাবি — শূন্য সম্পর্কের উপর নিচের দাবিটা
         * আপনা থেকেই পাস করত, আর পাহারাটা চিরকাল অন্ধ থাকত।
         */
        $this->assertGreaterThan(100, $checked,
            "মাত্র {$checked}টা সম্পর্ক পাওয়া গেল — খোঁজাটা কি আর কাজ করছে?");

        $this->assertSame([], $broken, implode("\n", [
            'এই সম্পর্কগুলো এমন ক্লাসের দিকে দেখায় যেটা নেই:',
            ...$broken,
            '',
            'সাধারণত কারণ একটাই: ফাইলের মাথায় `use` লাইনটা নেই, তাই',
            'নামটা নিজের namespace-এ খোঁজা হচ্ছে।',
        ]));
    }

    /**
     * অ্যাপের প্রতিটা Eloquent মডেল।
     *
     * @return list<class-string<Model>>
     */
    private function models(): array
    {
        $found = [];

        foreach ([app_path('Models'), app_path('Modules')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $class = $this->classIn((string) $file);

                if ($class !== null
                    && class_exists($class)
                    && is_subclass_of($class, Model::class)
                    && ! (new ReflectionClass($class))->isAbstract()) {
                    $found[] = $class;
                }
            }
        }

        sort($found);

        return $found;
    }

    /** ফাইলের namespace + class নাম, ঘোষণা থেকেই পড়া। */
    private function classIn(string $path): ?string
    {
        $src = (string) file_get_contents($path);

        if (! preg_match('/^namespace\s+([^;]+);/m', $src, $ns)) {
            return null;
        }

        if (! preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $cl)) {
            return null;
        }

        return trim($ns[1]).'\\'.$cl[1];
    }

    /**
     * যে পাবলিক মেথডগুলো একটা সম্পর্ক ফেরায় — return type ধরে।
     *
     * ⓘ প্যারামিটার নেয় এমন মেথড বাদ: ওগুলো ডাকতে গেলে আন্দাজে মান
     * দিতে হত, আর তখন ব্যর্থতাটা আর সম্পর্কের কথা বলত না।
     *
     * @param  class-string<Model>  $class
     * @return list<string>
     */
    private function relationMethods(string $class): array
    {
        $names = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfParameters() > 0 || $method->isStatic()) {
                continue;
            }

            $type = $method->getReturnType();

            if ($type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && is_subclass_of($type->getName(), Relation::class)) {
                $names[] = $method->getName();
            }
        }

        return $names;
    }
}

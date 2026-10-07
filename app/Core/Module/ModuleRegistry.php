<?php

declare(strict_types=1);

namespace App\Core\Module;

use RuntimeException;

/**
 * app/Modules/ ফোল্ডারে যা আছে, সেটাই মডিউলের তালিকা।
 *
 * কোথাও হাতে লেখা তালিকা নেই — একটা ফোল্ডার আর তার module.php থাকলেই মডিউল
 * চেনা যায়। প্ল্যান সেকশন ১৯.৩: নতুন মডিউল কোর কোড না ছুঁয়েই যোগ হবে।
 *
 * নির্ভরতার ক্রম এখানেই ঠিক হয়, কারণ Sales-এর মাইগ্রেশন Accounts-এর টেবিল
 * তৈরি হওয়ার আগে চললে ফরেন কি বসবে না।
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleDefinition>|null */
    private ?array $modules = null;

    public function __construct(
        private readonly string $modulesPath,
        private readonly string $baseNamespace = 'App\\Modules',
    ) {}

    /**
     * নির্ভরতা অনুযায়ী সাজানো — যে মডিউল যার উপর নির্ভর করে, সে আগে।
     *
     * @return array<string, ModuleDefinition>
     */
    public function all(): array
    {
        return $this->modules ??= $this->sortByDependency($this->discover());
    }

    public function get(string $code): ?ModuleDefinition
    {
        return $this->all()[$code] ?? null;
    }

    public function has(string $code): bool
    {
        return isset($this->all()[$code]);
    }

    /** @return array<string, ModuleDefinition> */
    private function discover(): array
    {
        if (! is_dir($this->modulesPath)) {
            return [];
        }

        $found = [];

        foreach (scandir($this->modulesPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $file = $this->modulesPath.DIRECTORY_SEPARATOR.$entry.DIRECTORY_SEPARATOR.'module.php';

            // module.php ছাড়া ফোল্ডার মানে মডিউল এখনো তৈরি হয়নি — চুপচাপ বাদ,
            // কারণ ফোল্ডার আগে বানিয়ে পরে module.php লেখা স্বাভাবিক ক্রম।
            if (! is_file($file)) {
                continue;
            }

            $definition = ModuleDefinition::fromArray(
                require $file,
                $file,
                $this->baseNamespace.'\\'.$entry,
            );

            if (isset($found[$definition->code])) {
                throw new RuntimeException(
                    "Two modules claim the code '{$definition->code}': "
                    ."{$found[$definition->code]->path} and {$definition->path}."
                );
            }

            $found[$definition->code] = $definition;
        }

        return $found;
    }

    /**
     * @param  array<string, ModuleDefinition>  $modules
     * @return array<string, ModuleDefinition>
     */
    private function sortByDependency(array $modules): array
    {
        $sorted = [];
        $visiting = [];

        $visit = function (string $code) use (&$visit, &$sorted, &$visiting, $modules): void {
            if (isset($sorted[$code])) {
                return;
            }

            // একটা চক্র (A→B→A) বুট-টাইমে না ধরলে অসীম লুপে আটকে যাবে,
            // আর কারণটা খুঁজে বের করা কঠিন হবে।
            if (isset($visiting[$code])) {
                throw new RuntimeException(
                    'Circular module dependency: '.implode(' → ', [...array_keys($visiting), $code]).'.'
                );
            }

            $module = $modules[$code] ?? null;

            if ($module === null) {
                throw new RuntimeException(
                    "Module '{$code}' is required by another module but was not found in {$this->modulesPath}."
                );
            }

            $visiting[$code] = true;

            foreach ($module->dependsOn as $dependency) {
                $visit($dependency);
            }

            unset($visiting[$code]);
            $sorted[$code] = $module;
        };

        foreach (array_keys($modules) as $code) {
            $visit($code);
        }

        return $sorted;
    }
}

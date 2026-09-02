<?php

declare(strict_types=1);

namespace App\Core\Engines\Sync;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Module\ModuleRegistry;
use RuntimeException;

/**
 * কোন মডিউল কী সিঙ্ক করতে পারে — এক জায়গায় জড়ো করা।
 *
 * ── কেন ফোল্ডার দেখে, module.php-তে ঘোষণা করে নয় ────────────────────
 * এই রিপোর স্বাভাবিক ছাঁচ হলো মডিউল তার সব কিছু `module.php`-তে ঘোষণা
 * করে, আর [[ModuleDefinition]] বুট-টাইমে ভুল ধরে। সেটাই ভালো ছাঁচ।
 *
 * এখানে ফোল্ডার দেখা হচ্ছে **একটাই কারণে**: `ModuleDefinition.php`
 * এখন আরেকটা সেশনের হাতে (`sensitive_fields` manifest key), আর একই
 * ফাইলে দুইজন লিখলে একজনের কাজ হারাত। কারিগরি কারণ নয়, সমন্বয়ের কারণ।
 *
 * তবু **ঘোষণার ছাঁচের নিরাপত্তাটা এখানেও রাখা হয়েছে**: প্রতিটা পাওয়া
 * ক্লাস চুক্তি মানে কি না, আর দুইটা ক্লাস একই নাম দাবি করে কি না —
 * দুইটাই এখানে ধরা পড়ে, প্রথম ব্যবহারেই, ছয় মাস পরে একটা খালি সিঙ্ক
 * দেখে নয়।
 *
 * `sensitive_fields` কমিট হয়ে গেলে এটা `sync_sources` manifest key-তে
 * সরানো যাবে — তখন এই ফাইলের কেবল [[discover()]] বদলাবে, বাকিটা নয়।
 *
 * ── ছাঁচ ────────────────────────────────────────────────────────────
 *     app/Modules/{Module}/Sync/{Anything}.php
 * যে ক্লাসগুলো [[SyncsToDevices]] বাস্তবায়ন করে, কেবল সেগুলোই গোনা হয়।
 */
final class SyncRegistry
{
    /** @var array<string, SyncsToDevices>|null entityType => handler */
    private ?array $handlers = null;

    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * প্রতিটা হ্যান্ডলার, রেকর্ডের ধরন ধরে।
     *
     * @return array<string, SyncsToDevices>
     */
    public function all(): array
    {
        return $this->handlers ??= $this->discover();
    }

    /**
     * একটা মডিউলের হ্যান্ডলারগুলো।
     *
     * @return list<SyncsToDevices>
     */
    public function forModule(string $module): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (SyncsToDevices $handler) => $handler::module() === $module,
        ));
    }

    public function forEntityType(string $entityType): ?SyncsToDevices
    {
        return $this->all()[$entityType] ?? null;
    }

    /**
     * `GET /sync/capabilities` যা ফেরত দেয় — মডিউল ও ধরনের জোড়া।
     *
     * ফোন এই তালিকা থেকেই নিজের পরিকল্পনা বানায়, হাতে লেখা তালিকা থেকে
     * নয়। তাই সার্ভারে একটা হ্যান্ডলার যোগ বা বাদ দিলে **নতুন মোবাইল
     * রিলিজ ছাড়াই** সেটা কার্যকর হয় — আর ঠিক সেটাই দরকার, কারণ কোন
     * জিনিস অফলাইনে যাবে সেটা ব্যবসার সিদ্ধান্ত, আর সেটা বদলায়।
     *
     * @return list<array{module: string, entityType: string}>
     */
    public function capabilities(): array
    {
        $rows = array_map(
            fn (SyncsToDevices $handler) => [
                'module' => $handler::module(),
                'entityType' => $handler::entityType(),
            ],
            array_values($this->all()),
        );

        usort($rows, fn (array $a, array $b) => [$a['module'], $a['entityType']] <=> [$b['module'], $b['entityType']]);

        return $rows;
    }

    /** @return list<string> */
    public function modules(): array
    {
        $modules = array_map(
            fn (SyncsToDevices $handler) => $handler::module(),
            array_values($this->all()),
        );

        $modules = array_values(array_unique($modules));
        sort($modules);

        return $modules;
    }

    public function knowsModule(string $module): bool
    {
        return in_array($module, $this->modules(), true);
    }

    /** @return array<string, SyncsToDevices> */
    private function discover(): array
    {
        $found = [];

        foreach ($this->modules->all() as $definition) {
            $directory = dirname($definition->path).DIRECTORY_SEPARATOR.'Sync';

            if (! is_dir($directory)) {
                continue;
            }

            foreach (scandir($directory) ?: [] as $entry) {
                if (! str_ends_with($entry, '.php')) {
                    continue;
                }

                $class = $definition->namespace.'\\Sync\\'.substr($entry, 0, -4);

                if (! class_exists($class)) {
                    throw new RuntimeException(
                        "{$directory}/{$entry}: expected the class {$class}. A sync handler whose "
                        .'class name does not match its file name is never loaded, and the records '
                        .'it was written for would simply never reach a phone.'
                    );
                }

                if (! is_subclass_of($class, SyncsToDevices::class)) {
                    throw new RuntimeException(
                        "{$class} sits in a Sync/ directory but does not implement SyncsToDevices. "
                        .'Either implement the contract or move the class out — a file in this '
                        .'directory that is not a handler is a handler somebody thinks exists.'
                    );
                }

                /** @var SyncsToDevices $handler */
                $handler = app($class);
                $entityType = $handler::entityType();

                if (isset($found[$entityType])) {
                    throw new RuntimeException(
                        "Two handlers claim the entity type '{$entityType}': "
                        .$found[$entityType]::class." and {$class}. Entity types must be unique — "
                        .'a phone asks for a record by that name alone, so a second claim would '
                        .'silently shadow the first.'
                    );
                }

                $found[$entityType] = $handler;
            }
        }

        return $found;
    }
}

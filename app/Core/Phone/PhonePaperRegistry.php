<?php

declare(strict_types=1);

namespace App\Core\Phone;

use App\Core\Module\ModuleRegistry;

/**
 * ফোনের লেনদেন-তালিকার ধরনগুলো — প্রতিটা মডিউল নিজেরটা বলে (`module.php`-এর `phone_papers`; [[PhonePaper]])।
 *
 * <p>⛔ কোর কোনো মডিউলের নাম জানে না (§১৯.৭) — তাই তালিকা এখানে হাতে লেখা নয়; বন্ধ মডিউলের ধরন এখানে আসেই না।
 */
final class PhonePaperRegistry
{
    /** @var array<string, PhonePaper>|null */
    private ?array $papers = null;

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function find(string $key): ?PhonePaper
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, PhonePaper> */
    public function all(): array
    {
        if ($this->papers !== null) {
            return $this->papers;
        }

        $papers = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->phonePapers as $class) {
                $paper = app($class);
                $papers[$paper->key()] = $paper;
            }
        }

        return $this->papers = $papers;
    }
}

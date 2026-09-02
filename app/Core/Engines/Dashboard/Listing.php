<?php

declare(strict_types=1);

namespace App\Core\Engines\Dashboard;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * ছোট একটা তালিকা — "এই নয়টা পণ্য ফুরিয়ে আসছে"।
 *
 * ── কেন ড্যাশবোর্ডে তালিকাও থাকে ─────────────────────────────────────
 * সংখ্যা বলে **কতটা**, তালিকা বলে **কোনটা**। "৯টা পণ্য সীমার নিচে"
 * দেখে পরের প্রশ্ন সবসময় "কোন নয়টা", আর তার জন্য আরেকটা পর্দায় গেলে
 * প্রশ্নটা প্রায়ই আর করাই হয় না।
 *
 * ── কেন `href` লাগে ──────────────────────────────────────────────────
 * এখানে কয়েকটা সারিই দেখানো হয়, তাই **পুরোটা কোথায়** সেটা বলতেই হবে।
 * না বললে মানুষ ধরে নিতেন এগুলোই সব।
 */
final class Listing
{
    /**
     * @param  list<array{key: string, label: string, render: callable, width?: string}>  $columns
     * @param  Collection<int, mixed>  $rows
     */
    public function __construct(
        public readonly string $label,
        public readonly array $columns,
        public readonly Collection $rows,
        public readonly string $empty,
        public readonly ?string $href = null,
    ) {
        if ($columns === []) {
            throw new InvalidArgumentException("Listing '{$label}' has no columns.");
        }

        if (trim($empty) === '') {
            throw new InvalidArgumentException(
                "Listing '{$label}' has no empty message. An empty box with no words reads as a broken screen."
            );
        }
    }
}

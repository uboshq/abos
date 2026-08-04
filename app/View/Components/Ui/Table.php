<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use Illuminate\View\Component;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * তালিকা দেখানোর একমাত্র কম্পোনেন্ট — সেকশন ১৫.২৪ ও ২০.৩।
 *
 * কলামগুলো PHP-তে সংজ্ঞায়িত, Blade-এ <td> হাতে লেখা নয়। কারণ মোবাইলে
 * প্রতিটা সারি card হয়ে যায়, আর তখন প্রতিটা ঘরে data-label না থাকলে
 * ব্যবহারকারী শুধু কতগুলো সংখ্যা দেখে — কোনটা কী বোঝার উপায় থাকে না।
 *
 * হাতে লিখলে সেটা ভোলা সবচেয়ে সহজ, আর ডেস্কটপে টেস্ট করলে কখনো ধরা পড়ে
 * না। এখানে label ছাড়া কলাম তৈরিই করা যায় না — ভোলার সুযোগ নেই।
 */
class Table extends Component
{
    /** @var list<array{key: string, label: string, numeric: bool, width: ?string}> */
    public array $normalised = [];

    /**
     * @param  iterable<int, mixed>  $rows
     * @param  list<array<string, mixed>|string>  $columns
     */
    public function __construct(
        public iterable $rows = [],
        array $columns = [],
        public ?string $empty = null,
        public bool $compact = false,
    ) {
        foreach ($columns as $index => $column) {
            if (is_string($column)) {
                throw new InvalidArgumentException(
                    "Column {$index} is just a string. Every column needs a label, because on a phone the "
                    .'table header is hidden and the label is the only thing telling the reader what a '
                    .'value is.'
                );
            }

            if (! isset($column['key'], $column['label'])) {
                throw new InvalidArgumentException(
                    "Column {$index} needs both 'key' and 'label'."
                );
            }

            $this->normalised[] = [
                'key' => $column['key'],
                'label' => $column['label'],
                'numeric' => (bool) ($column['numeric'] ?? false),
                'width' => $column['width'] ?? null,
            ];
        }
    }

    public function render(): View
    {
        return view('components.ui.table');
    }

    /** একটা সারি থেকে একটা ঘরের মান — অ্যারে ও অবজেক্ট দুটোই চলে। */
    public function valueOf(mixed $row, string $key): mixed
    {
        return data_get($row, $key);
    }
}

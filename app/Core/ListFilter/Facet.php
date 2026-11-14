<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ⭐ ছাঁকনির একটা অংশ — "অবস্থা", "এলাকা", "বকেয়ার পরিমাণ"… (মালিক, ১৪ নভেম্বর ২০২৬: *"সেন্ট্রালি নিয়ন্ত্রিত হওয়াটা ভালো …
 *   সবগুলো স্ক্রিনেই সেমভাবে"*)।
 *
 * ⓘ তালিকা কেবল ঘোষণা দেয় কী কী অংশ; প্যানেল আঁকা, ঠিকানা পড়া, কোয়েরিতে বসানো, সংখ্যা গোনা আর চিপ — সব [[FilterSet]]-এর।
 * ⛔ কোনো অংশ ঠিকানার মান সরাসরি SQL-এ বসায় না: [[read()]] আগে চেনা মানে নামিয়ে আনে, অচেনা মান চুপচাপ বাদ।
 */
abstract class Facet
{
    /** ⓘ শুরুতে অংশটা খোলা কি না — চালু থাকলে সবসময় খোলা */
    public bool $open = true;

    /** ⭐ অংশের রং — প্যানেলের টালি, চালু অংশের দাগ আর চিপ একই রঙে ([[TONES]]; মালিকের অনুমোদিত নমুনা, ১৪ নভেম্বর ২০২৬) */
    public string $tone = 'blue';

    /** ⓘ টালির চিহ্ন — একটা অক্ষর বা প্রতীক (৳, ◈, ⌖ …), ছবি নয় */
    public string $icon = '●';

    /** ⓘ রঙের নাম — CSS-এ `.tone-<নাম>` (আলো আর অন্ধকার দুই থিমে আলাদা মান) */
    public const TONES = ['green', 'red', 'amber', 'slate', 'teal', 'violet', 'blue', 'pink', 'sky'];

    /** ⓘ ঘোষণায় এক লাইনে: `->look('red', '৳')` */
    public function look(string $tone, string $icon): static
    {
        $this->tone = in_array($tone, self::TONES, true) ? $tone : 'blue';
        $this->icon = $icon;

        return $this;
    }

    public function __construct(
        public readonly string $key,
        public readonly string $label,
    ) {}

    /** ⓘ প্যানেলের কোন ছাঁচে আঁকা হবে: choice · range · date · fields */
    abstract public function kind(): string;

    /**
     * ঠিকানা থেকে এই অংশের মান — চালু না থাকলে null।
     */
    abstract public function read(Request $request): mixed;

    /** কোয়েরিতে বসানো — [[read()]] যা দিয়েছে কেবল তা-ই */
    abstract public function apply(Builder $query, mixed $value): void;

    /**
     * চালু থাকলে চিপ — লেখা আর কোন চাবিগুলো মুছলে চিপটা যায়।
     *
     * @return list<array{text: string, drop: array<string, string|null>}>
     */
    abstract public function chips(mixed $value): array;

    /**
     * ঠিকানার যে চাবিগুলো এই অংশের — "সব মুছুন" এগুলোই মোছে।
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return [$this->key];
    }

    /** ⓘ ডিফল্ট মানে থাকলেও কোয়েরিতে বসাতে হয় (যেমন "চালু" গ্রাহক), কিন্তু সেটা চালু ছাঁকনি নয় */
    public function applied(Request $request): mixed
    {
        return $this->read($request);
    }
}

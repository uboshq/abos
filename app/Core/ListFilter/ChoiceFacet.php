<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ⭐ টিক দেওয়ার অংশ — "চালু / বন্ধ", "বকেয়া আছে / অগ্রিম আছে", এলাকার নাম… প্রতিটা ঘরের পাশে সংখ্যা।
 *
 * ⓘ একাধিক টিক = "যেকোনোটা" (ময়মনসিংহ **বা** শম্ভুগঞ্জ); আলাদা অংশগুলো একসাথে খাটে ("এবং")।
 * ⓘ একটাই বাছা যায় এমন অংশে (`multi: false`) গোল ঘর; ডিফল্ট মান থাকলে সেটা চুপচাপ খাটে, চিপ হয় না।
 * ⓘ পুরোনো লিংক (`?area=5`, `?quick=due`) আর নতুন (`?area[]=5&area[]=7`) দুটোই পড়ে।
 */
final class ChoiceFacet extends Facet
{
    /** ⓘ এর বেশি ঘর হলে অংশের মাথায় খোঁজার ঘর */
    public const FIND_FROM = 8;

    /**
     * @param  array<string, array{label: string, apply: Closure(Builder): mixed, tone?: string, chip?: string}>  $options  ⓘ `tone` দিলে ঘরের বিন্দু আর চিপ সেই রঙে (যেমন "বকেয়া" লাল); `chip` দিলে চিপে পুরো এই লেখা
     * @param  array<string, string>  $legacy  পুরোনো চাবি => যে ঘর বোঝায় — নতুন চাবি না থাকলে খাটে (`['inactive' => 'all']`); চিপ মুছলে সেটাও যায়
     */
    public function __construct(
        string $key,
        string $label,
        public readonly array $options,
        public readonly bool $multi = true,
        public readonly ?string $default = null,
        public readonly bool $counted = true,
        public readonly array $legacy = [],
        /** ⓘ সব ঘরের সংখ্যা এক কোয়েরিতে — দিলে ঘরপ্রতি আলাদা COUNT হয় না ([[Places]]) */
        public readonly ?Closure $countAll = null,
    ) {
        parent::__construct($key, $label);
    }

    public function kind(): string
    {
        return 'choice';
    }

    public function keys(): array
    {
        return [$this->key, ...array_keys($this->legacy)];
    }

    /** @return list<string>|null */
    public function read(Request $request): mixed
    {
        $raw = $request->query($this->key);

        if (($raw === null || $raw === '') && $this->legacy !== []) {
            foreach ($this->legacy as $old => $means) {
                if ($request->boolean($old)) {
                    $raw = $means;
                    break;
                }
            }
        }
        $picked = is_array($raw) ? $raw : (is_scalar($raw) && (string) $raw !== '' ? [(string) $raw] : []);
        $picked = array_values(array_unique(array_filter(
            array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $picked),
            fn (string $v) => $v !== '' && array_key_exists($v, $this->options),
        )));

        if (! $this->multi) {
            $picked = array_slice($picked, 0, 1);
        }

        // ⓘ ডিফল্ট নিজেই বাছা থাকলে সেটা চালু ছাঁকনি নয়
        if ($this->default !== null && $picked === [$this->default]) {
            return null;
        }

        return $picked === [] ? null : $picked;
    }

    /** @return list<string>|null */
    public function applied(Request $request): mixed
    {
        return $this->read($request) ?? ($this->default !== null ? [$this->default] : null);
    }

    /** @param  list<string>  $value */
    public function apply(Builder $query, mixed $value): void
    {
        if (count($value) === 1) {
            ($this->options[$value[0]]['apply'])($query);

            return;
        }

        $query->where(function (Builder $any) use ($value): void {
            foreach ($value as $v) {
                $any->orWhere(fn (Builder $one) => ($this->options[$v]['apply'])($one));
            }
        });
    }

    /** @param  list<string>  $value */
    public function chips(mixed $value): array
    {
        return array_map(fn (string $v) => [
            'text' => $this->options[$v]['chip'] ?? $this->label.': '.$this->options[$v]['label'],
            'drop' => [$this->key => $v] + array_fill_keys(array_keys($this->legacy), null),
            'tone' => $this->options[$v]['tone'] ?? $this->tone,
        ], $value);
    }

    /** ⓘ একটা ঘরের সংখ্যা — বাকি অংশগুলো খাটানো কোয়েরিতে এই ঘরটা বসিয়ে */
    public function countOption(Builder $base, string $option): int
    {
        $query = clone $base;
        ($this->options[$option]['apply'])($query);

        return $query->count();
    }

    /**
     * ⓘ একটাই ঘরের টিক ("বাতিলসহ", "কেবল আমার") — ঠিকানায় পুরোনো একক চাবি (`?cancelled=1`), অ্যারে নয়।
     *   ⛔ অ্যারে হলে তালিকার `$request->boolean('cancelled')` মিথ্যা পড়ত আর টিকটা কিছুই করত না।
     */
    public function flat(): bool
    {
        return $this->multi && count($this->options) === 1;
    }

    public function findable(): bool
    {
        return count($this->options) > self::FIND_FROM;
    }
}

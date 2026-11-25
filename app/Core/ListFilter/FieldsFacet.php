<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use App\Core\Support\DateFormat;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ⭐ কয়েকটা সাধারণ ঘরের অংশ — যেমন "সেরা / দুর্বল": কোনটা, কতজন, কী মাপে, কোন সময়ে।
 *
 * ⓘ সংখ্যা গোনা হয় না (টিকের ঘর নয়); প্রথম ঘরটা ([[$trigger]]) ভরা থাকলে তবেই অংশটা চালু।
 *
 * ⓘ ঘরের ধরন: select · number · date · text (লেখা, যেমন "রেফারেন্স") · find (লিখে-খোঁজা, নিচে নামের তালিকা — datalist;
 *   রিপোর্টের গুদাম/পণ্য/দোকান: লেখা যায় "কোড · নাম", সার্ভার নম্বরে বদলায়)।
 *
 * @phpstan-type Field array{key: string, label: string, type: 'select'|'number'|'date'|'month'|'text'|'find', options?: array<string, string>, placeholder?: string, min?: int, max?: int}
 */
final class FieldsFacet extends Facet
{
    /**
     * @param  list<Field>  $fields
     * @param  Closure(Builder, array<string, string>): mixed  $applyFields
     * @param  Closure(array<string, string>): string  $chipText
     */
    public function __construct(
        string $key,
        string $label,
        public readonly array $fields,
        private readonly Closure $applyFields,
        private readonly Closure $chipText,
    ) {
        parent::__construct($key, $label);
        $this->open = false;
    }

    public function kind(): string
    {
        return 'fields';
    }

    public function keys(): array
    {
        return array_column($this->fields, 'key');
    }

    /** @return array<string, string>|null */
    public function read(Request $request): mixed
    {
        $values = [];

        foreach ($this->fields as $field) {
            $raw = $request->query($field['key']);

            if (! is_scalar($raw) || (string) $raw === '') {
                continue;
            }

            $raw = (string) $raw;

            if ($field['type'] === 'select' && ! array_key_exists($raw, $field['options'] ?? [])) {
                continue;
            }

            // ⛔ তারিখ বা মাস নয় এমন লেখা ঘরে বসে না — চিপ হয় না, আর সাজাতে গিয়ে পাতা ৫০০ দেয় না
            $raw = match ($field['type']) {
                'date' => DateFormat::plainDay($raw),
                'month' => DateFormat::plainMonth($raw),
                default => $raw,
            };

            if ($raw === null) {
                continue;
            }

            $values[$field['key']] = $raw;
        }

        return array_key_exists($this->fields[0]['key'], $values) ? $values : null;
    }

    /** @param  array<string, string>  $value */
    public function apply(Builder $query, mixed $value): void
    {
        ($this->applyFields)($query, $value);
    }

    /** @param  array<string, string>  $value */
    public function chips(mixed $value): array
    {
        return [[
            'text' => ($this->chipText)($value),
            'drop' => array_fill_keys($this->keys(), null),
            'tone' => $this->tone,
        ]];
    }
}

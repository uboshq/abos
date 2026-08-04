<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * একটা রিপোর্টের সংজ্ঞা — কোয়েরি, কলাম, ফিল্টার।
 *
 * প্ল্যান সেকশন ২.২: রিপোর্ট engine "কোয়েরি + কলাম + ফিল্টার কনফিগে;
 * রেন্ডার এক জায়গায়"। ৩০+ রিপোর্টে একই কোড বারবার লেখার বদলে প্রতিটা
 * রিপোর্ট শুধু বলে দেয় সে কী চায়।
 *
 * কোয়েরিটা Closure, কারণ ফিল্টারের মান রান-টাইমে আসে — আগেই তৈরি করে
 * রাখা Builder-এ কোম্পানির স্কোপ ভুল সময়ে বসে যেত।
 */
final class ReportDefinition
{
    /** @var list<ReportColumn> */
    public readonly array $columns;

    /**
     * @param  Closure(array<string, mixed>): (QueryBuilder|EloquentBuilder)  $query
     * @param  list<array<string, mixed>>  $columns
     * @param  list<string>  $filters  কোন ফিল্টারগুলো এই রিপোর্টে আছে
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly Closure $query,
        array $columns,
        public readonly array $filters = ['date_range'],
        public readonly ?string $groupBy = null,
        public readonly bool $runningBalance = false,
    ) {
        $this->columns = array_map(
            fn (array $column, int $index) => ReportColumn::fromArray($column, $index),
            $columns,
            array_keys($columns),
        );
    }

    /** @return list<ReportColumn> */
    public function totalledColumns(): array
    {
        return array_values(array_filter($this->columns, fn (ReportColumn $c) => $c->total));
    }

    public function hasFilter(string $name): bool
    {
        return in_array($name, $this->filters, true);
    }
}

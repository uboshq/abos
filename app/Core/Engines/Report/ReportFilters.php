<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

use App\Core\Contracts\ReportFilterSource;
use App\Core\Module\ModuleRegistry;
use Illuminate\Validation\ValidationException;

/**
 * রিপোর্টের সাধারণ ছাঁকনি — কোন চাবির বাছাই-তালিকা কে দেয়, আর ঠিকানার মান যাচাই (রিপোর্ট সেন্টার ধাপ ১)।
 *
 * ── ⛔ কেন লাগল ─────────────────────────────────────────────────────
 * নতুন রিপোর্টগুলো গুদাম, পণ্য, ব্র্যান্ড… ছাঁকনি ঘোষণা করত, কিন্তু পর্দা আঁকত কেবল তারিখ আর শাখা — বাকিগুলো
 * চলত কেবল হাতে লেখা ঠিকানায়। আর ঠিকানায় যেকোনো নম্বর বসত, যাচাই ছাড়া।
 *
 * ── ⭐ এখন ──────────────────────────────────────────────────────────
 * প্রতিটা চাবির উৎস যে মডিউলের জিনিস সে ঘোষণা করে (module.php `report_filters`); পর্দা আঁকে, আর ইঞ্জিন প্রতিটা
 * মান উৎস দিয়ে মেলায় ([[resolve()]]) — ⛔ তালিকার বাইরের নম্বর (অন্যের দোকান) এলে রিপোর্টই ফেরে, ছাঁকনি ফেলে
 * গোটা তালিকা দেখানো হয় না।
 */
final class ReportFilters
{
    /** @var array<string, ReportFilterSource>|null */
    private ?array $sources = null;

    public function __construct(private readonly ModuleRegistry $modules) {}

    /** @return array<string, ReportFilterSource> চাবি → উৎস */
    public function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $out = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->reportFilters as $key => $class) {
                $out[$key] = app($class);
            }
        }

        return $this->sources = $out;
    }

    public function for(string $key): ?ReportFilterSource
    {
        return $this->sources()[$key] ?? null;
    }

    /**
     * রিপোর্টের ঘোষিত প্রতিটা উৎস-ছাঁকনির মান — নম্বরে, যাচাই করে।
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function resolve(ReportDefinition $report, array $filters): array
    {
        foreach ($report->filters as $key) {
            $source = $this->for($key);
            $raw = $filters[$key] ?? null;

            if ($source === null || $raw === null || $raw === '') {
                continue;
            }

            /*
             * ⛔ অ্যারে ফেরত, নীরবে ফেলা নয় — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬।
             *
             * ⚠️ আগে `warehouse_id[]=7` এখানে চুপচাপ পার হত, আর রিপোর্টের কোয়েরি
             * `(int) $f['warehouse_id']` লিখে অ্যারেটাকে **১** বানাত — অর্থাৎ যাচাই ছাড়াই
             * গুদাম ১-এর মজুদ, যেটা হয়তো অন্য দোকানের। ⓘ একটা ঘরে একটাই মান।
             */
            $id = is_scalar($raw) ? $source->resolve((string) $raw) : null;

            if ($id === null) {
                throw ValidationException::withMessages([
                    $key => __('core.report.filter_not_allowed', ['filter' => __($source->label())]),
                ]);
            }

            $filters[$key] = $id;
        }

        return $filters;
    }
}

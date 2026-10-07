<?php

declare(strict_types=1);

namespace App\Core\Metrics;

use App\Core\Module\ModuleRegistry;
use InvalidArgumentException;

/**
 * সব মডিউলের সংখ্যাগুলো এক জায়গায় — কে কী গোনে তার একমাত্র তালিকা।
 *
 * ── যে ভুলটা এটা আটকায় ──────────────────────────────────────────────
 * "আজকের বিক্রয়" চার জায়গায় হিসাব হত: ড্যাশবোর্ড, POS, রিপোর্ট, শিফট।
 * একবার তারা দুইটা আলাদা উত্তর দিয়েছিল — একজন খসড়াও গুনত, অন্যজন নয়।
 * ধরে-রাখা একটা বিলের টাকা ক্যাশিয়ারের শিফটে দেখা যেত, দিনশেষে হাতের
 * নগদ কম পড়ত, আর কেউ বুঝত না কেন।
 *
 * এখন প্রশ্নটার একটাই উত্তর, আর উত্তরটা কোথা থেকে এলো সেটাও লেখা।
 *
 * ── কেন প্রতিবার নতুন করে জিজ্ঞেস করা হয় ───────────────────────────
 * `Metric`-এর ভেতরের callable সংখ্যাটা **তখনই** গোনে যখন ডাকা হয়।
 * বুট-টাইমে গুনে রাখলে একই অনুরোধের ভেতরে বিক্রয় হলে পুরনো সংখ্যাটাই
 * থেকে যেত।
 */
class MetricRegistry
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * চাবি ধরে একটা সংখ্যা।
     *
     * অজানা চাবি মানে ছাপার ভুল বা মুছে ফেলা মেট্রিক — নীরবে শূন্য
     * ফেরালে পর্দায় "০ টাকা" বসত, আর শূন্য একটা বিশ্বাসযোগ্য উত্তর।
     */
    public function get(string $key): Metric
    {
        $all = $this->all();

        if (! isset($all[$key])) {
            throw new InvalidArgumentException(
                "No metric '{$key}' is declared. Declared: ".(
                    $all === [] ? '(none)' : implode(', ', array_keys($all))
                )
            );
        }

        return $all[$key];
    }

    /**
     * সব মডিউলের সব সংখ্যা।
     *
     * ── দুই মডিউল একই চাবি দাবি করলে ────────────────────────────────
     * চুপচাপ একটা আরেকটাকে চাপা দিলে পর্দায় অন্য মডিউলের সংখ্যা বসত,
     * আর লেবেলটা থাকত প্রথমটার — ঠিক যে ধরনের ভুল কেউ ধরে না।
     *
     * @return array<string, Metric>
     */
    public function all(): array
    {
        $metrics = [];
        $owner = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->metrics as $provider) {
                foreach ($provider::metrics() as $metric) {
                    if (isset($metrics[$metric->key])) {
                        throw new InvalidArgumentException(
                            "Two modules declare the metric '{$metric->key}': "
                            ."{$owner[$metric->key]} and {$module->code}."
                        );
                    }

                    $metrics[$metric->key] = $metric;
                    $owner[$metric->key] = $module->code;
                }
            }
        }

        return $metrics;
    }
}

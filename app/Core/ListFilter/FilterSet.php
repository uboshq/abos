<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ⭐ ছাঁকনি ইঞ্জিন — একটা তালিকার সব অংশ একসাথে (মালিক, ১৪ নভেম্বর ২০২৬: *"সেন্ট্রালি নিয়ন্ত্রিত হওয়াটা ভালো … ফিল্টারগুলো
 *   সবগুলো স্ক্রিনেই সেমভাবে"*; নমুনা: claude.ai/artifact/7UoyiGpYFqJCeuL3dMFveR)।
 *
 * ── কে কী করে ─────────────────────────────────────────────────────────────
 *   তালিকা      কেবল ঘোষণা: কোন কোন অংশ ([[Facet]]), আর প্রতিটা অংশ কোয়েরিতে কী বসায়।
 *   এই ইঞ্জিন    ঠিকানা পড়া, কোয়েরিতে বসানো, প্রতিটা ঘরের সংখ্যা, চিপ, "সব মুছুন", প্যানেলের তথ্য।
 *   প্যানেল      `x-ui.filter-panel` — একটাই ছাঁচ, সব তালিকায় ([[filter-layout]])।
 *
 * ── সংখ্যা কীভাবে গোনা হয় ─────────────────────────────────────────────────
 *   একটা ঘরের পাশের সংখ্যা = "এটা বাছলে কতজন আসবে": তালিকার নিজের কোয়েরি (শাখা, দেয়াল, খোঁজা সব সহ) + **বাকি** অংশগুলো +
 *   এই ঘরটা। ⓘ নিজের অংশটা বাদ — নাহলে "ময়মনসিংহ" বাছার পর "শম্ভুগঞ্জ"-এর পাশে ০ দেখাত, অথচ টিক দিলে আসে।
 *   ⛔ গোনা হয় **একই** কোয়েরিতে যেটা মানুষটা দেখতে পান — বিক্রয়কর্মী নিজের দেয়ালের বাইরের দোকানের সংখ্যাও পান না।
 */
final class FilterSet
{
    /** ⓘ এর বেশি ঘরের অংশে সংখ্যা গোনা হয় না — প্রতিটা ঘর একটা COUNT, পাতা ধীর হত */
    public const COUNT_UP_TO = 60;

    /** @var list<Facet> */
    public readonly array $facets;

    /** @param  list<Facet>  $facets */
    public function __construct(array $facets)
    {
        $this->facets = $facets;
    }

    /** কোয়েরিতে সব চালু অংশ বসানো (ডিফল্ট মানসহ) */
    public function apply(Builder $query, Request $request): void
    {
        foreach ($this->facets as $facet) {
            $value = $facet->applied($request);

            if ($value !== null) {
                $facet->apply($query, $value);
            }
        }
    }

    /**
     * প্রতিটা টিকের ঘরের সংখ্যা।
     *
     * @param  Closure(): Builder  $base  তালিকার কোয়েরি, ছাঁকনি ছাড়া (শাখা, দেয়াল, খোঁজা সহ)
     * @return array<string, array<string, int>>
     */
    public function counts(Closure $base, Request $request): array
    {
        $counts = [];

        foreach ($this->facets as $facet) {
            if (! $facet instanceof ChoiceFacet || ! $facet->counted || count($facet->options) > self::COUNT_UP_TO) {
                continue;
            }

            $query = $base();

            foreach ($this->facets as $other) {
                if ($other === $facet) {
                    continue;
                }

                $value = $other->applied($request);

                if ($value !== null) {
                    $other->apply($query, $value);
                }
            }

            foreach (array_keys($facet->options) as $option) {
                $counts[$facet->key][(string) $option] = $facet->countOption($query, (string) $option);
            }
        }

        return $counts;
    }

    /**
     * চালু ছাঁকনির চিপ — প্রতিটায় নিজেকে মোছার ঠিকানা।
     *
     * @return list<array{text: string, url: string}>
     */
    public function chips(Request $request): array
    {
        $chips = [];

        foreach ($this->facets as $facet) {
            $value = $facet->read($request);

            if ($value === null) {
                continue;
            }

            foreach ($facet->chips($value) as $chip) {
                $chips[] = ['text' => $chip['text'], 'url' => $this->urlWithout($request, $chip['drop'])];
            }
        }

        return $chips;
    }

    public function activeCount(Request $request): int
    {
        return count($this->chips($request));
    }

    /** "সব মুছুন" — এই তালিকার ছাঁকনির সব চাবি বাদ; খোঁজা, সাজানো, দৃশ্য থাকে */
    public function clearUrl(Request $request): string
    {
        return $this->urlWithout($request, array_fill_keys($this->keys(), null));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (Facet $f) => $f->keys(), $this->facets))));
    }

    /**
     * প্যানেলের তথ্য — [[filter-panel]] যা আঁকে।
     *
     * @param  array<string, array<string, int>>  $counts
     * @return list<array<string, mixed>>
     */
    public function sections(Request $request, array $counts = []): array
    {
        $sections = [];

        foreach ($this->facets as $facet) {
            $value = $facet->read($request);
            $section = [
                'kind' => $facet->kind(),
                'key' => $facet->key,
                'label' => $facet->label,
                'active' => $value === null ? 0 : count($facet->chips($value)),
                'open' => $facet->open || $value !== null,
            ];

            if ($facet instanceof ChoiceFacet) {
                $shown = $facet->applied($request) ?? [];
                $section += [
                    'multi' => $facet->multi,
                    'findable' => $facet->findable(),
                    'options' => array_map(fn (string $option) => [
                        'value' => $option,
                        'label' => $facet->options[$option]['label'],
                        'count' => $counts[$facet->key][$option] ?? null,
                        'checked' => in_array($option, $shown, true),
                    ], array_map('strval', array_keys($facet->options))),
                ];
            } elseif ($facet instanceof RangeFacet) {
                $section += ['min' => $value['min'] ?? null, 'max' => $value['max'] ?? null];
            } elseif ($facet instanceof DateRangeFacet) {
                $section += [
                    'from_key' => $facet->fromKey(), 'to_key' => $facet->toKey(),
                    'from' => $value['from'] ?? null, 'to' => $value['to'] ?? null,
                ];
            } elseif ($facet instanceof FieldsFacet) {
                $section += ['fields' => array_map(fn (array $field) => $field + [
                    'value' => is_scalar($request->query($field['key'])) ? (string) $request->query($field['key']) : '',
                ], $facet->fields)];
            }

            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * এখনকার ঠিকানা, কিছু চাবি বা মান বাদে — পাতা নম্বরও বাদ (ছাঁকনি বদলালে প্রথম পাতা)।
     *
     * @param  array<string, string|null>  $drop  চাবি => বাদ দেওয়ার মান (null হলে পুরো চাবি)
     */
    private function urlWithout(Request $request, array $drop): string
    {
        $query = $request->query();
        unset($query['page']);

        foreach ($drop as $key => $value) {
            if ($value === null || ! is_array($query[$key] ?? null)) {
                unset($query[$key]);

                continue;
            }

            $query[$key] = array_values(array_filter($query[$key], fn ($v) => (string) $v !== $value));

            if ($query[$key] === []) {
                unset($query[$key]);
            }
        }

        // ⓘ একক মানের পুরোনো লিংক (`?quick=due`) — চাবিটাই যায়
        $query = array_filter($query, fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $request->url().($query === [] ? '' : '?'.http_build_query($query));
    }
}

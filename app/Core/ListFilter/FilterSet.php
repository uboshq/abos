<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use App\Core\Support\DateFormat;
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

    /** @var list<ListCard> */
    public readonly array $cards;

    /**
     * @param  list<Facet>  $facets
     * @param  list<ListCard>  $cards  উপরের রঙিন কার্ড — চারটা পর্যন্ত
     */
    public function __construct(array $facets, array $cards = [])
    {
        $this->facets = $facets;
        $this->cards = array_slice($cards, 0, 4);
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

    /** ⓘ একটা অংশ বাদে বাকি সব — যেমন ধাপের পটি: অন্য ছাঁকনি মানে, নিজের অবস্থারটা নয় */
    public function applyExcept(Builder $query, Request $request, string $key): void
    {
        foreach ($this->facets as $facet) {
            if ($facet->key === $key) {
                continue;
            }

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
            if (! $facet instanceof ChoiceFacet || ! $facet->counted || ($facet->countAll === null && count($facet->options) > self::COUNT_UP_TO)) {
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

            if ($facet->countAll !== null) {
                $counts[$facet->key] = ($facet->countAll)($query);

                continue;
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
     * @return list<array{text: string, url: string, tone: string}>
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
                $chips[] = ['text' => $chip['text'], 'url' => $this->urlWithout($request, $chip['drop']), 'tone' => $chip['tone'] ?? $facet->tone,
                    // ⓘ বারের "📅 তারিখ" বোতামে যে তারিখ লেখা, সেটা চিপ হয়ে আবার আসে না ([[Panel::of()]])
                    'bar' => $facet instanceof DateRangeFacet && $facet->onBar];
            }
        }

        return $chips;
    }

    /**
     * ⭐ ছাঁকনি মেনে তালিকায় কতটা — প্যানেলের "Nটা দেখুন" বোতাম চাপার আগেই জানায় (নমুনার নিচের বোতাম)।
     *
     * @param  Closure(): Builder  $base
     */
    public function total(Closure $base, Request $request): int
    {
        $query = $base();
        $this->apply($query, $request);

        return $query->count();
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

    /**
     * ⭐ চটজলদি তারিখের লিংক — আজ, গতকাল, গত ৭ দিন, এই মাস, গত মাস, গত ৯০ দিন, যেকোনো তারিখ (পরিকল্পনা §৩.৩)।
     *
     * ⓘ প্রতিটা একটা সাধারণ ঠিকানা: বাকি ছাঁকনি, খোঁজা, সাজানো অটুট, কেবল এই অংশের দুই চাবি বদলায়, পাতা নম্বর বাদ।
     *
     * @return list<array{key: string, label: string, url: string, on: bool}>
     */
    public function presets(Request $request, DateRangeFacet $facet): array
    {
        $value = $facet->read($request);
        $current = DateRangeFacet::presetOf($value);
        $links = [];

        foreach (DateRangeFacet::PRESETS as $preset) {
            $range = DateRangeFacet::presetRange($preset);
            $links[] = [
                'key' => $preset,
                'label' => __('core.list_filter.preset.'.$preset),
                'url' => $this->urlWith($request, [$facet->fromKey() => $range['from'], $facet->toKey() => $range['to']]),
                'on' => $current === $preset,
            ];
        }

        // ⓘ পাতার বাড়তি পরিসর ("শুরু থেকে") চালু থাকলে সাধারণ নামগুলো চালু দেখায় না — একই সময়ে দুটো টিক নয়
        $extraOn = array_filter($facet->extra, fn (array $e) => $e['on']) !== [];

        if ($extraOn) {
            $links = array_map(fn (array $link) => ['on' => false] + $link, $links);
        }

        foreach ($facet->extra as $extra) {
            $links[] = [
                'key' => $extra['key'],
                'label' => $extra['label'],
                'url' => $this->urlWith($request, $extra['set']),
                'on' => $extra['on'],
            ];
        }

        $links[] = [
            'key' => 'any',
            'label' => __('core.list_filter.preset.any'),
            'url' => $this->urlWithout($request, [$facet->fromKey() => null, $facet->toKey() => null]),
            'on' => $value === null,
        ];

        return $links;
    }

    /**
     * ⭐ বারের "📅 তারিখ ▾" — লেনদেনের তালিকার তারিখের অংশ ([[DateRangeFacet::onTheBar()]]); নেই তো null।
     *
     * ⓘ বোতামের লেখা: চটজলদি নামে মিললে সেই নাম ("এই মাস"), নিজে বাছা পরিসর হলে তারিখ দুটো, কিছু না থাকলে null (বোতাম বলে "তারিখ")।
     *
     * @return array{label: string, text: ?string, presets: list<array{key: string, label: string, url: string, on: bool}>}|null
     */
    public function bar(Request $request): ?array
    {
        foreach ($this->facets as $facet) {
            if (! $facet instanceof DateRangeFacet || ! $facet->onBar) {
                continue;
            }

            $value = $facet->read($request);
            $preset = DateRangeFacet::presetOf($value);
            $extra = array_values(array_filter($facet->extra, fn (array $e) => $e['on']))[0] ?? null;

            $text = match (true) {
                $extra !== null => $extra['label'],
                $value === null => null,
                $preset !== null => __('core.list_filter.preset.'.$preset),
                default => ($value['from'] !== null ? DateFormat::format($value['from']) : '…')
                    .' – '.($value['to'] !== null ? DateFormat::format($value['to']) : '…'),
            };

            return ['label' => __('core.list_filter.date'), 'text' => $text, 'presets' => $this->presets($request, $facet)];
        }

        return null;
    }

    /**
     * এখনকার ঠিকানা, কিছু চাবি বসিয়ে — পাতা নম্বর বাদ।
     *
     * @param  array<string, string>  $set
     */
    private function urlWith(Request $request, array $set): string
    {
        $query = array_merge($request->query(), $set);
        unset($query['page']);

        return $request->url().'?'.http_build_query($query);
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
                'tone' => $facet->tone,
                'icon' => $facet->icon,
            ];

            if ($facet instanceof ChoiceFacet) {
                $shown = $facet->applied($request) ?? [];
                $section += [
                    'multi' => $facet->multi,
                    // ⓘ একটাই টিকের ঘর ("বাতিলসহ") পুরোনো চাবিতেই যায় — `cancelled=1`, `cancelled[]=1` নয়; তালিকা `boolean()` পড়ে
                    'name' => $facet->flat() ? $facet->key : $facet->key.($facet->multi ? '[]' : ''),
                    'default' => $facet->default,
                    'findable' => $facet->findable(),
                    'options' => array_map(fn (string $option) => [
                        'value' => $option,
                        'label' => $facet->options[$option]['label'],
                        'count' => $counts[$facet->key][$option] ?? null,
                        'checked' => in_array($option, $shown, true),
                        'tone' => $facet->options[$option]['tone'] ?? null,
                    ], array_map('strval', array_keys($facet->options))),
                ];
            } elseif ($facet instanceof RangeFacet) {
                $section += ['min' => $value['min'] ?? null, 'max' => $value['max'] ?? null];
            } elseif ($facet instanceof DateRangeFacet) {
                // ⓘ বাড়তি পরিসর চালু ("শুরু থেকে", `from=all`) — "থেকে" ঘরে তারিখ নয়, সেই মানটাই থাকে; নাহলে "দেখান" চাপলে হারাত
                $fixed = array_values(array_filter($facet->extra, fn (array $e) => $e['on']))[0] ?? null;
                $section += [
                    'fixed_from' => $fixed !== null && isset($fixed['set'][$facet->fromKey()])
                        ? ['value' => $fixed['set'][$facet->fromKey()], 'label' => $fixed['label']] : null,
                    'from_key' => $facet->fromKey(), 'to_key' => $facet->toKey(),
                    'from' => $value['from'] ?? null, 'to' => $value['to'] ?? null,
                    'presets' => $this->presets($request, $facet),
                ];
            } elseif ($facet instanceof FieldsFacet) {
                // ⓘ ঠিকানায় না থাকলে পাতার মানা মান (`default`, যেমন স্থিতিপত্রের "আজ") — ঘরে দেখায়, চিপ হয় না
                $section += ['fields' => array_map(fn (array $field) => $field + [
                    'value' => is_scalar($request->query($field['key'])) && (string) $request->query($field['key']) !== ''
                        ? (string) $request->query($field['key']) : (string) ($field['default'] ?? ''),
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

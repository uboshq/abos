@props(['document', 'ability' => 'view'])

{{--
    ⭐ সংশোধনের ইতিহাস — পোস্ট হওয়া কাগজের প্রতিটা সংশোধন, আগে আর পরে (মালিক, ৩ অক্টোবর ২০২৬)।

    ⓘ যেকোনো কাগজের পাতা এক লাইনে বসায়: <x-ui.revisions :document="$voucher" />
    কোনো মডিউলের নাম এখানে নেই — কাগজটা নিজেই বলে সে কে ([[DocumentRevision::scopeForDocument()]])।

    ── ⛔ কে দেখেন ────────────────────────────────────────────────────────
    যিনি কাগজটা দেখতে পারেন (`can('view', $document)`), কেবল তিনিই। ⚠️ নীতি না থাকলে উত্তর "না" —
    অর্থাৎ বন্ধ, খোলা নয়। অন্য অনুমতি লাগলে `ability` দিন।

    ── ⓘ মালিকের পর্দা ডান দিক কাটে (১ অক্টোবর ২০২৬) ─────────────────────
    চওড়া টেবিল নেই: প্রতিটা ঘর এক লাইনে নাম, তার নিচে "আগে" আর "পরে" — ছোট পর্দায় একটার নিচে
    আরেকটা, চওড়া পর্দায় পাশাপাশি। বদলানো ঘর রঙে আলাদা, আর অপরিবর্তিতগুলো গুটানো থাকে।

    ⚠️ সংশোধন না থাকলে কিছুই আঁকা হয় না — প্রতিটা কাগজে একটা খালি বাক্স কেবল ভিড় বাড়াত।
--}}
@php
    $mayView = auth()->user()?->can($ability, $document) ?? false;

    $revisions = $mayView
        ? \App\Models\DocumentRevision::query()->forDocument($document)->with('editor')->orderByDesc('revision_no')->get()
        : collect();

    $ways = [
        \App\Models\DocumentDelivery::PRINTED => 'core.print.way_printed',
        \App\Models\DocumentDelivery::DOWNLOADED => 'core.print.way_downloaded',
        \App\Models\DocumentDelivery::SHARED => 'core.print.way_shared',
        \App\Models\DocumentDelivery::OPENED => 'core.print.way_opened',
    ];
@endphp

@if ($revisions->isNotEmpty())
    <section data-boxed data-revisions
             class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('revision.title') }}
        </h2>

        <ol class="divide-y divide-(--color-border)">
            @foreach ($revisions as $revision)
                @php $diff = \App\Core\Support\RevisionDiff::of($revision); @endphp

                <li data-revision="{{ $revision->revision_no }}" class="px-4 py-3">
                    <details @if ($loop->first) open @endif>
                        <summary class="cursor-pointer text-sm">
                            <span class="font-semibold">{{ __('revision.revision_no', ['n' => $revision->revision_no]) }}</span>
                            <span class="block text-2xs text-(--color-ink-muted)">
                                {{ __('revision.by') }}: {{ $revision->editor?->name ?? '—' }}
                            </span>
                            <span class="block text-2xs text-(--color-ink-muted)">
                                {{ __('revision.when') }}: {{ \App\Core\Support\DateFormat::formatWithTime($revision->edited_at) }}
                            </span>
                            <span class="block text-2xs">
                                {{ __('revision.reason') }}: {{ $revision->reason }}
                            </span>
                        </summary>

                        @if (is_array($revision->printed_before))
                            @php $out = $revision->printed_before; @endphp
                            <div data-printed-before="{{ $out['delivery_public_id'] ?? '' }}"
                                 class="mt-3 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-2xs
                                        text-(--color-badge-warning-ink)">
                                <p>
                                    {{ __('revision.printed_before', [
                                        'times' => $out['times'] ?? 1,
                                        'how' => __($ways[$out['how'] ?? ''] ?? 'core.print.way_printed'),
                                        'when' => \App\Core\Support\DateFormat::formatWithTime($out['at'] ?? null),
                                        'who' => $out['by_name'] ?? '—',
                                    ]) }}
                                </p>
                                @if (filled($out['document_type'] ?? null))
                                    <a href="{{ route('paper.history', ['type' => $out['document_type'], 'id' => $out['document_id'] ?? $revision->document_id]) }}"
                                       class="mt-1 inline-block underline underline-offset-2">
                                        {{ __('revision.printed_link') }}
                                    </a>
                                @endif
                            </div>
                        @endif

                        @foreach (\App\Core\Support\RevisionDiff::SECTIONS as $section)
                            @php $part = $diff[$section] ?? []; @endphp

                            @continue($part === [])

                            <div data-revision-section="{{ $section }}" class="mt-3">
                                <h3 class="text-xs font-semibold text-(--color-ink-muted)">
                                    {{ __('revision.section.'.$section) }}
                                </h3>

                                @if ($section === 'header')
                                    @php
                                        $changed = array_values(array_filter($part, fn ($f) => $f['changed']));
                                        $same = array_values(array_filter($part, fn ($f) => ! $f['changed']));
                                    @endphp

                                    @if ($changed === [])
                                        <p class="mt-1 text-2xs text-(--color-ink-muted)">{{ __('revision.nothing_here') }}</p>
                                    @endif

                                    @foreach ($changed as $f)
                                        <x-ui.revision-field :f="$f" />
                                    @endforeach

                                    @if ($same !== [])
                                        <details class="mt-1">
                                            <summary class="cursor-pointer text-2xs text-(--color-ink-muted)">
                                                {{ __('revision.unchanged', ['n' => count($same)]) }}
                                            </summary>
                                            @foreach ($same as $f)
                                                <x-ui.revision-field :f="$f" />
                                            @endforeach
                                        </details>
                                    @endif
                                @else
                                    @if (! \App\Core\Support\RevisionDiff::sectionChanged($part, $section))
                                        <p class="mt-1 text-2xs text-(--color-ink-muted)">{{ __('revision.nothing_here') }}</p>
                                    @endif

                                    @foreach ($part as $row)
                                        @continue($row['state'] === \App\Core\Support\RevisionDiff::SAME)

                                        <div data-revision-row="{{ $row['state'] }}"
                                             class="mt-2 rounded-(--radius-field) border border-(--color-border) px-2 py-1">
                                            <p class="text-2xs font-semibold">
                                                {{ __('revision.row', ['n' => $row['position']]) }} ·
                                                {{ __('revision.state.'.$row['state']) }}
                                            </p>
                                            @foreach ($row['fields'] as $f)
                                                @continue($row['state'] === \App\Core\Support\RevisionDiff::CHANGED && ! $f['changed'])
                                                <x-ui.revision-field :f="$f" :state="$row['state']" />
                                            @endforeach
                                        </div>
                                    @endforeach
                                @endif
                            </div>
                        @endforeach
                    </details>
                </li>
            @endforeach
        </ol>
    </section>
@endif

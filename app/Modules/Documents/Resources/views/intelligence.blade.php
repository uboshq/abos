{{--
    Document Intelligence (ABE) — নিয়মে, আমাদের সার্ভারে (§৮; ষষ্ঠ ধাপ, ৯ অক্টোবর ২০২৬)।

    ⓘ আগে একটা কাগজ বাছা, তারপর ছয়টা কাজের ট্যাব। প্রতিটা ফল **কেন** বলে — কোন শব্দ মিলল, কোন লাইন কেন নেওয়া।
    ⛔ কোনো বাইরের AI নয়; সারাংশ কাগজ থেকে বাছা লাইন, অনুবাদ কেবল ঘরের নামের শব্দকোষ — পর্দায় সেটা লেখা।
--}}
@php
    $tabs = $document === null ? [] : collect($tools)->map(fn ($t) => [
        'key' => $t,
        'label' => __('documents::message.abe_'.$t),
        'url' => route('documents.intelligence', ['document' => $document->id, 'tool' => $t]),
        'active' => $tool === $t,
    ])->all();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.intelligence') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('documents::menu.intelligence')"
                          :subtitle="$document ? $document->document_no.' · '.$document->name : __('documents::message.abe_subtitle')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <p class="mb-4 rounded-(--radius-field) bg-(--color-badge-info-bg) px-3 py-2 text-sm text-(--color-badge-info-ink)" data-abe-promise>
        {{ __('documents::message.abe_promise') }}
    </p>

    @if (! $enabled)
        <p class="text-sm text-(--color-ink-muted)">{{ __('documents::message.abe_off') }}</p>
    @elseif ($document === null)
        {{-- ── কাগজ বাছা ── --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <form method="GET" class="flex flex-wrap items-end gap-2 border-b border-(--color-border) px-4 py-3">
                <x-ui.field name="q" :label="__('documents::message.abe_pick')" :value="$q" maxlength="100" />
                <x-ui.button type="submit" icon="search">{{ __('documents::action.find') }}</x-ui.button>
            </form>

            <ul class="divide-y divide-(--color-border) text-sm">
                @forelse ($picks as $pick)
                    <li class="flex items-center gap-3 px-4 py-2">
                        <span class="num text-(--color-ink-muted)">{{ $pick->document_no }}</span>
                        <a href="{{ route('documents.intelligence', ['document' => $pick->id]) }}"
                           class="min-w-0 flex-1 truncate text-(--color-link) hover:underline">{{ $pick->name }}</a>
                    </li>
                @empty
                    <li class="px-4 py-2 text-(--color-ink-muted)">{{ __('core.empty.no_results') }}</li>
                @endforelse
            </ul>

            <x-ui.pager :rows="$picks" />
        </section>
    @else
        <x-ui.list-tabs :tabs="$tabs" :label="__('documents::menu.intelligence')" />

        <section data-boxed data-abe-tool="{{ $tool }}" class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm">
            @if ($text === null && $tool !== 'compare')
                <p class="text-(--color-ink-muted)">{{ __('documents::message.abe_no_text') }}</p>
            @elseif ($tool === 'classify')
                @forelse ($result as $row)
                    <div class="mb-3 flex flex-wrap items-center gap-3" data-abe-type="{{ $row['type'] }}">
                        <strong>{{ $choices->typeName($row['type']) }}</strong>
                        <span class="num text-(--color-ink-muted)">{{ __('documents::message.abe_score', ['score' => $row['score']]) }}</span>
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('documents::message.abe_because', ['words' => implode(', ', $row['words'])]) }}</span>
                        @can('update', $document)
                            @if ($loop->first && $row['type'] !== $document->doc_type && array_key_exists($row['type'], $choices->types()))
                                <form method="POST" action="{{ route('documents.classify', $document) }}">
                                    @csrf
                                    <input type="hidden" name="doc_type" value="{{ $row['type'] }}">
                                    <input type="hidden" name="folder" value="{{ $row['folder'] }}">
                                    <x-ui.button type="submit" tone="primary">{{ __('documents::action.use_type') }}</x-ui.button>
                                </form>
                            @endif
                        @endcan
                    </div>
                @empty
                    <p class="text-(--color-ink-muted)">{{ __('documents::message.abe_no_match') }}</p>
                @endforelse
            @elseif ($tool === 'extract')
                <dl class="grid grid-cols-[minmax(0,14rem)_minmax(0,1fr)] gap-x-3 gap-y-2">
                    @foreach ($result as $row)
                        <dt class="text-(--color-ink-muted)">{{ $row['label'] }}
                            @if ($row['rule'] === 'company') <span class="text-2xs">· {{ __('documents::message.abe_company_rule') }}</span> @endif
                        </dt>
                        <dd data-abe-field>{{ $row['value'] ?? '—' }}</dd>
                    @endforeach
                </dl>
            @elseif ($tool === 'summarize')
                <p class="mb-2 text-2xs text-(--color-ink-muted)">{{ __('documents::message.abe_extractive') }}</p>
                <ol class="list-inside list-decimal space-y-1">
                    @foreach ($result as $row)
                        <li data-abe-line>{{ $row['line'] }}</li>
                    @endforeach
                </ol>
            @elseif ($tool === 'compare')
                <form method="GET" class="mb-3 flex flex-wrap items-end gap-2">
                    <input type="hidden" name="document" value="{{ $document->id }}">
                    <input type="hidden" name="tool" value="compare">
                    @php($versionOptions = $versions->mapWithKeys(fn ($v) => [$v->id => 'v'.$v->label()])->all())
                    <x-ui.select name="from" :label="__('documents::message.abe_from')" :options="$versionOptions"
                                 :selected="request('from', $versions->get(1)?->id)" />
                    <x-ui.select name="to" :label="__('documents::message.abe_to')" :options="$versionOptions"
                                 :selected="request('to', $versions->first()?->id)" />
                    <x-ui.button type="submit">{{ __('documents::message.abe_compare') }}</x-ui.button>
                </form>

                @if ($result === null)
                    <p class="text-(--color-ink-muted)">{{ __('documents::message.abe_need_two') }}</p>
                @else
                    <table class="ui-list mb-3 w-full border-collapse">
                        <tbody>
                            @foreach ($result['meta'] as $row)
                                <tr @class(['font-semibold' => ! $row['same']]) data-abe-meta="{{ $row['same'] ? 'same' : 'changed' }}">
                                    <td class="text-(--color-ink-muted)">{{ $row['label'] }}</td>
                                    <td class="max-w-[24rem] truncate">{{ $row['old'] }}</td>
                                    <td class="max-w-[24rem] truncate">{{ $row['new'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if (! $result['text'])
                        <p class="text-(--color-ink-muted)">{{ __('documents::message.abe_no_text_both') }}</p>
                    @else
                        <pre class="max-h-[50vh] overflow-auto rounded-(--radius-field) bg-(--color-surface-app) p-3 text-xs">@foreach ($result['lines'] as $row)<span data-diff="{{ $row['op'] }}" @class(['block', 'text-(--color-danger) line-through' => $row['op'] === 'del', 'text-(--color-state-on)' => $row['op'] === 'add'])>{{ $row['op'] === 'add' ? '+ ' : ($row['op'] === 'del' ? '− ' : '  ') }}{{ $row['line'] }}</span>@endforeach</pre>
                    @endif
                @endif
            @elseif ($tool === 'ask')
                <form method="GET" class="mb-3 flex flex-wrap items-end gap-2">
                    <input type="hidden" name="document" value="{{ $document->id }}">
                    <input type="hidden" name="tool" value="ask">
                    <x-ui.field name="ask" :label="__('documents::message.abe_ask')" :value="request('ask')" maxlength="100" />
                    <x-ui.button type="submit" icon="search">{{ __('documents::action.find') }}</x-ui.button>
                </form>

                <ul class="space-y-1">
                    @forelse ($result as $row)
                        {{-- ⓘ লাইনটা আগে নিরাপদ করা ([[DocumentIntelligence::ask()]]); কেবল <mark> আমাদের --}}
                        <li data-abe-hit><span class="num text-(--color-ink-muted)">{{ $row['line'] }}:</span> {!! $row['html'] !!}</li>
                    @empty
                        @if (filled(request('ask')))
                            <li class="text-(--color-ink-muted)">{{ __('core.empty.no_results') }}</li>
                        @endif
                    @endforelse
                </ul>
            @else
                <p class="mb-2 text-2xs text-(--color-ink-muted)">{{ __('documents::message.abe_glossary_only') }}</p>
                <table class="ui-list w-full border-collapse">
                    <tbody>
                        @forelse ($result as $row)
                            <tr data-abe-glossary><td>{{ $row['en'] }}</td><td>{{ $row['bn'] }}</td></tr>
                        @empty
                            <tr><td class="text-(--color-ink-muted)">{{ __('documents::message.abe_no_match') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </section>

        <p class="mt-3 text-sm"><a href="{{ route('documents.show', $document) }}" class="text-(--color-link) hover:underline">{{ __('documents::action.back_to_document') }}</a></p>
    @endif
</x-layouts.app>

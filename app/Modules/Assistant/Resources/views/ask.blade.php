{{--
    ⭐ কোপাইলটকে প্রশ্ন (পূর্ণ পরিকল্পনা অংশ ২, ধাপ ৪) — উত্তরের প্রতিটা সংখ্যা ABOS-এর দরজা থেকে, উৎসের লিংকসহ; 👍 👎; "কোপাইলটের লেখা — যাচাই করুন"।
--}}
@php
    $field = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3 text-sm';
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('assistant::ask.title', ['name' => $name]) }}</x-slot:title>

    <div class="space-y-4" data-assistant-ask>
        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex size-10 items-center justify-center rounded-(--radius-card) bg-(--color-brand-500) text-white" aria-hidden="true"><x-ui.icon name="brain" :size="24" /></span>
            <div class="min-w-0">
                <h1 class="text-lg font-semibold">{{ __('assistant::ask.title', ['name' => $name]) }}</h1>
                <p class="text-sm text-(--color-ink-muted)">{{ $modelOn ? __('assistant::ask.model_on') : __('assistant::ask.model_off') }}</p>
            </div>
            <a href="{{ route('assistant.today') }}" class="ms-auto text-sm text-(--color-link) hover:underline">{{ __('assistant::page.tab_today') }}</a>
        </div>

        @include('notification::partials.flash')

        <form method="GET" action="{{ route('assistant.ask') }}" class="flex flex-wrap gap-2" role="search">
            <label for="assistant-q" class="sr-only">{{ __('assistant::ask.placeholder') }}</label>
            <input id="assistant-q" type="search" name="q" maxlength="500" value="{{ $question }}" placeholder="{{ __('assistant::ask.placeholder') }}" class="{{ $field }} min-w-0 flex-1" autofocus>
            <button type="submit" class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-4 text-sm text-white">{{ __('assistant::ask.send') }}</button>
        </form>

        @if ($examples !== [])
            <div class="flex flex-wrap gap-2 text-sm" data-ask-examples>
                @foreach ($examples as $ex)
                    <a href="{{ route('assistant.ask', ['q' => $ex]) }}" class="rounded-full border border-(--color-brand-500) px-3 py-0.5 text-(--color-brand-500) hover:bg-(--color-surface-selected)">{{ $ex }}</a>
                @endforeach
            </div>
        @endif

        @if ($answer !== null)
            <section class="space-y-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4" data-ask-answer="{{ $answer['state'] }}">
                <p class="text-sm text-(--color-ink-muted)">{{ $question }}</p>

                @if ($answer['state'] === 'answered')
                    @php $r = $answer['result']; @endphp
                    <p class="text-base font-medium">{{ $r['summary'] }}</p>
                    @if ($r['rows'] !== [])
                        <div class="overflow-x-auto">
                            <table class="ui-grid">
                                <tbody>
                                    @foreach ($r['rows'] as $row)
                                        <tr class="border-t border-(--color-border)">
                                            @foreach ($r['columns'] as $col)
                                                <td class="{{ $loop->index > 0 ? 'num' : '' }} text-sm">
                                                    @if ($loop->first && ! empty($row['href']))<a href="{{ $row['href'] }}" class="text-(--color-link) hover:underline">{{ $row[$col] ?? '' }}</a>@else{{ $row[$col] ?? '' }}@endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    <p class="border-t border-dashed border-(--color-border) pt-2 text-2xs text-(--color-ink-muted)">
                        {{ __('assistant::ask.source') }}:
                        @foreach ($r['sources'] as $s)<a href="{{ $s['href'] }}" class="text-(--color-link) hover:underline">{{ $s['label'] }}</a>@if (! $loop->last) · @endif @endforeach
                        · {{ __('assistant::ask.by', ['model' => $answer['model'] === 'words' ? __('assistant::ask.words') : $answer['model']]) }}
                        · {{ __('assistant::ask.check', ['name' => $name]) }}
                    </p>
                    @if ($event !== null && $event->kind === \App\Models\AssistantEvent::QUESTION)
                        <div class="flex gap-2" data-ask-feedback>
                            @foreach ([1 => '👍', -1 => '👎'] as $v => $face)
                                <form method="POST" action="{{ route('assistant.feedback', $event) }}">
                                    @csrf
                                    <input type="hidden" name="value" value="{{ $v }}">
                                    <button type="submit" aria-label="{{ __('assistant::ask.feedback_'.($v > 0 ? 'up' : 'down')) }}" @class(['rounded-(--radius-field) border px-2 text-sm', 'border-(--color-brand-500)' => (int) $event->feedback === $v, 'border-(--color-border)' => (int) $event->feedback !== $v])>{{ $face }}</button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                @else
                    <p class="text-base" data-ask-state="{{ $answer['state'] }}">{{ __('assistant::ask.state.'.$answer['state']) }}</p>
                @endif
            </section>
        @endif

        <p class="text-2xs text-(--color-ink-muted)">{{ __('assistant::page.rules') }}</p>
    </div>
</x-layouts.app>

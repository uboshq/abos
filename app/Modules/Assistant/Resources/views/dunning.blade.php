{{--
    ⭐ তাগাদার SMS — কোপাইলটের খসড়া, মানুষ টিক দিয়ে পাঠান (পূর্ণ পরিকল্পনা অংশ ৭, ধাপ ৩)।
    ⓘ লেখা সার্ভারে বানানো, পাঠানোর সময় আবার বানানো হয় — পাতার লেখা নয়; যায় অফিসের SMS ফোন দিয়ে, খবর SMS-এর ফোনের পাতায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('assistant::draft.dunning_title') }}</x-slot:title>

    <div class="space-y-4" data-assistant-dunning>
        <x-ui.toolbar :title="__('assistant::draft.dunning_title')" :search="false" :export="false" :share="false" />
        <p class="text-sm text-(--color-ink-muted)">{{ __('assistant::draft.dunning_note', ['name' => $name]) }}</p>

        @include('notification::partials.flash')

        @if (! $phoneReady)
            <p class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)" data-dunning-no-phone>{{ __('notification::sms.no_gateway') }}</p>
        @endif

        @if ($drafts === [])
            <p class="text-sm text-(--color-ink-muted)">{{ __('assistant::draft.dunning_empty') }}</p>
        @else
            <form method="POST" action="{{ route('assistant.draft.dunning.send') }}" class="space-y-3">
                @csrf
                <ul class="grid gap-2">
                    @foreach ($drafts as $d)
                        <li class="grid gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3" data-dunning="{{ $d['customer_id'] }}">
                            <label class="flex flex-wrap items-center gap-2 text-sm">
                                <input type="checkbox" name="customers[]" value="{{ $d['customer_id'] }}" @disabled($d['phone'] === null)>
                                <b>{{ $d['name'] }}</b>
                                <span class="text-(--color-ink-muted) tabular-nums">৳{{ $d['amount'] }} · {{ __('assistant::suggest.oldest', ['n' => $d['days']]) }}</span>
                                <span class="text-(--color-ink-muted) tabular-nums">{{ $d['phone'] === null ? __('assistant::draft.no_phone') : '+'.$d['phone'] }}</span>
                            </label>
                            <p class="whitespace-pre-line rounded-(--radius-field) bg-(--color-surface-muted) p-2 text-sm">{{ $d['text'] }}</p>
                        </li>
                    @endforeach
                </ul>
                <button type="submit" @disabled(! $phoneReady) class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-3 text-sm text-white disabled:opacity-50">{{ __('assistant::draft.dunning_send') }}</button>
            </form>
        @endif
    </div>
</x-layouts.app>

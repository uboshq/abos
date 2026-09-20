{{--
    ঝুঁকির ড্যাশবোর্ড — মানচিত্র §১, ২০ সেপ্টেম্বর ২০২৬।

    যেগুলো সত্যিই ডিপোকে কামড়ায়: টাকা ফুরিয়ে আসা, মেয়াদ পেরোনো পাওনা,
    সুবিধার সীমা ভরে যাওয়া, জমার মেয়াদ, ঋণের কিস্তি, আর বসে থাকা মাল।

    ⭐ সব ঠিক থাকলে পাতাটা ফাঁকা — আর সেটাই ঠিক। ⓘ সবুজ সারির লম্বা
    তালিকা মানুষকে লাল সারিও এড়াতে শেখায়।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);

    $tone = [
        'bad' => ['bg-(--color-badge-danger-bg)', 'text-(--color-badge-danger-ink)'],
        'warn' => ['bg-(--color-badge-warning-bg)', 'text-(--color-badge-warning-ink)'],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::risk.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::risk.title')" :subtitle="__('finance::risk.note')" />
    </x-slot:header>

    @if ($risks === [])
        <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-6 text-center">
            <p class="font-semibold text-(--color-badge-success-ink)">{{ __('finance::risk.all_clear') }}</p>
            <p class="mt-1 text-sm text-(--color-ink-muted)">{{ __('finance::risk.all_clear_hint') }}</p>
        </div>
    @else
        <div class="grid gap-3 lg:grid-cols-2">
            @foreach ($risks as $risk)
                @php [$bg, $ink] = $tone[$risk['level']] ?? $tone['warn']; @endphp

                <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-semibold">{{ __('finance::risk.'.$risk['key']) }}</h2>
                        <span class="rounded-full {{ $bg }} {{ $ink }} px-3 py-0.5 text-xs font-medium">
                            {{ __('finance::risk.level_'.$risk['level']) }}
                        </span>
                    </div>

                    {{--
                        ⭐ সংখ্যাটাই দরজা — মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬:
                        *"sob jaygay hyper link dewar kotha"*।

                        ⓘ যে সংখ্যা দেখে মানুষ থমকান, ক্লিকটা তিনি ওখানেই
                        করেন — নিচের ছোট "দেখুন" লেখাটায় নয়। ⚠️ দরজা না
                        থাকলে (রুটটাই নেই) সাধারণ লেখাই থাকে।
                    --}}
                    @php
                        $shown = match ($risk['key']) {
                            'facility_used' => $risk['value'].'%',
                            'deposits_maturing' => $risk['value'],
                            default => $money($risk['value']),
                        };
                    @endphp

                    <p class="num mt-2 text-xl font-semibold {{ $ink }}">
                        @if ($risk['href'])
                            <a href="{{ $risk['href'] }}" class="underline-offset-4 hover:underline">{{ $shown }}</a>
                        @else
                            {{ $shown }}
                        @endif
                    </p>

                    @php
                        /* ⓘ টাকার ঘরগুলো টাকার মতো, বাকিগুলো যেমন আছে — আর
                           চাবিগুলো অক্ষত, নইলে বার্তায় `:amount` লেখাই থেকে যেত */
                        $hint = [];

                        foreach ($risk['hint'] as $key => $value) {
                            $hint[$key] = in_array($key, ['amount', 'total', 'used', 'ceiling'], true)
                                ? $money($value)
                                : $value;
                        }
                    @endphp

                    <p class="mt-1 text-sm text-(--color-ink-muted)">
                        {{ __('finance::risk.'.$risk['key'].'_hint', $hint) }}
                    </p>

                    @if ($risk['href'])
                        <a href="{{ $risk['href'] }}"
                           class="mt-2 inline-block text-sm text-(--color-brand-500) underline-offset-2 hover:underline">
                            {{ __('finance::risk.look') }}
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-layouts.app>

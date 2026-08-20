{{--
    খাতা নিজেই মেলে — চালিয়ে দেখার পর্দা।

    ── কেন সবুজ সারিগুলোও দেখানো হয় ─────────────────────────────────
    শুধু ভাঙাগুলো দেখালে সব ঠিক থাকা অবস্থায় পর্দাটা খালি আসত, আর
    "সব মিলেছে" আর "যাচাই চলেইনি" দেখতে হুবহু এক। কী কী দেখা হয়েছে
    সেটা লেখা থাকলে তবেই খালি তালিকাটার একটা মানে দাঁড়ায়।

    ── কেন প্রতিটা সারিতে "ভাঙলে কী হয়" লেখা ─────────────────────────
    ছয় মাস পরে যিনি লাল সারিটা দেখবেন তিনি এই কোড লেখেননি। শুধু
    "trial balance mismatch" পড়ে তিনি বুঝবেন না এটা জরুরি না
    উপেক্ষণীয় — আর না বুঝলে লাল সারিটা দেখা আর না দেখা একই।
--}}
@php
    /*
        কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে।

        এই পর্দায় টেবিলটা প্রতিটা ভাঙা পরীক্ষার নিচে আলাদা করে বসে,
        তাই কলামের সংজ্ঞা একবার লিখে সবগুলোয় ব্যবহার করা হয়।
    */
    $findingColumns = [
        [
            'key' => 'what',
            'label' => __('core.table.document'),
            'render' => fn ($f) => view('accounts::integrity.partials.what', ['finding' => $f]),
        ],
        [
            'key' => 'detail',
            'label' => __('accounts::message.what_does_not_match'),
            'numeric' => true,
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.books_check') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('accounts::menu.books_check')"
            :subtitle="$broken === 0
                ? __('accounts::message.books_all_clear', ['count' => count($results)])
                : __('accounts::message.books_broken', ['count' => $broken])" />
    </x-slot:header>

    @if ($results === [])
        <x-ui.empty-state :message="__('accounts::message.no_checks_for_you')" />
    @else
        <div class="space-y-4">
            @foreach ($results as $result)
                @php
                    $check = $result['check'];
                    $ok = $result['ok'];
                @endphp

                <section class="overflow-hidden rounded-(--radius-card) border
                                {{ $ok ? 'border-(--color-border)' : 'border-(--color-danger)' }}
                                bg-(--color-surface-card)">

                    <header class="flex items-start gap-3 border-b border-(--color-border) px-4 py-3">
                        {{-- রঙ একা যথেষ্ট নয়: আইকন ও লেখা দুইটাই বলে --}}
                        <span class="{{ $ok ? 'text-(--color-success)' : 'text-(--color-danger)' }}">
                            <x-ui.icon :name="$ok ? 'check-circle' : 'alert-triangle'" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <h2 class="font-medium">{{ $check->label }}</h2>
                            <p class="mt-0.5 text-2xs text-(--color-ink-muted)">{{ $check->question }}</p>
                        </div>

                        <span @class([
                            'shrink-0 rounded-(--radius-pill) px-2.5 py-1 text-2xs font-medium',
                            'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $ok,
                            'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' => ! $ok,
                        ])>
                            {{ $ok
                                ? __('accounts::message.check_passed')
                                : trans_choice('accounts::message.check_failed', count($result['findings']),
                                    ['count' => count($result['findings'])]) }}
                        </span>
                    </header>

                    @unless ($ok)
                        {{-- ভাঙলে কী হয় — কেবল লাল সারিতে, কারণ সবুজ সারিতে
                             এটা পড়ার কেউ নেই আর পর্দাটা লম্বা হত --}}
                        <p class="border-b border-(--color-border) bg-(--color-badge-danger-bg) px-4 py-2 text-2xs
                                  text-(--color-badge-danger-ink)">
                            {{ $check->whenBroken }}
                        </p>

                        <x-ui.table :rows="$result['findings']"
                                    :columns="$findingColumns" />

                    @endunless
                </section>
            @endforeach
        </div>
    @endif
</x-layouts.app>

{{--
    কাগজটার অবস্থা — আর মানুষ এখান থেকে কী করবেন।

    ── ⭐ পর্দাটার একটাই কাজ: ভয় কমানো ──────────────────────────────────
    ⓘ যিনি এটা দেখছেন তাঁর সামনে সব বন্ধ, আর প্রথম ভাবনাটা হয় *"আমার
    তথ্য কি গেল?"*। ⚠️ তাই সবার আগে ঐ প্রশ্নের উত্তর — তারপর কী করতে
    হবে, তারপর কাগজের বিবরণ।

    ⛔ উল্টো ক্রমে সাজালে (আগে বিবরণ, শেষে আশ্বাস) মানুষ প্রথম লাইনটা
    পড়েই ফোন তুলতেন।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.licence.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('core.licence.title')" />
    </x-slot:header>

    <div class="mx-auto max-w-2xl space-y-4">

        {{-- ⓘ অবস্থাটা সবার আগে, আর রঙটা কেবল অবস্থা ধরে --}}
        <div role="status"
             @class([
                 'rounded-(--radius-card) border px-4 py-3 text-sm',
                 'border-(--color-badge-success-bg) bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $verdict->isValid(),
                 'border-(--color-badge-warning-bg) bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' => ! $verdict->isValid(),
             ])>
            {{ $verdict->state === 'expired' && $licence?->expiresOn
                ? __('core.licence.expired', ['date' => $licence->expiresOn->translatedFormat('j F Y')])
                : $verdict->message() }}
        </div>

        {{--
            ⭐ সবচেয়ে গুরুত্বপূর্ণ বাক্যটা — আর সেটা কেবল তালাবদ্ধ অবস্থায়।

            ⓘ বৈধ কাগজে এটা দেখানোর মানে নেই: যাঁর সব চলছে তাঁকে
            "আপনার তথ্য নিরাপদ" বলাটা অকারণে দুশ্চিন্তা তৈরি করে।
        --}}
        @unless ($verdict->isValid())
            <p class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)
                      px-4 py-3 text-sm">{{ __('core.licence.still_open') }}</p>

            <p class="text-sm text-(--color-ink-muted)">{{ __('core.licence.how_to_renew') }}</p>
        @endunless

        {{-- কাগজের নিজের কথা — কেবল যদি পড়া গিয়ে থাকে --}}
        @if ($licence !== null)
            <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                                   bg-(--color-surface-card)">
                <dl class="divide-y divide-(--color-border) text-sm">
                    <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                        <dt class="text-(--color-ink-muted)">{{ __('core.licence.buyer') }}</dt>
                        <dd class="font-medium">{{ $licence->buyer }}</dd>
                    </div>

                    <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                        <dt class="text-(--color-ink-muted)">{{ __('core.licence.expires_on') }}</dt>
                        <dd class="font-medium">
                            @if ($licence->expiresOn === null)
                                {{ __('core.licence.never_expires') }}
                            @else
                                {{ $licence->expiresOn->translatedFormat('j F Y') }}

                                {{-- ⓘ বাকি দিনের সংখ্যাটা কেবল যখন এখনো বাকি আছে —
                                     ঋণাত্মক দিন দেখানো মানে "আর -৫ দিন", যা কেউ পড়ে না --}}
                                @if (($left = $licence->daysLeft()) !== null && $left >= 0)
                                    <span class="text-(--color-ink-muted)">
                                        · {{ __('core.licence.days_left', ['days' => $left]) }}
                                    </span>
                                @endif
                            @endif
                        </dd>
                    </div>

                    <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                        <dt class="text-(--color-ink-muted)">{{ __('core.licence.companies') }}</dt>
                        <dd class="font-medium">
                            {{ $licence->companies === 0 ? __('core.licence.unlimited') : $licence->companies }}
                        </dd>
                    </div>
                </dl>
            </div>
        @endif
    </div>
</x-layouts.app>

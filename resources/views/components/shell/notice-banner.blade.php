{{--
    ⭐ সতর্কবাণীর ফিতা — SAP Fiori Message Strip (ওয়েবের মান ধাপ ২, মালিকের আদেশ "100% int. standard", ২০ অক্টোবর ২০২৬;
    নকশা: E:\ABOS\মালিকের-ফাইল\পুরো-পাতার-নমুনা-ওয়েব-ও-অ্যাপ-২০-অক্টোবর.pdf, পাতা ১, ভুল ৮)।

    পুরো চওড়া বারের ঠিক নিচে, প্রতিটা নোটিশ এক সারিতে: চিহ্ন · লেখা · "বিস্তারিত" · ✕।

    ── কেন ফুটারের চলন্ত বার থেকে উঠে এল ────────────────────────────────────────────────
    ⛔ নিচের চলন্ত বারে (১১ অক্টোবর থেকে) লেখা সরে যেত — পড়তে হলে চোখ দিয়ে তাড়া করতে হত, চাপার আগেই লিংক সরে যেত
    (WCAG 2.2.2: নড়া লেখা থামানো যায় এমন হতে হয়), আর ফুটারটা ছিল পাতার সবচেয়ে কম দেখা জায়গা। মালিকের অনুমোদিত নমুনায়
    (২০ অক্টোবর) সতর্কতা উপরে, স্থির, ফুটারে কেবল সংস্করণ · © · নাম — এটাই ১১ অক্টোবরের সিদ্ধান্তের পরের সিদ্ধান্ত।

    ⭐ মালিক, ২১ অক্টোবর ২০২৬ (fe-র মাধ্যমে): *"জরুরি সতর্কতা উপরে স্থির, ঘোষণা নিচে চলন্ত"*। ⓘ উৎস [[StatusNotices::forStrip()]]:
    যন্ত্রের সতর্কতা আর কাজের নোটিশ, সাথে লেখক যে ঘোষণায় "জরুরি" টিক দিয়েছেন (সেটা আগে)। ⛔ জরুরি নয় এমন ঘোষণা এখানে নয় —
    সেটা নিচের চলন্ত বারে ([[shell.statusbar]]); একই নোটিশ দুই জায়গায় কখনো নয়। ঘণ্টাতেও সব থাকে।
    ⓘ ✕: প্রশাসকের নিজের নোটিশ যিনি সরাতে পারেন তাঁর জন্য সেটা সত্যিই সরায় (system_admin.notice.dismiss); বাকিগুলো কেবল
    এই লগইনে লুকায় ([[NotificationController::closeBanner()]]) — সতর্কতা নিজে হারায় না।
    ⓘ জাভাস্ক্রিপ্ট ছাড়া, ছোট ফর্ম; ছাপায় আসে না। সর্বোচ্চ তিনটা — বাকিগুলো ঘণ্টায় (Fiori: ফিতা কম, নইলে পাতাটাই ঢাকা পড়ে)।
--}}
@php
    $closed = (array) session('shell.banner.closed', []);
    $all = auth()->check()
        ? array_values(array_filter(
            app(\App\Core\Services\StatusNotices::class)->forStrip(),
            fn (array $n): bool => ! in_array(sha1($n['text']), $closed, true),
        ))
        : [];
    // ⭐ সবচেয়ে জরুরিটা আগে — লাল, তারপর হলুদ, তারপর নীল; একই রঙে আগের ক্রম (মালিক, ৩১ অক্টোবর ২০২৬)
    $rank = ['danger' => 0, 'pending' => 1];
    $all = collect($all)->sortBy(fn (array $n, int $i): array => [$rank[$n['tone']] ?? 2, $i])->values()->all();
    $strips = array_slice($all, 0, 3);
    $hidden = count($all) - count($strips);
    $canDismiss = \Illuminate\Support\Facades\Route::has('system_admin.notice.dismiss');
@endphp

{{-- ⭐ এক জায়গায় গুটানো — মালিক, ৩১ অক্টোবর ২০২৬: *"notice ta curo kore ekta jaygay dekhaw zate porda na vange web r mobile er"*।
     ⛔ আগে প্রতিটা নোটিশ নিজের সারিতে — তিনটা থাকলে ফোনে পর্দার এক-তৃতীয়াংশ (৩৯০-এ ২৬৪px), বড় পর্দায় তিন সারি।
     ⭐ এখন একটা সারি: সবচেয়ে জরুরিটা, এক লাইনে (লম্বা হলে … — পুরো লেখা চাপলে), পাশে "+২টা ▾"; চাপলে বাকিগুলো নিচে খোলে
     (SAP Fiori message strip-এর "আরও", Material 3-এর banner)। ✕ আর "বিস্তারিত" প্রতিটায় আগের মতো। --}}
@if ($strips !== [])
    <div data-message-strips class="print-hide" x-data="{ open: false }">
        @foreach ($strips as $strip)
            @php
                [$bg, $ink, $icon] = match ($strip['tone']) {
                    'danger' => ['bg-(--color-badge-warning-bg)', 'text-(--color-warning-hover)', 'alert-triangle'],
                    'pending' => ['bg-(--color-badge-pending-bg)', 'text-(--color-badge-pending-ink)', 'clock'],
                    default => ['bg-(--color-badge-info-bg)', 'text-(--color-badge-info-ink)', 'info'],
                };
            @endphp
            <div data-notice-banner data-message-strip="{{ $strip['tone'] }}" role="status"
                 @if (! $loop->first) x-cloak x-show="open" @endif
                 class="flex items-center gap-3 border-b border-(--color-border) px-3 py-1.5 text-sm text-(--color-ink) md:px-5 {{ $bg }}">
                <x-ui.icon :name="$icon" :size="18" class="shrink-0 {{ $ink }}" />

                {{-- ⓘ গুটানো অবস্থায় এক লাইন; লেখায় চাপলে পুরোটা (খোলা অবস্থায় সব লাইন) --}}
                <span class="min-w-0 flex-1" :class="open ? '' : 'truncate'" @click="open = true" title="{{ $strip['text'] }}">{{ $strip['text'] }}</span>

                @if ($loop->first && count($strips) > 1)
                    <button type="button" data-strip-toggle
                            @click="open = ! open"
                            :aria-expanded="open.toString()"
                            class="shrink-0 rounded-(--radius-pill) border border-(--color-border) bg-(--color-surface) px-2 py-0.5 text-xs font-medium
                                   text-(--color-ink) hover:bg-(--color-surface-hover)">
                        <span x-show="! open">+{{ count($strips) - 1 + $hidden }} ▾</span>
                        <span x-cloak x-show="open">▴</span>
                    </button>
                @endif

                @if ($strip['url'])
                    <a href="{{ $strip['url'] }}" class="shrink-0 font-medium text-(--color-link) underline-offset-2 hover:underline">
                        {{ __('core.notice.details') }}
                    </a>
                @endif

                @if ($canDismiss && ($strip['id'] ?? null) && ($strip['can_dismiss'] ?? false))
                    <form method="POST" action="{{ route('system_admin.notice.dismiss', $strip['id']) }}" class="shrink-0">
                @else
                    <form method="POST" action="{{ route('notifications.banner.close') }}" class="shrink-0">
                        <input type="hidden" name="key" value="{{ sha1($strip['text']) }}">
                @endif
                        @csrf
                        <button type="submit" data-strip-close
                                title="{{ __('core.notice.close') }}"
                                aria-label="{{ __('core.notice.close') }}: {{ $strip['text'] }}"
                                class="grid size-8 place-items-center rounded-(--radius-field) text-(--color-ink-muted)
                                       hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
                            <x-ui.icon name="x" :size="16" />
                        </button>
                    </form>
            </div>
        @endforeach

        @if ($hidden > 0)
            <p class="border-b border-(--color-border) px-3 py-1 text-xs text-(--color-ink-muted) md:px-5" data-strip-more x-cloak x-show="open">
                {{ __('core.notice.more_in_bell', ['count' => $hidden]) }}
            </p>
        @endif
    </div>
@endif

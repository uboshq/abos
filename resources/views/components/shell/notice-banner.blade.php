{{--
    ⭐ সতর্কবাণীর ফিতা — SAP Fiori Message Strip (ওয়েবের মান ধাপ ২, মালিকের আদেশ "100% int. standard", ২০ অক্টোবর ২০২৬;
    নকশা: E:\ABOS\মালিকের-ফাইল\পুরো-পাতার-নমুনা-ওয়েব-ও-অ্যাপ-২০-অক্টোবর.pdf, পাতা ১, ভুল ৮)।

    পুরো চওড়া বারের ঠিক নিচে, প্রতিটা নোটিশ এক সারিতে: চিহ্ন · লেখা · "বিস্তারিত" · ✕।

    ── কেন ফুটারের চলন্ত বার থেকে উঠে এল ────────────────────────────────────────────────
    ⛔ নিচের চলন্ত বারে (১১ অক্টোবর থেকে) লেখা সরে যেত — পড়তে হলে চোখ দিয়ে তাড়া করতে হত, চাপার আগেই লিংক সরে যেত
    (WCAG 2.2.2: নড়া লেখা থামানো যায় এমন হতে হয়), আর ফুটারটা ছিল পাতার সবচেয়ে কম দেখা জায়গা। মালিকের অনুমোদিত নমুনায়
    (২০ অক্টোবর) সতর্কতা উপরে, স্থির, ফুটারে কেবল সংস্করণ · © · নাম — এটাই ১১ অক্টোবরের সিদ্ধান্তের পরের সিদ্ধান্ত।

    ⓘ নোটিশের উৎস বদলায়নি — [[StatusNotices::forTicker()]]: মানুষের লেখা আগে, যন্ত্রের সতর্কতা পরে; ঘণ্টাতেও সব থাকে।
    ⓘ ✕: প্রশাসকের নিজের নোটিশ যিনি সরাতে পারেন তাঁর জন্য সেটা সত্যিই সরায় (system_admin.notice.dismiss); বাকিগুলো কেবল
    এই লগইনে লুকায় ([[NotificationController::closeBanner()]]) — সতর্কতা নিজে হারায় না।
    ⓘ জাভাস্ক্রিপ্ট ছাড়া, ছোট ফর্ম; ছাপায় আসে না। সর্বোচ্চ তিনটা — বাকিগুলো ঘণ্টায় (Fiori: ফিতা কম, নইলে পাতাটাই ঢাকা পড়ে)।
--}}
@php
    $closed = (array) session('shell.banner.closed', []);
    $all = auth()->check()
        ? array_values(array_filter(
            app(\App\Core\Services\StatusNotices::class)->forTicker(),
            fn (array $n): bool => ! in_array(sha1($n['text']), $closed, true),
        ))
        : [];
    $strips = array_slice($all, 0, 3);
    $hidden = count($all) - count($strips);
    $canDismiss = \Illuminate\Support\Facades\Route::has('system_admin.notice.dismiss');
@endphp

@if ($strips !== [])
    <div data-message-strips class="print-hide">
        @foreach ($strips as $strip)
            @php
                [$bg, $ink, $icon] = match ($strip['tone']) {
                    'danger' => ['bg-(--color-badge-warning-bg)', 'text-(--color-warning-hover)', 'alert-triangle'],
                    'pending' => ['bg-(--color-badge-pending-bg)', 'text-(--color-badge-pending-ink)', 'clock'],
                    default => ['bg-(--color-badge-info-bg)', 'text-(--color-badge-info-ink)', 'info'],
                };
            @endphp
            <div data-notice-banner data-message-strip="{{ $strip['tone'] }}" role="status"
                 class="flex items-center gap-3 border-b border-(--color-border) px-3 py-1.5 text-sm text-(--color-ink) md:px-5 {{ $bg }}">
                <x-ui.icon :name="$icon" :size="18" class="shrink-0 {{ $ink }}" />

                <span class="min-w-0 flex-1">{{ $strip['text'] }}</span>

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
            <p class="border-b border-(--color-border) px-3 py-1 text-xs text-(--color-ink-muted) md:px-5" data-strip-more>
                {{ __('core.notice.more_in_bell', ['count' => $hidden]) }}
            </p>
        @endif
    </div>
@endif

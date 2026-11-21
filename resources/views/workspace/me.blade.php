{{--
    ⭐ "আমার প্রোফাইল" — Facebook-ধাঁচের মলাট, তার উপর অর্ধেক ঝুলে থাকা বড় গোল ছবি (মালিক, ২০ নভেম্বর ২০২৬: প্রোফাইলের নমুনা
    TjvNzZvq, ট্যাব গ-তে "ok" — "যেমন আঁকা তেমনই, কিছু যোগ নয়")। [[ProfilePageController]] · তথ্য [[ProfileFacts]] (অ্যাপের একই উৎস)।

    সাজ (নমুনা গ, ১৯২০×১০৮০):
      মাথা এক সারিতে — নাম … "অন্যরা যেমন দেখে" · "মলাট বদলান"; বিবরণের লাইন নেই (মালিক, ১৯ নভেম্বর ২০২৬ রাত)।
      মলাট ৩০০px (ছবি না থাকলে কোম্পানির রঙের ঢাল) → ১৭৬px গোল ছবি অর্ধেক মলাটে · নাম, পদ, কোম্পানি · শাখা · ডানে তিন বোতাম
        (প্রোফাইল ঠিক করুন · বার্তা "শীঘ্রই" · কল "শীঘ্রই")।
      বাঁয়ে ৫২০px: পরিচয় · আমার ফোনগুলো · নিরাপত্তা। ডানে: এই মাসে আমার কাজ (৩টা ঘর) · আমার সাম্প্রতিক কাজ (সময়রেখা)।
    ⛔ অন্যের প্রোফাইলে ফোন, কাজের সংখ্যা, লগইন করা ফোন, নিরাপত্তা লুকানো — "লুকানো" চিহ্নে (নমুনার ছোট ছবি)।
    ⓘ রং রূপের টোকেন থেকে — ABOS স্মার্ট, Navy, Blue নিজের রঙে; SAP আর Dynamic-এর নিজের CSS-এ কিছু ছোঁয়া হয়নি।
    ⓘ অঙ্ক ইংরেজিতে (মালিক, ১৬ নভেম্বর ২০২৬)।
--}}
@php
    /** @var array<string, mixed> $profile */
    $p = $profile;
    $self = (bool) $p['self'];
    $about = $p['about'];
    $month = $p['month'];
    $hidden = $p['hidden'];

    // ⓘ ছোট আঁকা চিহ্ন — x-ui.icon-এ নেই এমনগুলো (ক্যামেরা, বার্তা, খাম, ফোন-যন্ত্র, পরিচয়পত্র, ঢাল, টাকা)
    $svg = [
        'camera' => '<path d="M4 8h3l1.6-2.4h6.8L17 8h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.6"/>',
        'chat' => '<path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.3 8.8 8.8 0 0 1-3.9-.9L3 21l1.9-5.3a8 8 0 0 1-1-3.9A8.4 8.4 0 0 1 12.5 3.5 8.4 8.4 0 0 1 21 11.5z"/>',
        'phone' => '<path d="M5 3.5h3.5l1.5 4-2 1.5a11 11 0 0 0 7 7l1.5-2 4 1.5V19a1.5 1.5 0 0 1-1.6 1.5A16.5 16.5 0 0 1 3.5 5.1 1.5 1.5 0 0 1 5 3.5z"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/>',
        'idcard' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.2"/><path d="M5.8 16c.6-1.6 1.8-2.4 3.2-2.4s2.6.8 3.2 2.4M14.5 10h4M14.5 13.5h3"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'shield' => '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.2 7.5 9.5 4.3-1.3 7.5-4.9 7.5-9.5V6z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
        'building' => '<path d="M4 21V5a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v16M15 9h4a1 1 0 0 1 1 1v11M2.5 21h19M7.5 8h1M10.5 8h1M7.5 12h1M10.5 12h1M7.5 16h1M10.5 16h1"/>',
        'device' => '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M10.5 18.5h3"/>',
        'lock' => '<rect x="4.5" y="10.5" width="15" height="10.5" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3M15 8l2 2"/>',
        'browser' => '<rect x="3" y="4.5" width="18" height="15" rx="2"/><path d="M3 9h18M6.5 6.8h.01M9 6.8h.01"/>',
        'pencil' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'chev' => '<path d="m9 5 7 7-7 7"/>',
        'tag' => '<path d="M3.5 12.5v-8a1 1 0 0 1 1-1h8l8 8-9 9z"/><circle cx="8" cy="8" r="1.6"/>',
        'approve' => '<circle cx="12" cy="12" r="9"/><path d="m8 12.3 2.8 2.8L16.5 9.5"/>',
        'doc' => '<path d="M6 3h8l5 5v12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v5h5M8.5 13h7M8.5 16.5h5"/>',
        'cart' => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2.5 3.5h2.4l2.5 11.2a1.6 1.6 0 0 0 1.6 1.3h8.9a1.6 1.6 0 0 0 1.6-1.2L21 7.5H6"/>',
        'chart' => '<path d="M4 4v16h16"/><path d="M8.5 16v-4M12.5 16V8M16.5 16v-6"/>',
        'deposit' => '<path d="M12 1.8v6.4M9.2 5.6 12 8.4l2.8-2.8"/><rect x="2.5" y="10.5" width="19" height="11" rx="2.4"/>',
        'undo' => '<path d="M9 6 4.5 10.5 9 15"/><path d="M5 10.5h9.5a5 5 0 0 1 0 10H11"/>',
    ];
    $i = fn (string $name, int $size = 18, string $class = '') => '<svg class="'.$class.'" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($svg[$name] ?? '').'</svg>';

    // ⓘ সময়রেখার চিহ্ন আর রং — কাগজের ধরন দেখে (নমুনার রং: আদায় সবুজ, ভাউচার নীল, বিক্রয় কমলা, ফেরত লাল)
    $look = function (array $r): array {
        $kind = (string) $r['kind'];

        if (in_array($r['verb'] ?? '', ['returned_to_draft', 'rejected', 'cancelled'], true)) {
            return ['undo', 'var(--color-dash-e11d48)'];
        }

        return match (true) {
            str_contains($kind, 'receipt') || str_contains($kind, 'collection') || str_contains($kind, 'deposit') => ['deposit', 'var(--color-dash-16a34a)'],
            str_contains($kind, 'voucher') || str_contains($kind, 'journal') => ['doc', 'var(--color-dash-2563eb)'],
            str_contains($kind, 'sales') || str_contains($kind, 'order') || str_contains($kind, 'invoice') => ['tag', 'var(--color-dash-ea580c)'],
            str_contains($kind, 'payment') || str_contains($kind, 'purchase') => ['cart', 'var(--color-dash-7c3aed)'],
            default => ['doc', 'var(--color-dash-475569)'],
        };
    };

    // ⓘ সংখ্যার রং — অ্যাপের চুক্তিতে হেক্স আসে ([[ProfileFacts]]), পর্দায় রূপের টোকেন (একই রঙের, পুরো নাম লেখা — জোড়া দেওয়া নাম পাহারাদার চেনে না)
    $statTones = [
        '16a34a' => 'var(--color-dash-16a34a)',
        'ea580c' => 'var(--color-dash-ea580c)',
        '4f46e5' => 'var(--color-dash-4f46e5)',
        'e11d48' => 'var(--color-dash-e11d48)',
        '2563eb' => 'var(--color-dash-2563eb)',
        '7c3aed' => 'var(--color-dash-7c3aed)',
    ];
    // ⓘ চাবিতে `#` নেই — হাতে-লেখা রঙের পাহারা `#`-সহ ছয় অঙ্ক খোঁজে; অচেনা রং হলে ব্র্যান্ডের রং
    $statTone = fn (string $hex): string => $statTones[strtolower(ltrim($hex, '#'))] ?? 'var(--color-brand-600)';

    $date = function (?string $ymd): ?string {
        if ($ymd === null) {
            return null;
        }
        $d = \Illuminate\Support\Carbon::parse($ymd);

        return $d->format('d').' '.__('profile.month.'.$d->month).' '.$d->year;
    };

    $card = 'rounded-[14px] border border-(--color-border) bg-(--color-surface-card) px-5 py-[18px]';
    $btn = 'inline-flex h-[38px] items-center gap-2 rounded-[10px] border border-(--color-border) bg-(--color-surface-card) px-4 text-sm font-semibold text-(--color-ink) hover:bg-(--color-surface-sunken)';
    $btnPri = 'inline-flex h-[38px] items-center gap-2 rounded-[10px] border border-(--color-brand-700) bg-(--color-brand-700) px-4 text-sm font-semibold text-(--color-ink-inverse) hover:opacity-90';
    $soon = '<i class="ms-0.5 rounded-full bg-(--color-badge-warning-bg) px-1.5 text-[10px] font-bold not-italic leading-4 text-(--color-badge-warning-ink)">'.e(__('profile.soon')).'</i>';
    $row = 'flex items-center gap-3 border-t border-(--color-border) py-[11px] first:border-t-0 first:pt-0';
    $title = $self ? __('profile.title') : ($asOther ? __('profile.as_others_see') : __('profile.their_title'));
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $self ? __('profile.title') : $p['name'] }}</x-slot:title>

    <div data-me-profile class="mx-auto max-w-[1600px] pb-10">
        {{-- মাথা এক সারিতে: নাম … বোতাম (বিবরণের লাইন নেই) --}}
        <div class="mb-4 flex flex-wrap items-center gap-2.5">
            <h1 class="me-auto text-2xl font-bold text-(--color-ink)">{{ $title }}</h1>

            @if ($self)
                <a href="{{ route('people.profile', ['user' => $p['public_id'], 'as' => 'other']) }}" class="{{ $btn }}" data-me-as-others>
                    {!! $i('eye', 17) !!}{{ __('profile.as_others_see') }}
                </a>
                {{-- ⓘ বোতামটা লুকানো মলাট-ঘরের লেবেল ([[x-ui.file-input]], নিচের ফর্মে); বাছলেই জমা — `data-action="submit-form"` --}}
                <label for="me-cover-file" class="{{ $btn }} cursor-pointer" data-me-cover-head>
                    {!! $i('camera', 17) !!}{{ __('profile.change_cover') }}
                </label>
            @elseif ($asOther)
                <span class="text-sm text-(--color-ink-muted)">{{ __('profile.as_others_note') }}</span>
                <a href="{{ route('me.profile') }}" class="{{ $btn }}">{{ __('profile.back_to_mine') }}</a>
            @endif
        </div>

        @if (session('saved'))
            <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
                {{-- ⓘ মলাটের দরজা লেখাটা `status`-এ দেয়, ফোন বাদের দরজা `saved`-এ --}}
                {{ is_string(session('status')) ? session('status') : (is_string(session('saved')) ? session('saved') : __('core.profile.saved')) }}
            </div>
        @endif
        <x-ui.errors />

        @if ($self)
            {{-- ⓘ মলাটের ফর্ম একটা, দুই বোতাম তাকে চালায় (মাথার আর মলাটের কোণের) --}}
            {{-- ⓘ আসল ফাইল-ঘর বাংলা কম্পোনেন্টের ([[x-ui.file-input]]); চোখে পড়ে কেবল ক্যামেরার বোতামগুলো, যারা এর লেবেল --}}
            <form id="me-cover-form" method="POST" action="{{ route('profile.cover') }}" enctype="multipart/form-data" class="hidden">
                @csrf
                <x-ui.file-input id="me-cover-file" name="cover" accept="image/jpeg,image/png" data-action="submit-form" />
            </form>
            <form id="me-photo-form" method="POST" action="{{ route('profile.avatar') }}" enctype="multipart/form-data" class="hidden">
                @csrf
                <x-ui.file-input id="me-photo-file" name="avatar" accept="image/jpeg,image/png,image/webp"
                                 data-action="scan-and-submit" data-scan-mode="face" />
            </form>
        @endif

        {{-- ═══ মলাট আর ছবি ═══ --}}
        <section class="overflow-hidden rounded-2xl border border-(--color-border) bg-(--color-surface-card)" data-me-head>
            <div class="relative h-[300px] max-md:h-[180px]" data-me-cover
                 @if (! $p['cover_url'])
                     style="background: linear-gradient(120deg, {{ $p['company_colour'] ?? 'var(--color-brand-900)' }} 0%, var(--color-dash-2563eb) 48%, var(--color-dash-06b6d4) 100%)"
                 @endif>
                @if ($p['cover_url'])
                    <img src="{{ $p['cover_url'] }}" alt="" class="absolute inset-0 h-full w-full object-cover">
                @else
                    {{-- ⓘ নমুনার হালকা পাহাড় আর সূর্য --}}
                    <svg class="absolute inset-0 h-full w-full text-white" viewBox="0 0 400 160" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
                        <circle cx="305" cy="50" r="22" fill="currentColor" opacity=".5"/>
                        <path d="M0 112C60 74 112 86 160 104S262 64 322 90 380 98 400 86V160H0z" fill="currentColor" opacity=".16"/>
                        <path d="M0 138C80 108 140 122 200 132S320 106 400 124V160H0z" fill="currentColor" opacity=".26"/>
                    </svg>
                    @if ($self)
                        <span class="absolute start-5 top-4 rounded-full bg-black/30 px-3 py-0.5 text-[13px] text-white">{{ __('profile.cover_default') }}</span>
                    @endif
                @endif

                @if ($self)
                    <div class="absolute end-5 bottom-[18px] flex items-center gap-2">
                        @if ($p['cover_url'])
                            <form method="POST" action="{{ route('profile.cover.remove') }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="inline-flex items-center gap-1.5 rounded-full bg-black/50 px-3.5 py-[7px] text-sm font-semibold text-white">{{ __('profile.remove_cover') }}</button>
                            </form>
                        @endif
                        <label for="me-cover-file" class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-black/50 px-3.5 py-[7px] text-sm font-semibold text-white" data-me-cover-button>
                            {!! $i('camera', 15) !!}{{ __('profile.change_cover') }}
                        </label>
                    </div>
                @endif
            </div>

            <div class="flex flex-wrap items-end gap-6 px-8 pb-[22px] max-md:px-4">
                <div class="relative -mt-[88px] size-[176px] shrink-0 max-md:-mt-[60px] max-md:size-[120px]" data-me-avatar>
                    <div class="grid size-full place-items-center overflow-hidden rounded-full border-[6px] border-(--color-surface-card) bg-linear-135 from-(--color-dash-f59e0b) to-(--color-dash-ea580c) text-[62px] font-bold text-white max-md:text-[42px]">
                        @if ($p['photo_url'])
                            <img src="{{ $p['photo_url'] }}" alt="{{ $p['name'] }}" class="h-full w-full object-cover">
                        @else
                            {{ $p['initial'] }}
                        @endif
                    </div>
                    @if ($self)
                        {{-- ⓘ ছবির কোণের ক্যামেরা — গোলের বাইরের খোলসে, তাই গোলের overflow তাকে কাটে না --}}
                        <label for="me-photo-file" class="absolute end-1 bottom-2 grid size-[42px] cursor-pointer place-items-center rounded-full border-2 border-(--color-surface-card) bg-(--color-brand-50) text-(--color-brand-700)"
                               title="{{ __('profile.change_photo') }}" aria-label="{{ __('profile.change_photo') }}" data-me-photo-button>
                            {!! $i('camera', 21) !!}
                        </label>
                    @endif
                </div>

                <div class="min-w-0 flex-1 pb-1">
                    <h2 class="text-[32px] leading-tight font-bold text-(--color-ink) max-md:text-2xl">{{ $p['name'] }}</h2>
                    @if ($p['designation'])
                        <div class="text-[17px] font-semibold text-(--color-ink-muted)">{{ $p['designation'] }}</div>
                    @endif
                    <div class="flex items-center gap-1.5 text-(--color-ink-muted)">
                        {!! $i('building', 16) !!}{{ implode(' · ', array_filter([$p['company'], $p['branch']])) }}
                    </div>
                </div>

                <div class="flex flex-wrap gap-2.5 pb-1.5" data-me-actions>
                    @if ($self)
                        <a href="{{ route('profile') }}" class="{{ $btnPri }}">{!! $i('pencil', 17) !!}{{ __('profile.edit') }}</a>
                    @endif
                    {{-- ⓘ মেসেঞ্জার আর কল তৈরি না হওয়া পর্যন্ত "শীঘ্রই আসছে" (নমুনার ঘ-৫) --}}
                    <button type="button" disabled title="{{ __('profile.soon_long') }}" class="{{ $self ? $btn : $btnPri }} cursor-not-allowed" data-me-soon>
                        {!! $i('chat', 17) !!}{{ __('profile.message') }}{!! $soon !!}
                    </button>
                    <button type="button" disabled title="{{ __('profile.soon_long') }}" class="{{ $btn }} cursor-not-allowed" data-me-soon>
                        {!! $i('phone', 17) !!}{{ __('profile.call') }}{!! $soon !!}
                    </button>
                </div>
            </div>
        </section>

        <div class="mt-5 grid items-start gap-5 lg:grid-cols-[minmax(0,520px)_minmax(0,1fr)]">
            {{-- ═══ বাঁয়ে: পরিচয় · আমার ফোনগুলো · নিরাপত্তা ═══ --}}
            <div class="flex flex-col gap-5">
                @if ($about !== null)
                    <section class="{{ $card }}" data-me-about>
                        <h3 class="mb-3 text-[17px] font-bold">{{ __('profile.about') }}</h3>
                        @foreach ([
                            ['phone', 'phone', $about['phone']],
                            ['mail', 'email', $about['email']],
                            ['idcard', 'employee_no', $about['employee_no']],
                            ['calendar', 'joined_on', $date($about['joined_on'])],
                            ['shield', 'role', $about['role']],
                            ['building', 'branches', $about['branches']],
                        ] as [$icon, $label, $value])
                            <div class="{{ $row }}">
                                <span class="text-(--color-ink-muted)">{!! $i($icon) !!}</span>
                                <div class="min-w-0 flex-1 leading-snug">
                                    <small class="block text-[12.5px] text-(--color-ink-muted)">{{ __('profile.'.$label) }}</small>
                                    <span class="text-[15px] break-words {{ $value ? '' : 'text-(--color-ink-muted)' }}">{{ $value ?: __('profile.not_set') }}</span>
                                </div>
                            </div>
                        @endforeach
                    </section>
                @endif

                @if ($self)
                    <section class="{{ $card }}" data-me-phones>
                        <h3 class="mb-3 text-[17px] font-bold">{{ __('profile.my_phones') }}</h3>
                        @forelse ($devices as $d)
                            <div class="{{ $row }}">
                                <span class="text-(--color-ink-muted)">{!! $i('device') !!}</span>
                                <div class="min-w-0 flex-1 leading-snug">
                                    <span class="text-[15px]">{{ $d['name'] }}</span>
                                    <small class="block text-[12.5px] text-(--color-ink-muted)">
                                        {{ __('profile.this_phone_seen', ['when' => app(\App\Core\Services\ProfileFacts::class)->ago($d['last_used_at'])]) }}{{ $d['app_version'] ? ' · '.$d['app_version'] : '' }}
                                    </small>
                                </div>
                                <form method="POST" action="{{ route('me.devices.forget', $d['id']) }}"
                                      data-confirm="{{ __('profile.remove_phone_confirm') }}" data-confirm-tone="danger">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="rounded-full border border-(--color-badge-danger-bg) px-2.5 py-0.5 text-xs font-semibold text-(--color-badge-danger-ink)">{{ __('profile.remove_phone') }}</button>
                                </form>
                            </div>
                        @empty
                            <p class="text-sm text-(--color-ink-muted)">{{ __('profile.no_phones') }}</p>
                        @endforelse
                    </section>

                    <section class="{{ $card }}" data-me-security>
                        <h3 class="mb-3 text-[17px] font-bold">{{ __('profile.security') }}</h3>
                        @foreach (array_filter([
                            $security['password_url'] ? ['key', 'password', __('profile.password_note'), $security['password_url']] : null,
                            $security['two_step_url'] ? ['shield', 'two_step', $security['two_step'] ? __('profile.two_step_on') : __('profile.two_step_off'), $security['two_step_url']] : null,
                            $security['sessions_url'] ? ['browser', 'sessions', __('profile.sessions_note'), $security['sessions_url']] : null,
                        ]) as [$icon, $label, $note, $url])
                            <a href="{{ $url }}" class="{{ $row }} hover:text-(--color-brand-700)">
                                <span class="text-(--color-ink-muted)">{!! $i($icon) !!}</span>
                                <div class="min-w-0 flex-1 leading-snug">
                                    <span class="text-[15px]">{{ __('profile.'.$label) }}</span>
                                    <small class="block text-[12.5px] text-(--color-ink-muted)">{{ $note }}</small>
                                </div>
                                <span class="text-(--color-ink-muted)">{!! $i('chev', 16) !!}</span>
                            </a>
                        @endforeach
                    </section>
                @endif

                @if (! $self && $hidden !== [])
                    {{-- ⓘ নমুনার ছোট ছবি: কী লুকানো, সেটা নাম ধরে --}}
                    <section class="{{ $card }}" data-me-hidden>
                        @foreach (array_filter([
                            in_array('phone', $hidden, true) ? ['phone', 'hidden_phone'] : null,
                            in_array('stats', $hidden, true) ? ['chart', 'hidden_stats'] : null,
                            in_array('security', $hidden, true) ? ['device', 'hidden_security'] : null,
                        ]) as [$icon, $label])
                            <div class="flex items-center gap-2 py-1 text-sm text-(--color-ink-muted)">
                                {!! $i($icon, 16) !!}{{ __('profile.'.$label) }}
                                <span class="ms-auto rounded-full border border-(--color-border) bg-(--color-surface-sunken) px-2 text-[11.5px] font-semibold">{{ __('profile.hidden') }}</span>
                            </div>
                        @endforeach
                    </section>
                @endif
            </div>

            {{-- ═══ ডানে: এই মাসে আমার কাজ · আমার সাম্প্রতিক কাজ ═══ --}}
            <div class="flex flex-col gap-5">
                @if ($month !== null)
                    <div data-me-month>
                        <h3 class="mb-3 text-[17px] font-bold">
                            {{ __('profile.my_month') }} <small class="text-[13px] font-medium text-(--color-ink-muted)">· {{ $month['label'] }}</small>
                        </h3>
                        @if ($month['stats'] === [])
                            <p class="{{ $card }} text-sm text-(--color-ink-muted)">{{ __('profile.no_stats') }}</p>
                        @else
                            <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2 xl:grid-cols-4">
                                @foreach ($month['stats'] as $s)
                                    <div class="flex items-center gap-3.5 rounded-[14px] border border-(--color-border) bg-(--color-surface-card) px-[18px] py-4" data-me-stat="{{ $s['key'] }}">
                                        <span class="grid size-[46px] shrink-0 place-items-center rounded-xl text-white" style="background: {{ $statTone($s['tone']) }}">{!! $i($s['icon'], 24) !!}</span>
                                        <div class="min-w-0">
                                            <small class="block text-sm text-(--color-ink-muted)">{{ $s['label'] }}</small>
                                            <b class="num block text-2xl font-bold whitespace-nowrap">{{ $s['value'] }}</b>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                <section class="{{ $card }}" data-me-recent>
                    <h3 class="mb-3 flex items-center text-[17px] font-bold">
                        {{ $self ? __('profile.my_recent') : __('profile.their_recent') }}
                        @if ($self && \Illuminate\Support\Facades\Route::has('governance.audit.index') && auth()->user()?->can('governance.audit.view'))
                            <a href="{{ route('governance.audit.index', ['user' => auth()->id()]) }}" class="ms-auto text-[13px] font-medium text-(--color-ink-muted) hover:text-(--color-brand-700)">{{ __('profile.see_all') }}</a>
                        @endif
                    </h3>
                    @if ($p['recent'] === [])
                        <p class="text-sm text-(--color-ink-muted)">{{ __('profile.no_recent') }}</p>
                    @else
                        <ul class="relative m-0 list-none p-0">
                            @foreach ($p['recent'] as $r)
                                @php [$icon, $tone] = $look($r); @endphp
                                <li class="relative flex gap-2.5 pb-[18px] last:pb-0">
                                    @unless ($loop->last)
                                        <span aria-hidden="true" class="absolute start-[19px] top-10 bottom-0.5 w-0.5 bg-(--color-border)"></span>
                                    @endunless
                                    <span class="grid size-10 shrink-0 place-items-center rounded-full text-white" style="background: {{ $tone }}">{!! $i($icon, 18) !!}</span>
                                    <div class="min-w-0 text-[15px] leading-snug">
                                        @if ($r['url'])
                                            <a href="{{ $r['url'] }}" class="num font-bold hover:underline">{{ $r['no'] }}</a>
                                        @else
                                            <b class="num">{{ $r['no'] }}</b>
                                        @endif
                                        {{ $r['action'] }}
                                        @if ($r['summary'] !== '')
                                            <small class="block text-xs text-(--color-ink-muted)">{{ $r['summary'] }}</small>
                                        @endif
                                        <time datetime="{{ $r['at'] }}" class="block text-[11.5px] text-(--color-ink-muted)">{{ $r['ago'] }}</time>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </div>
    </div>
</x-layouts.app>

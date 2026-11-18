@props(['level' => 'info'])

{{--
    ⭐ অবস্থার চিপ — প্রতিটা রঙের একটাই অর্থ (মালিক, ১৮ নভেম্বর ২০২৬, নীল প্যানেলের নমুনা):
        urgent  লাল    জরুরি
        today   কমলা   আজই
        week    হলুদ   এ সপ্তাহে
        ok      সবুজ   ঠিক আছে
        info    নীল    সাধারণ তথ্য (লেখা পাতা দেয়)
    ⓘ রং একা কিছু বলে না — চিপে সবসময় শব্দ থাকে; slot খালি হলে অর্থের নিজের শব্দ ([[home.level_*]])।
    ⓘ [[x-ui.badge]] কাগজের অবস্থা বলে (খসড়া, পোস্ট …) — হালকা জোড়া; এটা কাজের তাড়া বলে, ভরাট রঙে, যাতে দূর থেকে চোখে পড়ে।
       রং `tokens.css`-এর `--color-status-*` (রূপ বদলাতে পারে, অর্থ নয়)।
--}}
@php
    $tones = [
        'urgent' => ['bg' => 'var(--color-status-urgent)', 'ink' => 'var(--color-status-on)'],
        'today' => ['bg' => 'var(--color-status-today)', 'ink' => 'var(--color-status-on)'],
        // ⓘ হলুদ ভরাটে সাদা লেখা পড়া যায় না — কালো, দুই থিমেই
        'week' => ['bg' => 'var(--color-status-week-bar)', 'ink' => 'var(--color-status-week-on)'],
        'ok' => ['bg' => 'var(--color-status-ok)', 'ink' => 'var(--color-status-on)'],
        'info' => ['bg' => 'var(--color-brand-50)', 'ink' => 'var(--color-brand-700)'],
    ];
    $level = array_key_exists($level, $tones) ? $level : 'info';
    $words = trim((string) $slot) !== '' ? $slot : ($level === 'info' ? '' : __('home.level_'.$level));
@endphp

<span {{ $attributes->merge(['class' => 'status-chip']) }} data-status-chip="{{ $level }}"
      style="background: {{ $tones[$level]['bg'] }}; color: {{ $tones[$level]['ink'] }}">{{ $words }}</span>

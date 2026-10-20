{{-- ⭐ কাগজের অবস্থার চিপ — আইকন + রং + বাংলা লেখা একসাথে, সব `resources/design/paper_states.json` থেকে ([[PaperStates]];
     মালিকের অনুমোদিত নকশা, ২০ অক্টোবর ২০২৬)। অ্যাপের চিপের একই ফাইল, তাই দুই পর্দা এক কথা বলে।

     ⓘ প্রপ: `state` (draft · waiting · sent_back · posting · posted · cancelled · not_posted · rejected · approved), `level` আর `levels`
       (কেবল waiting-এ, ইংরেজি অঙ্কে "2-এর 1")। না দিলে ধাপছাড়া লেখা।
     ⛔ অচেনা অবস্থা বা `app_only` অবস্থা (ফোনের "unsent") — কিছুই আঁকা হয় না; ওয়েবে এমন কাগজ থাকে না।
     ⓘ রং আলো/অন্ধকার দুইটাই CSS চলকে (`--ps-*`), থিম বদলালে নিজে বদলায়; নিয়মগুলো পাতায় একবারই বসে।
     ⓘ পর্দা-পাঠক পড়ে ফাইলের `semantics_bn` লেখা (sr-only), চোখের লেখাটা নয় — দুইবার পড়া হয় না। --}}
{{-- ⓘ `kind` (v4): money (ডিফল্ট) · stock · claim — মজুদের কাগজে "পাকা · মজুদে উঠেছে" ধরনের নিজের লেখা --}}
@props(['state', 'level' => null, 'levels' => null, 'kind' => 'money'])

@php
    $chip = \App\Core\Support\PaperStates::chip((string) $state, $level === null ? null : (int) $level, $levels === null ? null : (int) $levels, (string) $kind);
    $firstOnPage = $chip !== null && ! request()->attributes->get('paper-state-css');
    if ($firstOnPage) {
        request()->attributes->set('paper-state-css', true);
    }
@endphp

@if ($chip)
    @if ($firstOnPage)
        <style @nonce>
            .paper-state { display: inline-flex; align-items: center; gap: .25rem; max-width: 100%; border-width: 1px; border-radius: 9999px;
                padding: .125rem .5rem; font-size: .75rem; font-weight: 600; line-height: 1.25rem; white-space: nowrap;
                color: var(--ps-fg); background: var(--ps-bg); border-color: var(--ps-bd); }
            :root[data-theme='dark'] .paper-state { color: var(--ps-fg-d); background: var(--ps-bg-d); border-color: var(--ps-bd-d); }
        </style>
    @endif
    <span data-paper-state="{{ $chip['state'] }}" {{ $attributes->class('paper-state') }}
          style="--ps-fg: {{ $chip['light']['foreground'] }}; --ps-bg: {{ $chip['light']['background'] }}; --ps-bd: {{ $chip['light']['border'] }}; --ps-fg-d: {{ $chip['dark']['foreground'] }}; --ps-bg-d: {{ $chip['dark']['background'] }}; --ps-bd-d: {{ $chip['dark']['border'] }}; border-style: {{ $chip['border_style'] }}">
        <x-ui.icon :name="$chip['icon']" :size="14" />
        <span aria-hidden="true">{{ $chip['label'] }}</span>
        <span class="sr-only">{{ $chip['speech'] }}</span>
    </span>
@endif

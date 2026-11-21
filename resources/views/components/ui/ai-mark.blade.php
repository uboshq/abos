{{--
    ⭐ The living "Ai" mark of Copilot — owner, 21 Nov 2026 (sample copilot-icon-options, option "★ সব মিলিয়ে একটায়" = o7;
    v5: the dot of the small "i" is golden with a faint glow).

    An open-corner rounded-square border, a capital "A", a small "i" (stem + golden dot) and three sparkle stars, in a
    teal → blue → purple gradient. One 8-second story: the border draws itself → the stars pop in → A and i hop and the
    dot bounces → a white light runs round the border → the stars twinkle and float → reset. All the while the gradient
    turns (SMIL) and the mark breathes. ⛔ No background — no disc, no tile.

    ⓘ Colours and motion live in [[resources/css/ai-mark.css]], not here (EveryScreenObeysTheThemeTest).
    ⓘ Only the ABOS looks draw it ([[AiMark::shows()]]); SAP and Dynamic keep their old mark.

    @param $size  pixels (width = height)
    @param $busy  true → "thinking": the same story at twice the speed. With Alpine, bind `x-bind:data-ai-busy="busy"`.
--}}
@props(['size' => 24, 'busy' => false])
@php
    // ⓘ each instance its own gradients — two marks on one page never share (or lose) a gradient
    $id = 'aim-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10));
    $border = 'M26 10H16a7 7 0 0 0-7 7v15a7 7 0 0 0 7 7h15a7 7 0 0 0 7-7V24';
    $fill = 'url(#'.$id.')';
@endphp
<svg viewBox="0 0 48 48" width="{{ $size }}" height="{{ $size }}" aria-hidden="true" focusable="false" data-ai-mark
     @if ($busy) data-ai-busy @endif
     style="--ai-mark-fast: url(#{{ $id }}-f); --ai-mark-still: url(#{{ $id }}-s)"
     {{ $attributes->class('ai-mark') }}>
    <defs>
        <linearGradient id="{{ $id }}" x1="0" y1="0" x2="1" y2="1">
            <stop class="ai-mark-c1" offset="0"/><stop class="ai-mark-c2" offset=".5"/><stop class="ai-mark-c3" offset="1"/>
            <animateTransform attributeName="gradientTransform" type="rotate" from="0 .5 .5" to="360 .5 .5" dur="3s" repeatCount="indefinite"/>
        </linearGradient>
        {{-- ⓘ "thinking": the gradient turns twice as fast (SMIL's dur cannot be changed from CSS, so a second gradient) --}}
        <linearGradient id="{{ $id }}-f" x1="0" y1="0" x2="1" y2="1">
            <stop class="ai-mark-c1" offset="0"/><stop class="ai-mark-c2" offset=".5"/><stop class="ai-mark-c3" offset="1"/>
            <animateTransform attributeName="gradientTransform" type="rotate" from="0 .5 .5" to="360 .5 .5" dur="1.5s" repeatCount="indefinite"/>
        </linearGradient>
        {{-- ⓘ "reduce motion": a gradient that never turns --}}
        <linearGradient id="{{ $id }}-s" x1="0" y1="0" x2="1" y2="1">
            <stop class="ai-mark-c1" offset="0"/><stop class="ai-mark-c2" offset=".5"/><stop class="ai-mark-c3" offset="1"/>
        </linearGradient>
    </defs>
    <g class="ai-mark-mk">
        <path class="ai-mark-bd ai-mark-k" d="{{ $border }}" fill="none" stroke="{{ $fill }}" stroke-width="3.6" stroke-linecap="round"/>
        <path class="ai-mark-comet" d="{{ $border }}" fill="none" stroke-width="3.8" stroke-linecap="round"/>
        <path class="ai-mark-la ai-mark-f" d="M14.6 32.5 19.6 18h3l5 14.5h-3.3l-1.1-3.4h-5.3l-1.1 3.4zm5.2-6.3h3.5l-1.75-5.5z" fill="{{ $fill }}"/>
        <g class="ai-mark-li">
            <rect class="ai-mark-f" x="29.4" y="22.6" width="3.2" height="9.9" rx=".6" fill="{{ $fill }}"/>
            <circle class="ai-mark-dot" cx="31" cy="19.2" r="1.9"/>
        </g>
        <path class="ai-mark-star ai-mark-f" d="M36 4.5c.35 3.6 1.9 5.15 5.5 5.5-3.6.35-5.15 1.9-5.5 5.5-.35-3.6-1.9-5.15-5.5-5.5 3.6-.35 5.15-1.9 5.5-5.5z" fill="{{ $fill }}"/>
        <path class="ai-mark-star ai-mark-star2 ai-mark-f" d="M43 2c.18 1.8.95 2.57 2.75 2.75-1.8.18-2.57.95-2.75 2.75-.18-1.8-.95-2.57-2.75-2.75C42.05 4.57 42.82 3.8 43 2z" fill="{{ $fill }}"/>
        <path class="ai-mark-star ai-mark-star3 ai-mark-f" d="M42.5 12c.2 2 1.05 2.85 3.05 3.05-2 .2-2.85 1.05-3.05 3.05-.2-2-1.05-2.85-3.05-3.05 2-.2 2.85-1.05 3.05-3.05z" fill="{{ $fill }}"/>
    </g>
</svg>

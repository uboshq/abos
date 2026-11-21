@props(['name', 'size' => 16])
{{-- ⭐ Lucide চিহ্ন — রঙিন মেনুর সারির নিজের আইকন ([[LucideIcons]]; মালিক, ৮ নভেম্বর ২০২৬)। লেখার রং নেয় (currentColor)। --}}
{{-- ⭐ Copilot's brain row: in the ABOS looks the living "Ai" mark instead (owner, 21 Nov 2026; [[AiMark]]) --}}
@php($inner = \App\Core\Navigation\LucideIcons::inner((string) $name))
@if ($name === 'brain' && \App\Core\Support\AiMark::shows())
    <x-ui.ai-mark :size="$size" data-licon="brain" {{ $attributes }} />
@elseif ($inner !== '')
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-licon="{{ $name }}" {{ $attributes->merge(['class' => 'shrink-0']) }}>{!! $inner !!}</svg>
@endif

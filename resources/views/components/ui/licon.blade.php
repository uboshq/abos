@props(['name', 'size' => 16])
{{-- ⭐ Lucide চিহ্ন — রঙিন মেনুর সারির নিজের আইকন ([[LucideIcons]]; মালিক, ৮ নভেম্বর ২০২৬)। লেখার রং নেয় (currentColor)। --}}
@php($inner = \App\Core\Navigation\LucideIcons::inner((string) $name))
@if ($inner !== '')
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-licon="{{ $name }}" {{ $attributes->merge(['class' => 'shrink-0']) }}>{!! $inner !!}</svg>
@endif

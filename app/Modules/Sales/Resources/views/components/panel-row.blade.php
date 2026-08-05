@props(['label', 'strong' => false])

{{-- প্যানেলের একটা সারি — বাঁয়ে কথা, ডানে অঙ্ক। --}}
<div class="flex items-center justify-between gap-2 {{ $strong ? 'font-semibold' : '' }}">
    <span class="{{ $strong ? '' : 'text-(--color-ink-muted)' }}">{{ $label }}</span>
    {{ $slot }}
</div>

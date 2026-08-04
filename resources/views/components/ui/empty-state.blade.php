@props(['message' => null, 'icon' => '📦'])

{{--
    Empty state — সেকশন ১৫.১৭।

    ফাঁকা তালিকা মানে ব্যবহারকারী আটকে গেছে। শুধু "কিছু নেই" লিখলে সে জানে
    না এরপর কী করতে হবে। তাই অন্তত একটা করণীয় থাকতে হবে — slot-এ বোতাম দিন।
--}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <span class="mb-3 text-4xl" aria-hidden="true">{{ $icon }}</span>

    <p class="text-(--color-ink-muted)">
        {{ $message ?? __('core.empty.nothing_here') }}
    </p>

    @if (! $slot->isEmpty())
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
            {{ $slot }}
        </div>
    @endif
</div>

{{-- মোটের ঘরগুলো — তিনটা ডকুমেন্টেই একই ছকে। --}}
<dl class="ms-auto w-full max-w-xs space-y-1 text-sm">
    @foreach ($rows as $label => $value)
        <div class="flex justify-between gap-4 {{ $loop->last ? 'border-t border-(--color-border) pt-1 font-semibold' : '' }}">
            <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
            <dd class="num">{{ \App\Core\Support\Money::format($value) }}</dd>
        </div>
    @endforeach
</dl>

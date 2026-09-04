{{ $claim->customer?->name_bn ?: $claim->customer?->name_en }}
<span class="block text-2xs text-(--color-ink-muted)">{{ $claim->customer?->code }}</span>

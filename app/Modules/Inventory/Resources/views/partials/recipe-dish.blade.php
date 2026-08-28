{{-- খাবারের নাম, আর নিষ্ক্রিয় হলে সেটাও। --}}
<a href="{{ route('inventory.recipe.edit', $recipe) }}" class="underline">
    {{ $recipe->product?->name() }}
</a>

@unless ($recipe->is_active)
    <span class="ms-1 text-2xs text-(--color-ink-muted)">{{ __('core.state.inactive') }}</span>
@endunless

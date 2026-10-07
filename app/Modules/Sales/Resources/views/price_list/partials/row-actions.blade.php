{{-- এক সারির কাজ — এডিট (চাবি থাকলে) আর ইতিহাস। --}}
<div class="flex items-center justify-end gap-3">
    @if ($canEdit)
        <details class="text-sm">
            <summary class="cursor-pointer text-(--color-brand-500) underline-offset-2 hover:underline">
                {{ __('sales::price_list.edit') }}
            </summary>
            <form method="POST" action="{{ route('sales.price_list.update', $product) }}" class="mt-2 flex items-center gap-2">
                @csrf
                @method('PUT')
                <input type="number" name="sale_price" step="0.0001" min="0" required
                       value="{{ (string) $product->sale_price }}"
                       aria-label="{{ __('sales::price_list.price') }}"
                       class="h-(--spacing-field-compact) w-28 rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-2 text-right">
                <x-ui.button type="submit" tone="primary">{{ __('sales::price_list.save') }}</x-ui.button>
            </form>
        </details>
    @endif
    <a href="{{ route('sales.price_list.history', $product) }}"
       class="text-sm text-(--color-brand-500) underline-offset-2 hover:underline">{{ __('sales::price_list.history') }}</a>
</div>

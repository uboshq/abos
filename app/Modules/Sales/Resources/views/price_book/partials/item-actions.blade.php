{{-- এক দরের কাজ — ইতিহাস, আর (চাবি থাকলে) তুলে দেওয়া। --}}
<div class="flex items-center justify-end gap-3">
    <a href="{{ route('sales.price_book.history', [$list, $item->product_id]) }}"
       class="text-sm text-(--color-brand-500) underline-offset-2 hover:underline">{{ __('sales::price_book.history') }}</a>
    @if ($canManage)
        <form method="POST" action="{{ route('sales.price_book.items.destroy', [$list, $item]) }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-sm text-(--color-danger) underline-offset-2 hover:underline">{{ __('sales::price_book.remove') }}</button>
        </form>
    @endif
</div>

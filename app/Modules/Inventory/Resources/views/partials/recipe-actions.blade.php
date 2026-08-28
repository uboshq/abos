{{-- সারির কাজ — সম্পাদনা, আর চালু/বন্ধ।

     "মোছা" নেই: রেসিপি মুছে ফেললে পুরনো বিক্রির ইতিহাস অনাথ হত
     ("ওই দিন কী দিয়ে বানানো হয়েছিল")। নিয়ম ৫। --}}
<x-ui.row-actions>
    @can('update', $recipe)
        <a href="{{ route('inventory.recipe.edit', $recipe) }}" role="menuitem"
           class="flex min-h-(--spacing-touch) items-center px-3 text-sm hover:bg-(--color-surface-hover)">
            {{ __('core.action.edit') }}
        </a>
    @endcan

    @can('delete', $recipe)
        @if ($recipe->is_active)
            <form method="POST" action="{{ route('inventory.recipe.destroy', $recipe) }}">
                @csrf @method('DELETE')
                <button type="submit" role="menuitem"
                        class="flex min-h-(--spacing-touch) w-full items-center px-3 text-start text-sm
                               hover:bg-(--color-surface-hover)">
                    {{ __('inventory::action.show_inactive') }}
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('inventory.recipe.activate', $recipe) }}">
                @csrf
                <button type="submit" role="menuitem"
                        class="flex min-h-(--spacing-touch) w-full items-center px-3 text-start text-sm
                               hover:bg-(--color-surface-hover)">
                    {{ __('core.state.active') }}
                </button>
            </form>
        @endif
    @endcan
</x-ui.row-actions>

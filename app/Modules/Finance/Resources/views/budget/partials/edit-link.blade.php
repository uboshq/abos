<a href="{{ route('finance.budget.create', array_filter([
        'year' => $year,
        'account' => $row['account']->id,
        'center' => $row['center']?->id,
    ])) }}"
   class="text-sm text-(--color-brand-500) underline-offset-2 hover:underline">
    {{ __('finance::budget.edit') }}
</a>

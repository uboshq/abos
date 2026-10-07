{{-- গুদামের নাম → তার নিজের পাতা। --}}
<a href="{{ route('inventory.warehouse.show', $warehouse) }}"
   class="text-(--color-link) hover:underline">{{ $warehouse->name() }}</a>

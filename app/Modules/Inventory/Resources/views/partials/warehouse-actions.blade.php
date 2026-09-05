@can('update', $warehouse)
    <a href="{{ route('inventory.warehouse.edit', $warehouse) }}"
       class="text-2xs text-(--color-brand-500) underline-offset-2 hover:underline">
        {{ __('core.action.edit') }}
    </a>
@endcan

{{--
    গুদামের ভিতরের জায়গা — ব্লক ▸ র‍্যাক ▸ শেলফ।

    ⚠️ ঢোকার পথটা এখানেই, আলাদা কোনো মেনু সারিতে নয়। তাক গুদামের
    সংজ্ঞার অংশ, আর মেনুতে বসালে যাঁর গুদামে তাক নেই তিনিও রোজ একটা
    খালি পাতা দেখতেন। ⓘ যাঁর দরকার, তিনি গুদামের তালিকা থেকেই আসেন।
--}}
@can('inventory.warehouse.view')
    <a href="{{ route('inventory.warehouse.place.index', $warehouse) }}"
       class="ms-3 text-2xs text-(--color-brand-500) underline-offset-2 hover:underline">
        {{ __('inventory::action.places') }}
    </a>
@endcan

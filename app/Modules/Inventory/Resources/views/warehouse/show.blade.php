{{--
    এক গুদামের নিজের পাতা।

    ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
    ভাড়ার চুক্তি গুদামের সাথে জোড়া লাগাতে গিয়ে ধরা পড়ল গুদামের কোনো পাতাই
    নেই — তালিকা আর সম্পাদনার ফর্ম ছাড়া। মালিককে জানানোর পর: *"ok"*।

    ⓘ পাতাটা ছোট, আর ইচ্ছে করেই: রোজকার তিনটা প্রশ্ন — কী আছে, কোথায় বসে,
    আর কাগজপত্র কোথায়। ⛔ মজুদের পুরো তালিকা এখানে আঁকা হয় না, কারণ ওটার
    নিজের পর্দা আছে আর সেখানে ছাঁকনি-সাজানো সবই আছে; এখানে বসালে দুইটা
    আলাদা তালিকা একদিন দুই উত্তর দিত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $warehouse->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$warehouse->name()"
                          :subtitle="$warehouse->code" />
    </x-slot:header>

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- কী আছে — সংখ্যা দুইটা, আর দুইটাই নিজের তালিকায় নামে --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('inventory::warehouse.what_is_here') }}</h2>

            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex items-baseline justify-between gap-2">
                    <dt class="text-(--color-ink-muted)">{{ __('inventory::warehouse.products_with_stock') }}</dt>
                    <dd>
                        <a href="{{ route('inventory.stock.index', ['warehouse' => $warehouse->id]) }}"
                           class="num font-semibold text-(--color-link) hover:underline">{{ $productCount }}</a>
                    </dd>
                </div>

                <div class="flex items-baseline justify-between gap-2">
                    <dt class="text-(--color-ink-muted)">{{ __('inventory::warehouse.places_count') }}</dt>
                    <dd>
                        <a href="{{ route('inventory.warehouse.place.index', $warehouse) }}"
                           class="num font-semibold text-(--color-link) hover:underline">{{ $places }}</a>
                    </dd>
                </div>
            </dl>
        </section>

        {{-- কোথায় বসে --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('inventory::warehouse.where_it_is') }}</h2>

            <dl class="mt-3 space-y-2 text-sm">
                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('inventory::field.branch') }}</dt>
                    <dd>{{ $warehouse->branch?->name() ?? '—' }}</dd>
                </div>

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('inventory::field.address') }}</dt>
                    <dd>{{ (app()->getLocale() === 'bn' ? $warehouse->address_bn : $warehouse->address_en) ?: ($warehouse->address_en ?: '—') }}</dd>
                </div>

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('core.table.status') }}</dt>
                    <dd>
                        {{ $warehouse->is_active ? __('core.state.active') : __('core.state.inactive') }}
                        @if ($warehouse->is_default)
                            · {{ __('inventory::warehouse.is_default') }}
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        {{-- কাগজপত্র — ⛔ সংখ্যা নয়, লিংক।

             মজুদ মডিউল অর্থ মডিউলের নাম জানে না (আজই ঐ সীমাটা অর্থের দিকে
             সারানো হয়েছে), তাই ভাড়ার হিসাব এখানে গোনা হয় না — ছাঁকা
             তালিকাটা অর্থের পাতাতেই খোলে, চালু ও বন্ধ দুইটাই। --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('inventory::warehouse.papers') }}</h2>

            <ul class="mt-3 space-y-2 text-sm">
                @if ($rentalUrl)
                    <li>
                        <a href="{{ $rentalUrl }}" class="text-(--color-link) hover:underline">
                            {{ __('inventory::warehouse.rental_contracts') }}
                        </a>
                        <span class="block text-2xs text-(--color-ink-muted)">
                            {{ __('inventory::warehouse.rental_note') }}
                        </span>
                    </li>
                @endif

                <li>
                    <a href="{{ route('inventory.warehouse.edit', $warehouse) }}"
                       class="text-(--color-link) hover:underline">{{ __('core.action.edit') }}</a>
                </li>
            </ul>
        </section>
    </div>
</x-layouts.app>

{{--
    গুদামের ভিতরের জায়গা — ব্লক ▸ র‍্যাক ▸ শেলফ।

    ── কেন গাছটা সমতল তালিকা হিসেবে আঁকা, ভাঁজ করা নয় ──────────────────
    ⚠️ ভাঁজ করা গাছ দেখতে সুন্দর, কিন্তু গুদামের লোক এখানে আসেন **নতুন
    একটা সারি যোগ করতে**, ঘুরে দেখতে নয়। প্রতিটা সারির পাশে তার পুরো
    পথ লেখা থাকলে তিনি এক নজরে দেখেন কী আছে আর কী নেই — আর ইন্ডেন্ট
    দিয়ে গভীরতাটাও চোখে পড়ে।

    ⓘ ফর্মটা উপরে, তালিকার নিচে নয়: পাঁচশো শেলফের গুদামে নিচে থাকলে
    প্রতিবার স্ক্রল করতে হত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $warehouse->name() }} — {{ __('inventory::menu.places') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::menu.places')"
                          :subtitle="$warehouse->code.' — '.$warehouse->name()" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @can('inventory.warehouse.update')
        <section data-boxed
                 class="mb-4 rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <form method="POST" action="{{ route('inventory.warehouse.place.store', $warehouse) }}"
                  class="flex flex-wrap items-end gap-3">
                @csrf

                {{--
                    ⭐ বাবা বাছলেই গভীরতা ঠিক হয়ে যায় — আলাদা কোনো
                    "স্তর" ঘর নেই।

                    ⛔ স্তরটা হাতে বাছতে দিলে "র‍্যাকের নিচে ব্লক" বসানো
                    যেত, আর তখন বসানোর পর্দার তিনটা ঘর ভুল সারি দেখাত।
                    ⓘ কিছু না বাছলে সেটা একটা ব্লক — গুদামের প্রথম ধাপ।
                --}}
                <label class="grid gap-1">
                    <span class="text-2xs text-(--color-ink-muted)">{{ __('inventory::field.parent_place') }}</span>
                    <select name="parent_id"
                            class="h-(--spacing-field) w-56 rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-2">
                        <option value="">{{ __('inventory::field.depth_1') }}</option>
                        @foreach ($places->where('depth', '<', 3) as $place)
                            <option value="{{ $place->id }}" @selected(old('parent_id') == $place->id)>
                                {{ $place->path() }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <x-ui.field name="code" :label="__('inventory::field.code')" required maxlength="32" class="w-32" />
                <x-ui.field name="name_en" :label="__('inventory::field.name_en')" required class="w-48" />
                <x-ui.field name="name_bn" :label="__('inventory::field.name_bn')" class="w-48" />

                {{-- ⓘ ক্রম — গুদামে হাঁটার পথ, বর্ণানুক্রম নয়। খালি রাখলে শূন্য। --}}
                <x-ui.field name="sort" type="number" min="0" :label="__('inventory::field.sort')" class="w-24" />

                <x-ui.button type="submit">{{ __('inventory::action.add_place') }}</x-ui.button>
            </form>
        </section>
    @endcan

    @if ($places->isEmpty())
        {{-- ⓘ খালি থাকা ভুল নয় — ছোট দোকানে তাকের ধারণাই নেই, আর তখন
             বসানোর পর্দায় ঘরগুলো আসেই না। বাক্যটা সেটাই বলে। --}}
        <x-ui.empty-state :message="__('inventory::message.no_places')" />
    @else
        <section data-boxed
                 class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="overflow-x-auto">
                <table class="ui-list w-full">
                    <thead>
                        <tr class="text-2xs text-(--color-ink-muted)">
                            <th class="text-start">{{ __('inventory::field.code') }}</th>
                            <th class="text-start">{{ __('inventory::field.name') }}</th>
                            <th class="text-start">{{ __('inventory::field.path') }}</th>
                            <th class="text-end">{{ __('inventory::field.sort') }}</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($places as $place)
                            <tr class="border-t border-(--color-border)"
                                @class(['opacity-60' => ! $place->is_active])>
                                {{-- ইন্ডেন্ট গভীরতা ধরে — গাছটা চোখে পড়ে,
                                     অথচ ভাঁজ খোলার কোনো ক্লিক লাগে না --}}
                                <td style="padding-inline-start: {{ ($place->depth - 1) * 20 + 12 }}px">
                                    {{ $place->code }}
                                </td>
                                <td>{{ $place->name() }}</td>
                                <td class="text-(--color-ink-muted)">{{ $place->path() }}</td>
                                <td class="num text-end">{{ $place->sort }}</td>
                                <td class="text-end">
                                    @can('inventory.warehouse.update')
                                        @if ($place->is_active)
                                            <form method="POST"
                                                  action="{{ route('inventory.warehouse.place.destroy', [$warehouse, $place]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.button type="submit" tone="secondary">
                                                    {{ __('inventory::action.retire_place') }}
                                                </x-ui.button>
                                            </form>
                                        @else
                                            <span class="text-2xs text-(--color-ink-muted)">
                                                {{ __('inventory::state.inactive') }}
                                            </span>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-layouts.app>

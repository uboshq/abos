{{--
    পণ্যের প্যাক — "১ কার্টন = ১২ বক্স", আর পাশে "= ২৮৮ পিস"।
    ধাপ ৪খ, ২০ সেপ্টেম্বর ২০২৬।

    ── ⭐ কেন "কিসের কত" ঘরটা আছে ────────────────────────────────────────
    মালিকের বাক্য: *"Dairy Milk Chocolate 24 pcs e ek box, 12 box e 1 ctn"*।
    ⓘ তিনি কার্টনকে বক্সে বলেন, পিসে নয়। শুধু পিসে লিখতে বাধ্য করলে
    মাথায় ১২ × ২৪ গুণ করতে হত, আর ভুলটা ঢুকত ঠিক সেখানেই। ⚠️ পাশের
    সংখ্যাটা (= ২৮৮ পিস) কেবল দেখানোর; আসল হিসাব ও পাহারা সার্ভারে
    ([[ProductPackService]])।

    ── ⓘ base-এর সারিটা উপরে, বাঁধা ─────────────────────────────────────
    পণ্যের নিজের একক সবসময় ১, তাই ওটা লেখার কিছু নেই — শুধু দেখা যায়,
    আর চারটা রেডিওতে "ডিফল্ট" হিসেবে বাছা যায়।

    ⚠️ `pack_table` লুকানো ঘরটা জরুরি: সব সারি মুছে জমা দিলে ব্রাউজার
    `packs` পাঠায়ই না, আর তখন "টেবিল ছোঁয়া হয়নি" আর "সব সরাও" আলাদা করা
    যেত না — শেষ প্যাকটা কখনো মোছা যেত না।
--}}
@if ($product->unit_id === null)
    {{-- ⓘ একক ছাড়া প্যাক লেখা যায় না ("১ কার্টন = ২৪ **কী**?")। নতুন পণ্যে
         তাই কেবল কথাটা — লুকিয়ে রাখলে মানুষ ভাবতেন প্যাক বলে কিছু নেই। --}}
    <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <h2 class="mb-1 font-semibold">{{ __('inventory::pack.title') }}</h2>
        <p class="text-2xs text-(--color-ink-muted)">{{ __('inventory::pack.needs_unit') }}</p>
    </section>
@else
<section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
         x-data="productPacks({
             rows: @js($packRows),
             base: @js((int) $product->unit_id),
             baseName: @js($baseName),
             names: @js($unitNames),
             defaults: @js($packDefaults),
         })">
    <h2 class="mb-1 font-semibold">{{ __('inventory::pack.title') }}</h2>
    <p class="mb-3 text-2xs text-(--color-ink-muted)">{{ __('inventory::pack.note') }}</p>

    <input type="hidden" name="pack_table" value="1">

    <div class="table-responsive">
        <table class="ui-lines table-cards w-full text-sm">
            <thead>
                <tr>
                    <th class="text-start">{{ __('inventory::pack.unit') }}</th>
                    <th class="text-end">{{ __('inventory::pack.per_qty') }}</th>
                    <th class="text-start">{{ __('inventory::pack.per_unit') }}</th>
                    <th class="text-end">{{ __('inventory::pack.in_base') }}</th>
                    <th class="text-start">{{ __('inventory::pack.barcode') }}</th>
                    @foreach (['purchase', 'sales', 'pos', 'counter'] as $kind)
                        <th class="text-center">{{ __('inventory::pack.default_'.$kind) }}</th>
                    @endforeach
                    <th><span class="sr-only">{{ __('inventory::pack.remove') }}</span></th>
                </tr>
            </thead>

            <tbody>
                {{-- base — বাঁধা সারি, সবসময় ১ --}}
                <tr class="border-b border-(--color-border)">
                    <td data-label="{{ __('inventory::pack.unit') }}">
                        {{ $baseName }}
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('inventory::pack.is_base') }}</span>
                    </td>
                    <td class="num text-end" data-label="{{ __('inventory::pack.per_qty') }}">1</td>
                    {{-- ⓘ base-এর "কিসের" আর "মোট" বলার কিছু নেই — ড্যাশ দুইটা
                         মাঝে রাখা, নইলে পাশের সংখ্যার গায়ে লেগে পড়া যেত না --}}
                    <td class="text-center text-(--color-ink-muted)"
                        data-label="{{ __('inventory::pack.per_unit') }}">—</td>
                    <td class="text-center text-(--color-ink-muted)"
                        data-label="{{ __('inventory::pack.in_base') }}">—</td>
                    <td data-label="{{ __('inventory::pack.barcode') }}">{{ $product->barcode ?: '—' }}</td>

                    @foreach (['purchase', 'sales', 'pos', 'counter'] as $kind)
                        <td class="text-center" data-label="{{ __('inventory::pack.default_'.$kind) }}">
                            <input type="radio" name="pack_defaults[{{ $kind }}]"
                                   x-model="defaults.{{ $kind }}" :value="base" class="size-4">
                        </td>
                    @endforeach

                    <td></td>
                </tr>

                <template x-for="(row, i) in rows" :key="i">
                    <tr class="border-b border-(--color-border)">
                        <td class="cell-input" data-label="{{ __('inventory::pack.unit') }}">
                            <select :name="'packs[' + i + '][unit_id]'" x-model="row.unit_id"
                                    class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border
                                           border-(--color-border) bg-(--color-surface-card) px-2">
                                <option value="">-</option>
                                @foreach ($units as $unit)
                                    @if ($unit->id !== $product->unit_id)
                                        <option value="{{ $unit->id }}">{{ $unit->name() }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </td>

                        <td class="cell-input" data-label="{{ __('inventory::pack.per_qty') }}">
                            <input type="number" step="0.000001" inputmode="decimal"
                                   :name="'packs[' + i + '][per_qty]'" x-model="row.per_qty"
                                   class="num h-(--spacing-field-compact) w-full sm:w-24 rounded-(--radius-field)
                                          border border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                        </td>

                        <td class="cell-input" data-label="{{ __('inventory::pack.per_unit') }}">
                            {{-- ⚠️ বিকল্পগুলো সার্ভার থেকে, `x-for` দিয়ে নয় —
                                 ২০ সেপ্টেম্বর ২০২৬, ব্রাউজারে মেপে ধরা।

                                 ⛔ আগে এখানে সারিগুলো থেকে বিকল্প বানানো হত।
                                 কিন্তু `x-model` বাছাইটা বসায় **বিকল্পগুলো
                                 তৈরির আগেই**, তাই জমা থাকা "১২ বক্স" পাতা আবার
                                 খুললে "১২ পিস" দেখাত — ডেটাবেসে ঠিক, পর্দায় ভুল।
                                 ⓘ সব একক আগে থেকে থাকলে বাছাইটা সবসময় জায়গা পায়;
                                 কোনটা চলবে না, সেটা সার্ভার বলে
                                 ([[ProductPackService::clean()]])। --}}
                            <select :name="'packs[' + i + '][per_unit_id]'" x-model="row.per_unit_id"
                                    class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border
                                           border-(--color-border) bg-(--color-surface-card) px-2">
                                <option value="{{ $product->unit_id }}">{{ $baseName }}</option>
                                @foreach ($units as $unit)
                                    @if ($unit->id !== $product->unit_id)
                                        <option value="{{ $unit->id }}">{{ $unit->name() }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </td>

                        <td class="num text-end text-(--color-ink-muted)" data-label="{{ __('inventory::pack.in_base') }}"
                            x-text="inBase(row)"></td>

                        <td class="cell-input" data-label="{{ __('inventory::pack.barcode') }}">
                            <input type="text" maxlength="64"
                                   :name="'packs[' + i + '][barcode]'" x-model="row.barcode"
                                   class="h-(--spacing-field-compact) w-full sm:w-40 rounded-(--radius-field)
                                          border border-(--color-border) bg-(--color-surface-card) px-2">
                        </td>

                        @foreach (['purchase', 'sales', 'pos', 'counter'] as $kind)
                            <td class="cell-input text-center" data-label="{{ __('inventory::pack.default_'.$kind) }}">
                                <input type="radio" name="pack_defaults[{{ $kind }}]"
                                       x-model="defaults.{{ $kind }}" :value="row.unit_id" class="size-4">
                            </td>
                        @endforeach

                        <td class="cell-input text-end">
                            <button type="button" @click="remove(i)"
                                    class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                           hover:bg-(--color-surface-hover)">
                                &times;<span class="sr-only">{{ __('inventory::pack.remove') }}</span>
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <button type="button" @click="add()"
            class="mt-2 rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm
                   transition-colors hover:bg-(--color-surface-hover)">
        + {{ __('inventory::pack.add') }}
    </button>
</section>
@endif

{{--
    পিস বেরোনো — নম্বরগুলো এক ঘরে, প্রতি লাইনে একটা।

    ── ⛔ এই পর্দাটা না থাকায় ইঞ্জিনটা অচল ছিল ──────────────────────────
    ⓘ [[SerialNumberService::issue()]] লেখা হয়েছিল ২৪ সেপ্টেম্বরে, আর
    সেদিন থেকে একটাও পথ ওটাতে পৌঁছাত না। ⚠️ পিস ঢুকত, কোনোদিন বেরোত না,
    আর প্রতিটা নম্বর চিরকাল "গুদামে" হয়ে বসে থাকত।

    ── ⚠️ ওয়ারেন্টি শুরু হয় **বেরোনোর** দিনে ────────────────────────────
    ⓘ গুদামে বসে থাকা মাসগুলো ক্রেতার ওয়ারেন্টি খেয়ে ফেলতে পারে না।
    ⛔ ঢোকার তারিখ ধরলে ছয় মাস পড়ে থাকা একটা যন্ত্র ক্রেতার হাতে
    যাওয়ার দিনেই অর্ধেক ওয়ারেন্টি হারাত, আর কেউ টের পেত না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::action.issue_serials') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::action.issue_serials')"
                          :subtitle="__('inventory::message.serial_issue_note')" />
    </x-slot:header>

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

    {{-- ⓘ গুদামে একটাও পিস না থাকলে এটাই সঠিক বার্তা: আগে পিস ঢোকাতে হবে। --}}
    @if ($inStock->isEmpty())
        <x-ui.empty-state :message="__('inventory::message.serial_none_in_stock')" />
    @else
        <form method="POST" action="{{ route('inventory.serial.issue.store') }}" class="space-y-4">
            @csrf

            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <x-ui.field name="issued_on" type="date"
                                :label="__('inventory::field.issued_on')"
                                :value="old('issued_on', now()->toDateString())" required />

                    <x-ui.field name="sold_to" :label="__('inventory::field.sold_to')"
                                :value="old('sold_to')" />

                    {{-- ⛔ শূন্য মানে "ওয়ারেন্টি নেই", খালি নয় — ⓘ সেবা
                         শূন্যে দুইটা তারিখই খালি রাখে, আর শূন্য মাসের
                         একটা তারিখ বসালে ওটা "আজই শেষ" বলত, যা মিথ্যা। --}}
                    <x-ui.field name="warranty_months" type="number"
                                :label="__('inventory::field.warranty_months')"
                                :value="old('warranty_months', 0)" />
                </div>
            </section>

            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <label for="serials" class="mb-1 block text-sm font-medium">
                    {{ __('inventory::field.serials') }}
                </label>

                <p class="mb-2 text-xs text-(--color-ink-muted)">
                    {{ __('inventory::message.serial_scan_hint') }}
                </p>

                <textarea id="serials" name="serials" rows="10"
                          class="w-full rounded-(--radius-field) border border-(--color-border)
                                 bg-(--color-surface-card) px-3 py-2 font-mono text-sm"
                          required>{{ old('serials') }}</textarea>
            </section>

            {{-- ⓘ গুদামে যে নম্বরগুলো আছে — দেখার জন্য, বেছে নেওয়ার জন্য নয়।
                 ⚠️ পাঁচশোতে থামানো: ব্যবহারকারী স্ক্যান করেন বা টাইপ করেন,
                 আর হাজার সারির একটা তালিকা পাতাটা ভারী করা ছাড়া কিছুই করত না। --}}
            <section data-boxed
                     class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                <div class="table-responsive">
                    <table class="ui-lines table-cards w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-start">{{ __('inventory::field.serial_no') }}</th>
                                <th class="text-start">{{ __('inventory::field.product') }}</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($inStock as $piece)
                                <tr class="border-b border-(--color-border)">
                                    <td class="font-mono" data-label="{{ __('inventory::field.serial_no') }}">
                                        {{ $piece->serial_no }}
                                    </td>
                                    <td data-label="{{ __('inventory::field.product') }}">
                                        {{ $piece->product?->name() ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" tone="primary">
                    {{ __('inventory::action.issue_serials') }}
                </x-ui.button>

                <x-ui.button tone="secondary" :href="route('inventory.serial.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    @endif
</x-layouts.app>

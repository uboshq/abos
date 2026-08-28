{{--
    নতুন রান্নার কাগজ।

    ── কেন উপকরণের সারি এখানে নেই ───────────────────────────────────────
    উপকরণ রেসিপি থেকেই আসে — কোনটা, কতটা, অপচয় ধরে কতটা। এখানে হাতে
    লেখার সুযোগ দিলে দুইটা সত্য দাঁড়াত: রেসিপি বলত এক কথা, কাগজ আরেক।

    রাঁধুনি কেবল দুইটা কথা বলেন: কোন রেসিপি, আর কয় প্লেট হলো। বাকিটা
    হিসাব, আর হিসাব মানুষের কাজ নয়।

    ── কেন পরিমাণের ঘরটা খালি থাকে ──────────────────────────────────────
    রেসিপিতে ফলন ৫০ লেখা থাকতে পারে, আজ হয়েছে ৪৭। আগে থেকে ৫০ বসিয়ে
    রাখলে বেশিরভাগ দিন কেউ ওটা বদলাতেন না, আর খাতা রোজ ৫০ ধরত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:header>
        <x-ui.page-header :title="__('inventory::action.new_production')"
                          :subtitle="__('inventory::message.production_subtitle')" />
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

    <form method="POST" action="{{ route('inventory.production.store') }}">
        @csrf

        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('inventory::section.production_head') }}</h2>

            @if ($recipes === [])
                {{-- হাঁড়ির রেসিপি না থাকলে এই পর্দাটার কিছুই করার নেই।
                     খালি ড্রপডাউন দেখিয়ে "বাছুন" বলার চেয়ে কেন বাছার
                     কিছু নেই সেটা বলাই কাজের। --}}
                <x-ui.empty-state :title="__('inventory::message.no_batch_recipes')"
                                  :note="__('inventory::message.no_batch_recipes_note')" />
            @else
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.select name="recipe_id"
                                 :label="__('inventory::field.dish')"
                                 :options="$recipes"
                                 :selected="old('recipe_id')"
                                 placeholder="—"
                                 :hint="__('inventory::field.production_recipe_hint')"
                                 required />

                    <x-ui.select name="warehouse_id"
                                 :label="__('inventory::field.warehouse')"
                                 :options="$warehouses"
                                 :selected="old('warehouse_id')"
                                 required />

                    <x-ui.field name="trx_date"
                                :label="__('inventory::field.date')"
                                type="date"
                                :value="old('trx_date', $production->trx_date)"
                                required />

                    <x-ui.field name="qty"
                                :label="__('inventory::field.made')"
                                type="number"
                                numeric
                                :value="old('qty')"
                                :hint="__('inventory::field.made_hint')"
                                required />
                </div>

                <div class="mt-3">
                    <x-ui.field name="narration"
                                :label="__('inventory::field.narration')"
                                :value="old('narration')" />
                </div>
            @endif
        </section>

        @if ($recipes !== [])
            <div class="mt-4 flex flex-wrap gap-2">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                <x-ui.button :href="route('inventory.production.index')" tone="secondary">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        @endif
    </form>
</x-layouts.app>

{{--
    একটা মুদ্রার হারের ইতিহাস।

    উপরে আজকের কার্যকর হার, নিচে সব তারিখ। পুরনো সারি সম্পাদনা করা যায়
    না — নতুন তারিখে নতুন সারি বসে, আর তাই গত মাসের বিলটা আজ খুললেও ওই
    মাসের হারেই দেখা যায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $currency->label() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('master_data::menu.exchange_rates')"
            :subtitle="$currency->label()">
            <x-slot:actions>
                <x-ui.button :href="route('master_data.currency.index')">
                    {{ __('master_data::action.back_to_currencies') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
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

    {{-- ভিত্তি মুদ্রা ঠিক না থাকলে হারের কোনো মানে নেই, তাই কাজটা
         এগোনোর আগেই সেটা বলা হয় — ফর্ম দেখিয়ে পরে "কীসের সাপেক্ষে"
         প্রশ্ন তোলা মানে ব্যবহারকারীকে ঘোরানো। --}}
    @if ($base === null)
        <div data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-6 text-center text-sm text-(--color-ink-muted)">
            {{ __('master_data::message.no_base_currency') }}
        </div>
    @elseif ($currency->is_default)
        <div data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-6 text-center text-sm text-(--color-ink-muted)">
            {{ __('master_data::message.base_currency_rate_is_one', ['code' => $currency->code]) }}
        </div>
    @else
        <div class="mb-4 grid gap-4 lg:grid-cols-[20rem_1fr]">

            {{-- আজকের কার্যকর হার --}}
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <p class="text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">
                    {{ __('master_data::field.rate_today') }}
                </p>

                @if ($today === null)
                    <p class="mt-2 text-sm text-(--color-ink-muted)">
                        {{ __('master_data::message.no_rate_yet') }}
                    </p>
                @else
                    <p class="num mt-2 text-2xl font-semibold">
                        1 {{ $currency->code }} = {{ $today }} {{ $base->code }}
                    </p>
                @endif
            </div>

            @can('master_data.manage')
                {{-- নতুন হার। একই তারিখে আবার বসালে সেটাই সংশোধন —
                     দুইটা হার থাকলে কোনটা সত্যি তা বলার উপায় থাকত না। --}}
                <form method="POST" action="{{ route('master_data.currency.rates.store', ['id' => $currency->id]) }}"
                      class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    @csrf

                    <div class="grid gap-3 sm:grid-cols-[10rem_10rem_1fr_auto] sm:items-end">
                        <label class="block">
                            <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                                         text-(--color-ink-muted)">{{ __('master_data::field.effective_from') }}</span>
                            <x-ui.date name="effective_from" :required="true"
                                       :value="old('effective_from', now()->toDateString())"
                                       class="w-full text-sm" />
                                       </label>

                        <label class="block">
                            <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                                         text-(--color-ink-muted)">{{ __('master_data::field.exchange_rate') }}</span>
                            <input type="number" step="0.000001" min="0.000001" name="rate" required
                                   value="{{ old('rate') }}"
                                   class="num w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 py-1.5 text-sm text-end">
                        </label>

                        <label class="block">
                            <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                                         text-(--color-ink-muted)">{{ __('master_data::field.rate_source') }}</span>
                            <input type="text" name="source" value="{{ old('source') }}"
                                   placeholder="{{ __('master_data::field.rate_source_hint') }}"
                                   class="w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 py-1.5 text-sm">
                        </label>

                        <x-ui.button type="submit" tone="primary">
                            {{ __('master_data::action.save_rate') }}
                        </x-ui.button>
                    </div>

                    <p class="mt-2 text-2xs text-(--color-ink-muted)">
                        {{ __('master_data::message.rate_meaning', ['code' => $currency->code, 'base' => $base->code]) }}
                    </p>
                </form>
            @endcan
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table
            :empty="__('master_data::message.no_rate_yet')"
            :rows="$rates"
            :columns="[
                ['key' => 'effective_from', 'label' => __('master_data::field.effective_from'), 'width' => '12rem',
                 'render' => fn ($r) => $r->effective_from->format('d M Y')],
                ['key' => 'rate', 'label' => __('master_data::field.exchange_rate'), 'numeric' => true, 'width' => '12rem',
                 'render' => fn ($r) => $r->rate],
                ['key' => 'source', 'label' => __('master_data::field.rate_source'),
                 'render' => fn ($r) => $r->source ?? '—'],
                ['key' => 'creator', 'label' => __('master_data::field.entered_by'), 'width' => '12rem',
                 'render' => fn ($r) => $r->creator?->name ?? '—'],
            ]" />
    </div>
</x-layouts.app>

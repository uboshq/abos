{{--
    একটা স্কিমের নিজের পাতা — আর তার ধাপগুলো।

    ── কেন ধাপগুলো এখানেই ──────────────────────────────────────────────
    "এই স্কিমটা আসলে কত দেয়" — এটাই সবচেয়ে বেশি জিজ্ঞেস করা প্রশ্ন।
    ধাপগুলো আলাদা পর্দায় পাঠালে উত্তরটা দিতে দুইটা পাতা খুলতে হত।
--}}
@php
    $live = $scheme->status === \App\Modules\Sales\Models\Scheme::ACTIVE;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $scheme->code }} — {{ $scheme->name }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$scheme->name" :subtitle="$scheme->code">
            <x-slot:actions>
                @can('sales.scheme.manage')
                    @unless ($live)
                        <form method="POST" action="{{ route('sales.scheme.activate', $scheme) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">
                                {{ __('sales::action.activate_scheme') }}
                            </x-ui.button>
                        </form>
                    @endunless
                @endcan
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

    {{-- চার নজরের সংখ্যা — পাতাটার উপরে, কারণ "এই স্কিমটা কী" প্রশ্নের
         উত্তর এই চারটাই। --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            __('accounts::field.state') => __('sales::scheme_state.' . $scheme->status),
            __('sales::field.scheme_basis') => __('sales::basis.' . $scheme->basis),
            __('sales::field.scheme_applies_to') => __('sales::applies.' . $scheme->applies_to),
            __('sales::field.scheme_valid') => \App\Core\Support\DateFormat::format($scheme->valid_from)
                . ' — ' . \App\Core\Support\DateFormat::format($scheme->valid_to),
        ] as $label => $value)
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) px-4 py-3">
                <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ $label }}</p>
                <p class="mt-1 font-semibold">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    @if ($scheme->hasLapsed())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm
                    text-(--color-badge-warning-ink)">
            {{ __('sales::message.scheme_lapsed_note') }}
        </div>
    @endif

    <section data-boxed class="mb-5 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('sales::field.scheme_bands') }}
        </h2>

        <div class="table-responsive">
            <table class="ui-list table-cards w-full border-collapse">
                <thead>
                    <tr class="border-b border-(--color-border)">
                        <th class="text-start">{{ __('sales::field.earner_role') }}</th>
                        <th class="text-start">{{ __('sales::field.band') }}</th>
                        <th class="num">{{ __('sales::field.commission_rate') }}</th>
                        <th class="num">{{ __('sales::field.level_order') }}</th>
                        <th class="text-end"><span class="sr-only">{{ __('core.table.actions') }}</span></th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($scheme->rules as $rule)
                        <tr>
                            <td data-label="{{ __('sales::field.earner_role') }}">{{ $rule->earner_role }}</td>

                            <td data-label="{{ __('sales::field.band') }}">
                                {{ $rule->bandLabel() }}

                                {{-- খোলা ধাপটাই সিঁড়ির সবচেয়ে জরুরি সারি —
                                     ওটা না থাকলে বছরের সবচেয়ে বড় বিলটা
                                     কিছুই পায় না। তাই চিহ্ন দিয়ে বলা হয়। --}}
                                @if ($rule->slab_to === null)
                                    <span class="ms-1 text-2xs text-(--color-ink-muted)">
                                        {{ __('sales::field.band_open') }}
                                    </span>
                                @endif
                            </td>

                            <td class="num" data-label="{{ __('sales::field.commission_rate') }}">
                                {{-- bccomp, (float) নয়: fixed_amount একটা DECIMAL(18,4), আর
                                     float-এ নিলে ০.০০০১ টাকার নিয়মও "শূন্য" হয়ে যেত। --}}
                                @if ($rule->fixed_amount !== null && bccomp((string) $rule->fixed_amount, '0', 4) > 0)
                                    {{ \App\Core\Support\Money::format($rule->fixed_amount) }}
                                @else
                                    {{ rtrim(rtrim((string) $rule->rate_percent, '0'), '.') }}%
                                @endif
                            </td>

                            <td class="num" data-label="{{ __('sales::field.level_order') }}">
                                {{ $rule->level_order }}
                            </td>

                            <td class="text-end">
                                @can('sales.scheme.manage')
                                    @unless ($live)
                                        <form method="POST"
                                              action="{{ route('sales.scheme.rule.remove', [$scheme, $rule]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" tone="danger">
                                                {{ __('core.action.delete') }}
                                            </x-ui.button>
                                        </form>
                                    @endunless
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-(--color-ink-muted)">
                                {{ __('sales::message.scheme_no_band') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @can('sales.scheme.manage')
        @if ($live)
            {{-- চালু স্কিমের হার বদলালে **আগের বিলগুলোর কমিশনও বদলে যেত** —
                 ইঞ্জিন হিসাব করে বর্তমান নিয়ম দেখে। তাই ফর্মটা দেখানোই
                 হয় না, আর কেন হয় না সেটা লেখা থাকে। --}}
            <p class="text-sm text-(--color-ink-muted)">
                {{ __('sales::validation.scheme_is_live') }}
            </p>
        @else
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('sales::action.add_band') }}</h2>

                <form method="POST" action="{{ route('sales.scheme.rule.add', $scheme) }}"
                      class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    @csrf

                    {{-- ভূমিকার ঘরটা লেখার, বাছাইয়ের নয় — প্রতিটা পরিবেশক
                         নিজের মতো নাম দেয়। আগে যা ব্যবহার হয়েছে সেগুলো
                         পরামর্শ হিসেবে আসে, যাতে বানান আলাদা না হয়। --}}
                    <div>
                        <x-ui.field name="earner_role" :label="__('sales::field.earner_role')" required
                                    list="scheme-roles" :value="old('earner_role')" />
                        <datalist id="scheme-roles">
                            @foreach ($roles as $role)
                                <option value="{{ $role }}"></option>
                            @endforeach
                        </datalist>
                    </div>

                    <x-ui.field name="rate_percent" type="number" step="0.0001" numeric
                                :label="__('sales::field.commission_rate')"
                                :value="old('rate_percent')" />

                    <x-ui.field name="fixed_amount" type="number" step="0.01" numeric
                                :label="__('sales::field.fixed_amount')"
                                :hint="__('sales::message.fixed_beats_rate')"
                                :value="old('fixed_amount')" />

                    <x-ui.field name="slab_from" type="number" step="0.0001" numeric
                                :label="__('sales::field.slab_from')" required
                                :value="old('slab_from', '0')" />

                    <x-ui.field name="slab_to" type="number" step="0.0001" numeric
                                :label="__('sales::field.slab_to')"
                                :hint="__('sales::message.leave_top_band_open')"
                                :value="old('slab_to')" />

                    <x-ui.field name="level_order" type="number" step="1" numeric
                                :label="__('sales::field.level_order')" required
                                :value="old('level_order', '1')" />

                    <div class="flex items-end sm:col-span-2 xl:col-span-6">
                        <x-ui.button type="submit" tone="primary">
                            {{ __('core.action.save') }}
                        </x-ui.button>
                    </div>
                </form>
            </section>
        @endif
    @endcan
</x-layouts.app>

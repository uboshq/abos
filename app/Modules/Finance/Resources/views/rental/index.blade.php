{{--
    ভাড়ার চুক্তি ও জামানত।

    ── কেন "শেষ হয়ে আসছে" তালিকাটা সবার উপরে ────────────────────────────
    ⚠️ এই গোটা পর্দার সবচেয়ে দামি সংখ্যা ওটাই। বারো লাখ জামানতের
    ৯,৬০,০০০ ফেরত নিতে ভুলে যাওয়া এভাবেই ঘটে — কাগজটা ফাইলে থাকে,
    তারিখটা কারো মনে থাকে না, আর যেদিন মনে পড়ে সেদিন বাড়িওয়ালা বলেন
    "ওটা তো ভাড়ার সাথে কেটে গেছে"।

    ⓘ তাই নিচের তালিকায় গিয়ে খুঁজতে হয় না; জিনিসটা নিজে থেকে সামনে আসে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.rental') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::menu.rental')" />
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

    @if ($endingSoon->isNotEmpty())
        <section data-boxed
                 class="mb-4 rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-badge-warning-bg) p-4">
            <h2 class="mb-2 font-semibold text-(--color-badge-warning-ink)">
                {{ __('finance::message.rental_ending_soon') }}
            </h2>

            <ul class="grid gap-1 text-sm text-(--color-badge-warning-ink)">
                @foreach ($endingSoon as $soon)
                    <li>
                        <a href="{{ route('finance.rental.show', $soon) }}"
                           class="underline decoration-dotted underline-offset-2">
                            {{ $soon->counterparty }}@if ($soon->subject) — {{ $soon->subject }}@endif
                        </a>
                        ·
                        {{ __('finance::message.rental_ends_on', ['date' => $soon->ends_on->format('d/m/Y')]) }}
                        ·
                        {{-- ⭐ ফেরতযোগ্য টাকাটা এখানেই লেখা — নাহলে কেউ
                             ক্লিক করে দেখতে যেতেন না --}}
                        <span class="num font-semibold">{{ \App\Core\Support\Money::format($soon->depositLeft()) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif


    <form method="GET" class="mb-3">
        <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
            <input type="checkbox" name="closed" value="1" @checked($showClosed)
                   onchange="this.form.submit()" class="size-4">
            {{ __('finance::action.rental_show_closed') }}
        </label>
    </form>

    @if ($contracts->isEmpty())
        <x-ui.empty-state :message="__('finance::message.no_rentals')" />
    @else
        <section data-boxed
                 class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="overflow-x-auto">
                <table class="ui-list w-full">
                    <thead>
                        <tr class="text-2xs text-(--color-ink-muted)">
                            <th class="text-start">{{ __('finance::field.rental_counterparty') }}</th>
                            <th class="text-start">{{ __('finance::field.rental_subject') }}</th>
                            <th class="text-end">{{ __('finance::field.rental_rent') }}</th>
                            <th class="text-end">{{ __('finance::field.rental_cash') }}</th>
                            <th class="text-end">{{ __('finance::field.rental_from_deposit') }}</th>
                            <th class="text-end">{{ __('finance::field.rental_deposit_left') }}</th>
                            <th class="text-start">{{ __('finance::field.rental_ends_on') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($contracts as $contract)
                            <tr class="border-t border-(--color-border)">
                                <td>
                                    <a href="{{ route('finance.rental.show', $contract) }}"
                                       class="text-(--color-brand-500) underline-offset-2 hover:underline">
                                        {{ $contract->counterparty }}
                                    </a>
                                </td>
                                <td class="text-(--color-ink-muted)">{{ $contract->subject }}</td>
                                <td class="num text-end">{{ \App\Core\Support\Money::format($contract->monthly_rent) }}</td>
                                <td class="num text-end">{{ \App\Core\Support\Money::format($contract->monthlyCash()) }}</td>
                                <td class="num text-end">{{ \App\Core\Support\Money::format($contract->monthly_adjustment) }}</td>

                                {{-- ⭐ যে সংখ্যাটার জন্য এই পর্দা — ফেরত পাওয়ার টাকা --}}
                                <td class="num text-end font-semibold">
                                    {{ \App\Core\Support\Money::format($contract->depositLeft()) }}
                                </td>

                                <td>{{ $contract->ends_on->format('d/m/Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <x-ui.pager :rows="$contracts" />
    @endif

    @can('finance.rental.create')
        <section data-boxed
                 class="mt-4 rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('finance::action.rental_new') }}</h2>

            <form method="POST" enctype="multipart/form-data" action="{{ route('finance.rental.store') }}"
                  class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @csrf

                <x-ui.field name="counterparty" :label="__('finance::field.rental_counterparty')" required />
                <x-ui.field name="counterparty_phone" :label="__('finance::field.rental_phone')" />
                <x-ui.field name="subject" :label="__('finance::field.rental_subject')" />

                <x-ui.field name="deposit_amount" type="number" step="0.0001" min="0"
                            :label="__('finance::field.rental_deposit')" required />
                <x-ui.field name="monthly_rent" type="number" step="0.0001" min="0"
                            :label="__('finance::field.rental_rent')" required />

                {{-- ⓘ নগদের অঙ্কটা চাওয়া হয় না — সেটা ভাড়া বিয়োগ এটা।
                     তিনটা সংখ্যা চাইলে কেউ এমন তিনটা বসাত যাদের যোগফল
                     মেলে না, আর ভাউচারটা ভারসাম্যহীন হয়ে থামত। --}}
                <x-ui.field name="monthly_adjustment" type="number" step="0.0001" min="0"
                            :label="__('finance::field.rental_from_deposit')" />

                <label class="grid gap-1">
                    <span class="text-2xs text-(--color-ink-muted)">
                        {{ __('finance::field.rental_starts_on') }}
                    </span>
                    <x-ui.date name="starts_on" :value="old('starts_on', now()->toDateString())" required />
                </label>

                <x-ui.field name="term_months" type="number" min="1" max="600"
                            :label="__('finance::field.rental_term')" required />

                {{-- ⭐ তিনটা ঘরই কলামে ছিল, পর্দায় ছিল না — ১৫ সেপ্টেম্বর ২০২৬।

                     ⚠️ ভাড়ার দিনটা লেখা না থাকলে "দেরি হয়েছে কি না"
                     প্রশ্নের উত্তর দেওয়া যায় না, আর বাড়িওয়ালা ফোন
                     করলে তর্ক হয়। ⓘ ৫ তারিখ ডিফল্ট, কারণ বেশিরভাগ
                     চুক্তিতে ওটাই লেখা থাকে। --}}
                <x-ui.field name="rent_day" type="number" min="1" max="28"
                            :label="__('finance::field.rent_day')"
                            :value="old('rent_day', 5)" />

                {{-- ⛔ অগ্রিম আর জামানত দুইটা আলাদা জিনিস — স্যাম্পলের
                     ঐ লাইনটাই এখানে সবচেয়ে জরুরি।

                     ⓘ অগ্রিম ভাড়া **ভাড়ারই আগাম**, তাই প্রতি মাসে ওটা
                     থেকে কাটা পড়ে আর একদিন শূন্য হয়। ⚠️ জামানত ফেরতযোগ্য
                     — চুক্তি শেষ না হলে ওটা কমে না। দুইটাকে এক ধরলে
                     মালিক ভাবতেন তাঁর টাকা জমা আছে, অথচ সেটা খরচ হয়ে
                     গেছে। --}}
                <x-ui.field name="advance_months" type="number" min="0" max="36"
                            :label="__('finance::field.advance_months')"
                            :value="old('advance_months', 0)" />

                {{-- ⓘ ভাড়ার উপর উৎসে কর — ভাড়াটিয়া কেটে সরকারকে দেয়,
                     তাই বাড়িওয়ালা হাতে পান কম। ⚠️ হারটা না থাকলে
                     বাড়িওয়ালার খাতা আর আমাদের খাতা মিলত না। --}}
                <x-ui.field name="tax_rate" type="number" step="0.01" min="0" max="100"
                            :label="__('finance::field.rental_tax_rate')"
                            :value="old('tax_rate', 5)" />

                {{-- ⓘ খালি রাখা যায়: পুরনো চুক্তি বসানোর সময় টাকাটা আগেই
                     দেওয়া হয়ে গেছে আর খোলার জেরে বসেছে, তখন আবার পোস্ট
                     করলে দুইবার হত। --}}
                @include('finance::rental._money', [
                    'money' => $money,
                    'label' => __('finance::field.rental_money_account'),
                    'blank' => __('finance::field.rental_already_paid'),
                ])

                <div class="sm:col-span-2 lg:col-span-3">

                    {{-- ⭐ ফিতাটা ঘরগুলোর পরে, ভাউচারের বাক্সের আগে — নমুনার ক্রম।
                         ⓘ আগে এটা ফর্মের বাইরে ছিল, তাই কার্ডের নিচে আলগা হয়ে
                         ঝুলত। মালিক পাঁচটা পর্দা পাশাপাশি দেখে ধরিয়ে দিয়েছেন। --}}
                    <div class="sm:col-span-2 xl:col-span-4">
                        @include('finance::partials.handoff', [
                            'voucher' => 'payment',
                            'to' => route('accounts.voucher.create', ['type' => 'payment']),
                            'action' => __('finance::action.pay_money_voucher'),
                        ])
                    </div>

                    {{-- ⭐ ভাউচারের ঘর — নমুনার সবচেয়ে বড় অংশ।
                         ⓘ ঘরগুলো খাতার সারিতে বসে না; ওগুলো ভাউচারে যায়। --}}
                    <div class="sm:col-span-2 xl:col-span-4">
                        @include('finance::partials.voucher-box', [
                            'direction' => 'out',
                            'carriers' => $carriers ?? [],
                        ])
                    </div>

                    {{-- ⭐ সংযুক্তি — ভাড়ার চুক্তিপত্র। ⚠️ কত বছর, কত বাড়বে, জামানত কত — সব ওখানে। --}}
                    <div class="sm:col-span-2">
                        <label for="rent-paper" class="mb-1 block text-sm font-medium">
                            {{ __('finance::field.attachment') }}
                        </label>
                        <input id="rent-paper" type="file" name="paper"
                               x-on:change="$store.scanner.begin($el, 'paper')"
                               class="w-full text-sm file:me-2 file:rounded-(--radius-field)
                                      file:border file:border-(--color-border) file:bg-(--color-surface-app)
                                      file:px-3 file:py-1.5 file:text-sm">
                    </div>

                    <x-ui.button type="submit">{{ __('finance::action.rental_open') }}</x-ui.button>
                </div>
            </form>
        </section>
    @endcan
</x-layouts.app>

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
                        <span class="num font-semibold">{{ number_format((float) $soon->depositLeft(), 2) }}</span>
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
                                <td class="num text-end">{{ number_format((float) $contract->monthly_rent, 2) }}</td>
                                <td class="num text-end">{{ number_format((float) $contract->monthlyCash(), 2) }}</td>
                                <td class="num text-end">{{ number_format((float) $contract->monthly_adjustment, 2) }}</td>

                                {{-- ⭐ যে সংখ্যাটার জন্য এই পর্দা — ফেরত পাওয়ার টাকা --}}
                                <td class="num text-end font-semibold">
                                    {{ number_format((float) $contract->depositLeft(), 2) }}
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

            <form method="POST" action="{{ route('finance.rental.store') }}"
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

                <label class="grid gap-1">
                    <span class="text-2xs text-(--color-ink-muted)">
                        {{ __('finance::field.rental_money_account') }}
                    </span>
                    <select name="money_account_id"
                            class="h-(--spacing-field) rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-2">
                        {{-- ⓘ খালি রাখা যায়: পুরনো চুক্তি বসানোর সময় টাকাটা
                             আগেই দেওয়া হয়ে গেছে আর খোলার জেরে বসেছে, তখন
                             আবার পোস্ট করলে দুইবার হত। --}}
                        <option value="">{{ __('finance::field.rental_already_paid') }}</option>
                        @foreach ($money as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name() }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="sm:col-span-2 lg:col-span-3">
                    <x-ui.button type="submit">{{ __('finance::action.rental_open') }}</x-ui.button>
                </div>
            </form>
        </section>
    @endcan
</x-layouts.app>

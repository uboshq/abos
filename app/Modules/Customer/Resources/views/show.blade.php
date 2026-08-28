{{--
    একজন গ্রাহক।

    বকেয়ার অঙ্কটা এখানে নিছক একটা সংখ্যা নয় — নিয়ম ১ বলে প্রতিটা অঙ্ক থেকে
    তার উৎসে যাওয়া যাবে। তাই অঙ্কটা লেজারের লিংক, আর নিচে সেই লেনদেনগুলোই
    দেখানো হয় যেগুলো যোগ হয়ে অঙ্কটা হয়েছে। খোলা ব্যালেন্সও আলাদা সারি,
    কারণ সেটা কোনো ডকুমেন্ট থেকে আসেনি — সেটা না বললে যোগফল মেলে না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $customer->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$customer->name()" :subtitle="$customer->code">
            <x-slot:actions>
                @can('update', $customer)
                    <x-ui.button tone="secondary" :href="route('customer.edit', $customer)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>
                @endcan

                @can('delete', $customer)
                    @if ($customer->is_active)
                        <form method="POST" action="{{ route('customer.destroy', $customer) }}"
                              onsubmit="return confirm('{{ __('customer::message.deactivate_confirm') }}')">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('customer::action.deactivate') }}
                            </x-ui.button>
                        </form>
                    @endif
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    {{-- ওডুর smart buttons — রেকর্ডের একদম মাথায়, শিরোনামের ঠিক নিচে।

         বাকি ন'টা রূপে এখানে কিছুই আঁকা হয় না; ওখানে একই তথ্য নিচের
         ঘরগুলোর সাথে সারি হিসেবে বসে। জায়গাটা রূপের সিদ্ধান্ত, পাতার নয়। --}}
    <x-ui.record-facts :facts="$facts" region="head" />

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">

        {{-- বকেয়া --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-sm font-medium text-(--color-ink-muted)">
                {{ __('customer::field.outstanding') }}
            </h2>

            {{-- num ক্লাসটা ট্যাবুলার অঙ্ক দেয় — একই প্রস্থে প্রতিটা সংখ্যা,
                 তাই দুই অঙ্ক পাশাপাশি রাখলে দশমিক বিন্দু এক লাইনে থাকে। --}}
            {{-- অঙ্কটাই লিংক — নিচের টেবিলে ঠিক সেই লেনদেনগুলো আছে
                 যেগুলো যোগ হয়ে এই সংখ্যাটা হয়েছে (নিয়ম ১) --}}
            <p class="mt-1 text-2xl font-semibold">
                <x-ui.amount :value="$outstanding" href="#transactions" />
            </p>

            @if ($creditLimitOn && bccomp((string) $customer->credit_limit, '0', 4) > 0)
                <p class="mt-2 text-2xs text-(--color-ink-muted)">
                    {{ __('customer::field.credit_limit') }}:
                    <span class="num">{{ \App\Core\Support\Money::format($customer->credit_limit) }}</span>
                    @if ($customer->credit_days > 0)
                        · {{ $customer->credit_days }} {{ __('customer::field.credit_days') }}
                    @endif
                </p>

                {{-- আর কত ধার দেওয়া যায় — সীমার নিচেই, কারণ প্রশ্নটা
                     সবসময় জোড়ায় আসে: "সীমা কত, আর কতটা বাকি আছে"। --}}
                <p class="mt-1 text-2xs text-(--color-ink-muted)">
                    {{ __('customer::field.available_limit') }}:
                    <span class="num">
                        {{ $customer->availableLimit() === null
                            ? '—'
                            : \App\Core\Support\Money::format($customer->availableLimit()) }}
                    </span>
                </p>

                @if ($customer->wouldExceedCreditLimit('0'))
                    {{-- সীমা ইতিমধ্যেই ছাড়িয়ে গেছে — এটা বিক্রির পর্দায় জানার
                         চেয়ে এখানে জানা ভালো। --}}
                    <p class="mt-2 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-2 py-1
                              text-2xs text-(--color-badge-danger-ink)">
                        {{ __('customer::message.over_limit') }}
                    </p>
                @endif
            @endif
        </section>

        {{-- পরিচয় --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4
                        lg:col-span-2">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="font-semibold">{{ __('customer::section.identity') }}</h2>
                @include('customer::partials.state-badge', ['customer' => $customer])
            </div>

            <dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                {{-- ক্রমটা মালিকের দেওয়া তালিকার মতোই: কে চালান, কোথায়
                     বসে, কীভাবে ধরা যায় — তারপর বাকি সব। --}}
                @foreach ([
                    'customer::field.owner_name' => $customer->owner_name,
                    'customer::field.point' => $customer->location?->name(),
                    'customer::field.area' => $customer->area()?->name(),
                    'customer::field.phone' => $customer->phone,
                    'customer::field.email' => $customer->email,
                    'customer::field.type' => $customer->typeName(),
                    'core.company.branch' => $customer->branch?->name(),
                    'customer::field.address' => $customer->address(),
                ] as $label => $value)
                    @if (filled($value))
                        <div>
                            <dt class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</dt>
                            <dd class="text-sm">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach

                {{-- বাকি মডিউলরা এই গ্রাহক সম্পর্কে যা জানে — "শেষ কেনা
                     কবে" বিক্রয়ের কথা, গ্রাহকের নয়। এই পাতা জানে না কে
                     কী দিল, শুধু জিজ্ঞেস করে।

                     আঁকার দায়িত্ব কম্পোনেন্টের: ওডুতে এগুলো রেকর্ডের
                     মাথায় smart buttons হয় (উপরে `head` অঞ্চল), বাকি
                     রূপে এখানেই তথ্যের সারি। --}}
                <x-ui.record-facts :facts="$facts" region="body" />
            </dl>
        </section>
    </div>

    {{-- ডিলার পোর্টালের চাবি --}}
    @can('managePortal', $customer)
        <section data-portal class="mt-4 rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <div class="mb-1 flex flex-wrap items-center gap-2">
                <h2 class="font-semibold">{{ __('customer::section.portal') }}</h2>
                <x-ui.badge :tone="$customer->portal_enabled ? 'success' : 'draft'">
                    {{ __($customer->portal_enabled
                        ? 'customer::state.portal_on'
                        : 'customer::state.portal_off') }}
                </x-ui.badge>
            </div>

            <p class="text-xs text-(--color-ink-muted)">{{ __('customer::message.portal_note') }}</p>

            @if ($customer->portal_enabled)
                {{-- চালু থাকলে দুইটা তথ্যই কাজে লাগে: কোডটা ডিলারকে বলতে
                     হয়, আর শেষ ঢোকার সময় দেখে বোঝা যায় তিনি সত্যিই
                     পাতাটা ব্যবহার করছেন কি না। --}}
                <dl class="mt-3 grid gap-x-4 gap-y-2 sm:grid-cols-2">
                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">
                            {{ __('customer::field.portal_code') }}
                        </dt>
                        <dd class="num text-sm font-medium">{{ $customer->code }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">
                            {{ __('customer::field.portal_last_login') }}
                        </dt>
                        <dd class="text-sm">
                            @if ($customer->portal_last_login_at)
                                <span class="num">{{ \App\Core\Support\DateFormat::formatWithTime($customer->portal_last_login_at) }}</span>
                            @else
                                <span class="text-(--color-ink-muted)">
                                    {{ __('customer::message.portal_never') }}
                                </span>
                            @endif
                        </dd>
                    </div>
                </dl>
            @endif

            <div class="mt-4 grid gap-4 lg:grid-cols-3">
                {{-- পাসওয়ার্ড বসানোর ফর্ম — চালু করা আর বদলানো একই ফর্ম,
                     কারণ মালিকের দিক থেকে কাজটা একই: "একটা পাসওয়ার্ড
                     দিলাম"। --}}
                <form method="POST" action="{{ route('customer.portal.store', $customer) }}"
                      class="grid gap-3 sm:grid-cols-2 lg:col-span-2">
                    @csrf

                    <x-ui.field name="password" type="password" required
                                autocomplete="new-password"
                                :label="__('customer::field.portal_password')"
                                :hint="__('customer::message.portal_password_hint')" />

                    {{-- দ্বিতীয়বার লেখানো হয়, কারণ পাসওয়ার্ডটা আর কোথাও
                         দেখা যায় না। টাইপো হলে মালিক ডিলারকে একটা ভুল
                         পাসওয়ার্ড বলতেন, আর দুইজনেই ভাবতেন পোর্টালটা
                         নষ্ট। --}}
                    <x-ui.field name="password_confirmation" type="password" required
                                autocomplete="new-password"
                                :label="__('customer::field.portal_password_again')" />

                    <div class="sm:col-span-2">
                        <x-ui.button type="submit" tone="primary">
                            {{ __($customer->portal_enabled
                                ? 'customer::action.portal_reset'
                                : 'customer::action.portal_enable') }}
                        </x-ui.button>
                    </div>
                </form>

                @if ($customer->portal_enabled)
                    <form method="POST" action="{{ route('customer.portal.destroy', $customer) }}"
                          onsubmit="return confirm('{{ __('customer::message.portal_disable_confirm') }}')"
                          class="flex items-end">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" tone="secondary">
                            {{ __('customer::action.portal_disable') }}
                        </x-ui.button>
                    </form>
                @endif
            </div>
        </section>
    @endcan

    {{-- লেনদেন — অঙ্কটা কোথা থেকে এল (নিয়ম ১) --}}
    <section id="transactions" class="scroll-mt-24 mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('customer::section.transactions') }}
        </h2>

        <x-ui.table
            :empty="__('customer::message.no_transactions')"
            :rows="$entries"
            :columns="[
                ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
                 'render' => fn ($e) => \App\Core\Support\DateFormat::format($e->trx_date)],
                ['key' => 'document', 'label' => __('core.table.document'),
                 'render' => fn ($e) => view('customer::partials.entry-source', ['entry' => $e])],
                ['key' => 'narration', 'label' => __('core.table.narration')],
                ['key' => 'debit', 'label' => __('core.table.debit'), 'numeric' => true, 'width' => '8rem',
                 'render' => fn ($e) => \App\Core\Support\Money::isZero($e->debit) ? '' : \App\Core\Support\Money::format($e->debit)],
                ['key' => 'credit', 'label' => __('core.table.credit'), 'numeric' => true, 'width' => '8rem',
                 'render' => fn ($e) => \App\Core\Support\Money::isZero($e->credit) ? '' : \App\Core\Support\Money::format($e->credit)],
                ['key' => 'balance', 'label' => __('core.table.balance'), 'numeric' => true, 'width' => '9rem',
                 'render' => fn ($e) => \App\Core\Support\Money::format($e->running_balance)],
            ]" />

        <x-ui.pager :rows="$entries" />
    </section>
</x-layouts.app>

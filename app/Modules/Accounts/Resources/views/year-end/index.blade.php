{{--
    বছর সমাপনী।

    এই পাতাটা অন্য স্ক্রিনের চেয়ে বেশি কথা বলে, আর সেটা ইচ্ছাকৃত: কাজটা
    বছরে একবার হয় আর ফেরানো যায় না। যে কাজ রোজ হয় তার ভুল পরদিনই ধরা
    পড়ে; যেটা বছরে একবার হয়, তার ভুল ধরা পড়ে পরের বছর।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.year_end') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.year_end')"
                          :subtitle="__('accounts::message.year_end_note')" />
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

    @if ($year === null)
        <x-ui.empty-state :message="__('accounts::message.no_current_year')" />
    @else
        <div class="grid gap-4 lg:grid-cols-3">

            {{-- কী ঘটবে --}}
            <section class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4 lg:col-span-2">
                <h2 class="mb-3 font-semibold">
                    {{ __('accounts::field.closing_year') }}: {{ $year->name }}
                </h2>

                <dl class="grid gap-x-4 gap-y-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">
                            {{ __('accounts::field.net_result') }}
                        </dt>
                        <dd @class([
                            'num text-xl font-semibold',
                            'text-(--color-danger)' => bccomp($preview['profit'], '0', 4) < 0,
                        ])>
                            {{ number_format((float) $preview['profit'], 2) }}
                        </dd>
                        <dd class="text-2xs text-(--color-ink-muted)">
                            {{ __('accounts::message.goes_to_retained') }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">
                            {{ __('accounts::field.accounts_to_close') }}
                        </dt>
                        <dd class="num text-xl font-semibold">{{ $preview['closing'] }}</dd>
                        <dd class="text-2xs text-(--color-ink-muted)">
                            {{ __('accounts::message.income_expense_zeroed') }}
                        </dd>
                    </div>
                </dl>

                {{-- যা টানা হয় না, সেটাও বলা হয়।

                     না বললে ব্যবহারকারী ভাবতেন সম্পদ ও দায় হারিয়ে গেছে,
                     আর প্রথম দিনেই আতঙ্কিত হয়ে হাতে জাবেদা লিখতেন। --}}
                <p class="mt-4 max-w-(--spacing-prose-max) rounded-(--radius-field)
                          bg-(--color-surface-app) px-3 py-2 text-2xs text-(--color-ink-muted)">
                    {{ __('accounts::message.carry_forward_note') }}
                </p>

                @if ($preview['drafts'] > 0)
                    {{-- খসড়া থাকলে বছর বন্ধ হয় না — আর কারণটা এখানেই বলা,
                         সেভ করার পরে নয় --}}
                    <p class="mt-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                              text-sm text-(--color-badge-danger-ink)">
                        {{ __('accounts::validation.year_has_drafts', ['count' => $preview['drafts']]) }}
                    </p>
                @endif
            </section>

            {{-- পরের বছর --}}
            <section class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('accounts::field.next_year') }}</h2>

                <form method="POST" action="{{ route('accounts.year_end.close', $year) }}"
                      x-data="{ busy: false }"
                      @submit="busy ? $event.preventDefault() : (busy = true)"
                      class="space-y-3">
                    @csrf

                    <x-ui.field name="name" :label="__('accounts::field.year_name')"
                                :value="old('name', $preview['next']['name'])" />

                    <x-ui.field name="starts_on" type="date" :label="__('accounts::field.starts_on')"
                                :value="old('starts_on', $preview['next']['starts_on'])" />

                    <x-ui.field name="ends_on" type="date" :label="__('accounts::field.ends_on')"
                                :value="old('ends_on', $preview['next']['ends_on'])" />

                    {{-- নামটা হাতে লিখে নিশ্চিত করা।

                         সাধারণ confirm() বাক্স যথেষ্ট নয় — মানুষ ওটা না
                         পড়েই "হ্যাঁ" চাপে। নামটা লিখতে বললে অন্তত একবার
                         চোখ বুলাতে হয়, আর এই কাজটা ফেরানো যায় না। --}}
                    <x-ui.field name="confirm"
                                :label="__('accounts::field.type_year_to_confirm', ['name' => $year->name])"
                                :hint="__('accounts::message.confirm_hint')"
                                required />

                    {{-- @disabled(...) কম্পোনেন্ট ট্যাগের ভেতরে চলে না —
                         Blade ওটাকে অ্যাট্রিবিউট হিসেবে না পড়ে ডিরেকটিভ
                         হিসেবে পড়ে, আর পুরো ফাইলের if/endif গোনা এলোমেলো
                         হয়ে যায়। তাই সাধারণ শর্তসাপেক্ষ অ্যাট্রিবিউট। --}}
                    <x-ui.button type="submit" tone="primary"
                                 ::class="busy && 'pointer-events-none opacity-70'"
                                 :disabled="$preview['drafts'] > 0">
                        {{ __('accounts::action.close_year') }}
                    </x-ui.button>
                </form>
            </section>
        </div>
    @endif

    {{-- সব বছর --}}
    <section class="mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) px-4 py-3 font-semibold">
            {{ __('accounts::field.financial_years') }}
        </h2>

        <x-ui.table
            :empty="__('accounts::message.no_current_year')"
            :rows="$years"
            :columns="[
                ['key' => 'name', 'label' => __('accounts::field.year_name'), 'width' => '10rem'],
                ['key' => 'starts_on', 'label' => __('accounts::field.starts_on'), 'width' => '10rem',
                 'render' => fn ($y) => \App\Core\Support\DateFormat::format($y->starts_on)],
                ['key' => 'ends_on', 'label' => __('accounts::field.ends_on'), 'width' => '10rem',
                 'render' => fn ($y) => \App\Core\Support\DateFormat::format($y->ends_on)],
                ['key' => 'is_closed', 'label' => __('accounts::field.state'),
                 'render' => fn ($y) => view('accounts::year-end.partials.state', ['year' => $y])],
            ]" />
    </section>
</x-layouts.app>

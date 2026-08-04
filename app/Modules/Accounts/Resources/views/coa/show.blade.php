{{--
    একটা খাত — তার ব্যালেন্স আর সেই ব্যালেন্স যা থেকে হয়েছে।

    নিয়ম ১: প্রতিটা সংখ্যা তার উৎসে ক্লিকযোগ্য। এখানে ব্যালেন্সটা উপরে,
    আর নিচে সেই লেনদেনগুলোই যেগুলো যোগ হয়ে সংখ্যাটা হয়েছে — প্রতিটার
    ডকুমেন্ট নম্বর ধরে তার নিজের পাতায় যাওয়া যায়।

    গ্রুপ খাতের নিজের লেনদেন থাকে না, তাই সেখানে লেনদেনের বদলে নিচের
    খাতগুলো ও তাদের ব্যালেন্স দেখানো হয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $account->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$account->name()" :subtitle="$account->code">
            <x-slot:actions>
                @can('update', $account)
                    <x-ui.button tone="secondary" :href="route('accounts.coa.edit', $account)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>
                @endcan

                @can('delete', $account)
                    @if ($account->is_active)
                        <form method="POST" action="{{ route('accounts.coa.destroy', $account) }}"
                              onsubmit="return confirm('{{ __('accounts::message.deactivate_confirm') }}')">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('accounts::action.deactivate') }}
                            </x-ui.button>
                        </form>
                    @endif
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

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-sm font-medium text-(--color-ink-muted)">{{ __('accounts::field.balance') }}</h2>

            {{-- অঙ্কটাই লিংক — নিচের টেবিলে এই খাতের এন্ট্রিগুলো (নিয়ম ১) --}}
            <p class="mt-1 text-2xl font-semibold">
                <x-ui.amount :value="$balance" href="#transactions" />
            </p>

            <p class="mt-2 text-2xs text-(--color-ink-muted)">
                {{ __('accounts::nature.' . $account->nature) }} · {{ __('accounts::type.' . $account->type) }}
            </p>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4
                        lg:col-span-2">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold">{{ __('accounts::section.placement') }}</h2>

                <span class="flex items-center gap-2">
                    @if ($account->is_system)
                        <x-ui.badge tone="warning">{{ __('accounts::message.system_account') }}</x-ui.badge>
                    @endif
                    @include('accounts::coa.partials.state', ['account' => $account])
                </span>
            </div>

            {{-- পূর্বপুরুষদের পথ, প্রতিটা ক্লিকযোগ্য — গাছের মধ্যে নিজের
                 জায়গাটা এক নজরে, আর উপরে ওঠাও যায় --}}
            @php $path = $account->ancestors() @endphp

            <p class="text-sm">
                @foreach ($path as $ancestor)
                    <a href="{{ route('accounts.coa.show', $ancestor) }}"
                       class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ $ancestor->name() }}</a>
                    <span class="text-(--color-ink-placeholder)" aria-hidden="true">›</span>
                @endforeach
                <span class="font-medium">{{ $account->name() }}</span>
            </p>

            @if (filled($account->bank_name) || filled($account->account_number))
                <dl class="mt-3 grid gap-x-4 gap-y-2 sm:grid-cols-3">
                    @foreach ([
                        'accounts::field.bank_name' => $account->bank_name,
                        'accounts::field.branch_name' => $account->branch_name,
                        'accounts::field.account_number' => $account->account_number,
                    ] as $label => $value)
                        @if (filled($value))
                            <div>
                                <dt class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</dt>
                                <dd class="text-sm">{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            @endif
        </section>
    </div>

    @if ($account->is_group)
        <section id="transactions" class="mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 font-semibold">
                {{ __('accounts::menu.chart_of_accounts') }}
            </h2>

            <x-ui.table
                :empty="__('core.empty.nothing_here')"
                :rows="$children"
                :columns="[
                    ['key' => 'code', 'label' => __('accounts::field.code'), 'width' => '8rem',
                     'render' => fn ($a) => view('accounts::coa.partials.code', ['account' => $a])],
                    ['key' => 'name_en', 'label' => __('accounts::field.name'),
                     'render' => fn ($a) => $a->name()],
                    ['key' => 'balance', 'label' => __('accounts::field.balance'), 'numeric' => true,
                     'width' => '11rem',
                     'render' => fn ($a) => number_format((float) $a->balanceOn(), 2)],
                    ['key' => 'is_active', 'label' => __('accounts::field.state'), 'width' => '7rem',
                     'render' => fn ($a) => view('accounts::coa.partials.state', ['account' => $a])],
                ]" />
        </section>
    @else
        <section class="mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 font-semibold">
                {{ __('accounts::section.entries') }}
            </h2>

            <x-ui.table
                :empty="__('accounts::message.no_entries')"
                :rows="$entries"
                :columns="[
                    ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
                     'render' => fn ($e) => $e->trx_date?->format('d/m/Y')],
                    ['key' => 'document_no', 'label' => __('core.table.document'),
                     'render' => fn ($e) => view('accounts::coa.partials.entry-source', ['entry' => $e])],
                    ['key' => 'narration', 'label' => __('core.table.narration')],
                    ['key' => 'debit', 'label' => __('core.table.debit'), 'numeric' => true, 'width' => '8rem',
                     'render' => fn ($e) => (float) $e->debit ? number_format((float) $e->debit, 2) : ''],
                    ['key' => 'credit', 'label' => __('core.table.credit'), 'numeric' => true, 'width' => '8rem',
                     'render' => fn ($e) => (float) $e->credit ? number_format((float) $e->credit, 2) : ''],
                    ['key' => 'balance', 'label' => __('core.table.balance'), 'numeric' => true, 'width' => '9rem',
                     'render' => fn ($e) => number_format((float) $e->running_balance, 2)],
                ]" />

            @if ($entries->hasPages())
                <div class="border-t border-(--color-border) px-3 py-2">{{ $entries->links() }}</div>
            @endif
        </section>
    @endif
</x-layouts.app>

{{--
    একটা নগদ কাউন্টার — হাতে কত, আর কীভাবে এল।

    লেনদেন নতুন থেকে পুরনো: কাউন্টারের পর্দায় লোকে আজকের কথা জানতে
    আসে। চলমান ব্যালেন্স তবু পুরনো থেকে গোনা, নাহলে প্রতিটা সারির
    সংখ্যা ভুল হত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $till->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$till->name()" :subtitle="$till->code">
            <x-slot:actions>
                @can('update', $till)
                    @unless ($till->is_primary)
                        <form method="POST" action="{{ route('accounts.till.primary', $till) }}">
                            @csrf
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('accounts::action.make_primary') }}
                            </x-ui.button>
                        </form>
                    @endunless

                    <x-ui.button tone="secondary" :href="route('accounts.till.edit', $till)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>
                @endcan

                @can('delete', $till)
                    @if ($till->is_active)
                        <form method="POST" action="{{ route('accounts.till.destroy', $till) }}"
                              onsubmit="return confirm('{{ __('accounts::message.close_till_confirm') }}')">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('accounts::action.close_till') }}
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

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-sm font-medium text-(--color-ink-muted)">{{ __('accounts::field.in_hand') }}</h2>

            <p @class([
                'num mt-1 text-2xl font-semibold',
                'text-(--color-danger)' => $till->isOverLimit(),
            ])>
                {{ number_format((float) $balance, 2) }}
            </p>

            @if (bccomp((string) $till->limit_amount, '0', 4) > 0)
                <p class="mt-2 text-2xs text-(--color-ink-muted)">
                    {{ __('accounts::field.limit') }}:
                    <span class="num">{{ number_format((float) $till->limit_amount, 2) }}</span>
                </p>

                @if ($till->isOverLimit())
                    {{-- আটকানো হয় না, জানানো হয় — বিকেলে আদায় বেশি হওয়া
                         কারও দোষ নয়, কিন্তু রাতে ওই টাকা হাতে থাকাটা ঝুঁকি --}}
                    <p class="mt-2 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-2 py-1
                              text-2xs text-(--color-badge-danger-ink)">
                        {{ __('accounts::message.over_limit_deposit') }}
                    </p>
                @endif
            @endif
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4
                        lg:col-span-2">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold">{{ __('accounts::section.custody') }}</h2>

                <span class="flex items-center gap-2">
                    @if ($till->is_primary)
                        <x-ui.badge tone="info">{{ __('accounts::field.primary') }}</x-ui.badge>
                    @endif
                    <x-ui.badge :tone="$till->is_active ? 'success' : 'neutral'">
                        {{ $till->is_active ? __('customer::state.active') : __('accounts::state.closed') }}
                    </x-ui.badge>
                </span>
            </div>

            <dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                <div>
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::field.holder') }}</dt>
                    <dd class="flex items-center gap-2 text-sm">
                        @if ($till->holder)
                            <x-ui.avatar :user="$till->holder" size="sm" />
                            {{ $till->holder->name }}
                        @else
                            {{ __('accounts::field.no_holder') }}
                        @endif
                    </dd>
                </div>

                @if ($till->branch)
                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">{{ __('core.company.branch') }}</dt>
                        <dd class="text-sm">{{ $till->branch->name() }}</dd>
                    </div>
                @endif

                <div>
                    {{-- খাতটা ক্লিকযোগ্য — নিয়ম ১। কাউন্টারের টাকা লেজারের
                         কোন খাতে বসে সেটা লুকানো থাকলে হিসাবরক্ষক মেলাতে
                         গিয়ে অনুমান করত। --}}
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::menu.chart_of_accounts') }}</dt>
                    <dd class="text-sm">
                        <a href="{{ route('accounts.coa.show', $till->account) }}"
                           class="text-(--color-brand-500) underline-offset-2 hover:underline">
                            {{ $till->account->label() }}
                        </a>
                    </dd>
                </div>
            </dl>
        </section>
    </div>

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
                 'render' => fn ($e) => \App\Core\Support\DateFormat::format($e->trx_date)],
                ['key' => 'document_no', 'label' => __('core.table.document'),
                 'render' => fn ($e) => view('accounts::coa.partials.entry-source', ['entry' => $e])],
                ['key' => 'narration', 'label' => __('core.table.narration')],
                ['key' => 'debit', 'label' => __('accounts::field.received'), 'numeric' => true, 'width' => '8rem',
                 'render' => fn ($e) => (float) $e->debit ? number_format((float) $e->debit, 2) : ''],
                ['key' => 'credit', 'label' => __('accounts::field.paid'), 'numeric' => true, 'width' => '8rem',
                 'render' => fn ($e) => (float) $e->credit ? number_format((float) $e->credit, 2) : ''],
                ['key' => 'balance', 'label' => __('core.table.balance'), 'numeric' => true, 'width' => '9rem',
                 'render' => fn ($e) => number_format((float) $e->running_balance, 2)],
            ]" />

        @if ($entries->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $entries->links() }}</div>
        @endif
    </section>
</x-layouts.app>

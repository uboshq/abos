{{--
    হিসাবের ছক — গাছ, তালিকা নয়।

    কেন গাছ: ছকের অর্থই কাঠামোয়। "৫২০২ ভাড়া" সংখ্যাটা একা কিছু বলে না;
    "খরচ › পরিচালন ব্যয় › ভাড়া" বলে। সমতল তালিকায় সেই সম্পর্কটা হারায়,
    আর তখন কেউ ভাড়াকে সম্পদের নিচে বসিয়ে ফেলে।

    খোঁজার সময় গাছটা সরে গিয়ে সমতল ফল আসে — কেউ "ভাড়া" লিখলে সে ওই
    খাতটা চায়, তার পূর্বপুরুষদের নয়। পথটা প্রতিটা সারিতেই লেখা থাকে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.chart_of_accounts') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('accounts::menu.chart_of_accounts')"
            :subtitle="trans_choice('accounts::message.count', $total, ['count' => $total])">
            <x-slot:actions>
                @can('create', \App\Modules\Accounts\Models\Account::class)
                    @if ($total > 0)
                        <x-ui.button tone="primary" icon="+" :href="route('accounts.coa.create')">
                            {{ __('accounts::action.new_account') }}
                        </x-ui.button>
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

    @if ($total === 0)
        {{-- খালি ছক — এখান থেকেই শুরু। একটা খালি টেবিল দেখিয়ে
             "নতুন খাত" বোতাম দিলে ব্যবহারকারীকে চল্লিশটা খাত হাতে
             লিখতে বলা হত, আর সেই ছকটা প্রায় নিশ্চিতভাবে ভুল হত। --}}
        <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-8 text-center">
            <h2 class="text-lg font-semibold">{{ __('accounts::message.chart_empty') }}</h2>

            <p class="mx-auto mt-2 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('accounts::message.chart_empty_note') }}
            </p>

            @can('create', \App\Modules\Accounts\Models\Account::class)
                <div class="mt-4 flex flex-wrap justify-center gap-2">
                    <form method="POST" action="{{ route('accounts.coa.install') }}"
                          x-data="{ busy: false }"
                          @submit="busy ? $event.preventDefault() : (busy = true)">
                        @csrf
                        <x-ui.button type="submit" tone="primary"
                                     ::class="busy && 'pointer-events-none opacity-70'">
                            {{ __('accounts::action.install_chart') }}
                        </x-ui.button>
                    </form>

                    <x-ui.button tone="secondary" :href="route('accounts.coa.create')">
                        {{ __('accounts::action.new_account') }}
                    </x-ui.button>
                </div>
            @endcan
        </div>
    @else
        <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            <form method="GET" class="contents">
                <x-ui.toolbar>
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="checkbox" name="inactive" value="1" @checked($showInactive) class="size-4">
                        {{ __('accounts::action.show_inactive') }}
                    </label>
                </x-ui.toolbar>
            </form>

            @if ($tooManyToShow)
                {{-- সীমার বেশি হলে চুপচাপ কেটে দেওয়া হয় না — কেটে দিলে
                     ব্যবহারকারী ভাবত ছকে এতটুকুই আছে। --}}
                <div class="border-b border-(--color-border) bg-(--color-surface-app) px-4 py-3 text-sm">
                    {{ __('accounts::message.too_many_to_tree', ['count' => $total, 'limit' => $treeLimit]) }}
                </div>
            @endif

            @if ($q)
                @if ($results->isEmpty())
                    <x-ui.empty-state :message="__('core.empty.no_results')" />
                @else
                    <x-ui.table
            :compact="request()->boolean('compact')"
                        :empty="__('core.empty.no_results')"
                        :rows="$results"
                        :columns="[
                            ['key' => 'code', 'label' => __('accounts::field.code'), 'width' => '8rem',
                             'render' => fn ($a) => view('accounts::coa.partials.code', ['account' => $a])],
                            ['key' => 'name_en', 'label' => __('accounts::field.name'),
                             'render' => fn ($a) => view('accounts::coa.partials.name-with-path', ['account' => $a])],
                            ['key' => 'type', 'label' => __('accounts::field.type'), 'width' => '8rem',
                             'render' => fn ($a) => __('accounts::type.' . $a->type)],
                            ['key' => 'balance', 'label' => __('accounts::field.balance'), 'numeric' => true,
                             'width' => '10rem',
                             // অঙ্কটাই লিংক — নিয়ম ১
                             'render' => fn ($a) => $a->is_group ? '' : view('ui.amount-link', [
                                 'value' => $balances[$a->id] ?? 0,
                                 'href' => route('accounts.coa.show', $a),
                             ])],
                            ['key' => 'is_active', 'label' => __('accounts::field.state'), 'width' => '7rem',
                             'render' => fn ($a) => view('accounts::coa.partials.state', ['account' => $a])],
                        ]" />
                @endif
            @elseif (! $tooManyToShow)
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-(--color-border) bg-(--color-surface-app)">
                                <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                                    {{ __('accounts::field.name') }}
                                </th>
                                {{-- ধরন কলামটা ছোট পর্দায় নেই: গাছে খাতটা
                                     কোথায় বসেছে সেটাই ধরন বলে দেয়, আর ৩৭৫px-এ
                                     নাম ও ব্যালেন্স দুটোই বেশি জরুরি। --}}
                                <th scope="col" style="width: 8rem"
                                    class="hidden px-3 py-2 text-start font-medium whitespace-nowrap
                                           text-(--color-ink-muted) sm:table-cell">
                                    {{ __('accounts::field.type') }}
                                </th>
                                <th scope="col" style="width: 11rem"
                                    class="num px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                                    {{ __('accounts::field.balance') }}
                                </th>
                                <th scope="col" style="width: 6rem" class="px-3 py-2"
                                    aria-label="{{ __('accounts::action.new_account') }}"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tree as $node)
                                @include('accounts::coa.partials.tree-row', ['account' => $node, 'depth' => 0])
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</x-layouts.app>

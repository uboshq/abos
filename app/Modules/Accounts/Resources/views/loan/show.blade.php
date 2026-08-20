{{--
    একটা ঋণ — কত বাকি, আর পরের কিস্তি কবে।

    ── উপরের তিনটা সংখ্যাই পুরো পাতার কারণ ─────────────────────────────
    ঋণ নিয়ে যত প্রশ্ন হয় তার প্রায় সবটাই তিনটার একটা: মোট কত নিয়েছি,
    এখন কত বাকি, আর (CC-তে) সীমার কতটা খালি। তাই ওগুলো উপরে, বড় হরফে;
    বাকি সব তার নিচে।

    বকেয়া প্রতিবার খতিয়ান থেকে গোনা হয় (Loan::outstanding), কোনো
    কলাম থেকে নয় — Loan মডেলে কারণটা লেখা আছে।

    ── কিস্তির সারিতেই পরিশোধের ঘর ─────────────────────────────────────
    "কোন কিস্তিটা দিচ্ছি" — এই প্রশ্নটা আলাদা পর্দায় নিলে ভুল কিস্তিতে
    টাকা বসার সুযোগ তৈরি হত। তাই সারিটার নিচেই ঘরগুলো খোলে, আর যে
    সারির বোতাম চাপা হয়েছে সেটার নম্বরই ফর্মের ঠিকানায় থাকে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $loan->lender }} — {{ $loan->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$loan->lender" :subtitle="$loan->document_no">
            <x-slot:actions>
                <x-ui.badge :tone="$loan->isTerm() ? 'info' : 'inventory'">
                    {{ $loan->isTerm() ? __('accounts::field.loan_term') : __('accounts::field.loan_cc') }}
                </x-ui.badge>

                @if ($loan->isSettled())
                    <x-ui.badge tone="success">{{ __('accounts::message.loan_settled') }}</x-ui.badge>
                @endif
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

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-sm font-medium text-(--color-ink-muted)">
                {{ $loan->isTerm() ? __('accounts::field.sanctioned') : __('accounts::field.cc_limit') }}
            </h2>
            <p class="num mt-1 text-2xl font-semibold">{{ \App\Core\Support\Money::format($loan->sanctioned) }}</p>
            <p class="mt-2 text-2xs text-(--color-ink-muted)">
                {{ __('accounts::field.interest_rate') }}:
                <span class="num">{{ rtrim(rtrim((string) $loan->interest_rate, '0'), '.') }}</span>
                @if ($loan->isTerm())
                    ·
                    {{ $loan->interest_method === \App\Modules\Accounts\Services\LoanSchedule::FLAT
                        ? __('accounts::field.method_flat')
                        : __('accounts::field.method_reducing') }}
                @endif
            </p>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-sm font-medium text-(--color-ink-muted)">
                {{ __('accounts::message.loan_outstanding') }}
            </h2>
            {{-- খতিয়ানের খাতটা ক্লিকযোগ্য — নিয়ম ১: প্রতিটা সংখ্যা থেকে
                 তার উৎসে যাওয়া যাবে --}}
            <p class="num mt-1 text-2xl font-semibold">
                <a href="{{ route('accounts.coa.show', $loan->principalAccount) }}"
                   class="text-(--color-brand-500) underline-offset-2 hover:underline">
                    {{ \App\Core\Support\Money::format($loan->outstanding()) }}
                </a>
            </p>
            <p class="mt-2 text-2xs text-(--color-ink-muted)">
                {{ $loan->principalAccount->label() }}
            </p>
        </section>

        @if ($loan->isCc())
            <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="text-sm font-medium text-(--color-ink-muted)">
                    {{ __('accounts::message.loan_available') }}
                </h2>
                <p class="num mt-1 text-2xl font-semibold">{{ \App\Core\Support\Money::format($loan->available()) }}</p>
            </section>
        @endif
    </div>

    <section class="mt-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <h2 class="mb-3 font-semibold">{{ __('accounts::section.details') }}</h2>

        <dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
            @if ($loan->account_no)
                <div>
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::field.loan_account_no') }}</dt>
                    <dd class="text-sm">{{ $loan->account_no }}</dd>
                </div>
            @endif

            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::field.starts_on') }}</dt>
                <dd class="text-sm">{{ $loan->start_date?->format('d/m/Y') }}</dd>
            </div>

            @if ($loan->isTerm())
                <div>
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::field.tenure_months') }}</dt>
                    <dd class="num text-sm">{{ $loan->tenure_months }}</dd>
                </div>
            @endif

            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::field.interest_account') }}</dt>
                <dd class="text-sm">
                    <a href="{{ route('accounts.coa.show', $loan->interestAccount) }}"
                       class="text-(--color-brand-500) underline-offset-2 hover:underline">
                        {{ $loan->interestAccount->label() }}
                    </a>
                </dd>
            </div>

            @if ($loan->security)
                <div class="sm:col-span-2">
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::field.security') }}</dt>
                    <dd class="text-sm">{{ $loan->security }}</dd>
                </div>
            @endif

            @if ($loan->narration)
                <div class="sm:col-span-2">
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('accounts::field.note') }}</dt>
                    <dd class="text-sm">{{ $loan->narration }}</dd>
                </div>
            @endif
        </dl>
    </section>

    @if ($loan->isTerm())
        <section class="mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="border-b border-(--color-border) p-4">
                <h2 class="font-semibold">{{ __('accounts::section.schedule') }}</h2>
                <p class="mt-0.5 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('accounts::message.schedule_note') }}
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="px-3 py-2 text-start">{{ __('accounts::field.instalment_no') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('accounts::field.due_date') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('accounts::field.principal') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('accounts::field.interest') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('accounts::field.instalment_total') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('accounts::field.state') }}</th>
                            <th class="px-3 py-2"><span class="sr-only">{{ __('core.table.actions') }}</span></th>
                        </tr>
                    </thead>

                    @foreach ($loan->instalments as $row)
                        {{-- প্রতিটা কিস্তি নিজের tbody-তে, কারণ পরিশোধের ঘরগুলো
                             ওই সারিরই নিচে খোলে। একাধিক tbody বৈধ HTML। --}}
                        <tbody x-data="{ open: false }"
                               class="border-t border-(--color-border)">
                            <tr @class(['bg-(--color-badge-danger-bg)' => $row->isOverdue()])>
                                <td class="num px-3 py-2">{{ $row->no }}</td>
                                <td class="px-3 py-2">{{ $row->due_date?->format('d/m/Y') }}</td>
                                <td class="num px-3 py-2 text-end">{{ \App\Core\Support\Money::format($row->principal) }}</td>
                                <td class="num px-3 py-2 text-end">{{ \App\Core\Support\Money::format($row->interest) }}</td>
                                <td class="num px-3 py-2 text-end font-medium">
                                    {{ \App\Core\Support\Money::format($row->total()) }}
                                </td>
                                <td class="px-3 py-2">
                                    @if ($row->isPaid())
                                        <x-ui.badge tone="success">{{ __('accounts::state.paid') }}</x-ui.badge>
                                        @if ($row->paid_on)
                                            <span class="block text-2xs text-(--color-ink-muted)">
                                                {{ $row->paid_on->format('d/m/Y') }}
                                            </span>
                                        @endif
                                    @elseif ($row->isOverdue())
                                        <x-ui.badge tone="danger">{{ __('accounts::state.overdue') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge tone="draft">{{ __('accounts::state.due') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-end">
                                    @can('accounts.loan.manage')
                                        @unless ($row->isPaid())
                                            <button type="button" @click="open = ! open"
                                                    class="min-h-(--spacing-touch) rounded-(--radius-field) border
                                                           border-(--color-border) px-3 text-sm">
                                                {{ __('accounts::action.pay_instalment') }}
                                            </button>
                                        @endunless
                                    @endcan
                                </td>
                            </tr>

                            @can('accounts.loan.manage')
                                @unless ($row->isPaid())
                                    <tr x-show="open" x-cloak>
                                        <td colspan="7" class="bg-(--color-surface-sunken) px-3 py-3">
                                            <form method="POST"
                                                  action="{{ route('accounts.loan.instalment.pay', [$loan->id, $row->id]) }}"
                                                  class="flex flex-wrap items-end gap-3">
                                                @csrf

                                                <label class="block">
                                                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                                        {{ __('accounts::field.from_account') }}
                                                    </span>
                                                    <select name="from_account_id" required
                                                            class="h-(--spacing-field) rounded-(--radius-field) border
                                                                   border-(--color-border) bg-(--color-surface-card) px-3">
                                                        @foreach ($moneyAccounts as $account)
                                                            <option value="{{ $account->id }}">
                                                                {{ $account->code }} — {{ $account->name() }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </label>

                                                <label class="block">
                                                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                                        {{ __('accounts::field.date') }}
                                                    </span>
                                                    <x-ui.date name="trx_date"
                                                               :value="$row->due_date?->toDateString()" />
                                                               </label>

                                                {{-- ব্যাংক সূচির চেয়ে কম-বেশি কাটলে এই ঘরটা।
                                                     খালি রাখলে সূচির অঙ্কই বসে — সাধারণত সেটাই
                                                     ঠিক, তাই ঘরটা খালিই থাকে। --}}
                                                <label class="block">
                                                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                                                        {{ __('accounts::field.amount') }}
                                                    </span>
                                                    <input type="number" name="amount" step="0.01" inputmode="decimal"
                                                           placeholder="{{ \App\Core\Support\Money::round($row->total(), 2) }}"
                                                           class="h-(--spacing-field) rounded-(--radius-field) border
                                                                  border-(--color-border) bg-(--color-surface-card)
                                                                  px-3 text-end font-(family-name:--font-numeric)">
                                                </label>

                                                <x-ui.button type="submit" tone="primary">
                                                    {{ __('accounts::action.pay_instalment') }}
                                                </x-ui.button>
                                            </form>
                                        </td>
                                    </tr>
                                @endunless
                            @endcan
                        </tbody>
                    @endforeach
                </table>
            </div>
        </section>
    @endif

    @if ($loan->movements->isNotEmpty())
        {{-- তোলা, জমা আর সুদ — প্রতিটা নিজে একটা ডকুমেন্ট, নিজের
             নম্বর সহ। উপরের "বকেয়া" সংখ্যাটা ঠিক এই সারিগুলো আর
             কিস্তিগুলো মিলিয়েই আসে, তাই সেগুলো দেখতে না পেলে সংখ্যাটা
             বিশ্বাস করা ছাড়া উপায় থাকত না। --}}
        <section class="mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="border-b border-(--color-border) p-4">
                <h2 class="font-semibold">{{ __('accounts::section.entries') }}</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="px-3 py-2 text-start">{{ __('core.print.document_no') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('accounts::field.date') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('accounts::field.type') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('core.print.account') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('accounts::field.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($loan->movements as $movement)
                            <tr class="border-t border-(--color-border)">
                                <td class="px-3 py-2">{{ $movement->document_no }}</td>
                                <td class="px-3 py-2">{{ $movement->trx_date?->format('d/m/Y') }}</td>
                                <td class="px-3 py-2">{{ $movement->label() }}</td>
                                <td class="px-3 py-2">
                                    @if ($movement->counterAccount)
                                        <a href="{{ route('accounts.coa.show', $movement->counterAccount) }}"
                                           class="text-(--color-brand-500) underline-offset-2 hover:underline">
                                            {{ $movement->counterAccount->label() }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="num px-3 py-2 text-end">
                                    {{ \App\Core\Support\Money::format($movement->amount) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @can('accounts.loan.manage')
        @if ($loan->isCc())
            {{-- CC-তে সারা বছর তিনটা কাজই বারবার হয়: তোলা, জমা, আর
                 মাসের সুদ। তাই তিনটাই পাশাপাশি, একটাও মেনুর ভেতরে নয়। --}}
            <section class="mt-4 rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('accounts::section.loan_movement') }}</h2>

                <div class="grid gap-4 lg:grid-cols-3">
                    <form method="POST" action="{{ route('accounts.loan.draw', $loan->id) }}" class="space-y-2">
                        @csrf
                        <h3 class="text-sm font-medium">{{ __('accounts::action.draw_down') }}</h3>

                        <input type="number" name="amount" step="0.01" inputmode="decimal" required
                               placeholder="{{ __('accounts::field.amount') }}"
                               class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-3 text-end
                                      font-(family-name:--font-numeric)">

                        <select name="into_account_id" required
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-3">
                            @foreach ($moneyAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name() }}</option>
                            @endforeach
                        </select>

                        <x-ui.date name="trx_date"
                                    :value="now()->toDateString()"
                                    class="w-full" />

                        <x-ui.button type="submit" tone="primary" class="w-full">
                            {{ __('accounts::action.draw_down') }}
                        </x-ui.button>
                    </form>

                    <form method="POST" action="{{ route('accounts.loan.repay', $loan->id) }}" class="space-y-2">
                        @csrf
                        <h3 class="text-sm font-medium">{{ __('accounts::action.repay') }}</h3>

                        <input type="number" name="amount" step="0.01" inputmode="decimal" required
                               placeholder="{{ __('accounts::field.amount') }}"
                               class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-3 text-end
                                      font-(family-name:--font-numeric)">

                        <select name="from_account_id" required
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-3">
                            @foreach ($moneyAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name() }}</option>
                            @endforeach
                        </select>

                        <x-ui.date name="trx_date"
                                    :value="now()->toDateString()"
                                    class="w-full" />

                        <x-ui.button type="submit" tone="secondary" class="w-full">
                            {{ __('accounts::action.repay') }}
                        </x-ui.button>
                    </form>

                    <form method="POST" action="{{ route('accounts.loan.interest', $loan->id) }}" class="space-y-2">
                        @csrf
                        <h3 class="text-sm font-medium">{{ __('accounts::action.charge_interest') }}</h3>
                        <p class="text-2xs text-(--color-ink-muted)">
                            {{ __('accounts::message.cc_interest_note') }}
                        </p>

                        <input type="number" name="amount" step="0.01" inputmode="decimal" required
                               placeholder="{{ __('accounts::field.amount') }}"
                               class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-3 text-end
                                      font-(family-name:--font-numeric)">

                        <x-ui.date name="trx_date"
                                    :value="now()->toDateString()"
                                    class="w-full" />

                        <x-ui.button type="submit" tone="secondary" class="w-full">
                            {{ __('accounts::action.charge_interest') }}
                        </x-ui.button>
                    </form>
                </div>
            </section>
        @endif
    @endcan
</x-layouts.app>

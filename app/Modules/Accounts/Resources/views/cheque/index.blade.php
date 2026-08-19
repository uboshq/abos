{{--
    চেকের খাতা।

    উপরে দুইটা সংখ্যা: কত টাকার চেক এখনো ঝুলছে, আর তার মধ্যে কয়টার
    তারিখ পেরিয়ে গেছে। দ্বিতীয়টাই রোজ সকালে দেখার জিনিস — আগাম
    তারিখের চেক ফেলে রাখা স্বাভাবিক, তারিখ পেরোনোর পরেও ফেলে রাখা নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.cheques') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.cheques')"
                          :subtitle="__('accounts::message.cheque_note')" />
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

    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) px-4 py-3">
            <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                {{ __('accounts::field.cheques_open_total') }}
            </p>
            <p class="num text-2xl font-semibold">{{ \App\Core\Support\Money::format($openTotal) }}</p>
        </div>

        <div @class([
            'rounded-(--radius-card) border px-4 py-3',
            'border-(--color-border) bg-(--color-surface-card)' => $ripe === 0,
            'border-(--color-badge-danger-ink)/30 bg-(--color-badge-danger-bg)' => $ripe > 0,
        ])>
            <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                {{ __('accounts::field.cheques_ripe') }}
            </p>
            <p class="num text-2xl font-semibold">{{ $ripe }}</p>
        </div>
    </div>

    @can('accounts.cheque.manage')
        <form method="POST" action="{{ route('accounts.cheque.store') }}"
              class="mb-5 grid gap-3 rounded-(--radius-card) border border-(--color-border)
                     bg-(--color-surface-card) p-4 md:grid-cols-3 lg:grid-cols-6">
            @csrf

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.cheque_direction') }}</span>
                <select name="direction"
                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2">
                    <option value="received">{{ __('accounts::field.cheque_received') }}</option>
                    <option value="issued">{{ __('accounts::field.cheque_issued') }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.cheque_no') }}</span>
                <input type="text" name="cheque_no" required value="{{ old('cheque_no') }}"
                       class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.bank_name') }}</span>
                <input type="text" name="bank_name" value="{{ old('bank_name') }}"
                       class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2">
            </label>

            {{--
                চেকের নিজের তারিখ — এন্ট্রির তারিখ নয়।

                আগাম তারিখের চেক (PDC) বাংলাদেশে রোজকার, আর ওটাই এই
                খাতার প্রাণ: "আগামী সপ্তাহে কত টাকার চেক পাশ হবে"।
            --}}
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.cheque_date') }}</span>
                <x-ui.date name="cheque_date" :required="true"
                           :value="old('cheque_date', now()->toDateString())" />
                           </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.amount') }}</span>
                <input type="number" step="0.01" min="0" name="amount" required value="{{ old('amount') }}"
                       class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2 text-end">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.party') }}</span>
                <select name="party"
                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2">
                    <option value="">—</option>
                    @foreach ($parties as $group)
                        <optgroup label="{{ $group['label'] }}">
                            @foreach ($group['options'] as $party)
                                <option value="{{ $group['type'] }}:{{ $party['id'] }}">{{ $party['label'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </label>

            {{--
                আমাদের কোন ব্যাংক হিসাবে টাকাটা বসবে।

                উপরের "ব্যাংকের নাম" ঘরটা আলাদা জিনিস — ওটা চেকটা **কোন
                ব্যাংকের**, অর্থাৎ যিনি চেক দিয়েছেন তাঁর ব্যাংক। দুইটাতেই
                একই লেবেল বসানো ছিল, আর পর্দায় পাশাপাশি দুইটা "ব্যাংকের
                নাম" দেখে বোঝার উপায় ছিল না কোনটা কী।
            --}}
            <label class="flex flex-col gap-1 md:col-span-2">
                <span class="text-sm font-medium">{{ __('accounts::field.deposit_into') }}</span>
                <select name="bank_account_id"
                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2">
                    <option value="">—</option>
                    @foreach ($banks as $bank)
                        <option value="{{ $bank->id }}">{{ $bank->label() }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1 md:col-span-2 lg:col-span-3">
                <span class="text-sm font-medium">{{ __('core.table.narration') }}</span>
                <input type="text" name="narration" value="{{ old('narration') }}"
                       class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2">
            </label>

            <div class="flex items-end">
                <x-ui.button type="submit" tone="primary" class="w-full">
                    {{ __('core.action.save') }}
                </x-ui.button>
            </div>
        </form>
    @endcan

    @if ($cheques->isEmpty())
        <x-ui.empty-state :message="__('accounts::message.no_cheques')" />
    @else
        <div class="overflow-x-auto rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-(--color-border) bg-(--color-surface-app)">
                        <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('accounts::field.cheque_no') }}
                        </th>
                        <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('accounts::field.cheque_date') }}
                        </th>
                        <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('accounts::field.bank_name') }}
                        </th>
                        <th scope="col" class="num px-3 py-2 text-end font-medium text-(--color-ink-muted)">
                            {{ __('accounts::field.amount') }}
                        </th>
                        <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('accounts::field.state') }}
                        </th>
                        <th scope="col" class="px-3 py-2 text-end font-medium text-(--color-ink-muted)">
                            {{ __('core.table.actions') }}
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($cheques as $cheque)
                        @php
                            $overdue = $cheque->isOpen()
                                && $cheque->cheque_date?->isBefore(now()->startOfDay());

                            $badge = match ($cheque->status) {
                                \App\Modules\Accounts\Models\Cheque::CLEARED => 'success',
                                \App\Modules\Accounts\Models\Cheque::BOUNCED,
                                \App\Modules\Accounts\Models\Cheque::CANCELLED => 'danger',
                                \App\Modules\Accounts\Models\Cheque::DEPOSITED => 'info',
                                default => 'pending',
                            };
                        @endphp

                        <tr class="border-b border-(--color-border)">
                            <td class="px-3 py-2 font-medium">
                                {{ $cheque->cheque_no }}
                                <span class="block text-2xs text-(--color-ink-muted)">
                                    {{ __('accounts::field.cheque_'.$cheque->direction) }}
                                </span>
                            </td>

                            <td @class(['px-3 py-2', 'font-semibold text-(--color-danger)' => $overdue])>
                                {{ $cheque->cheque_date?->format('d M Y') }}
                            </td>

                            <td class="px-3 py-2">{{ $cheque->bank_name ?: '—' }}</td>

                            <td class="num px-3 py-2 text-end font-medium">
                                {{ \App\Core\Support\Money::format($cheque->amount) }}
                            </td>

                            <td class="px-3 py-2">
                                <span class="rounded-(--radius-field) bg-(--color-badge-{{ $badge }}-bg)
                                             px-2 py-0.5 text-2xs text-(--color-badge-{{ $badge }}-ink)">
                                    {{ __('accounts::field.cheque_'.$cheque->status) }}
                                </span>

                                @if ($cheque->bounce_reason)
                                    <p class="mt-0.5 text-2xs text-(--color-ink-muted)">{{ $cheque->bounce_reason }}</p>
                                @endif
                            </td>

                            <td class="px-3 py-2 text-end">
                                @if ($cheque->isOpen())
                                    @can('accounts.cheque.manage')
                                        <div class="flex flex-wrap items-center justify-end gap-2">
                                            @if ($cheque->status === \App\Modules\Accounts\Models\Cheque::PENDING)
                                                <form method="POST"
                                                      action="{{ route('accounts.cheque.deposit', $cheque) }}">
                                                    @csrf
                                                    <x-ui.button type="submit" tone="secondary">
                                                        {{ __('accounts::action.cheque_deposit') }}
                                                    </x-ui.button>
                                                </form>
                                            @endif

                                            <form method="POST"
                                                  action="{{ route('accounts.cheque.clear', $cheque) }}"
                                                  class="flex items-center gap-1">
                                                @csrf
                                                @if (! $cheque->bank_account_id)
                                                    <select name="bank_account_id" required
                                                            class="h-(--spacing-field) rounded-(--radius-field)
                                                                   border border-(--color-border)
                                                                   bg-(--color-surface-app) px-2">
                                                        @foreach ($banks as $bank)
                                                            <option value="{{ $bank->id }}">{{ $bank->label() }}</option>
                                                        @endforeach
                                                    </select>
                                                @endif
                                                <x-ui.button type="submit" tone="primary">
                                                    {{ __('accounts::action.cheque_clear') }}
                                                </x-ui.button>
                                            </form>

                                            <form method="POST"
                                                  action="{{ route('accounts.cheque.bounce', $cheque) }}"
                                                  class="flex items-center gap-1">
                                                @csrf
                                                <input type="text" name="bounce_reason" required minlength="3"
                                                       placeholder="{{ __('accounts::field.bounce_reason') }}"
                                                       class="h-(--spacing-field) w-44 rounded-(--radius-field)
                                                              border border-(--color-border)
                                                              bg-(--color-surface-app) px-2">
                                                <x-ui.button type="submit" tone="secondary">
                                                    {{ __('accounts::action.cheque_bounce') }}
                                                </x-ui.button>
                                            </form>
                                        </div>
                                    @endcan
                                @else
                                    <span class="text-2xs text-(--color-ink-muted)">
                                        {{ $cheque->cleared_on?->format('d M Y') }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $cheques->links() }}</div>
    @endif
</x-layouts.app>

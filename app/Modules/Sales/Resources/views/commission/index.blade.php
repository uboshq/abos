{{--
    ডিলারের কমিশন — দেওয়া, আর কোম্পানির সিদ্ধান্ত বসানো।

    উপরে বসানোর ফর্ম, নিচে তালিকা। মাস শেষে কোম্পানির লোক বসে সারি
    ধরে ধরে বলেন কোনটা মানা হলো — তাই সিদ্ধান্তের বোতাম দুইটা সারিতেই,
    আলাদা পাতায় নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.commission') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::menu.commission')"
                          :subtitle="__('sales::message.commission_note')" />
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

    {{--
        এখনো কত কোম্পানির কাছে আটকে — পাতাটার একমাত্র যোগফল।

        মাস শেষে কোম্পানির লোককে বলা প্রথম সংখ্যাটা এটাই, তাই উপরে,
        আর বড় করে।
    --}}
    <div class="mb-4 rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) px-4 py-3">
        <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
            {{ __('sales::field.commission_pending_total') }}
        </p>
        <p class="num text-2xl font-semibold">{{ \App\Core\Support\Money::format($pendingTotal) }}</p>
    </div>

    @can('sales.commission.manage')
        <form method="POST" action="{{ route('sales.commission.store') }}"
              class="mb-5 grid gap-3 rounded-(--radius-card) border border-(--color-border)
                     bg-(--color-surface-card) p-4 md:grid-cols-3 lg:grid-cols-6">
            @csrf

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.date') }}</span>
                <x-ui.date name="trx_date"
                           :value="old('trx_date', now()->toDateString())" />
                           </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('customer::menu.party') }}</span>
                <select name="customer_id"
                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2">
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>
                            {{ $customer->name() }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('supplier::menu.party') }}</span>
                <select name="supplier_id"
                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2">
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>
                            {{ $supplier->name() }}
                        </option>
                    @endforeach
                </select>
            </label>

            {{--
                ভিত্তি — শতাংশ কিসের উপর বসবে।

                বিলের সাথে জোড়া দিলে বিলের অঙ্কই ভিত্তি হয়ে যায়, তাই
                ঘরটা কেবল নগদে দেওয়া কমিশনের জন্য।
            --}}
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('sales::field.commission_base') }}</span>
                <input type="number" step="0.01" min="0" name="base_amount" value="{{ old('base_amount') }}"
                       class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2 text-end">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('sales::field.commission_percent') }}</span>
                <input type="number" step="0.01" min="0" max="100" name="rate_percent" value="{{ old('rate_percent') }}"
                       class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2 text-end">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('sales::field.commission_flat') }}</span>
                <input type="number" step="0.01" min="0" name="rate_amount" value="{{ old('rate_amount') }}"
                       class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2 text-end">
            </label>

            <label class="flex flex-col gap-1 md:col-span-2 lg:col-span-5">
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

    @if ($claims->isEmpty())
        <x-ui.empty-state :message="__('sales::message.no_commissions')" />
    @else
        <div class="overflow-x-auto rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-(--color-border) bg-(--color-surface-app)">
                        <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('core.print.document_no') }}
                        </th>
                        <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('accounts::field.date') }}
                        </th>
                        <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('customer::menu.party') }}
                        </th>
                        <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('supplier::menu.party') }}
                        </th>
                        <th scope="col" class="num px-3 py-2 text-end font-medium text-(--color-ink-muted)">
                            {{ __('sales::field.commission_rate') }}
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
                    @foreach ($claims as $claim)
                        <tr class="border-b border-(--color-border)">
                            <td class="px-3 py-2 font-medium">{{ $claim->document_no }}</td>
                            <td class="px-3 py-2">{{ $claim->trx_date?->format('d M Y') }}</td>
                            <td class="px-3 py-2">{{ $claim->customer?->name() }}</td>
                            <td class="px-3 py-2">{{ $claim->supplier?->name() }}</td>
                            <td class="num px-3 py-2 text-end">{{ $claim->describeRate() }}</td>
                            <td class="num px-3 py-2 text-end font-medium">
                                {{ \App\Core\Support\Money::format($claim->amount) }}
                            </td>

                            <td class="px-3 py-2">
                                @php
                                    $badge = match ($claim->status) {
                                        \App\Modules\Sales\Models\CommissionClaim::SETTLED => 'success',
                                        \App\Modules\Sales\Models\CommissionClaim::REJECTED => 'danger',
                                        default => 'pending',
                                    };
                                @endphp

                                <span class="rounded-(--radius-field) bg-(--color-badge-{{ $badge }}-bg)
                                             px-2 py-0.5 text-2xs text-(--color-badge-{{ $badge }}-ink)">
                                    {{ __('sales::field.commission_'.$claim->status) }}
                                </span>

                                @if ($claim->decision_reason)
                                    <p class="mt-0.5 text-2xs text-(--color-ink-muted)">
                                        {{ $claim->decision_reason }}
                                    </p>
                                @endif
                            </td>

                            <td class="px-3 py-2 text-end">
                                @if ($claim->isPending())
                                    @can('sales.commission.manage')
                                        <div class="flex flex-wrap items-center justify-end gap-2">
                                            <form method="POST"
                                                  action="{{ route('sales.commission.settle', $claim) }}">
                                                @csrf
                                                <x-ui.button type="submit" tone="primary">
                                                    {{ __('sales::action.commission_settle') }}
                                                </x-ui.button>
                                            </form>

                                            {{--
                                                না মানার কারণটা এখানেই চাওয়া হয়।

                                                একটা পাওনা খরচ হয়ে যাওয়া মানে টাকাটা
                                                আর আসবে না; ছয় মাস পরে "কেন" প্রশ্নের
                                                উত্তর কেবল এই ঘরেই থাকে।
                                            --}}
                                            <form method="POST"
                                                  action="{{ route('sales.commission.reject', $claim) }}"
                                                  class="flex items-center gap-2">
                                                @csrf
                                                <input type="text" name="decision_reason" required minlength="3"
                                                       placeholder="{{ __('sales::field.commission_reject_reason') }}"
                                                       class="h-(--spacing-field) w-48 rounded-(--radius-field)
                                                              border border-(--color-border)
                                                              bg-(--color-surface-app) px-2">
                                                <x-ui.button type="submit" tone="secondary">
                                                    {{ __('sales::action.commission_reject') }}
                                                </x-ui.button>
                                            </form>
                                        </div>
                                    @endcan
                                @else
                                    <span class="text-2xs text-(--color-ink-muted)">
                                        {{ $claim->decided_on?->format('d M Y') }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $claims->links() }}</div>
    @endif
</x-layouts.app>

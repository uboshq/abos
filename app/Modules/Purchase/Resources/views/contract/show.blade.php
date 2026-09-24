{{--
    একটা চুক্তি — আর তাকে চালু করার বোতাম।

    ⚠️ চালু করা মানে ঐ দরটাকে প্রতিষ্ঠানের কথা বানিয়ে দেওয়া: এরপর থেকে
    প্রতিটা আদেশ ঐ সংখ্যার বিরুদ্ধে মেলানো হবে। ⛔ তাই বোতামটা আলাদা
    চাবির নিচে ([[PurchaseContractPolicy::activate()]])।
--}}
@php
    $left = $contract->daysLeft();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $contract->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$contract->document_no"
                          :subtitle="$contract->supplier?->name()" />
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

    <div class="space-y-4">
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.starts_on') }}</dt>
                    <dd>{{ \App\Core\Support\DateFormat::format($contract->starts_on) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.ends_on') }}</dt>
                    <dd>{{ \App\Core\Support\DateFormat::format($contract->ends_on) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.days_left') }}</dt>
                    <dd @class(['num', 'text-(--color-badge-danger-ink)' => $left < 0])>
                        {{ $left < 0 ? __('purchase::field.contract_over') : $left }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.state') }}</dt>
                    <dd>@include('purchase::contract.partials.status', ['contract' => $contract])</dd>
                </div>
            </dl>

            @if ($contract->terms)
                <p class="mt-3 text-sm">
                    <span class="text-xs text-(--color-ink-muted)">{{ __('purchase::field.terms') }}:</span>
                    {{ $contract->terms }}
                </p>
            @endif
        </section>

        <section data-boxed
                 class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="table-responsive">
                <table class="ui-lines table-cards w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('purchase::field.product') }}</th>
                            <th class="text-end">{{ __('purchase::field.agreed_rate') }}</th>
                            <th class="text-end">{{ __('purchase::field.qty_limit') }}</th>
                            <th class="text-end">{{ __('purchase::field.value_limit') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($contract->lines as $line)
                            <tr class="border-b border-(--color-border)">
                                <td data-label="{{ __('purchase::field.product') }}">
                                    {{ $line->product?->name() ?? '—' }}
                                </td>
                                <td class="num text-end" data-label="{{ __('purchase::field.agreed_rate') }}">
                                    {{ \App\Core\Support\Money::format((string) $line->agreed_rate) }}
                                </td>

                                {{-- ⓘ সীমা বলা না থাকলে ড্যাশ, শূন্য নয় — ⚠️ শূন্য
                                     লিখলে মনে হত চুক্তিতে কিছুই দেওয়া যাবে না। --}}
                                <td class="num text-end" data-label="{{ __('purchase::field.qty_limit') }}">
                                    {{ $line->qty_limit !== null ? $line->qty_limit : '—' }}
                                </td>
                                <td class="num text-end" data-label="{{ __('purchase::field.value_limit') }}">
                                    {{ $line->value_limit !== null
                                        ? \App\Core\Support\Money::format((string) $line->value_limit)
                                        : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @can('activate', $contract)
            <form method="POST" action="{{ route('purchase.contract.activate', $contract) }}"
                  data-boxed
                  class="flex flex-wrap items-center gap-3 rounded-(--radius-card) border
                         border-(--color-border) bg-(--color-surface-card) p-4">
                @csrf

                <x-ui.button type="submit" tone="primary">
                    {{ __('purchase::action.activate_contract') }}
                </x-ui.button>

                <p class="text-xs text-(--color-ink-muted)">
                    {{ __('purchase::message.contract_activate_note') }}
                </p>
            </form>
        @endcan
    </div>
</x-layouts.app>

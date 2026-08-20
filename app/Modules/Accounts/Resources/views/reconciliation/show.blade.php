{{--
    মিলকরণের কাজের পর্দা।

    উপরে অঙ্কটা, নিচে সারিগুলো — আর ক্রমটা ইচ্ছাকৃত। ব্যবহারকারী টিক
    দেন নিচে, কিন্তু দেখেন উপরে: প্রতিটা টিকের পরে "এখনো ব্যাখ্যাহীন"
    সংখ্যাটা শূন্যের দিকে যাচ্ছে কি না। ওটা নিচে থাকলে প্রতিবার
    স্ক্রল করে দেখতে হত, আর তখন কেউ আর দেখত না।

    তফাতের ঘরটা কেবল শূন্য হলেই সবুজ। "প্রায় মিলে গেছে" বলে কিছু নেই —
    দুই টাকার তফাতও একটা এন্ট্রি ভুল হওয়ার প্রমাণ।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::recon.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$recon->bankAccount?->label() ?? __('accounts::recon.title')"
                          :subtitle="$recon->statement_date?->format('d M Y')" />
    </x-slot:header>

    @if (session('status'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('status') }}
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

    {{-- অঙ্কটা — পাঁচটা সংখ্যা, আর শেষেরটাই আসল। --}}
    <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ([
            ['accounts::recon.statement', $summary['statement']],
            ['accounts::recon.deposits_pending', $summary['deposits']],
            ['accounts::recon.cheques_pending', $summary['cheques']],
            ['accounts::recon.ledger', $summary['ledger']],
        ] as [$label, $value])
            <div class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) px-4 py-3">
                <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ __($label) }}</p>
                <p class="num text-xl font-semibold">{{ \App\Core\Support\Money::format($value) }}</p>
            </div>
        @endforeach

        <div @class([
            'rounded-(--radius-card) border px-4 py-3',
            'border-(--color-badge-success-ink)/30 bg-(--color-badge-success-bg)' => $summary['agrees'],
            'border-(--color-badge-danger-ink)/30 bg-(--color-badge-danger-bg)' => ! $summary['agrees'],
        ])>
            <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                {{ __('accounts::recon.difference') }}
            </p>
            <p class="num text-xl font-semibold">{{ \App\Core\Support\Money::format($summary['difference']) }}</p>
        </div>
    </div>

    @if (! $summary['agrees'])
        <p class="mb-4 text-sm text-(--color-ink-muted)">{{ __('accounts::recon.does_not_agree_hint') }}</p>
    @endif

    @if ($lines->isEmpty())
        <x-ui.empty-state :message="__('accounts::recon.empty_lines')" />
    @else
        <form method="POST" action="{{ route('accounts.reconciliation.mark', $recon) }}">
            @csrf

            <x-ui.table :columns="[
                ['label' => __('accounts::recon.seen_by_bank')],
                ['label' => __('accounts::recon.date')],
                ['label' => __('accounts::recon.document')],
                ['label' => __('accounts::recon.narration_col')],
                ['label' => __('accounts::recon.paid_in'), 'numeric' => true],
                ['label' => __('accounts::recon.paid_out'), 'numeric' => true],
            ]">
                @foreach ($lines as $line)
                    <tr class="border-t border-(--color-border)">
                        <td class="px-3 align-middle" data-label="{{ __('accounts::recon.seen_by_bank') }}">
                            <input type="checkbox" name="lines[]" value="{{ $line->id }}"
                                   @checked($line->reconciliation_id !== null)
                                   @disabled($recon->isConfirmed())
                                   class="size-4 rounded border-(--color-border)">
                        </td>
                        <td class="px-3 align-middle" data-label="{{ __('accounts::recon.date') }}">
                            {{ $line->voucher?->trx_date?->format('d M Y') }}
                        </td>
                        <td class="px-3 align-middle" data-label="{{ __('accounts::recon.document') }}">
                            {{ $line->voucher?->document_no }}
                        </td>
                        <td class="px-3 align-middle" data-label="{{ __('accounts::recon.narration_col') }}">
                            {{ $line->narration ?? $line->voucher?->narration }}
                        </td>
                        <td class="px-3 text-end align-middle num" data-label="{{ __('accounts::recon.paid_in') }}">
                            <x-ui.amount :value="$line->debit" :blankOnZero="true" />
                        </td>
                        <td class="px-3 text-end align-middle num" data-label="{{ __('accounts::recon.paid_out') }}">
                            <x-ui.amount :value="$line->credit" :blankOnZero="true" />
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            <div class="mt-4 flex flex-wrap gap-2">
                @if ($recon->isDraft())
                    @can('accounts.reconciliation.manage')
                        <x-ui.button type="submit">{{ __('accounts::recon.save_ticks') }}</x-ui.button>
                    @endcan
                @endif
            </div>
        </form>

        <div class="mt-3 flex flex-wrap gap-2">
            @if ($recon->isDraft())
                @can('accounts.reconciliation.manage')
                    {{--
                        বন্ধ করার বোতামটা তফাত শূন্য না হলে থাকে না।

                        সার্ভারেও একই পাহারা আছে, আর সেটাই আসল পাহারা;
                        এটা কেবল যাতে কেউ চেপে ভুল বার্তা না পান।
                    --}}
                    @if ($summary['agrees'])
                        <form method="POST" action="{{ route('accounts.reconciliation.confirm', $recon) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">
                                {{ __('accounts::recon.confirm') }}
                            </x-ui.button>
                        </form>
                    @endif
                @endcan
            @else
                @can('accounts.reconciliation.reopen')
                    <form method="POST" action="{{ route('accounts.reconciliation.reopen', $recon) }}">
                        @csrf
                        <x-ui.button type="submit">{{ __('accounts::recon.reopen') }}</x-ui.button>
                    </form>
                @endcan
            @endif
        </div>
    @endif
</x-layouts.app>

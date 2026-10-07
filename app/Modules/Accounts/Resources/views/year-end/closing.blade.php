{{--
    বছরশেষের সমাপনী ভাউচার — ভাউচারের আন্তর্জাতিক পরিকল্পনা, অংশ ৩ঙ (৭ অক্টোবর ২০২৬; [[YearEndController::closing()]])।

    ⓘ আয়-ব্যয়ের প্রতিটা খাত বছরের শেষ দিনে শূন্য, পার্থক্য সঞ্চিত মুনাফায় — শাখা ধরে ([[YearEndService::close()]])। বছর আবার
    খুললে উল্টো দাখিলা একই নম্বরে নিচে; আবার বন্ধ করলে নতুন নম্বর ("/২")। কাগজটা কেবল পড়ার — বন্ধ-খোলা বছরশেষের পাতা থেকে।
--}}
@php
    use App\Core\Support\Money;

    $last = $papers[array_key_last($papers)];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $last['document_no'] }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$last['document_no']" :subtitle="__('accounts::voucher.closing_voucher').' · '.$year->name">
            <x-slot:actions>
                <x-ui.print-menu :documents="[[
                    'label' => $last['document_no'],
                    'url' => route('accounts.year_end.closing.print', $year),
                    'type' => 'accounts_year_closing',
                    'id' => $year->id,
                    'no' => $last['document_no'],
                ]]" />
                <x-ui.button tone="secondary" :href="route('accounts.year_end.index')">
                    {{ __('accounts::menu.year_end') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @foreach ($papers as $doc)
        <section data-boxed data-closing-paper="{{ $doc['kind'] }}"
                 class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                <span class="num">{{ $doc['document_no'] }}</span>
                · {{ $doc['date'] }}
                · {{ __('accounts::voucher.closing_kind_'.$doc['kind']) }}
            </h2>

            @if ($doc['narration'] !== '')
                <p class="border-b border-(--color-border) px-4 py-2 text-sm">{{ $doc['narration'] }}</p>
            @endif

            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('core.print.account') }}</th>
                            <th scope="col">{{ __('accounts::voucher.closing_branch') }}</th>
                            <th scope="col" class="text-right">{{ __('core.table.debit') }}</th>
                            <th scope="col" class="text-right">{{ __('core.table.credit') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($doc['lines'] as $line)
                            <tr>
                                <td>{{ $line['account'] }}</td>
                                <td>{{ $line['branch'] }}</td>
                                <td class="num text-right">{{ bccomp($line['debit'], '0', 4) > 0 ? Money::format($line['debit']) : '' }}</td>
                                <td class="num text-right">{{ bccomp($line['credit'], '0', 4) > 0 ? Money::format($line['credit']) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row" colspan="2">{{ __('core.print.total') }}</th>
                            <td class="num text-right font-semibold" data-closing-debit>{{ Money::format($doc['debit']) }}</td>
                            <td class="num text-right font-semibold">{{ Money::format($doc['credit']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    @endforeach
</x-layouts.app>

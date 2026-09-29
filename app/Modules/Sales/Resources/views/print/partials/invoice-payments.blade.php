{{--
    জমার ছক — "Paid - Received Into Accounts"। চাই: $v, $doc; ঐচ্ছিক $lang। সুইচ বন্ধ হলে কিছুই না।
    নকশা সাজায়: `.pay-head`, `table.pay`, `.num`।
--}}
@php($lang = $lang ?? 'en')
@if ($v->shows('deposits'))
    <div class="pay-head" data-deposits>{{ $v->t('payments_title', $lang) }}</div>
    <table class="pay">
        <tr>
            <th>{{ $v->t('txn_id', $lang) }}</th>
            <th>{{ $v->t('txn_date', $lang) }}</th>
            <th>{{ $v->t('method', $lang) }}</th>
            <th class="num">{{ $v->t('amount', $lang) }}</th>
        </tr>
        @foreach ($doc->payments as $row)
            <tr>
                <td>{{ $row['ref'] }}</td>
                <td>{{ $row['date'] }}</td>
                <td data-method>{{ $row['method'] }}</td>
                <td class="num">{{ $row['amount'] }}</td>
            </tr>
        @endforeach
    </table>
@endif

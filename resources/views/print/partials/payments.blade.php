{{--
    এই কাগজের বিপরীতে আসা টাকা — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।

    ⓘ বিলের বাঁ-নিচে একটা ছোট ছক, যাতে গ্রাহক ফোন করে জিজ্ঞেস না করেন
    "আমার জমাটা বসেছে কি না"।

    ⛔ সারিগুলো হুবহু সেই দুইটা উৎস থেকে আসে যেগুলো দিয়ে উপরের
    "পরিশোধ" লাইনটা তৈরি ([[SalesPrintController::paymentsAgainst()]])।
    ⚠️ গ্রাহকের সব জমা ছাপলে ছকের যোগফল আর ঐ লাইনটা দুই কথা বলত, আর
    পাঠক ভাবতেন কোথাও টাকা দুইবার গোনা হয়েছে।

    ⓘ খালি হলে কিছুই আঁকা হয় না — চালান বা অর্ডারের কাগজ অপরিবর্তিত।
--}}
@if (($payments ?? []) !== [])
    <div class="payments-block">
        <div class="payments-head">{{ __('sales::print.payments_title') }}</div>

        <table class="ui-list payments">
            <thead>
                <tr>
                    <th class="num">{{ __('core.print.serial') }}</th>
                    <th>{{ __('sales::print.txn_no') }}</th>
                    <th>{{ __('core.print.date') }}</th>
                    <th>{{ __('sales::print.method') }}</th>
                    <th>{{ __('core.print.narration') }}</th>
                    <th class="num">{{ __('core.print.amount') }}</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($payments as $row)
                    <tr>
                        <td class="num">{{ $row['no'] }}</td>
                        <td>{{ $row['ref'] }}</td>
                        <td>{{ $row['date'] }}</td>
                        <td>{{ $row['method'] }}</td>
                        <td>{{ $row['narration'] }}</td>
                        <td class="num">{{ $row['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

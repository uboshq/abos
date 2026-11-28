{{-- ⭐ শেষবার পাকা করা — মালিকের অনুমোদিত নমুনা (২৮ নভেম্বর ২০২৬), ক্রয়ের "মাল বুঝে নিন"-এর আগে। কেবল ABOS-এর রূপে।
     ⓘ ক্রয়ের সার্ভার-খসড়া বোতাম নেই, তাই কেবল নিশ্চিতের পপআপ। খোলা-বন্ধ [[final-confirm.js]]-এ। --}}
<dialog class="fc-dialog" data-final-dialog="confirm" aria-labelledby="fc-pconfirm-title">
    <div class="fc-head fc-ok" id="fc-pconfirm-title">✓ {{ __('purchase::field.final_confirm_title') }}</div>
    <div class="fc-body">
        <div class="fc-who">
            <span>{{ __('purchase::field.supplier') }}
                <span class="fc-cust"><b x-text="(supplier && supplier.name) || ''"></b></span>
            </span>
            <span class="fc-no">{{ __('purchase::field.items_word') }}<b class="num" x-text="lines.length"></b></span>
        </div>
        <div class="fc-big fc-ok"><small>{{ __('purchase::field.bill_total') }}</small><b class="num" x-text="finalTotalText"></b></div>
        <div class="fc-rows">
            <span>{{ __('purchase::field.paid_total') }}</span><span class="num" x-text="finalPaidText"></span>
            <span class="fc-due">{{ __('purchase::field.invoice_due') }}</span><span class="num fc-due" x-text="finalDueText"></span>
        </div>
        <p class="fc-q">{{ __('purchase::message.final_confirm_question') }}</p>
    </div>
    <div class="fc-foot">
        <button type="button" class="fc-back" data-final-back>← {{ __('purchase::action.go_back') }} <kbd>Esc</kbd></button>
        <button type="button" class="fc-yes" data-final-yes>{{ __('purchase::action.yes_take_goods') }} <kbd>⏎</kbd></button>
    </div>
</dialog>

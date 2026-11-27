{{-- ⭐ শেষবার পাকা করা — মালিকের অনুমোদিত নমুনা (২৮ নভেম্বর ২০২৬, "নিশ্চিত-ও-খসড়ার-পপআপ-নমুনা")। কেবল ABOS-এর রূপে।
     খোলা-বন্ধ আর জমা [[final-confirm.js]]-এ; লেখা Alpine-এর বাঁধাই (ফর্মের ভিতরে)। পর্দার মাঝে, স্ক্রল ছাড়া, কেবল দরকারি তথ্য। --}}
<dialog class="fc-dialog" data-final-dialog="confirm" aria-labelledby="fc-confirm-title">
    <div class="fc-head fc-ok" id="fc-confirm-title">✓ {{ __('sales::field.final_confirm_title') }}</div>
    <div class="fc-body">
        <div class="fc-who">
            <span>{{ __('sales::field.buyer_word') }}
                <span class="fc-cust"><b x-text="customer.name"></b>
                    <span class="fc-pt" x-show="finalPoint" x-cloak><x-ui.licon name="map-pin" :size="17" /><span x-text="finalPoint"></span></span>
                </span>
            </span>
            <span class="fc-no">{{ __('sales::field.sale_no') }}<b>{{ old('challan_no', $resume['challanNo'] ?? $salePreview ?? '') }}</b></span>
        </div>
        <div class="fc-big fc-ok"><small>{{ __('sales::field.v2_total_payable') }}</small><b class="num" x-text="finalTotalText"></b></div>
        <div class="fc-rows">
            <span>{{ __('sales::field.items') }}</span><span class="num" x-text="finalItemsText"></span>
            <span>{{ __('sales::field.received_deposit') }}</span><span class="num" x-text="finalPaidText"></span>
            <span class="fc-due">{{ __('sales::field.v2_bill_left') }}</span><span class="num fc-due" x-text="finalDueText"></span>
        </div>
        <p class="fc-q">{{ __('sales::message.final_confirm_question') }}</p>
    </div>
    <div class="fc-foot">
        <button type="button" class="fc-back" data-final-back>← {{ __('sales::action.go_back') }} <kbd>Esc</kbd></button>
        <button type="button" class="fc-yes" data-final-yes>{{ __('sales::action.yes_confirm') }} <kbd>⏎</kbd></button>
    </div>
</dialog>

<dialog class="fc-dialog" data-final-dialog="draft" aria-labelledby="fc-draft-title">
    <div class="fc-head fc-draft" id="fc-draft-title">💾 {{ __('sales::field.final_draft_title') }}</div>
    <div class="fc-body">
        <div class="fc-who">
            <span>{{ __('sales::field.buyer_word') }}
                <span class="fc-cust"><b x-text="customer.name"></b>
                    <span class="fc-pt" x-show="finalPoint" x-cloak><x-ui.licon name="map-pin" :size="17" /><span x-text="finalPoint"></span></span>
                </span>
            </span>
            <span class="fc-no">{{ __('sales::field.items') }}<b class="num" x-text="lines.length"></b></span>
        </div>
        <div class="fc-big fc-draft"><small>{{ __('sales::field.total_so_far') }}</small><b class="num" x-text="finalTotalText"></b></div>
        <p class="fc-q">{{ __('sales::message.final_draft_question') }}</p>
    </div>
    <div class="fc-foot">
        <button type="button" class="fc-back" data-final-back>← {{ __('sales::action.go_back') }} <kbd>Esc</kbd></button>
        <button type="button" class="fc-yes fc-yes-draft" data-final-yes>{{ __('sales::action.yes_draft') }} <kbd>⏎</kbd></button>
    </div>
</dialog>

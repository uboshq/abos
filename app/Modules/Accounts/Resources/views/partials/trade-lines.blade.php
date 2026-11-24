{{--
    ⭐ নোট আর স্টক ছাড়া ভাউচারের সারির ছক, ন্যারেশন, "খাতায় যা উঠবে" আর ডানের সারাংশ — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬।

    চাই (ডাকার পাতা থেকে): $types [{key,label}], $pickProducts, $taxes, $accounts, $narrationRequired, $isNote,
    $returnLabel (CN/DN-এ, নাহলে null), $returnChip, $returnNote, $bottomLabel, $withTds (PUV)।
    ⓘ সব যুক্তি [[trade-lines.js]]-এ (CSP-Alpine) — এখানে কেবল নাম ডাকা। ⛔ সারির ঘরগুলোর `name` নেই: সারি যায় একটাই
    লুকানো ঘরে (`lines_json`), পর্দার হিসাবের হুবহু।
--}}
<input type="hidden" name="lines_json" :value="linesJson" data-lines-json>

<div class="tv-body">
    <div class="tv-main">
        <div class="tv-lines" data-trade-lines>
            <div class="tv-scroll">
                <table>
                    <thead>
                        <tr>
                            <th class="tv-c-no">{{ __('accounts::trade_form.col_no') }}</th>
                            <th>{{ __('accounts::trade_form.col_type') }}</th>
                            <th>{{ __('accounts::trade_form.col_item') }}</th>
                            <th>{{ __('accounts::trade_form.col_desc') }}</th>
                            @if ($isNote)
                                <th class="tv-r" x-show="hasBill">{{ __('accounts::trade_form.col_billed') }}</th>
                            @endif
                            <th class="tv-r">{{ __('accounts::trade_form.col_qty') }}</th>
                            <th>{{ __('accounts::trade_form.col_unit') }}</th>
                            <th class="tv-r">{{ __('accounts::trade_form.col_rate') }}</th>
                            <th class="tv-r">{{ __('accounts::trade_form.col_amount') }}</th>
                            <th>{{ __('accounts::trade_form.col_vat') }}</th>
                            <th class="tv-r">{{ __('accounts::trade_form.col_vat_amount') }}</th>
                            <th class="tv-r">{{ __('accounts::trade_form.col_total') }}</th>
                            @if ($returnLabel)
                                <th>{{ __('accounts::trade_form.col_goods') }}</th>
                            @endif
                            <th><span class="sr-only">{{ __('accounts::trade_form.remove_row') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="line in lines" :key="line.key">
                            <tr data-trade-line>
                                <td class="num tv-c-no" x-text="rowNo(line)"></td>
                                <td>
                                    <select class="tv-type" x-model="line.type" x-on:change="typeChanged(line)"
                                            aria-label="{{ __('accounts::trade_form.col_type') }}">
                                        @foreach ($types as $lineType)
                                            <option value="{{ $lineType['key'] }}">{{ $lineType['label'] }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="tv-item">
                                    <template x-if="fromCatalogue(line)">
                                        <div>
                                            <select x-model="line.product_id" x-on:change="productPicked(line)"
                                                    aria-label="{{ __('accounts::trade_form.type_product') }}">
                                                <option value="">{{ __('accounts::trade_form.pick_product') }}</option>
                                                @foreach ($pickProducts as $product)
                                                    <option value="{{ $product['id'] }}">{{ $product['name'] }}</option>
                                                @endforeach
                                            </select>
                                            <span class="tv-src" x-text="productAccountText(line)"></span>
                                        </div>
                                    </template>
                                    <template x-if="fromBill(line)">
                                        <div>
                                            <select x-on:change="billLinePicked(line, $event)"
                                                    aria-label="{{ __('accounts::trade_form.type_product') }}">
                                                <option value="">{{ __('accounts::trade_form.pick_bill_line') }}</option>
                                                <template x-for="billLine in billLines()" :key="billLine.line_id">
                                                    <option :value="billLine.line_id" :selected="isBillLine(line, billLine)"
                                                            x-text="billLineLabel(billLine)"></option>
                                                </template>
                                            </select>
                                            <span class="tv-src" x-text="productAccountText(line)"></span>
                                        </div>
                                    </template>
                                    <template x-if="isService(line)">
                                        <div class="tv-svc">
                                            <input type="text" x-model="line.item_name" maxlength="191"
                                                   placeholder="{{ __('accounts::trade_form.service_name') }}"
                                                   aria-label="{{ __('accounts::trade_form.service_name') }}">
                                            <select x-model="line.account_id" aria-label="{{ __('accounts::trade_form.type_account') }}">
                                                <option value="">{{ __('accounts::trade_form.pick_account') }}</option>
                                                @foreach ($accounts as $account)
                                                    <option value="{{ $account['id'] }}">{{ $account['code'] }} · {{ $account['name'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </template>
                                    <template x-if="isAccount(line)">
                                        <select x-model="line.account_id" aria-label="{{ __('accounts::trade_form.type_account') }}">
                                            <option value="">{{ __('accounts::trade_form.pick_account') }}</option>
                                            @foreach ($accounts as $account)
                                                <option value="{{ $account['id'] }}">{{ $account['code'] }} · {{ $account['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </template>
                                </td>
                                <td class="tv-desc">
                                    <input type="text" x-model="line.description" maxlength="255"
                                           aria-label="{{ __('accounts::trade_form.col_desc') }}">
                                </td>
                                @if ($isNote)
                                    <td class="num tv-r" x-show="hasBill" x-text="billedShown(line)"></td>
                                @endif
                                <td class="tv-w-qty">
                                    <input type="text" inputmode="decimal" class="num tv-n" :value="qtyShown(line)"
                                           x-on:input="setQty(line, $event)" :readonly="qtyLocked(line)"
                                           aria-label="{{ __('accounts::trade_form.col_qty') }}">
                                </td>
                                <td class="tv-unit">
                                    <span x-show="! isService(line)" x-text="unitShown(line)"></span>
                                    <input type="text" x-show="isService(line)" x-model="line.unit" maxlength="32"
                                           aria-label="{{ __('accounts::trade_form.col_unit') }}">
                                </td>
                                <td class="tv-w-rate">
                                    <input type="text" inputmode="decimal" class="num tv-n" :value="rateShown(line)"
                                           x-on:input="setRate(line, $event)" :readonly="rateLocked(line)"
                                           aria-label="{{ __('accounts::trade_form.col_rate') }}">
                                </td>
                                <td class="tv-w-amount">
                                    <input type="text" inputmode="decimal" class="num tv-n" :value="amountShown(line)"
                                           x-on:input="setAmount(line, $event)" :readonly="amountLocked(line)"
                                           aria-label="{{ __('accounts::trade_form.col_amount') }}" data-line-amount>
                                </td>
                                <td class="tv-w-vat">
                                    @if ($isNote)
                                        <span class="tv-novat" x-show="noVat()" data-not-adjusted>{{ __('accounts::note_form.not_adjusted') }}</span>
                                        <span class="tv-vatlock num" x-show="vatLocked(line)" x-text="vatLockedText(line)"></span>
                                    @endif
                                    <select x-show="vatPickable(line)" x-model="line.tax_id" aria-label="{{ __('accounts::trade_form.col_vat') }}">
                                        <option value="">{{ __('accounts::trade_form.vat_none') }}</option>
                                        @foreach ($taxes as $tax)
                                            <option value="{{ $tax['id'] }}">{{ $tax['name'] }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="num tv-r" x-text="lineVatText(line)"></td>
                                <td class="num tv-r tv-strong" x-text="lineTotalText(line)"></td>
                                @if ($returnLabel)
                                    <td>
                                        <label class="tv-tick" x-show="canReturn(line)">
                                            <input type="checkbox" :checked="line.goods_moved" x-on:change="setGoods(line, $event)" data-goods-moved>
                                            {{ $returnLabel }}
                                        </label>
                                    </td>
                                @endif
                                <td>
                                    <button type="button" class="tv-x" x-on:click="remove(line)"
                                            title="{{ __('accounts::trade_form.remove_row') }}"
                                            aria-label="{{ __('accounts::trade_form.remove_row') }}">×</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="tv-foot">
                <button type="button" class="tv-add" x-on:click="add()" data-add-line>{{ __('accounts::trade_form.add_row') }}</button>
                @error('lines')<span class="tv-badge tv-badge-bad">{{ $message }}</span>@enderror
                @foreach ($errors->getMessages() as $errorKey => $errorMessages)
                    @if (str_starts_with((string) $errorKey, 'lines.'))
                        <span class="tv-badge tv-badge-bad">{{ __('accounts::trade_form.lines') }} {{ ((int) explode('.', (string) $errorKey)[1]) + 1 }}: {{ $errorMessages[0] }}</span>
                    @endif
                @endforeach
            </div>
        </div>

        <div class="tv-narr">
            <label for="tv-narration">
                {{ __('accounts::trade_form.narration') }}
                @if ($narrationRequired)<b aria-hidden="true">*</b>@endif
            </label>
            <textarea id="tv-narration" name="narration" rows="2" maxlength="500"
                      @if ($narrationRequired) required @endif
                      @error('narration') aria-invalid="true" @enderror>{{ old('narration') }}</textarea>
            @error('narration')<span class="tv-badge tv-badge-bad">{{ $message }}</span>@enderror
        </div>

        <details class="tv-post" data-posting-preview>
            <summary>
                <span>{{ __('accounts::trade_form.post_title') }} ▸</span>
                <span class="tv-dr">{{ __('accounts::trade_form.debit') }} <span class="num" x-text="drText"></span></span>
                <span class="tv-cr">{{ __('accounts::trade_form.credit') }} <span class="num" x-text="crText"></span></span>
                <span class="tv-ok" x-show="balanced">{{ __('accounts::trade_form.balanced') }}</span>
                <span class="tv-bad" x-show="! balanced">{{ __('accounts::trade_form.unbalanced') }}</span>
                @if ($returnLabel)
                    <span class="tv-retchip" x-show="hasReturns" data-return-chip>{{ $returnChip }}</span>
                @endif
            </summary>
            <table>
                <thead>
                    <tr>
                        <th>{{ __('accounts::trade_form.col_account') }}</th>
                        <th>{{ __('accounts::trade_form.col_party') }}</th>
                        <th class="tv-r">{{ __('accounts::trade_form.debit') }}</th>
                        <th class="tv-r">{{ __('accounts::trade_form.credit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in postingRows()" :key="row.key">
                        <tr>
                            <td class="num" x-text="row.label"></td>
                            <td x-text="row.party"></td>
                            <td class="num tv-r tv-dr" x-text="row.debit"></td>
                            <td class="num tv-r tv-cr" x-text="row.credit"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
            @if ($returnLabel)
                <div x-show="hasReturns">
                    <p class="tv-sub"><b>{{ $returnNote }}</b> (<span x-text="returnItems"></span>)</p>
                    <table>
                        <tbody>
                            <template x-for="row in returnRows()" :key="row.key">
                                <tr>
                                    <td class="num" x-text="row.label"></td>
                                    <td x-text="row.party"></td>
                                    <td class="num tv-r tv-dr" x-text="row.debit"></td>
                                    <td class="num tv-r tv-cr" x-text="row.credit"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            @endif
        </details>
    </div>

    <aside class="tv-sum" data-trade-summary>
        <h4>{{ __('accounts::trade_form.summary') }}</h4>
        <span class="tv-k">{{ __('accounts::trade_form.sum_amount') }}</span><span class="tv-v num" x-text="sumAmountText"></span>
        <span class="tv-k">{{ __('accounts::trade_form.sum_vat') }}</span><span class="tv-v num" x-text="sumVatText"></span>
        <span class="tv-k">{{ __('accounts::trade_form.sum_gross') }}</span><span class="tv-v num" x-text="sumGrossText"></span>
        @if ($withTds)
            <h4 class="tv-sum-gap">{{ __('accounts::trade_form.withheld') }}</h4>
            <label class="tv-k" for="tv-tds">{{ __('accounts::trade_form.tds') }}</label>
            <input id="tv-tds" type="text" inputmode="decimal" name="tds_amount" class="num tv-n" x-model="tds" data-tds>
            <span class="tv-badge tv-badge-bad tv-span" x-show="tdsOver" x-cloak>{{ __('accounts::trade.tds_over_total') }}</span>
            @error('tds_amount')<span class="tv-badge tv-badge-bad tv-span">{{ $message }}</span>@enderror
        @endif
        <span class="tv-k tv-tot">{{ $bottomLabel }}</span><span class="tv-v num tv-tot tv-tone-ink" x-text="bottomText" data-bottom-line></span>
    </aside>
</div>

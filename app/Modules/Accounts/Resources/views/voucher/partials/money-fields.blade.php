{{--
    ⭐ টাকার অংশ — পদ্ধতি → সেই পদ্ধতির খাত → পদ্ধতির ঘর, তারপর উৎসে কাটা; রসিদ আর পরিশোধের "সাধারণ তথ্য" কার্ডের ভিতরে, পক্ষ ·
    তারিখ · টাকার নিচে (নকশা পাতা ২ আর ৪: এক কার্ডে সব; fe, ২৫ অক্টোবর ২০২৬)। ⓘ আগে আলাদা কার্ডে, খোলা বিলের নিচে বসত — নকশায় খোলা
    বিল এই কার্ডের পরে। ঘরের নাম, যুক্তি আর ক্রম আগের মতোই ([[simple-form]] থেকে সরানো, বদলানো নয়)।
--}}
            @php
                /*
                 * ⭐ টাকার খাতের ধরন প্রতিটা সারির গায়ে — ২৭ সেপ্টেম্বর ২০২৬।
                 * ⓘ ২৪ অক্টোবর ২০২৬ থেকে উল্টো দিকে: পদ্ধতি আগে, আর বাক্স কেবল সেই পদ্ধতির খাত দেখায় ([[x-money.method]] `accounts`, [[money.js]])।
                 */
                $isPayment = $voucher->type === \App\Modules\Accounts\Models\Voucher::PAYMENT;
                $moneyRows = collect($moneyAccounts)->map(fn ($a) => ['id' => $a->id, 'label' => $a->label(), 'kind' => (string) $a->money_kind])->values()->all();
            @endphp

            <div class="mt-4" data-voucher-money>
                {{--
                    ⭐ পদ্ধতি আগে, তারপর কেবল সেই পদ্ধতির খাত, তারপর রেফারেন্স আর চার্জ (মালিকের প্রশ্ন, ২৪ অক্টোবর ২০২৬, fe; SAP/Oracle-এর
                    payment method → house bank)। ⓘ খাতের ঘর এখন [[x-money.method]]-এর ভিতরে, পদ্ধতির ঠিক পরে — তিন পর্দায় (রসিদ-পরিশোধ,
                    সরবরাহকারী-পরিশোধ, আদায়) একই নিয়ম এক জায়গায়। ⛔ আগে খাত আগে ছিল, আর "Roket"-এর মতো MFS খাত বেছে "নগদ" চাপা যেত।
                    ⓘ ঘর `x-model="account"`-এ বাঁধা — স্থিতি, প্রিভিউ আর রেফারেন্সের উত্তর আগের মতোই এটা দেখে।
                --}}
                {{--
                    ⓘ সমান চার কলাম, টাকা দেওয়ার পর্দার মতো — চিপ অর্ধেক, খাত অর্ধেক। ২৪ অক্টোবরে চিপের কলাম নিজের মাপে (`auto auto`) ছিল,
                    কারণ তখন চিপ জায়গা না পেলে খাতের ঘরে ঢুকত; এখন চিপের fieldset `min-w-0` আর সারি ভাঁজ হয় ([[x-money.method]]), তাই রসিদের
                    পাঁচ চিপ (চেকের লিংকসহ) অর্ধেকেই বসে, আর খাতের নাম কাটে না (২৫ অক্টোবর ২০২৬)। পদ্ধতির ঘরগুলো (`col-span-full`) আগের মতোই
                    পুরো চওড়ায়।
                --}}
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-voucher-money-grid>
                    {{-- ⓘ নতুন ভাউচারে record নয় — তখন মূল্য-তারিখ আজ দিয়ে শুরু। ⭐ `slip-check`: রেফারেন্সের নিচে চলতি "একই খাতে আগে ব্যবহার হয়নি ✓" --}}
                    {{-- ⛔ পরিশোধে খাতের ঘরটা একসময় ছিলই না — লাইভে একটাও পরিশোধ পোস্ট হয়নি (hp2, TCL, ২৭ সেপ্টেম্বর ২০২৬)। ⭐ লেখা নকশার: "যে খাত থেকে" --}}
                    <x-money.method inline slip-check
                        :direction="$isPayment ? 'out' : 'in'"
                        :record="$voucher->exists ? $voucher : null"
                        :modes="$transferModes ?? []"
                        :methods="$isPayment ? ['cash', 'mfs', 'transfer', 'cheque'] : ['cash', 'mfs', 'transfer', 'deposit']"
                        :accounts="$moneyRows"
                        :account-name="$isPayment ? 'from_account_id' : 'to_account_id'"
                        account-model="account"
                        :account-label="$isPayment ? __('money.screen.from_account') : __($sides['to']['label'])"
                        :account-selected="$isPayment ? old('from_account_id', $creditLine?->account_id) : old('to_account_id', $debitLine?->account_id)"
                        :account-hint="$cashHiddenReason ?? null"
                        :elsewhere="$isPayment ? [] : ['cheque' => route('accounts.cheque.create')]"
                        :elsewhere-title="__('money.screen.cheque_elsewhere')"
                        counting>
                        <x-slot:account-note>
                            {{-- ⭐ খাতে আছে কত — নকশার "খাতে আছে ৳…"; পরিশোধে পরিশোধ আর চার্জের চেয়ে কম হলে লাল --}}
                            <p class="mt-1 text-2xs" data-account-balance x-show="hasBalance" x-cloak :class="balanceClass">
                                {{ __('money.screen.in_account') }}<span x-text="balanceText"></span>
                                <span x-show="short">— {{ __('money.screen.short') }}</span><span x-show="! short" data-balance-ok>✓</span>
                            </p>
                        </x-slot:account-note>
                    </x-money.method>
                </div>

                {{--
                    ⭐ উৎসে কাটা — দুই ভাউচারেই (fe, ২৪ অক্টোবর ২০২৬)।
                    · পরিশোধ (নকশা পাতা ৪, AIT আগে): আমরা কাটি — পক্ষের খাত মোটে, টাকার খাত দেওয়া অঙ্কে, কর্তন ২১২১ (AIT) আর ২১২২ (VDS)-এ;
                      খরচের ভাউচারের একই পথ ([[WithheldTax::onPayment()]])।
                    · রসিদ (নকশা পাতা ২, VDS আগে): পক্ষ কাটেন — টাকার খাতে পাওয়া − চার্জ, ১১৩৮ (VDS) আর ১১৩৫ (AIT)-এ ডেবিট, পক্ষের খাত কমে
                      পুরোটায়; আদায়ের দফা ৫-এর একই নিয়ম ([[WithheldAtSource]], [[WithheldTax::onReceipt()]])।
                --}}
                @php
                    $withheldFields = [
                        'ait' => ['ait_amount', $isPayment ? __('money.screen.sum.ait') : __('money.screen.sum.ait_in')],
                        'vds' => ['vds_amount', $isPayment ? __('money.screen.sum.vds') : __('money.screen.sum.vds_in')],
                    ];
                    $withheldOrder = $isPayment ? ['ait', 'vds'] : ['vds', 'ait'];
                @endphp
                <div class="mt-3 grid gap-3 sm:grid-cols-2" data-voucher-withheld>
                    @foreach ($withheldOrder as $kind)
                        <x-ui.field :name="$withheldFields[$kind][0]" type="money" :model="$kind" empty-shows="0.00"
                                    :label="$withheldFields[$kind][1]"
                                    :value="old($withheldFields[$kind][0], bccomp((string) ($voucher->{$withheldFields[$kind][0]} ?? '0'), '0', 4) > 0 ? $voucher->{$withheldFields[$kind][0]} : '')" />
                    @endforeach
                </div>
            </div>

{{--
    ⭐ রসিদ আর পরিশোধ ভাউচারের ডানের কলাম — হিসাবের সারাংশ → খাতায় যা যাবে → নিয়ন্ত্রণ ([[simple-form]] থেকে হুবহু সরানো, বদলানো নয়;
    cb-ma-5: পাতা ১০০০ লাইনের ছাদ পেরিয়েছিল — [[NoScreenGrowsPastAThousandLinesTest]])। ⓘ মূল পাতার সব চলক এখানে আসে
    ($voucher, $isPayment, $moneySignatures …); নিজের @php নেই।
--}}
            {{--
                ═══ ডানের কলাম — একটাই খণ্ড: মাথার তিন অঙ্ক → হিসাবের সারাংশ → খাতায় যা যাবে → নিয়ন্ত্রণ (নকশা পাতা ২ আর ৪; a6-এর আসন্ন
                Core দুই-কলামের ফর্মের `right` খণ্ডের হুবহু ক্রম — তখন পুরো খণ্ডটা সরে বসবে, নতুন করে লেখা নয়) ═══
            --}}
            <aside class="flex min-w-0 flex-col gap-4" data-voucher-aside data-part="right">
            {{--
                ⭐ হিসাবের সারাংশ — তিন পর্দার একই কার্ড ([[x-money.summary]])। রসিদ: পাওয়া টাকা → চার্জ → খাতে উঠবে (নিট) → কথায় → মিলেছে;
                পরিশোধ আয়নায়: দেওয়া টাকা → চার্জ → খাত থেকে যাবে (নিট) → কথায় → মিলেছে। ⓘ অঙ্ক আর কথা সার্ভারের, খাতার একই সারি থেকে
                ([[VoucherController::summaryOf()]])। ⓘ উৎসে কাটার ঘর রসিদ-পরিশোধ ভাউচারে নেই (আছে আদায় আর সরবরাহকারী-পরিশোধে), তাই সারিও নেই।
            --}}
            <x-money.summary data-voucher-summary words="sumWords">
                @if ($isPayment)
                    {{-- ⭐ নকশা পাতা ৪-এর ক্রম: মোট (পক্ষের খাতে কমে) − AIT − VDS; তারপর দেওয়া, চার্জ, নিট --}}
                    <dt>{{ __('money.screen.sum.gross_party') }}</dt>
                    <dd class="num" data-sum-gross x-text="sumGross"></dd>
                    {{-- ⭐ নকশার "বিলে প্রয়োগ · অপ্রয়োগকৃত" — বিলের কার্ডের যোগফল (`bills-applied` ঘটনা), টিক থাকলে কেবল; বাকিটা পক্ষের নিজের খাতায় (চুক্তি §৭.৬) --}}
                    <dt x-show="hasApplied" x-cloak>{{ __('money.screen.sum.applied_bills') }}</dt>
                    <dd class="num" x-show="hasApplied" x-cloak data-sum-applied x-text="sumApplied"></dd>
                    <dt x-show="hasApplied" x-cloak>{{ __('money.screen.sum.unapplied_on_'.($isPayment ? 'out' : 'in')) }}</dt>
                    <dd class="num" x-show="hasApplied" x-cloak data-sum-unapplied x-text="sumUnapplied"></dd>
                    <dt>{{ __('money.screen.sum.ait') }}</dt>
                    <dd class="num" data-sum-ait x-text="sumAitMinus"></dd>
                    <dt>{{ __('money.screen.sum.vds') }}</dt>
                    <dd class="num" data-sum-vds x-text="sumVdsMinus"></dd>
                    {{-- ⭐ দফা ৪ — সরবরাহকারীর ছাড় (৫১৫০, কেনার খরচ কমে — IAS 2) মালিকের সইয়ে; রাউন্ডিং (৫৩০৫) ৳০.৫০-এর বেশি হলে সইয়ে --}}
                    <dt x-show="hasDiscount" x-cloak>{{ __('money.screen.sum.discount_out') }} <span class="ms-1 rounded bg-(--color-badge-warning-bg) px-1.5 py-0.5 text-2xs text-(--color-badge-warning-ink)" data-sum-owner-tag>{{ __('money.screen.tag.owner_signs') }}</span></dt>
                    <dd class="num" x-show="hasDiscount" x-cloak data-sum-discount x-text="sumDifference"></dd>
                    <dt x-show="hasRounding" x-cloak>{{ __('money.screen.sum.rounding', ['limit' => \App\Core\Support\Money::format(\App\Core\Support\Settlement::limit(app(\App\Core\Services\SettingsService::class)))]) }} <span class="ms-1 rounded bg-(--color-badge-warning-bg) px-1.5 py-0.5 text-2xs text-(--color-badge-warning-ink)" x-show="roundingOwner" data-sum-rounding-owner>{{ __('money.screen.tag.owner_signs') }}</span></dt>
                    <dd class="num" x-show="hasRounding" x-cloak data-sum-rounding x-text="sumDifference"></dd>
                @endif
                <dt>{{ $isPayment ? __('money.screen.sum.paid') : __('money.screen.sum.received') }}</dt>
                <dd class="num" data-sum-amount x-text="sumAmount"></dd>
                @unless ($isPayment)
                    {{-- ⭐ নকশা পাতা ২-এর ক্রম: পাওয়া টাকা → পক্ষ কেটে রেখেছেন VDS → AIT → পক্ষের খাতে (মোট); তারপর চার্জ, নিট --}}
                    <dt>{{ __('money.screen.sum.vds_in') }} <span class="ms-1 rounded bg-(--color-badge-warning-bg) px-1.5 py-0.5 text-2xs text-(--color-badge-warning-ink)" data-sum-vds-tag>{{ __('money.screen.tag.if_any') }}</span></dt>
                    <dd class="num" data-sum-vds x-text="sumVdsText"></dd>
                    <dt>{{ __('money.screen.sum.ait_in') }}</dt>
                    <dd class="num" data-sum-ait x-text="sumAitText"></dd>
                    {{-- ⭐ দফা ৪ — আদায়ে ছাড় (৪১২০) মালিকের সইয়ে; রাউন্ডিং (৫৩০৫) ৳০.৫০-এর বেশি হলে সইয়ে --}}
                    <dt x-show="hasDiscount" x-cloak>{{ __('money.screen.sum.discount_in') }} <span class="ms-1 rounded bg-(--color-badge-warning-bg) px-1.5 py-0.5 text-2xs text-(--color-badge-warning-ink)" data-sum-owner-tag>{{ __('money.screen.tag.owner_signs') }}</span></dt>
                    <dd class="num" x-show="hasDiscount" x-cloak data-sum-discount x-text="sumDifference"></dd>
                    <dt x-show="hasRounding" x-cloak>{{ __('money.screen.sum.rounding', ['limit' => \App\Core\Support\Money::format(\App\Core\Support\Settlement::limit(app(\App\Core\Services\SettingsService::class)))]) }} <span class="ms-1 rounded bg-(--color-badge-warning-bg) px-1.5 py-0.5 text-2xs text-(--color-badge-warning-ink)" x-show="roundingOwner" data-sum-rounding-owner>{{ __('money.screen.tag.owner_signs') }}</span></dt>
                    <dd class="num" x-show="hasRounding" x-cloak data-sum-rounding x-text="sumDifference"></dd>
                    <dt class="font-semibold">{{ __('money.screen.sum.gross_party') }}</dt>
                    <dd class="num font-semibold" data-sum-gross x-text="sumGross"></dd>
                    {{-- ⭐ নকশার "বিলে প্রয়োগ · অপ্রয়োগকৃত" — বিলের কার্ডের যোগফল (`bills-applied` ঘটনা), টিক থাকলে কেবল; বাকিটা পক্ষের নিজের খাতায় (চুক্তি §৭.৬) --}}
                    <dt x-show="hasApplied" x-cloak>{{ __('money.screen.sum.applied_bills') }}</dt>
                    <dd class="num" x-show="hasApplied" x-cloak data-sum-applied x-text="sumApplied"></dd>
                    <dt x-show="hasApplied" x-cloak>{{ __('money.screen.sum.unapplied_on_'.($isPayment ? 'out' : 'in')) }}</dt>
                    <dd class="num" x-show="hasApplied" x-cloak data-sum-unapplied x-text="sumUnapplied"></dd>
                @endunless
                <dt>{{ __('money.screen.sum.charge_any') }}</dt>
                <dd class="num" data-sum-charge x-text="sumCharge"></dd>
                <dt class="border-t border-(--color-border) pt-2 font-semibold">{{ $isPayment ? __('money.screen.sum.net') : __('money.screen.sum.into') }}</dt>
                <dd class="num border-t border-(--color-border) pt-2 font-semibold" data-sum-net x-text="sumNet"></dd>
                <x-slot:after>
                    <p class="mt-3 border-s-2 border-(--color-badge-success-ink) bg-(--color-badge-success-bg) px-3 py-2 text-xs text-(--color-badge-success-ink)"
                       x-show="sumMatches" x-cloak data-sum-matches>{{ $isPayment ? __('money.screen.sum.matches_out') : __('money.screen.sum.matches_in') }}</p>
                    {{-- ⭐ নকশার কমলা লাইন — পরিশোধে AIT/VDS কাটলে পোস্টে সত্যিই কোষাগারে জমা আর মূসক-৬.৬-এর করণীয় ওঠে
                         ([[VoucherService::raiseTaxDuties()]], cb-ma-4); তাই লাইনটা এখন সত্য, টাকা দেওয়ার পর্দার একই কথা --}}
                    @if ($isPayment)
                        <p class="mt-3 border-s-2 border-(--color-warning) bg-(--color-badge-warning-bg) px-3 py-2 text-xs text-(--color-badge-warning-ink)"
                           x-show="hasWithheld" x-cloak data-sum-tax-note>{{ __('money.screen.sum.tax_note') }}</p>
                    @endif
                </x-slot:after>
            </x-money.summary>

            {{-- ── ⭐ খাতায় যা যাবে — পোস্টের আগে, সার্ভারের হিসাবে ([[VoucherController::simulate()]]); কিছুই লেখা হয় না ── --}}
            <section data-boxed data-voucher-ledger
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <div class="mb-3 flex items-baseline justify-between">
                    <h2 class="font-semibold">{{ __('money.screen.ledger') }}</h2>
                    <span class="text-2xs text-(--color-ink-muted)">{{ __('money.screen.ledger_hint') }}</span>
                </div>
                <p class="text-sm text-(--color-ink-muted)" x-show="! hasPreview && ! previewBlocked">{{ __('accounts::message.ledger_empty') }}</p>
                {{-- ⛔ পোস্ট যে কারণে থামত (যেমন অন্য শাখার পক্ষ), প্রিভিউ সেই বার্তাই দেখায় --}}
                <p class="text-sm text-(--color-danger)" data-ledger-blocked x-show="previewBlocked" x-cloak x-text="preview.message"></p>
                <table class="ui-grid w-full text-sm" x-show="hasPreview" x-cloak>
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('money.screen.ledger_col.account') }}</th>
                            @unless ($isPayment)
                                <th class="text-start" data-ledger-branch-col>{{ __('money.screen.ledger_col.branch') }}</th>
                            @endunless
                            <th class="num">{{ __('money.screen.ledger_col.debit') }}</th>
                            <th class="num">{{ __('money.screen.ledger_col.credit') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in preview.rows" :key="i">
                            <tr data-ledger-row>
                                <td class="text-xs">
                                    <span x-text="row.account"></span>
                                    <span class="block text-2xs text-(--color-ink-muted)"><span x-text="row.party"></span>@if ($isPayment) <span x-text="row.branch"></span>@endif</span>
                                </td>
                                @unless ($isPayment)
                                    <td class="text-xs" x-text="row.branch"></td>
                                @endunless
                                <td class="num" x-text="row.debit"></td>
                                <td class="num" x-text="row.credit"></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td @unless ($isPayment) colspan="2" @endunless>{{ __('money.screen.ledger_col.total') }}</td>
                            <td class="num" x-text="preview.debit"></td>
                            <td class="num" x-text="preview.credit"></td>
                        </tr>
                    </tfoot>
                </table>
                <p class="mt-2 text-xs text-(--color-badge-success-ink)" data-ledger-matches x-show="previewMatches" x-cloak>{{ __('accounts::message.ledger_matches') }}</p>
                <p class="mt-2 text-xs text-(--color-danger)" x-show="unbalanced" x-cloak>{{ __('money.screen.ledger_unbalanced') }}</p>
            </section>

            {{-- ⭐ নিয়ন্ত্রণ — টাকা দেওয়ার পর্দার একই কার্ড ([[x-money.controls]]): সই চালু/বন্ধ, লেখক ≠ পাকাকারী --}}
            <x-money.controls data-voucher-controls :signatures="$moneySignatures ?? false" :three-hands="$moneyThreeHands ?? false"
                              :direction="$isPayment ? 'out' : 'in'">
                {{--
                    ⭐ নকশার "বাউন্স চিহ্ন দিলে নিজে উল্টাবে" — সত্যি করে: গৃহীত চেক এই পর্দায় ঢোকে না (মালিকের ২৬ সেপ্টেম্বরের নিয়ম), চেকের খাতায়;
                    সেখানে বাউন্স চিহ্ন দিলে খাতা নিজে উল্টায়, গ্রাহকের বকেয়া ফেরে ([[ChequeService::bounce()]])। তাই লাইনটা সেই খাতায় নিয়ে যায় (cb-ma-4)।
                --}}
                {{-- ⭐ নিয়ন্ত্রণের "ব্যাংক ফাইলে (BEFTN) যাবে" — সত্যি করে: এই খাতের আজকের ট্রান্সফার-পরিশোধের ফাইল ([[VoucherBankFile]]) --}}
                @if ($isPayment)
                    <p class="px-1 text-xs text-(--color-ink-muted)" data-control="bank-file" x-show="bankFileHref !== ''" x-cloak>
                        {{ __('money.screen.bank_file_note') }}
                        <a x-bind:href="bankFileHref" class="text-(--color-brand-700) underline" data-bank-file>{{ __('money.screen.bank_file') }}</a>
                    </p>
                @endif
                @unless ($isPayment)
                    <p class="px-1 text-xs text-(--color-ink-muted)" data-control="bounce">
                        {{ __('money.screen.bounce_note') }}
                        <a href="{{ route('accounts.cheque.index') }}" class="text-(--color-brand-700) underline" data-bounce-register>{{ __('money.screen.cheque_register') }}</a>
                    </p>
                @endunless
            </x-money.controls>
            </aside>

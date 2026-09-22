{{--
    কোন চালানের জন্য — প্রত্যক্ষ না পরোক্ষ।

    ── ⭐ মালিকের নিজের কথায় কেন এটা লাগে ──────────────────────────────
    "এক্সপেন্স সংযুক্তির সাথে ইনভয়েস নম্বর ট্যাগ করলেই হয় — তাহলে
    ডাইরেক্ট এক্সপেন্স আর ইনডাইরেক্ট ট্যাগ করা সহজ হবে।"

    ⓘ ভাড়া, হাম্মালি বা গাড়িভাড়া কোন মালের দামে উঠবে সেটা এই ট্যাগ
    ছাড়া কেউ বলতে পারে না। ⛔ আর তখন "কোন পণ্যে কত লাভ" প্রশ্নের
    উত্তরটাই ভুল থাকে — খরচটা কোথাও একটা বসে, কিন্তু ঐ মালের গায়ে নয়।

    ── ⚠️ আটকায় না, দেখায় ─────────────────────────────────────────────
    মালিকের নির্দেশ ছিল স্পষ্ট: "আটকে দেব না। দেখিয়ে দেব। কোন কোন
    খরচ হয়েছে তা দেখিয়ে দেবে ইনভয়েসের সাথে।"

    ⓘ তাই এখানে কিছুই বাধ্যতামূলক নয়। একটাও চালান না বাছলে খরচটা
    পরোক্ষ — সরাসরি ঐ খাতে বসে, আর সেটাও একটা বৈধ উত্তর। বাছলেই
    প্রত্যক্ষ, আর টাকাটা ঐ মালের দামে ওঠে।

    ── ⛔ এক ট্রাকে একাধিক চালান ────────────────────────────────────────
    মালিকের কথা: "এক ট্রাকে একাধিক চালান এলে সবগুলোই বাছুন", আর
    "একই পণ্যের বিলে দুইবার ভাড়া বসলে সমস্যা — তাই যেগুলো পেন্ডিং
    তালিকা করে দিলেই ভালো"।

    ⓘ সেজন্যই "আগে বসেছে" কলামটা আর "কেবল যেগুলোয় এখনো ভাড়া বসেনি"
    ছাঁকনিটা — দুইবার বসানো ঠেকানো হয় না, দেখিয়ে দেওয়া হয়।
--}}
{{--
    ⓘ নকশায় এই বাক্সটা **খোলা**, আর বাম ধারে নীল রেখা — কারণ
    সিদ্ধান্তটা (প্রত্যক্ষ না পরোক্ষ) এই পর্দার সবচেয়ে বড় প্রশ্ন।
    ⛔ ভাঁজ করা থাকলে কেউ খুলতেন না, আর সব খরচই পরোক্ষ
    হয়ে বসত — যা ঠিক সেই ভুল যেটা এই বাক্সটা ঠেকানোর জন্য।
--}}
{{-- ⓘ নিজের `mt-4` নেই — বাইরের `space-y-4` ফাঁকটা দেয়, নইলে দ্বিগুণ হত। --}}
<details class="rounded-(--radius-card) border border-(--color-border)
                border-l-4 border-l-(--color-brand-500) p-3"
         open
         x-data="{ onlyUntagged: true }">
    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2
                    text-sm font-semibold">
        <span>{{ __('accounts::field.against_which_bill') }}</span>
        <span class="text-xs font-normal"
              :class="isDirect ? 'text-(--color-badge-success-ink)' : 'text-(--color-ink-muted)'"
              x-text="isDirect
                  ? directLabel + ' · ' + tagged
                  : indirectLabel + ' · ' + noBillLabel"></span>
    </summary>

    <p class="mt-2 text-xs text-(--color-ink-muted)">
        {{ __('accounts::message.bill_tag_hint') }}
    </p>

    @if (($taggableBills ?? collect())->isEmpty())
        {{--
            ⓘ একটাও চালান নেই — আর সেটা স্বাভাবিক, ব্যতিক্রম নয়।

            ⚠️ খালি টেবিল দেখালে ব্যবহারকারী ভাবতেন কিছু ভাঙা। তাই
            কারণটা লেখা থাকে: হয় এখনো কোনো ক্রয় হয়নি, নয় সবগুলোয়
            ইতিমধ্যে ভাড়া বসেছে।
        --}}
        <p class="mt-3 rounded-(--radius-field) bg-(--color-surface-app) p-3 text-sm text-(--color-ink-muted)">
            {{ __('accounts::message.no_bill_to_tag') }}
        </p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-(--color-ink-muted)">
                    <tr class="border-b border-(--color-border)">
                        <th class="w-8"></th>
                        <th class="p-2 text-start">{{ __('accounts::field.bill') }}</th>
                        <th class="p-2 text-start">{{ __('accounts::field.goods') }}</th>
                        <th class="p-2 text-end">{{ __('accounts::field.qty') }}</th>
                        <th class="p-2 text-end">{{ __('accounts::field.already_charged') }}</th>
                        <th class="p-2 text-end">{{ __('accounts::field.this_share') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($taggableBills as $i => $bill)
                        @php
                            $rows = collect(old('bill_shares', $voucher->billShares?->all() ?? []));
                            $picked = $rows->first(function ($s) use ($bill) {
                                $id = is_array($s) ? ($s['purchase_bill_id'] ?? null) : $s->purchase_bill_id;

                                return (int) $id === (int) $bill->id;
                            });
                            $share = is_array($picked) ? ($picked['share_amount'] ?? '') : ($picked?->share_amount ?? '');
                        @endphp
                        <tr class="border-b border-(--color-border)"
                            x-show="! onlyUntagged || {{ $bill->already_charged > 0 ? 'false' : 'true' }}">
                            <td class="p-2">
                                <input type="checkbox"
                                       name="bill_shares[{{ $i }}][purchase_bill_id]"
                                       value="{{ $bill->id }}"
                                       @checked($picked)
                                       x-on:change="toggle({{ $i }}, $event.target.checked)">
                            </td>
                            {{--
                                ⭐ চালান নম্বরটা খুলে দেখা যায় — মালিক, ২১ সেপ্টেম্বর ২০২৬:
                                *"bill no hyper link kore daw zate vew kore dekte pari"*।

                                ⚠️ `target="_blank"` — এটা সুবিধা নয়, **রক্ষা**। পাতাটা
                                একটা অর্ধসমাপ্ত ভাউচারের ফর্ম। একই ট্যাবে খুললে তারিখ,
                                খাত, টিক দেওয়া চালান, বসানো ভাগ — সব হারাত, আর
                                ফেরত এসে তিনি দেখতেন খালি ফর্ম।

                                ⛔ `@can('view', $bill)` — যার দেখার অনুমতি নেই তাঁকে
                                লিংক দিলে ক্লিক করে তিনি 403 পেতেন। ⓘ মালিকের
                                পুরনো নিয়ম: যে ঘর কোথাও নিয়ে যায় না, সেটা সাদা থাকে।
                            --}}
                            <td class="p-2 font-medium">
                                @can('view', $bill)
                                    <a href="{{ route('purchase.bill.show', ['bill' => $bill->id]) }}"
                                       target="_blank" rel="noopener"
                                       class="underline underline-offset-2 hover:no-underline">{{ $bill->document_no }}</a>
                                @else
                                    {{ $bill->document_no }}
                                @endcan
                            </td>
                            {{--
                                ⭐ মাল বেশি হলে ভাঁজ — মালিক, ২১ সেপ্টেম্বর ২০২৬:
                                *"goods e item zodi ekhane besi hoy tahole vaj kora thbe"*।

                                ⓘ `goods_summary` আগেই তিনটায় কেটে " …" বসায়। তাই
                                তিনটা বা তার কম হলে ভাঁজ লাগেই না — খালি তিরচিহ্ন
                                দিলে কেউ ক্লিক করে দেখতেন হুবহু একই লেখা।

                                ⚠️ `<details>` — Alpine নয়। এই টেবিলটা নিজেই একটা
                                Alpine স্কোপের ভিতরে, আর সারিগুলো `x-show`-এ লুকায়।
                                প্রতি সারিতে আরেকটা `x-data` বসালে স্কোপ চিতা লম্বা হত,
                                আর `<details>` বিনা জাভাস্ক্রিপ্টেই খোলে।

                                ⛔ কয়টা দেখাবে সেই সংখ্যাটা এখানে লেখা নয়। চালানটাই
                                বলে দেয় ([[PurchaseBill::goods_folded]]) — কাটা আর ভাঁজ
                                দুইটাই একই সংখ্যা ধরে, নাহলে একদিন আলাদা হয়ে যেত।

                                ⓘ ভিউটা `Purchase`-কে নাম ধরে ডাকে না: `accounts`-এর
                                উপর বাকি সবাই দাঁড়ায়, তাই সে কারও উপর দাঁড়াতে
                                পারে না ([[BoundariesTest]])।
                            --}}
                            <td class="p-2">
                                @if ($bill->goods_folded)
                                    <details>
                                        <summary class="cursor-pointer">{{ $bill->goods_summary }}</summary>
                                        <ul class="mt-1 list-inside list-disc text-xs text-(--color-ink-muted)">
                                            @foreach ($bill->goods_names as $name)
                                                <li>{{ $name }}</li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @else
                                    {{ $bill->goods_summary }}
                                @endif
                            </td>
                            <td class="num p-2 text-end">{{ $bill->total_qty }}</td>
                            <td class="num p-2 text-end text-(--color-ink-muted)">
                                {{ bccomp((string) $bill->already_charged, '0', 4) > 0 ? number_format((string) $bill->already_charged, 2) : '—' }}
                            </td>
                            <td class="p-2 text-end">
                                {{--
                                    ⓘ `x-ref` — ভাগ বসানোর কোড এই ঘরটায় লেখে।
                                    ⚠️ `x-model` নয়: তাহলে Alpine ঘরটার মালিক হত আর
                                    সার্ভার থেকে আসা পুরনো মানটা মুছে যেত।
                                --}}
                                <input type="number" step="0.01" inputmode="decimal"
                                       x-ref="share{{ $i }}"
                                       class="num w-28 rounded-(--radius-field) border border-(--color-border) p-1 text-end"
                                       name="bill_shares[{{ $i }}][share_amount]"
                                       value="{{ $share }}">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-2 flex flex-wrap gap-2">
            <button type="button"
                    class="rounded-full border border-(--color-border) px-3 py-1 text-xs"
                    :class="onlyUntagged ? 'bg-(--color-surface-app) font-semibold' : ''"
                    x-on:click="onlyUntagged = true">
                {{ __('accounts::field.only_untagged') }}
            </button>
            <button type="button"
                    class="rounded-full border border-(--color-border) px-3 py-1 text-xs"
                    :class="! onlyUntagged ? 'bg-(--color-surface-app) font-semibold' : ''"
                    x-on:click="onlyUntagged = false">
                {{ __('accounts::field.all_bills') }}
            </button>
        </div>

        {{--
            ⭐ তিনটা জিনিস বাঁয়ে, পাশাপাশি — মালিক, ২১ সেপ্টেম্বর ২০২৬।

            ⛔ আগে এটা `grid-cols-3` ছিল, তাই তিনটা ঘর পর্দার পুরো চওড়ায়
            সমান ভাগ হয়ে যেত: ড্রপডাউনটা অকারণে লম্বা, আর "ধরন" ও
            "কোথায় বসবে" — দুইটা ছোট **লেখা**, ঘর নয় — অনেক দূরে ডানে
            গিয়ে বসত। ⓘ মালিকের কথা: *"কোথায় বসবে eta bame soriye naw,
            ভাগ হবে কীসের অনুপাতে boxta cuto kore"*।

            ⚠️ তাই গ্রিড নয়, flex: ড্রপডাউনটার নিজের একটা মাপ থাকে
            (১৪rem), আর বাকি দুইটা লেখা তার ঠিক পাশে বসে — যতটুকু জায়গা
            লাগে ততটুকুই।
        --}}
        <div class="mt-3 flex flex-wrap items-start gap-x-6 gap-y-3">
            {{--
                ভাগ হবে কীসের অনুপাতে — পরিমাণ, মূল্য, না ওজন।

                ⓘ অনুপাতটা সারিতেও লেখা থাকে, কারণ পরে নিয়ম বদলালে
                পুরনো ভাউচারের ভাগও বদলে যেত — আর অনুমোদিত কাগজ নিজে
                থেকে বদলায় না।
            --}}
            {{--
                ⛔ স্লটে `<option>` দিলে কম্পোনেন্ট সেগুলো নীরবে ফেলে দেয় —
                ড্রপডাউনটা পর্দায় থাকত, ভিতরে একটাও সারি না। ⚠️ আর `:value`
                নামে কোনো প্রপ নেই — বাছাইটা হয় `:selected` দিয়ে।
                ⓘ দুইটা ভুল একসাথে: অপশন নেই, আর থাকলেও বাছা হত না।
            --}}
            {{--
                ⭐ `max-w-48` (১২rem) — ড্রপডাউনটা অকারণে লম্বা হয় না।

                ⚠️ ২১ সেপ্টেম্বর ২০২৬-এ এখানে লেখা ছিল যে `sm:w-56`
                বান্ডিলে নেই — **ওটা ভুল ছিল**। যে স্ক্রিপ্টে যাচাই
                করেছিলাম সেটা শেল-কোটিংয়ে ব্যাকস্ল্যাশ খেয়ে ফেলে ১০৯৬-এর
                মধ্যে মাত্র ৬৪২টা ক্লাস গুনেছিল— তাই `lg:grid-cols-2`-ও
                "নেই" বলত, অথচ সেটা পর্দায় কাজ করছিল।

                ⓘ `max-w-48` **সর্বোচ্চ** মাপ, নির্দিষ্ট নয়
                — তাই ছোট পর্দায় ঘরটা পুরো চওড়া নেয়, আর বড় পর্দায় ১২rem-এ থামে।
                ⚠️ `flex-nowrap` বান্ডিলে **নেই** — যাচাই করা। তাই এক লাইনটা
                জোর করে নয়, মাপ ছোট করে পাওয়া হয়েছে — আর সেটাই ভালো:
                ফোনে তিনটা এক লাইনে চাপালে পড়াই যেত না।

                ⛔ মূল শিক্ষাটা তবু খাটে: CSS আগে থেকে বিল্ড করা (লাইভে
                node নেই), তাই বান্ডিলে নেই এমন ক্লাস লিখলে কিছুই হয় না —
                ব্রাউজার চুপচাপ উপেক্ষা করে। পাহারাটা
                `AClassTheBundleNeverHeardOfDoesNothingTest`।
            --}}
            <div class="w-full max-w-48">
            <x-ui.select name="alloc_basis" :label="__('accounts::field.alloc_basis')"
                         :options="[
                             'qty' => __('accounts::field.basis_qty'),
                             'value' => __('accounts::field.basis_value'),
                             'weight' => __('accounts::field.basis_weight'),
                         ]"
                         :selected="old('alloc_basis', 'qty')"
                         x-model="basis" x-on:change="spread()" />
            </div>

            {{--
                ধরন ও কোথায় বসবে — লেখা, ঘর নয়।

                ⚠️ দুইটাই উপরের বাছাই থেকে আপনা-আপনি আসে, তাই ব্যবহারকারী
                এগুলো টাইপ করেন না। ⓘ ঘর বানালে কেউ "প্রত্যক্ষ" লিখে
                একটাও চালান না বাছতে পারতেন, আর দুইটা পরস্পরবিরোধী হয়ে
                থাকত।
            --}}
            <div>
                <span class="block text-sm whitespace-nowrap text-(--color-ink-muted)">{{ __('accounts::field.kind') }}</span>
                <p class="mt-1 text-sm font-medium whitespace-nowrap" x-text="isDirect ? directLabel : indirectLabel"></p>
            </div>

            <div>
                <span class="block text-sm whitespace-nowrap text-(--color-ink-muted)">{{ __('accounts::field.lands_where') }}</span>
                <p class="mt-1 text-sm font-medium whitespace-nowrap" x-text="isDirect ? landsGoods : landsHead"></p>
            </div>
        </div>

        <p class="mt-3 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2
                  text-xs text-(--color-badge-pending-ink)"
           x-text="isDirect ? directEffect : indirectEffect"></p>
    @endif
</details>

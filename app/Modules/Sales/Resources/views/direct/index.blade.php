{{--
    ⚠️ এই ফাইলের কোনো মন্তব্যে **কম্পোনেন্টের ট্যাগ কোণ-বন্ধনীসহ লিখবেন না**
    (কোণ-বন্ধনী ছাড়া, যেমন `x-ui.date`) — Blade মন্তব্য ফেলে দেওয়ার **আগে**
    কম্পোনেন্টের ট্যাগ খোঁজে, তাই মন্তব্যের ভিতরের ট্যাগটাও সে **আসল খোলা
    ট্যাগ** ধরে নেয়। বন্ধ না পেলে সে বাকি পুরো ফাইলটা গিলে ফেলে, আর ভুলটা
    দেখা যায় একদম শেষে: *"unexpected end of file, expecting endif"* — যে
    লাইনটার সাথে আসল ভুলের কোনো সম্পর্ক নেই।

    ⓘ একই কথা JavaScript-এর `/* */` মন্তব্যেও খাটে — ওগুলো Blade-এর কাছে
    সাধারণ লেখা, আর সেখানেও ট্যাগটা ধরা পড়ে। ৩ সেপ্টেম্বর ২০২৬-এ ঠিক
    এভাবেই পর্দাটা ৫০০ দিয়েছিল।

    সরাসরি বিক্রয় — নমুনার হুবহু বিন্যাস।

        উপরে সরু স্ট্রিপ   তারিখ · বিল নম্বর · মেয়াদ · DO · গুদাম
        উপরের সারি        স্ট্রিপ · "এই লাইন" · পণ্যের ছবি — এক সারিতে
        এন্ট্রি এলাকা       পণ্য খোঁজা ও লাইনের ঘরগুলো, পুরো প্রস্থে
        কার্ট              SL# থেকে টাকা পর্যন্ত ন'টা কলাম
        ডান পাশের প্যানেল   ক্রেতা, এই চালান, দিতে হবে, পার্টির বকেয়া, গোনা

    ── কেন ডান পাশটা আলাদা কলাম, নিচে নয় ────────────────────────────────
    টাকার অঙ্কগুলো সবসময় চোখের সামনে থাকতে হয়, কার্ট যত লম্বাই হোক।
    নিচে রাখলে দশ লাইনের বিলে Confirm বোতামটা ভাঁজের নিচে চলে যেত, আর
    কাউন্টারের লোককে স্ক্রল করে খুঁজতে হত — যে কাজটা করতে তিনি এসেছেন।

    ── "এই লাইন" প্যানেলটা কেন ────────────────────────────────────────
    কার্টে যোগ করার আগেই লাইনটার টাকা কত হচ্ছে সেটা দেখা যায়। না দেখালে
    ভুল দর বা ভুল ছাড় ধরা পড়ত কার্টে যোগ করার পরে, আর তখন সারিটা মুছে
    আবার লিখতে হত।
--}}
@php
    $vatEnabled = $show['vat'] ?? true;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.direct') }}</x-slot:title>

    {{-- ⓘ সংরক্ষণ আর ত্রুটির বার্তা এখান থেকে সরে "খসড়া রাখুন / নিশ্চিত
         করুন" বোতামের নিচে গেছে — মালিকের ছবি, ২৬ সেপ্টেম্বর ২০২৬
         ([[direct/partials/totals]])। --}}

    {{-- ⭐ কোন খসড়াটা খোলা, আর তাকে বাতিল করার পথ — মালিকের নির্দেশ,
         ২৬ সেপ্টেম্বর ২০২৬: খোলা খসড়া আগে নিশ্চিত, বাতিল বা সম্পাদনা।

         ⚠️ বাতিলের ফর্মটা মূল ফর্মের **বাইরে** — ফর্মের ভিতরে ফর্ম HTML-এ
         অবৈধ, আর ব্রাউজার তখন ভিতরেরটা ফেলে দিয়ে বোতামটা দিয়ে **মূল
         বিলটাই** জমা দিত। ⓘ Alpine লাগে না, তাই এটা সাধারণ HTML। --}}
    {{-- ⭐ সইয়ের অপেক্ষার বিক্রি — কেবল দেখা; যিনি পাঠিয়েছেন তিনি খসড়ায় ফেরাতে পারেন
         (মালিকের অনুমোদিত নকশা, ২৮ সেপ্টেম্বর ২০২৬)। ⓘ বাতিল এখানে নেই — সইয়ের
         অপেক্ষার বিক্রি আগে খসড়ায় ফেরে, তারপর বাতিল বা বদল। --}}
    {{-- ⭐ ডেলিভারির অপেক্ষায় — পাকা বিক্রি, কেবল দেখা; "ডেলিভারি নিশ্চিত" চালানের পাতায় --}}
    @if (! empty($resume) && ($resume['stage'] ?? '') === 'delivery')
        {{-- ⭐ গেট পাসের আগে সম্পাদনা এখান থেকেও — বিলের পাতার হুবহু একই শর্তে (63, ৪ অক্টোবর ২০২৬; [[SaleEditor]]).
             ⓘ মালিক পেন্ডিং থেকে খুলে "হালনাগাদ করুন" চাপছিলেন, আর কিছুই হচ্ছিল না। --}}
        @php
            $deliveryInvoice = \App\Modules\Sales\Models\SalesInvoice::query()->find($resume['invoiceId']);
            $canEditSale = $deliveryInvoice !== null
                && $deliveryInvoice->status === \App\Core\Support\DocumentStatus::CONFIRMED
                && auth()->user()?->can('sales.invoice.create') && auth()->user()?->can('sales.challan.create')
                && rescue(fn () => (bool) app(\App\Modules\Sales\Services\SaleEditor::class)->assertEditable($deliveryInvoice), false, false);
        @endphp
        <div role="status" data-delivery-banner
             class="mb-3 flex flex-wrap items-center gap-3 rounded-(--radius-card) border
                    border-(--color-border) bg-(--color-badge-info-bg) p-3">
            <span class="text-sm font-semibold text-(--color-badge-info-ink)">
                🚚 {{ __('sales::field.pending_group_delivery') }}:
                <span class="num">{{ $resume['invoiceNo'] }}</span>
                @if ($resume['challanNo'] !== '')
                    · {{ __('sales::field.challan_no_short') }}
                    <span class="num">{{ $resume['challanNo'] }}</span>
                @endif
            </span>
            <span class="text-xs text-(--color-badge-info-ink)">{{ __('sales::message.delivery_view_only') }}</span>
            @if ($canEditSale)
                <a href="{{ route('sales.direct.create', ['edit' => $resume['invoiceId']]) }}" data-edit-sale data-no-peek
                   class="ms-auto rounded-(--radius-field) border border-(--color-brand-600) bg-(--color-surface-card) px-4 py-1.5
                          text-xs font-semibold text-(--color-brand-700)">
                    ✎ {{ __('sales::field.edit_sale') }}
                </a>
            @endif
            @if ($resume['challanUrl'])
                <a href="{{ $resume['challanUrl'] }}"
                   class="ms-auto rounded-(--radius-field) bg-(--color-brand-600) px-4 py-1.5 text-xs font-semibold
                          text-white hover:bg-(--color-brand-700)">
                    {{ __('sales::field.open_challan_to_deliver') }}
                </a>
            @endif
        </div>
    {{-- ⭐ নিশ্চিত বিক্রি সম্পাদনা — গেট পাসের আগে (মালিক, ২ অক্টোবর ২০২৬; [[SaleEditor]]) --}}
    @elseif (! empty($resume) && ($resume['stage'] ?? '') === 'edit')
        {{-- ⓘ সম্পাদনার বার্তা এখন বাঁ কলামের ভিতরে, এক লাইনে (মালিক, ৪ অক্টোবর ২০২৬: "skin na vange, scrol korte na hoy") --}}
    @elseif (! empty($resume) && ! empty($resume['viewOnly']))
        <div role="status"
             class="mb-3 flex flex-wrap items-center gap-3 rounded-(--radius-card) border
                    border-(--color-warning) bg-(--color-badge-pending-bg) p-3">
            <span class="text-sm font-semibold text-(--color-badge-pending-ink)">
                ⏳ {{ __('sales::field.pending_group_held') }}:
                <span class="num">{{ $resume['invoiceNo'] }}</span>
                @if ($resume['challanNo'] !== '')
                    · {{ __('sales::field.challan_no_short') }}
                    <span class="num">{{ $resume['challanNo'] }}</span>
                @endif
            </span>
            <span class="text-xs text-(--color-badge-pending-ink)">{{ __('sales::message.held_view_only') }}</span>

            <a href="{{ $resume['approvalUrl'] }}"
               class="ms-auto rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app)
                      px-4 py-1.5 text-xs font-semibold">
                {{ __('sales::field.held_open_approval') }}
            </a>

            <form method="POST" action="{{ route('sales.direct.draft_withdraw', $resume['invoiceId']) }}">
                @csrf
                <x-ui.button type="submit" tone="primary" class="py-1.5 text-xs">
                    {{ __('sales::field.held_withdraw') }}
                </x-ui.button>
            </form>
        </div>
    @elseif (! empty($resume))
        <form method="POST" action="{{ route('sales.direct.discard', $resume['invoiceId']) }}"
              class="mb-3 flex flex-wrap items-center gap-3 rounded-(--radius-card) border
                     border-(--color-warning) bg-(--color-badge-pending-bg) p-3">
            @csrf
            <span class="text-sm font-semibold text-(--color-badge-pending-ink)">
                {{ __('sales::field.pending_drafts') }}:
                <span class="num">{{ $resume['invoiceNo'] }}</span>
                @if ($resume['challanNo'] !== '')
                    · {{ __('sales::field.challan_no_short') }}
                    <span class="num">{{ $resume['challanNo'] }}</span>
                @endif
            </span>

            <input type="text" name="reason" required maxlength="500"
                   aria-label="{{ __('sales::message.cancel_reason') }}"
                   placeholder="{{ __('sales::message.cancel_reason') }}"
                   class="ms-auto h-(--spacing-field-dense) w-56 rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-sm">

            <button type="submit"
                    class="rounded-(--radius-field) bg-(--color-danger) px-4 py-1.5 text-xs font-semibold
                           text-white hover:bg-(--color-danger-hover)">
                {{ __('core.action.cancel') }}
            </button>
        </form>
    @endif

    {{-- ⭐ উৎস থেকে খোলা — "DO-0012 থেকে" (বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬, ধাপ ঙ)। ⓘ সারি আগে থেকে ভরা; কম দেওয়া
         চলে, ⛔ অনুমোদিতের বেশি নয় — পাহারা সেবায় ([[DirectSaleService::guardSource()]])। --}}
    @if (! empty($counterSource))
        <div role="status"
             class="mb-3 flex flex-wrap items-center gap-3 rounded-(--radius-card) border
                    border-(--color-border) bg-(--color-badge-info-bg) p-3">
            <span class="text-sm font-semibold text-(--color-badge-info-ink)">
                {{ __('sales::counter_source.from', ['ref' => $counterSource['ref']]) }}
            </span>
            <span class="text-xs text-(--color-badge-info-ink)">{{ __('sales::counter_source.banner_hint') }}</span>
        </div>
    @endif

    {{-- ⭐ সরাসরি বিক্রয়ের চূড়ান্ত নকশা — মালিক, ৪ অক্টোবর ২০২৬ ("ok final ekhoni eti planer 100% live daw")।
         ⓘ নিজের ছোট CSS, কারণ Tailwind-এর বান্ডেল আগে থেকে বানানো — নতুন `[...]` শ্রেণি ওতে নেই।
         ⚠️ রং কেবল থিমের টোকেন (`--color-*`), হাতে লেখা রং নয় — নয়টা থিমেই মানায়। --}}
    <style @nonce>
        .ds-gold { border-left: 6px solid var(--color-brand-gold) !important; }
        .ds-top { display: grid; gap: .5rem .75rem; align-items: start; }
        @media (min-width: 1024px) { .ds-top { grid-template-columns: minmax(0, 1fr) 17rem; } }
        .ds-msgs { grid-column: 1 / -1; }
        .ds-paper { display: flex; flex-wrap: wrap; gap: .5rem; padding-bottom: .3rem;
                    border-bottom: 1px dashed var(--color-border); }
        .ds-paper > label { min-width: 0; flex: 1 1 7rem; }
        /* ⭐ এক সারিতেই — সরু পর্দায়ও "পেন্ডিং" নিচে নামে না (মালিকের ছবি, ৪ অক্টোবর ২০২৬); ঘরগুলো ছোট হয়, ভাঙে না */
        @media (min-width: 1024px) { .ds-paper { flex-wrap: nowrap; } .ds-paper > label { flex-basis: auto; } }
        .ds-paper > .ds-f-date { flex: 0 1 8.5rem; min-width: 6.8rem; }
        .ds-paper > .ds-f-no { flex: 0 1 7rem; min-width: 4.5rem; }
        .ds-paper > .ds-f-ref { flex: 0 1 5.5rem; min-width: 3.5rem; }
        .ds-paper > .ds-f-term { flex: 0 1 8.5rem; min-width: 5rem; }
        .ds-paper > .ds-f-pend { flex: 1 1 10rem; min-width: 6rem; }
        /* ⭐ লটের ঘর — মালিকের ছবির মতো: নীল কিনারা, "লট" চিহ্ন, নম্বর · মেয়াদ · তাকে · ফ্রি।
           ⓘ আসল <select> উপরে স্বচ্ছ হয়ে বসে, তাই বাছাই আগের মতোই; চোখে পড়ে সাজানো মুখটা। */
        .ds-lot { position: relative; display: inline-flex; align-items: center; min-width: 11rem; max-width: 22rem; flex: 0 1 22rem; }
        .ds-lot-face { display: flex; align-items: center; gap: .4rem; width: 100%; min-width: 0; height: var(--spacing-field-dense);
                       padding: 0 1.6rem 0 .35rem; border: 1.5px solid var(--color-brand-500); border-radius: var(--radius-field);
                       box-shadow: inset 4px 0 0 var(--color-brand-500); background: var(--color-surface-app);
                       font-size: .7rem; white-space: nowrap; overflow: hidden; }
        .ds-lot-face .dot { color: var(--color-ink-muted); }
        .ds-lot-face::after { content: ''; position: absolute; right: .6rem; width: .4rem; height: .4rem;
                              border-right: 2px solid var(--color-brand-500); border-bottom: 2px solid var(--color-brand-500);
                              transform: rotate(45deg) translateY(-2px); }
        .ds-lot select { position: absolute; inset: 0; width: 100%; opacity: 0; cursor: pointer; }
        .ds-lot select:focus-visible + .ds-lot-face, .ds-lot:focus-within .ds-lot-face { outline: 2px solid var(--color-brand-500); outline-offset: 1px; }
        .ds-lot-tag { border-radius: var(--radius-field); background: var(--color-brand-600);
                      color: var(--color-brand-ink); padding: .05rem .4rem; font-size: .6rem; font-weight: 700; }
        /* ⭐ নমুনা ৩-এর ৮টা বোতাম — হালকা জমিন, রঙিন কিনারা, নিচে শর্টকাট (রং থিমের টোকেন থেকে) */
        .ds-b8 { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 2.3rem;
                 border-radius: var(--radius-field); border: 1.5px solid var(--b8-ink); background: var(--b8-bg);
                 color: var(--b8-ink); font-size: .68rem; font-weight: 800; line-height: 1.1; text-align: center; padding: .15rem .1rem; }
        .ds-b8 small { font-size: .52rem; font-weight: 700; opacity: .8; margin-top: .1rem; }
        .ds-b8.is-on { box-shadow: 0 0 0 2px var(--b8-ink); }
        .ds-b8-success { --b8-bg: var(--color-badge-success-bg); --b8-ink: var(--color-badge-success-ink); }
        .ds-b8-pending { --b8-bg: var(--color-badge-pending-bg); --b8-ink: var(--color-badge-pending-ink); }
        .ds-b8-info { --b8-bg: var(--color-badge-info-bg); --b8-ink: var(--color-badge-info-ink); }
        .ds-b8-draft { --b8-bg: var(--color-badge-draft-bg); --b8-ink: var(--color-badge-draft-ink); }
        .ds-b8-inventory { --b8-bg: var(--color-badge-inventory-bg); --b8-ink: var(--color-badge-inventory-ink); }
        .ds-b8-neutral { --b8-bg: var(--color-surface-app); --b8-ink: var(--color-ink); }
        .ds-b8-danger { --b8-bg: var(--color-badge-danger-bg); --b8-ink: var(--color-badge-danger-ink); }
        /* পপ-আপ */
        .ds-dialog { width: 100%; max-width: 46rem; max-height: calc(100dvh - 3rem); overflow-y: auto; }
        .ds-kbd { font-size: .55rem; font-weight: 700; color: var(--color-ink-muted); border: 1px solid var(--color-border);
                  border-bottom-width: 2px; border-radius: .3rem; padding: 0 .35rem; }
        .ds-seg { display: grid; grid-auto-columns: minmax(0, 1fr); grid-auto-flow: column; gap: .25rem;
                  border: 1px solid var(--color-border); border-radius: var(--radius-field); padding: .15rem; background: var(--color-surface-app); }
        .ds-seg > button { min-height: 2rem; border-radius: var(--radius-field); font-size: .68rem; font-weight: 700;
                           color: var(--color-ink-muted); line-height: 1.1; padding: .1rem .3rem; }
        .ds-seg > button.is-on { background: var(--color-surface-card); color: var(--color-brand-700);
                                 box-shadow: inset 0 -2px 0 var(--color-brand-600), 0 1px 2px var(--color-border); }
        .ds-rkv-plain { display: flex; justify-content: space-between; gap: .5rem; padding: .15rem 0; border-bottom: 1px dotted var(--color-border); }
        /* ⭐ নিচের চারটা কার্ড — আগের নকশার মতো (মালিক, ৪ অক্টোবর ২০২৬) */
        .ds-cards { display: grid; gap: .6rem; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); }
        @media (min-width: 1024px) { .ds-cards { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .ds-paper > label > span:first-child { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ds-card { display: block; text-align: start; border-radius: var(--radius-card); border: 1px solid var(--b8-ink);
                   border-left: 6px solid var(--color-brand-gold); background: var(--b8-bg); padding: .4rem .7rem; min-width: 0; }
        .ds-card-h { display: flex; justify-content: space-between; font-weight: 800; font-size: .72rem; color: var(--b8-ink); }
        .ds-card-r { display: flex; justify-content: space-between; gap: .5rem; font-size: .65rem; color: var(--color-ink); margin-top: .15rem; }
        .ds-card-r > span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        /* ⭐ বিলের মোট — ক্রেতার সাথে জোড়া, তবু আলাদা: উপরে সোনালি দাগ, মাথায় নিজের জমিন (মালিক) */
        .ds-side > aside { border-top: 4px solid var(--color-brand-gold) !important; }
        .ds-side > aside > div:first-child > div:first-child { background: var(--color-surface-app); }
        /* ⭐ পরিমাণের সারি — ঘরগুলো চেপে একে অন্যের উপর পড়ত (মালিকের ছবি, ৪ অক্টোবর ২০২৬) */
        @media (min-width: 1024px) {
            /* ⓘ ভগ্নাংশে, নিচে সীমা — সরু পর্দায় ঘর ছোট হয়, সারি পাশে কাটে না (মালিকের ছবি, ৪ অক্টোবর ২০২৬) */
            .ds-qtyrow { grid-template-columns: minmax(3rem, .9fr) minmax(3.2rem, .9fr) minmax(3rem, .9fr) minmax(3.2rem, .9fr)
                                                minmax(3.8rem, 1.1fr) minmax(4.2rem, 1.2fr) auto auto !important; }
        }
        .ds-qtyrow > * { min-width: 0; }
        /* ⓘ বিলের ঘর আর পণ্যের সারির মাঝের ফাঁক কম (মালিক) */
        .ds-top > .space-y-3 > :not(:last-child) { margin-block-end: .35rem; }
        .ds-total { border-color: var(--color-brand-gold-deep); background: var(--color-surface-selected); }
        /* ⭐ "এই লাইন" — পুরো বাক্স সবুজ, বাঁয়ে সোনালি দাগ, উচ্চতা পরিমাণের সারি পর্যন্ত (মালিক, ৪ অক্টোবর ২০২৬) */
        .ds-rcpt { border-radius: var(--radius-card); background: var(--color-success); color: var(--color-brand-ink);
                   border-left: 6px solid var(--color-brand-gold); padding: .45rem .7rem; }
        .ds-rcpt > * + * { margin-top: .3rem !important; }
        .ds-rcpt .text-\(--color-ink-muted\) { color: inherit; opacity: .85; }
        .ds-rcpt .ds-amt { background: color-mix(in srgb, var(--color-brand-ink) 16%, transparent); }
        .ds-rcpt input { color: var(--color-ink); }
        .ds-rcpt .grid > button { background: var(--color-surface-card) !important; }
        :where(.ds-rcpt .grid > button) { color: var(--color-ink); }
        .ds-rt { font-weight: 800; letter-spacing: .1em; font-size: .75rem; padding-bottom: .3rem;
                 border-bottom: 2px dashed color-mix(in srgb, var(--color-brand-ink) 35%, transparent); }
        .ds-rkv { display: flex; justify-content: space-between; gap: .5rem; padding: .12rem 0;
                  border-bottom: 1px dotted color-mix(in srgb, var(--color-brand-ink) 30%, transparent); }
        .ds-cart-scroll { max-height: max(14rem, calc(100dvh - 26rem)); overflow-y: auto; }
        /* ⭐ পর্দা স্থির — মালিক, ৪ অক্টোবর ২০২৬ ("counter skin fixt thakbe"): বাঁ কলাম পর্দার উচ্চতায় বাঁধা, কার্ট বাকি
           জায়গাটুকু নিয়ে নিজে স্ক্রল করে; ⓘ পাতা আর নড়ে না। ৬.৪rem = উপরের বার + নিচের স্ট্যাটাস বার + পাতার নিচের ফাঁক (১৬২৩×৮৬০ আর ১৯২০×১০৮০-এ মাপা)। */
        @media (min-width: 1280px) {
            .ds-left { display: flex; flex-direction: column; height: calc(100dvh - 6.4rem); }
            .ds-left > * { flex: none; }
            .ds-left > section[data-boxed].ds-gold { flex: 1 1 auto; min-height: 8rem; display: flex; flex-direction: column; }
            .ds-left .ds-cart-scroll { flex: 1 1 auto; min-height: 0; max-height: none; }
        }
        /* ⓘ ডান কলাম ঘন — ভিতরে আর স্ক্রল না আসে */
        .ds-side > aside .space-y-1 > :not(:last-child) { margin-block-end: .1rem; }
        .ds-side > section .ds-gold { padding-bottom: .3rem; }
        .ds-cart-scroll thead th { position: sticky; top: 0; z-index: 2; background: var(--color-surface-card); }
        /* ⭐ ক্রেতা আর বিলের মোট জোড়া — মাঝে ফাঁক নেই (মালিক, ৪ অক্টোবর ২০২৬) */
        .ds-side { display: flex; flex-direction: column; gap: 0; }
        /* ⭐ ডান কলাম ১০% চওড়া — মালিক, ৪ অক্টোবর ২০২৬ ("halka mota koro 10%"): ১৭rem → ১৮.৭rem */
        @media (min-width: 1280px) {
            form[data-confirm-overview] { grid-template-columns: minmax(0, 1fr) 18.7rem !important; }
        }
        .ds-side > section .ds-gold { border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
        .ds-side > aside { border-top-left-radius: 0; border-top-right-radius: 0; border-top-width: 0; }
        @media (min-width: 1280px) {
            .ds-side { position: sticky; top: .75rem; align-self: start; max-height: calc(100dvh - 6.4rem); }
            .ds-side > aside { min-height: 0; flex: 1 1 auto; }
        }
    </style>

    {{-- ⓘ `data-confirm-overview` — নিশ্চিতের আগে সারাংশের পপ-আপ (abos-2c) এই ফর্ম চেনে --}}
    <form method="POST" action="{{ route('sales.direct.store') }}" data-confirm-overview="{{ route('sales.direct.overview') }}"
          x-data="directSale({
              catalogue: @js($products),
              margin: @js($margin),
              lots: @js($lots),
              customers: @js($customerTerms),
              walkinId: {{ $walkinId }},
              vatEnabled: {{ $vatEnabled ? 'true' : 'false' }},
              packs: @js($packs),
              paymentTermDefault: @js($paymentTermDefault),
              carriers: @js($carriers),
              drivers: @js($drivers),
              depositMethods: @js($depositMethods),
              moneyAccounts: @js($moneyAccounts),
              draftKey: 'abos.direct-sale.{{ App\Core\Support\CompanyContext::id() }}.{{ auth()->id() }}',
              hasErrors: @js($errors->any() || session('approval_failed', false)),
              texts: @js([
                  'notForSales' => __('sales::message.not_for_sales'),
                  'freeBeyondRatio' => __('sales::validation.free_over_allowance'),
                  'creditBeyondLimit' => __('sales::validation.credit_left_is_only'),
                  'creditWall' => __('sales::message.credit_wall_body'),
                  'lotIsRequired' => __('sales::validation.lot_must_be_chosen'),
                  'lotAlreadyInCart' => __('sales::validation.lot_already_in_cart'),
                  'lotHoldsLess' => __('sales::validation.lot_holds_less'),
                  'itemAlreadyInCart' => __('sales::validation.item_already_in_cart'),
                  'freeNextAt' => __('sales::message.free_next_at'),
                  'freeRatio' => __('sales::message.free_ratio'),
                  'lotExpiry' => __('sales::field.lot_expiry_short'),
                  'qtyFirst' => __('sales::validation.qty_first'),
                  'freeBeyondPool' => __('sales::field.free_beyond_pool'),
                  'invoiceIndex' => route('sales.invoice.index'),
                  'modes' => ['take_now' => __('sales::field.mode_take_now'), 'pickup_later' => __('sales::field.mode_pickup_later'), 'send_later' => __('sales::field.mode_send_later')],
                  'owners' => ['own' => __('sales::field.owner_own'), 'hired' => __('sales::field.owner_hired'), 'customer' => __('sales::field.owner_customer'), 'none' => __('sales::field.owner_none')],
                  'fares' => ['us' => __('sales::field.fare_us'), 'us_add_to_bill' => __('sales::field.fare_us_add_to_bill'), 'customer' => __('sales::field.fare_customer'), 'none' => __('sales::field.fare_none')],
                  'lotOnShelf' => __('sales::field.lot_on_shelf'),
                  'lotFree' => __('sales::field.free_short'),
                  'openDraftBlocks' => __('sales::validation.open_draft_blocks_new_bill'),
              ]),
              pendingDrafts: @js($pendingDrafts ?? []),
              resume: @js($resume ?? $sourceResume ?? null),
              pendingUrl: @js(route('sales.direct.create')),
              transferModes: @js($transferModes ?? []),
              freeAllowedUrl: @js(route('sales.direct.free_allowed')),
              warehouseId: @js($warehouse?->id),
              creditRules: @js($creditRules),
          })"
          @bulk-applied.window="absorbBulk($event.detail.rows)"

          {{--
              ── অসমাপ্ত চালান ধরে রাখা ─────────────────────────────────

              `x-init` পাতা খোলার সময় দেখে খসড়া আছে কিনা — **ফেরায় না**,
              কেবল উপরে প্রস্তাব দেখায়।

              `x-effect` প্রতিবার কিছু বদলালেই লিখে রাখে। Alpine নিজেই
              বুঝে নেয় কোন কোন মান পড়া হয়েছে, তাই আলাদা করে watcher
              লিখতে হয় না — আর নতুন একটা ঘর যোগ করলে সেটাও **নিজে থেকেই**
              খসড়ায় ঢোকে, কেউ ভুলে গেলেও।

              ⚠️ `@submit`-এ খসড়া মুছে যায়। **কারণটা ওজন করে নেওয়া:**
              সফল বিক্রির পরে খসড়া থেকে গেলে **প্রতিটা বিক্রির পরেই**
              "ফিরিয়ে আনব?" প্রশ্নটা আসত — আর দিনে পঞ্চাশবার "বাদ দিন"
              চাপতে চাপতে একদিন কেউ **সত্যিকারের একটা খসড়া** বাদ দিয়ে
              ফেলতেন। ⓘ দাম: সার্ভার যদি চালানটা ফিরিয়ে দেয়, কার্টটা
              যায় — কিন্তু সেটা আজও যেত, এই বদলে নতুন কিছু হারায়নি।
          --}}
          x-init="start()"
          x-effect="saveDraft()"
          @submit="guardSubmit($event)"

          {{--
              ── কি-বোর্ডের শর্টকাট — POS-এর কী-গুলোই ─────────────────────

              মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"sortcut baton kaj
              koraw"*।

              ── কেন নতুন কী বানানো হয়নি ─────────────────────────────────
              POS-এ এই ব্যবস্থাটা **আগে থেকেই আছে** (F1–F10), আর কাউন্টারের
              লোক ওগুলো হাতে শিখে ফেলেছেন। ⚠️ এখানে আলাদা কী দিলে **একই
              মানুষকে দুই পর্দায় দুই অভ্যাস** রাখতে হত — আর কাউন্টারে
              অভ্যাসই গতি।

                  F1   এই তালিকা          POS-এ একই
                  F2   জমা যোগ            POS-এ "টাকার ঘর" — একই অর্থ
                  F6   চার্ট এন্ট্রি        POS-এ split, এখানে নেই
                  F7   ক্রেতা খোঁজা         POS-এ একই
                  F8   পণ্য খোঁজা          POS-এ একই
                  F9   কার্টে যোগ করুন
                  F10  নিশ্চিত করুন         POS-এ "বিক্রয় সম্পূর্ণ"
                  Esc  খোলা জিনিস বন্ধ

              ⚠️ **F4 ইচ্ছে করে খালি** — POS-এ ওটা "বিল ধরে রাখুন", আর সেই
              কাজটা এই পর্দাতেও আসছে। এখন অন্য কিছুতে দিলে পরে কেড়ে নিতে হত,
              আর কেড়ে নেওয়া অভ্যাস সবচেয়ে বিরক্তিকর।

              ⓘ `.prevent` লাগে কারণ ব্রাউজার F-কী গুলো নিজের কাজে নেয়
              (F7 caret browsing, F10 মেনু) — না দিলে শর্টকাট চলত, সাথে
              ব্রাউজারের কাজটাও।

              ⚠️ Esc দুই ধাপে, POS-এর মতোই: আগে খোলা প্যানেল, তারপর
              বাছাইয়ের ঘর। একবারে সব বন্ধ করলে ভুল চাপে টাইপ করা সব যেত।
          --}}
          @keydown.window.f1.prevent="helping = ! helping"
          @keydown.window.f2.prevent="openPanel('deposit')"
          @keydown.window.f3.prevent="openPanel('price')"
          @keydown.window.f4.prevent="openPanel('transport')"
          @keydown.window.f6.prevent="openPanel('drafts')"
          @keydown.window.f7.prevent="openPanel('delivery')"
          @keydown.window.f8.prevent="openPanel('reprint')"
          @keydown.window.f9.prevent="openPanel('return')"
          @keydown.window.ctrl.x="cancelKey($event)"
          @keydown.window.ctrl.b.prevent="openChartEntry()"
          @keydown.window.ctrl.k.prevent="customerPickerOpen = true"

          {{--
              ── Enter দিয়েও কার্টে যোগ — মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬)

              *"শর্টকাট Enter key রাখো add করার জন্য"*।

              ⚠️ **শর্তগুলো ছাড়া এটা বিপজ্জনক হত:**

              `picked` না থাকলে কিছু করে না — নাহলে খালি পর্দায় Enter চেপে
              কিছু না ঘটা দেখে মানুষ ভাবতেন পর্দা আটকে গেছে।

              `panel` খোলা থাকলে করে না — জমা বা নোটের ঘরে Enter চাপা মানে
              ওই ঘরের কাজ, কার্টে ফেলা নয়।

              ⓘ পণ্য ও ক্রেতা খোঁজার ঘরে Enter আগে থেকেই **প্রথমটা বাছে**
              (`pickFirst`), আর ওগুলো নিজের ঘরে `.prevent` করে — তাই এই
              window-স্তরের হ্যান্ডলারে পৌঁছায় না। **দুইটা Enter দুই কাজ
              করে, আর কোনটা কখন তা ঘরটাই ঠিক করে।**
          --}}
          @keydown.window.enter="! panel && picked && addToCart()"
          @keydown.window.f10.prevent="canConfirm && $press($refs.confirm)"
          @keydown.window.escape="escape()"

          class="grid gap-3 xl:grid-cols-[1fr_17rem]">
        @csrf


        {{-- ⭐ রাখা খসড়া — মালিকের নকশা, ২৬ সেপ্টেম্বর ২০২৬: "খসড়া রাখুন"
             বিলটা রাখে, আর পাকা হয় এই পর্দাতেই ফিরে এসে।

             ⓘ `screen_state` পর্দার পুরো ছবি (সারি, লট, ফ্রি, উপহার, ছাড়,
             জমা) — পেন্ডিং থেকে খুললে হুবহু ফেরে। `resume_invoice_id` বলে
             কোন খসড়াটা পাকা হচ্ছে; ⚠️ সেবা তখন **একই** বিল-চালান পাকা করে,
             নম্বর না বদলে। --}}
        <input type="hidden" name="screen_state" :value="screenSnapshot">
        <input type="hidden" name="resume_invoice_id" :value="resumeId">
        {{-- ⓘ সম্পাদনার বিল — সার্ভারে আঁকা, Alpine নয়; থাকলে সংরক্ষণ [[SaleEditor]]-এ যায় --}}
        @if (! empty($resume['editInvoiceId']))
            <input type="hidden" name="edit_invoice_id" value="{{ $resume['editInvoiceId'] }}">
        @endif
        {{-- ⓘ উৎস — নতুন বিলে; রাখা খসড়া নিজের উৎস নিজেই মনে রাখে --}}
        @if (! empty($counterSource) && empty($counterSource['fromDraft']))
            <input type="hidden" name="source" value="{{ $counterSource['key'] }}">
            <input type="hidden" name="source_id" value="{{ $counterSource['id'] }}">
        @endif

        {{--
            ── মজুদ নেই — পর্দার মাঝখানে, লাল, শব্দসহ ──────────────────────

            মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"stock na thakle …
            warning sound dibe, color lal hobe, screen-er majkhane boro
            kore notice dibe"*।

            ── কেন মাঝখানে, কোণায় নয় ──────────────────────────────────
            ⚠️ কাউন্টারে চোখ থাকে পণ্যের ঘরে, আর কোণার ছোট বার্তা **কেউ
            পড়েন না** — বিশেষ করে যখন ক্রেতা সামনে দাঁড়িয়ে কথা বলছেন।
            এটা সরিয়ে না দেওয়া পর্যন্ত পর্দা এগোয় না, তাই না পড়ে
            এগোনোর উপায় নেই।

            ⓘ পণ্যের নাম ও কোড দুইটাই লেখা — একই নামের দুইটা পণ্য থাকলে
            কোনটা তা কোডই বলে (আজকের ডুপ্লিকেশন ইঞ্জিনের কারণ)।

            ⚠️ Esc বা বাইরে চাপলেই বন্ধ — একটা বাড়তি ক্লিক চাপিয়ে দেওয়ার
            মানে নেই, বার্তাটা পড়া হয়ে গেলে কাজ শেষ।
        --}}
        <div x-show="outOfStock" x-cloak @click.self="outOfStock = null"
             @keydown.window.escape="outOfStock = null"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-(--radius-card) border-2 border-(--color-danger)
                        bg-(--color-surface-card) p-6 text-center shadow-lg">
                <p class="text-3xl font-bold text-(--color-danger)">
                    {{ __('sales::message.no_stock_title') }}
                </p>

                <p class="mt-3 text-lg font-semibold text-(--color-ink)"
                   x-text="(outOfStock && outOfStock.name)"></p>

                <p class="num mt-1 text-2xs text-(--color-ink-muted)"
                   x-text="(outOfStock && outOfStock.code)"></p>

                <p class="mt-4 text-sm text-(--color-ink-muted)">
                    {{ __('sales::message.no_stock_hint') }}
                </p>

                <button type="button" @click="outOfStock = null"
                        class="mt-5 w-full rounded-(--radius-field) bg-(--color-danger) px-4 py-2
                               font-semibold text-white hover:bg-(--color-danger-hover)">
                    {{ __('core.action.close') }}
                </button>
            </div>
        </div>

        {{--
            ── ⛔ বাকির সীমা পার — বড় পপ-আপ, মালিকের নির্দেশ ─────────────────

            মালিক, ২৫ সেপ্টেম্বর ২০২৬: *"limit over confarm korte caile boro kore
            pop up notice & sound dite hobe ze eta kono vabei somvob na"*।

            ⚠️ বোতাম কেবল একটা — "বুঝেছি"। ⛔ কোনো "তবুও চালাও" নেই, আর সীমা
            বাড়ানোর কথাও নেই: *"kotin kore likhar karon zate malik k request na
            korte pare kew"*। ⓘ বাইরে চাপলে বা Esc-এ বন্ধ হয় না — পড়ে "বুঝেছি"
            চাপতে হয়।

            ⓘ লেখাটা [[direct-sale.js]]-এর `creditBlockText` থেকে; সংখ্যা দুইটা
            সেবার হিসাবের আয়না ([[CreditExposure::assertRoom()]])।
        --}}
        <div x-show="creditBlocked" x-cloak role="alertdialog" aria-modal="true"
             aria-labelledby="credit-wall-title"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-(--radius-card) border-2 border-(--color-danger)
                        bg-(--color-surface-card) p-6 text-center shadow-lg">
                <p id="credit-wall-title" class="text-3xl font-bold text-(--color-danger)">
                    {{ __('sales::message.credit_wall_title') }}
                </p>

                <p class="mt-4 text-lg font-semibold leading-relaxed text-(--color-ink)"
                   x-text="creditBlockText"></p>

                <button type="button" @click="closeCreditBlock()" x-ref="creditWallOk"
                        class="mt-6 w-full rounded-(--radius-field) bg-(--color-danger) px-4 py-3
                               text-lg font-bold text-white hover:bg-(--color-danger-hover)">
                    {{ __('sales::message.credit_wall_ok') }}
                </button>
            </div>
        </div>

        {{--
            ── ⭐ দুইটা পপ-আপ — মালিকের ছবি, ২৭ সেপ্টেম্বর ২০২৬ (সন্ধ্যা) ─────────

            ⓵ অনুমোদনের বার্তা (*"অনুমোদনের জন্য পাঠানো হয়েছে — ডেলিভারি চালান ·
              ৳… যিনি সই দেবেন…"*) — পাতার মাথার ব্যানারে নয়, বোতামের নিচেও নয়।
            ⓶ বাকির সীমা ছাড়ানোর সতর্কতা — কেবল জানায়, সারিটা কার্টে যায়।

            ⓘ দুইটাই উপরের সীমার দেয়ালের হুবহু ছাঁচ (`alertdialog`, একই ধ্বনি —
            [[direct-sale.js]] `soundTheAlarm`), যাতে কাউন্টারের সব জরুরি কথা
            একই চেহারায় আসে। ⚠️ দেয়ালের লাল নয়, সতর্কতার রং — এগুলো থামায় না।
        --}}
        <div data-popup="approval-notice" x-show="approvalNoticeShown" x-cloak role="alertdialog" aria-modal="true"
             aria-labelledby="approval-notice-title"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-(--radius-card) border-2 border-(--color-warning)
                        bg-(--color-surface-card) p-6 text-center shadow-lg">
                <p id="approval-notice-title" class="text-2xl font-bold text-(--color-warning-hover)">
                    {{ __('sales::message.approval_notice_title') }}
                </p>

                <p class="mt-4 text-lg font-semibold leading-relaxed text-(--color-ink)"
                   x-ref="approvalNoticeText" x-text="approvalNotice">{{ session('approval_notice') }}</p>

                <button type="button" @click="closeApprovalNotice()"
                        class="mt-6 w-full rounded-(--radius-field) bg-(--color-warning) px-4 py-3
                               text-lg font-bold text-(--color-warning-ink) hover:bg-(--color-warning-hover)">
                    {{ __('sales::message.credit_wall_ok') }}
                </button>
            </div>
        </div>

        {{-- ⭐ এক ক্রেতার একটাই খসড়া — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬। ⓘ ক্রেতা
             বাছার মুহূর্তেই আসে, সাথে খসড়াটা খোলার বোতাম ([[openTheOpenDraft()]])। --}}
        <div data-popup="open-draft" x-show="openDraftPopup" x-cloak role="alertdialog" aria-modal="true"
             aria-labelledby="open-draft-title"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-(--radius-card) border-2 border-(--color-warning)
                        bg-(--color-surface-card) p-6 text-center shadow-lg">
                <p id="open-draft-title" class="text-2xl font-bold text-(--color-warning-hover)">
                    {{ __('sales::message.open_draft_title') }}
                </p>

                <p class="mt-4 text-lg font-semibold leading-relaxed text-(--color-ink)"
                   x-text="openDraftText"></p>

                <div class="mt-6 grid grid-cols-2 gap-3">
                    <button type="button" @click="openTheOpenDraft()"
                            class="rounded-(--radius-field) bg-(--color-warning) px-4 py-3 text-lg font-bold
                                   text-(--color-warning-ink) hover:bg-(--color-warning-hover)">
                        {{ __('sales::action.open_draft') }}
                    </button>
                    <button type="button" @click="closeOpenDraftPopup()"
                            class="rounded-(--radius-field) border border-(--color-border) px-4 py-3 text-lg
                                   font-semibold text-(--color-ink) hover:bg-(--color-surface-hover)">
                        {{ __('sales::message.credit_wall_ok') }}
                    </button>
                </div>
            </div>
        </div>

        <div data-popup="credit-warning" x-show="creditWarningOpen" x-cloak role="alertdialog" aria-modal="true"
             aria-labelledby="credit-warning-title"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-(--radius-card) border-2 border-(--color-warning)
                        bg-(--color-surface-card) p-6 text-center shadow-lg">
                <p id="credit-warning-title" class="text-2xl font-bold text-(--color-warning-hover)">
                    {{ __('sales::message.credit_warning_title') }}
                </p>

                <p class="mt-4 text-lg font-semibold leading-relaxed text-(--color-ink)"
                   x-text="creditWarning"></p>

                <button type="button" @click="closeCreditWarning()"
                        class="mt-6 w-full rounded-(--radius-field) bg-(--color-warning) px-4 py-3
                               text-lg font-bold text-(--color-warning-ink) hover:bg-(--color-warning-hover)">
                    {{ __('sales::message.credit_wall_ok') }}
                </button>
            </div>
        </div>

        {{--
            ── F1-এর সাহায্য — শর্টকাটগুলো কোথাও লেখা থাকতেই হবে ───────────

            ⚠️ **শর্টকাট আছে অথচ কোথাও লেখা নেই — এটাই সবচেয়ে অকেজো ধরনের
            সুবিধা।** যিনি জানেন কেবল তিনিই পান, আর নতুন কর্মী কোনোদিন
            জানেনই না যে জিনিসটা আছে।

            ⓘ POS-এও F1 ঠিক এই তালিকাটাই খোলে — একই কী, একই আচরণ।
        --}}
        <div x-show="helping" x-cloak @click.self="helping = false"
             class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-sm rounded-(--radius-card) bg-(--color-surface-card) p-4 shadow-lg">
                <h2 class="mb-3 font-medium">{{ __('sales::message.direct_keys') }}</h2>

                <dl class="space-y-1 text-sm">
                    @foreach ([
                        'F1' => 'sales::message.key_help',
                        'F2' => 'sales::field.btn_money',
                        'F3' => 'sales::field.key_price',
                        'F4' => 'sales::field.key_transport',
                        'F6' => 'sales::field.key_drafts',
                        'F7' => 'sales::field.key_delivery',
                        'F8' => 'sales::field.key_reprint',
                        'F9' => 'sales::field.key_return',
                        'Ctrl+X' => 'sales::field.key_cancel',
                        'Ctrl+B' => 'sales::field.key_many',
                        'Ctrl+K' => 'sales::field.key_customer2',
                        'Enter' => 'sales::field.key_enter',
                        'F10' => 'sales::message.key_checkout',
                        'Esc' => 'sales::message.key_close',
                    ] as $key => $label)
                        <div class="flex justify-between gap-4">
                            <dt class="num font-medium">{{ $key }}</dt>
                            <dd class="text-(--color-ink-muted)">{{ __($label) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>

        {{--
            ── "একটা অসমাপ্ত চালান পড়ে আছে" ───────────────────────────────

            ⚠️ প্রস্তাব, নিজে থেকে ফেরানো নয় — আর পার্থক্যটা টাকার।

            নিজে থেকে ফিরিয়ে আনলে কেউ নতুন বিক্রি শুরু করতে এসে **আগের
            অসমাপ্ত চালানটা না বুঝেই পেয়ে যেতেন**, তার উপরে নতুন লাইন যোগ
            করে নিশ্চিত করে ফেলতেন — **ভুল ক্রেতার নামে ভুল মাল**।

            তাই দুইটা স্পষ্ট বোতাম, আর তারিখ-সময়সহ — কোন সময়ের খসড়া তা
            না জানলে ফেরানো কি বাদ দেওয়া, কোনোটাই নিরাপদ সিদ্ধান্ত নয়।
        --}}
        <div x-show="draftFound" x-cloak
             class="flex flex-wrap items-center gap-3 rounded-(--radius-card) border
                    border-(--color-warning) bg-(--color-badge-pending-bg) p-3 xl:col-span-2">
            <span class="text-sm font-semibold text-(--color-badge-pending-ink)">
                {{ __('sales::message.draft_found') }}
            </span>

            <span class="num text-2xs text-(--color-ink-muted)" x-text="draftAt"></span>

            <button type="button" @click="restoreDraft()"
                    class="ms-auto rounded-(--radius-field) bg-(--color-brand-600) px-4 py-1.5
                           text-xs font-semibold text-white">
                {{ __('sales::action.draft_restore') }}
            </button>

            <button type="button" @click="dropDraft()"
                    class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-xs">
                {{ __('sales::action.draft_discard') }}
            </button>
        </div>

        {{-- ══ বাঁ দিক: স্ট্রিপ · এন্ট্রি · কার্ট ══════════════════════ --}}
        <div class="ds-left min-w-0 space-y-3">
            {{-- ⭐ সম্পাদনা চলছে — এক লাইনের পট্টি, বাঁ কলামের ভিতরে; কলামটা পর্দার উচ্চতায় বাঁধা, তাই পাতা নড়ে না,
                 কার্ট একটু ছোট হয় (মালিক, ৪ অক্টোবর ২০২৬)। ⓘ পুরো কথাটা পট্টির title-এ। --}}
            @if (! empty($resume) && ($resume['stage'] ?? '') === 'edit')
                <div role="status" data-editing-sale title="{{ __('sales::message.editing_sale_hint') }}"
                     class="flex items-center gap-2 overflow-hidden whitespace-nowrap rounded-(--radius-field) border
                            border-(--color-warning) bg-(--color-badge-pending-bg) px-3 py-1 text-xs text-(--color-badge-pending-ink)">
                    <b class="shrink-0">✎ {{ __('sales::field.editing_sale') }}:
                        <span class="num">{{ $resume['invoiceNo'] }}</span> · {{ __('sales::field.challan_no_short') }}
                        <span class="num">{{ $resume['challanNo'] }}</span></b>
                    <span class="truncate">— {{ __('sales::message.editing_sale_hint') }}</span>
                </div>
            @endif
            {{-- ⭐ সম্পাদনা ফিরে এলে কারণটা মাথায়, লাল, শব্দসহ — 63, ৪ অক্টোবর ২০২৬: মালিক দুবার "হালনাগাদ" চেপে
                 ফিরে এসেছিলেন, আর কারণটা ডানের বাক্সের একদম নিচে চাপা পড়ে ছিল ("edit hoy na")।
                 ⓘ নিচের তালিকাটাও থাকে — মালিক আগে ওখানেই চেয়েছিলেন। --}}
            @if ($errors->any() && ($resume['stage'] ?? '') === 'edit')
                <div role="alert" data-edit-refused x-init="beep()"
                     class="rounded-(--radius-card) border border-(--color-danger) bg-(--color-badge-danger-bg) px-3 py-2
                            text-sm font-semibold text-(--color-badge-danger-ink)">
                    ⛔ {{ __('sales::field.edit_refused') }}: {{ implode(' · ', $errors->all()) }}
                </div>
            @endif
            {{--
                ── কাগজের পরিচয় — দুইটা বাক্স, পাশাপাশি, এক সারিতে ─────────

                ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ───────────────────────
                *"Customar ei linei alada box koro — tahole customer details
                er sathe baki line gulo mota hobe na"*।

                ⚠️ **এই কারণটা আমি নিজে ধরতে পারিনি, আর ওটাই আসল কথা।**
                আগের ধাপে দুইটা এক বাক্সে ঢোকানো হয়েছিল। এক বাক্স মানে
                এক উচ্চতা: ক্রেতার তিন সারি (নাম · এলাকা+ফোন · ঠিকানা)
                বাক্সটাকে উঁচু করত, আর **তারিখ-নম্বর-মেয়াদ-DO-র পাশে বিশাল
                ফাঁকা জায়গা তৈরি হত** — ঘরগুলো "মোটা" দেখাত।

                এখন দুইটা আলাদা `<section>`, একই সারিতে `flex` দিয়ে।
                `items-start` বলে দেয় **যার যতটুকু উচ্চতা দরকার সে ততটুকুই
                নেবে** — একজনের লম্বা পরিচয় অন্যজনের ঘরগুলোকে টানবে না।

                ── আজকের চার ধাপ, যাতে কেউ পিছিয়ে না যায় ───────────────────
                সকাল: ক্রেতা ডান প্যানেল → বাঁ স্ট্রিপে।
                দুপুর: স্ট্রিপ থেকেও উপরে, নিজের বাক্সে, পুরো প্রস্থে।
                বিকাল: দুইটা বাক্স এক করা হলো (এক সারি, এক বর্ডার)।
                সন্ধ্যা: **আবার দুইটা — কিন্তু পাশাপাশি, উপর-নিচ নয়।**

                ⓘ পার্থক্যটা সূক্ষ্ম কিন্তু আসল: সমস্যাটা "দুইটা বাক্স" ছিল না,
                ছিল **দুইটা বাক্স একটার নিচে আরেকটা**। পাশাপাশি বসলে দুইটা
                বাক্স উচ্চতাও বাঁচায়, আর প্রত্যেকে নিজের মাপে থাকে।

                ⚠️ দুইটাই `<form>`-এর ভেতরে থাকতেই হবে — লুকানো
                `customer_id` আর `warehouse_id` এদের ভেতরে, আর বাইরে গেলে
                চালান ক্রেতা বা গুদাম ছাড়াই সেভ হবে, **কোনো ভুলবার্তা ছাড়াই**।
            --}}
            {{--
                ── ⚠️ এই ছকটা নিচের ছকের হুবহু নকল, আর সেটাই এর একমাত্র কাজ ──

                ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ───────────────────────
                লাল কলম দিয়ে প্রতিটা বাক্সের সীমা এঁকে: *"box gulo mark kora
                ache — dekho konta kon porjonto zabe"*।

                মেপে দেখা গেল উপরের সারির ভাগটা নিচের সারির ভাগের সাথে
                **মেলে না**: কাগজের বাক্স ৬২০px-এ শেষ হত, অথচ ঠিক তার নিচের
                এন্ট্রি বাক্স ৭৬৫px-এ। **দুইটা খাড়া রেখা, ১৪৫px দূরে** —
                চোখে ওটা এলোমেলো দেখায়, আর কারণটা ধরা কঠিন।

                ── কেন মিলত না ─────────────────────────────────────────────
                এটা ছিল `flex` + `basis`, আর নিচেরটা `grid` — অর্থাৎ উপরের
                ভাগটা **জিনিসের মাপ দেখে** ঠিক হত, নিচেরটা **কলামের মাপ
                দেখে**। দুইটা আলাদা নিয়ম, তাই মেলার কোনো কারণই ছিল না।

                ⚠️ **তাই এখানে ছকের সংজ্ঞাটা নিচেরটার সাথে অক্ষরে অক্ষরে
                এক** — `lg:grid-cols-[1fr_11rem]` আর
                `2xl:grid-cols-[1fr_13rem_9rem]`। **একটা বদলালে অন্যটাও
                বদলাতে হবে**, নইলে রেখা দুইটা আবার আলাদা হয়ে যাবে।

                ক্রেতার বাক্স `lg:col-span-2` — অর্থাৎ সে নিচের "এই লাইন"
                আর ছবির ঘর, **দুইটা কলাম মিলিয়ে ততটাই** চওড়া। আর সরু
                পর্দায় (মাত্র দুই কলাম) ওটা নিজে থেকেই নিচের সারিতে পুরো
                প্রস্থ নিয়ে নামে — চেপে যাওয়ার বদলে।
            --}}
            {{-- ⭐ বিলের ঘর আর পণ্য **এক বাক্সে**, ডান কোণে "এই লাইন" — মালিক, ৪ অক্টোবর ২০২৬ --}}
            <div class="ds-gold ds-top rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3 shadow-sm">

                {{-- বাক্স ১ · কাগজটা কী — তারিখ · নম্বর · মেয়াদ · DO --}}
            {{-- বাঁ কলাম: কাগজের পরিচয় → পণ্য খোঁজা → লাইনের ঘর → কার্ট

                 ⚠️ কাগজের বাক্সটা এই কলামের **ভেতরে**, আলাদা ছক-ঘরে নয়।

                 ── কেন (৩ সেপ্টেম্বর ২০২৬) ──────────────────────────────
                 আলাদা ঘরে থাকলে প্রথম সারির উচ্চতা ঠিক হত ডান পাশের "এই
                 লাইন" দেখে — আর ও দুইটার চেয়ে উঁচু। ফলে কাগজের বাক্সের
                 নিচে **~১৩০px ফাঁকা** পড়ে থাকত, আর পণ্য খোঁজার ঘরটা
                 অকারণে নিচে নেমে যেত।

                 এক কলামে সব থাকলে ওরা একটার পিঠে আরেকটা বসে, আর ডান
                 পাশের উচ্চতা কিছুই টানে না। --}}
            <div class="min-w-0 space-y-3 lg:col-start-1">
                {{--
                    ⚠️ সবুজ উপরের রেখাটা তুলে দেওয়া হলো (৩ সেপ্টেম্বর ২০২৬)।

                    মালিক বললেন সব বাক্স একসাথে ডিজাইন করতে। মেপে দেখা গেল
                    পাঁচটা বাক্সের **পাঁচ রকম চেহারা**: একটার সবুজ উপরের
                    রেখা, একটার সাধারণ বর্ডার, একটার বর্ডারই নেই, কারও
                    ছায়া নেই।

                    ⭐ **এখন পাঁচটাই এক নিয়মে** — একই বর্ডার, একই কোণ, একই
                    হালকা ছায়া। D365 বা Odoo-র মতো পর্দায় **কার্ডগুলো
                    নিজেরা চুপ থাকে, কথা বলে ভেতরের জিনিস** — একটা কার্ডের
                    গায়ে রঙিন রেখা মানে সে অন্যদের চেয়ে জরুরি, আর এখানে
                    সেটা সত্যি ছিল না।

                    ⓘ একমাত্র রঙিন কার্ড "এই লাইন", কারণ ওটাই **চলমান
                    অঙ্ক** — বাকিরা ঘর, ওটা ফল।
                --}}
                {{-- ⚠️ এই মোড়কটা আর **দেখা যায় না** — সীমানা, ছায়া আর
                     প্যাডিং তিনটাই ভিতরের দুইটা বাক্সে চলে গেছে।

                     ⛔ না সরালে বাক্সের ভিতরে বাক্স হত — তিনটা
                     সীমানা পাশাপাশি, আর মালিক ঠিক ঐ এলোমেলো
                     রেখাগুলোর কথাই বলছেন। --}}
                @include('sales::direct.partials.party', ['part' => 'paper'])
                {{--
                    ── পণ্য খোঁজা ও লাইনের ঘর — স্ট্রিপের ঠিক নিচে ──────────

                    ── কেন এখানে (৩ সেপ্টেম্বর ২০২৬) ────────────────────────
                    মালিক লাল বাক্স এঁকে দেখালেন: স্ট্রিপের নিচে একটা বড়
                    ফাঁকা জায়গা পড়ে ছিল, আর নিচের ব্লকটা তার বাইরে।
                    *"upore faka lal box e nicher mark kora box guchiye
                    uporer mark kora box er vitore niye aso"*।

                    ফাঁকাটা ছিল কারণ পাশের সবুজ প্যানেল স্ট্রিপের চেয়ে
                    উঁচু। এখন ব্লকটা ওই জায়গাতেই বসে, আর সারিটার তিনটা
                    কলামই কাছাকাছি উচ্চতার হয়ে যায়।

                    ⚠️ ঘরগুলো এখনো `flex-wrap` + নিজের নিজের প্রস্থে, তাই
                    কলামটা সরু হলে ওরা নিচে নামে — চেপে যায় না।
                --}}

                {{--
                    ── এন্ট্রি এলাকা — পুরো প্রস্থে, কিন্তু ঘরগুলো স্থির ────────

                    ── কেন কলামটা চওড়া, অথচ ঘরগুলো নয় (৩ সেপ্টেম্বর ২০২৬) ──
                    একবার এটাকে উপরের সরু কলামে ঢোকানো হয়েছিল, যাতে ঘরগুলো
                    টেনে লম্বা না হয়। ফল উল্টো: কলামটা ~৪৩০px হওয়ায় দশটা ঘর
                    দুইয়ে দুইয়ে পাঁচ সারি হয়ে গেল, আর পণ্য খোঁজার বারটা এত
                    সরু হল যে লেখাটাই কেটে গেল।

                    আসল সমস্যাটা কলামের প্রস্থ ছিল না, **ঘরের**: ঘরগুলো
                    `w-full` ছিল, তাই যত জায়গা পেত তত টানত। এখন প্রতিটার
                    নিজের মাপ বাঁধা, আর অতিরিক্ত জায়গাটা ডান পাশে ফাঁকা
                    থাকে — যেটাই ঠিক, কারণ একটা সংখ্যার ঘর দুই ইঞ্চি চওড়া
                    হয়ে কিছুই বেশি বলে না।
                --}}
                {{-- ⓘ পণ্যের বাক্স — নিজের রং আর বাঁয়ে দাগ, মালিকের
                     নির্দেশে (*"Producter Box eo dio"*)। --}}
                @include('sales::direct.partials.entry')
            </div>

                    {{-- মাঝ: এই লাইন --}}
                    {{--
                        ⚠️ `lg:row-start-1` — ইচ্ছাকৃত, আর ছাড়া চলে না।

                        ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ────────────────
                        *"This line upore dike tulo"* — ক্রেতার বাক্স কাগজের
                        বাক্সে নেমে যাওয়ায় উপরের ডান কোণটা খালি হয়েছিল, আর
                        "এই লাইন" তবু নিচেই পড়ে থাকত।

                        ── কেন নিজে থেকে উপরে ওঠেনি ───────────────────────────
                        CSS-এর অটো-প্লেসমেন্ট **পিছনে ফেরে না**। বাঁ কলামটা
                        `col-start-1` দিয়ে দ্বিতীয় সারিতে বসার পর কার্সরটা
                        ওখানেই থেকে যায় — তাই পরের জিনিসটা **প্রথম সারির খালি
                        ঘরে না গিয়ে** দ্বিতীয় সারিতে বসে।

                        সারিটা হাতে বলে দেওয়াই একমাত্র নিশ্চিত পথ।
                    --}}
                    {{-- ⛔ জমিন `surface-card`, **ব্যাজের সবুজ নয়** — ৬ সেপ্টেম্বর ২০২৬।

                         ── কী ভাঙা ছিল ─────────────────────────────────────────
                         প্যানেলটা `--color-badge-success-bg` পরত। ⚠️ কিন্তু
                         **ব্যাজের টোকেন দিয়ে প্যানেল আঁকা যায় না**: ব্যাজ একটা
                         ছোট পিল — সে নিজের জমিন **আর** নিজের কালি একসাথে আনে,
                         আর ভিতরে আর কিছু বসে না। ⓘ এখানে ভিতরে বোতাম, লেবেল,
                         সংখ্যা, ড্রপডাউন — সবাই বসে, আর তাদের কেউ জানে না তারা
                         সবুজের উপরে আছে; তারা **পাতার কালি** পায়।

                         ⛔ মাপা ফল (গাঢ় থিম, সবুজ জমিনের উপরে):
                             উপহার বোতামের জমিন   ১.০৩:১   কার্যত অদৃশ্য
                             "ঘর খালি করুন" লেখা   ২.৫৫:১   পড়তে চাপ লাগে
                             লেবেলগুলোর কালি      ৪.৯০:১   অথচ পাশের সংখ্যা ১০.৩৫

                         ⭐ **ছাঁচটা মনে রাখার মতো:** টোকেনটা যাচাই হয়েছিল
                         **কার্ডের জমিনে** (১.৩৭:১, পাস), কিন্তু জিনিসটা বসে
                         **অন্য জমিনে**। ⚠️ "টোকেন মাপা আছে" যথেষ্ট নয় — সে
                         কোথায় বসছে সেটাও মাপার অংশ।

                         ⭐ তাই জমিনটা এখন `--color-surface-success-tint` —
                         **আলো থিমে সবুজ ছোপ, গাঢ় থিমে কার্ড**। ⓘ আপনার পছন্দের
                         সবুজ ঘরটা দিনের আলোয় অক্ষত; রাতে সবুজটা **পরিচয় হয়ে
                         বাঁচে** — বাঁয়ের দাগ, কিনারা আর "এই লাইন" শিরোনামে।

                         ⚠️ কেন গাঢ়তে ছোপ রাখা গেল না, তার মাপা তালিকাটা
                         `tokens.css`-এ ঐ টোকেনের পাশে। ⛔ সংক্ষেপে: জমিন যত
                         সবুজ, ভিতরের সবকিছু তত খারাপ — কারণ ভিতরের রংগুলো
                         নিরপেক্ষ গাঢ় কার্ডের সাথে মেপে বাছা। --}}
                    {{-- ⭐ ডান কলামের মোড়ক — "এই লাইন" আর তার নিচের তিনটা বোতাম
                         **একসাথে**, মালিকের নির্দেশ ২৪ সেপ্টেম্বর ২০২৬:
                         *"uporer faka ongshe mark kora lal box er vitor fit kore daw"*।

                         ── ⛔ কেন ফাঁকটা পড়েছিল ───────────────────────────────
                         মোড়ক গ্রিডে বাঁ কলামটা **একটাই ছক-ঘর** (কাগজের বাক্স +
                         এন্ট্রি কার্ড একসাথে), আর সেটা লম্বা। ⓘ বোতামগুলো আলাদা
                         ছক-ঘর হওয়ায় তারা পড়ত **দ্বিতীয় সারিতে** — আর দ্বিতীয়
                         সারি শুরু হয় বাঁ কলামটা শেষ হওয়ার পরে।
                         ⚠️ ফলে "এই লাইন"-এর নিচে একটা লম্বা ফাঁক, আর বোতামগুলো
                         অনেক নিচে — ছবিতে ঠিক সেটাই।

                         ⭐ এখন দুইটাই এক মোড়কে, আর মোড়কটাই ছক-ঘর। ⓘ `space-y-2`
                         বোতামগুলোকে প্যানেলের ঠিক নিচে এনে বসায়, ফাঁক ছাড়া। --}}
                    <div class="ds-rcpt space-y-2 lg:col-start-2 lg:col-end-[-1] lg:row-start-1" data-this-line>
                    {{-- ⭐ মাথার সারি: "এই লাইন" বাঁয়ে, টাকা ডানে সবুজ ঘরে — মালিক, ৪ অক্টোবর ২০২৬:
                         "এই লাইন bame capiye nit mulo dane daw, background sobuj takuk, নিট মূল্য likhar dorkar nai"।
                         ⓘ টাকাটা `entryNet` — বাঁয়ের হিসাবের হুবহু একই সংখ্যা। --}}
                    <div class="ds-rt flex items-center justify-between gap-2">
                        <span>{{ __('sales::field.this_line') }}</span>
                        <b class="ds-amt num rounded-(--radius-card) px-3 py-1 text-2xl"
                           style="letter-spacing: 0" x-text="'৳' + money(entryNet)"></b>
                    </div>

                    {{-- ⓘ বিন্দু-দাগের সারি: পরিমাণ × দর · ফ্রি · ছাড়; সবুজ ঘরে নিট মূল্য; নিচে কার্টে কয়টা আর কত --}}
                    <dl class="text-xs">
                        <div class="ds-rkv">
                            <dt class="num text-(--color-ink-muted)"
                                x-text="qty($num(entry.qty || 0)) + ' ' + ((picked && picked.unit) || '') + ' × ' + money(entry.rate || 0)"></dt>
                            <dd class="num" x-text="money(entryBase)"></dd>
                        </div>

                        @if ($show['free_qty'])
                            <div class="ds-rkv">
                                <dt class="text-(--color-ink-muted)">{{ __('sales::field.free_short') }}</dt>
                                <dd class="num" x-text="qty($num(entry.freeQty || 0)) + ' ' + ((picked && picked.unit) || '')"></dd>
                            </div>
                        @endif

                        {{-- ছাড় — এখানেই লেখা যায়, টাকায় বা শতাংশে --}}
                        @if ($show['line_discount'])
                            <div class="ds-rkv items-center">
                                <dt class="text-(--color-ink-muted)">{{ __('sales::field.line_discount') }}</dt>
                                <dd class="flex flex-1 items-center gap-1">
                                    <input type="text" inputmode="decimal" x-model="entry.discountInput"
                                           placeholder="{{ __('sales::field.amount_or_pct') }}"
                                           class="num h-(--spacing-inline) w-20 rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                                    <span class="num ms-auto w-16 text-end" x-text="money(entryDiscount)"></span>
                                </dd>
                            </div>
                        @endif
                    </dl>

                    <div class="text-xs">
                        <div class="flex justify-between gap-2" data-row="in-cart">
                            <span class="text-(--color-ink-muted)">{{ __('sales::field.running_total') }}</span>
                            <span class="num font-semibold"
                                  x-text="lines.length + ' ' + @js(__('sales::field.items')) + ' · ৳' + money(subTotal)"></span>
                        </div>

                        <template x-if="hasCustomer && hasCreditLimit">
                                <div class="flex justify-between">
                                    <span class="text-(--color-ink-muted)">{{ __('sales::field.credit_left') }}</span>
                                    <span class="num font-semibold"
                                          :class="! termUsesCredit
                                            ? 'text-(--color-ink-muted)'
                                            : (creditLeft > 0 ? 'text-(--color-success)' : 'text-(--color-danger)')"
                                          x-text="'৳' + money(creditLeft > 0 ? creditLeft : 0)"></span>
                                </div>
                            </template>

                        <template x-if="hasCustomer && hasCreditLimit && creditOver > 0">
                                <div class="mt-0.5 flex justify-between rounded-(--radius-field) bg-(--color-danger)
                                            px-1.5 font-bold text-white" data-row="credit-over">
                                    <span>{{ __('sales::message.credit_over') }}</span>
                                    <span class="num" x-text="'৳' + money(creditOver)"></span>
                                </div>
                            </template>
                    </div>

                    {{-- ⭐ তিনটা বোতাম — ডান কলামে, "এই লাইন" বাক্সের **ঠিক নিচে**।
                         মালিকের ছবি, ২৪ সেপ্টেম্বর ২০২৬: লাল বাক্স দিয়ে ঘেরা
                         ফাঁকা জায়গাটা, আর তীর এঁকে দেখানো।

                         ── ⛔ একই দিনে তিনবার সরেছে, আর তিনবারই আমার পাঠের ভুল ──
                         ⓘ ১ম: *"ager jaygay daw"* → আমি ধরলাম "এই লাইন" বাক্সের
                         **ভিতরে** (৩ সেপ্টেম্বরের জায়গা)। ভুল।
                         ⓘ ২য়: *"Qty … Sales Rate … কার্টে যোগ করুন — ei gulor dane"*
                         → আমি ধরলাম এন্ট্রির সারির ভিতরে, অষ্টম কলাম। আবার ভুল —
                         ওটা এন্ট্রি কার্ডের ভিতরে পড়ে, আর তিনি কার্ডের **বাইরের**
                         ফাঁকা জায়গাটা দেখাচ্ছিলেন।
                         ⭐ ৩য়: ছবিতে লাল বাক্স — এই জায়গাটা।

                         ⚠️ দুইবার শব্দ পড়ে ধরে নিয়েছি, দুইবারই ভুল। ⛔ পর্দার
                         জায়গা শব্দে বোঝা যায় না; ছবিটাই একমাত্র সঠিক উৎস ছিল।

                         ── ⓘ কেন এখানে বসলে ঠিক জায়গায় পড়ে ──────────────────
                         মোড়ক গ্রিডটা `lg:grid-cols-[1fr_14rem]`, আর `items-start`
                         বলে ডান কলামটা টানটান হয় না — তাই "এই লাইন"-এর নিচে
                         জায়গাটা ফাঁকা পড়ে থাকত। ⭐ এই বাক্সটা ঐ কলামের দ্বিতীয়
                         সারি, তাই ঠিক ওখানেই বসে।

                         ⚠️ `lg:col-start-2` ছাড়া চলে না: `lg:` -এর নিচে কলাম
                         একটাই, আর তখন বাক্সটা নিজে থেকেই পুরো প্রস্থে নামে —
                         ⓘ ফোনে সেটাই ঠিক।

                         ⛔ ক্রমটা মালিকের লেখা, অনুমান করে বদলাবেন না। আর
                         "সব মুছুন" নিচের বারে আলাদাই থাকে — দুইটা মুছে ফেলার
                         বোতাম পাশাপাশি থাকলে ভুল চাপ পড়া নিশ্চিত। --}}
                    {{-- ⭐ তিনটা পাশাপাশি, একটার নিচে একটা নয় — মালিকের নির্দেশ,
                         ২৪ সেপ্টেম্বর ২০২৬: *"egulo pasa pasi bosbe"*।

                         ⓘ ৬ সেপ্টেম্বরেও তিনি একই কথা বলেছিলেন (*"Gift, Costing,
                         Clear Data এক লাইন রাখো"*) — অর্থাৎ এটা নতুন সিদ্ধান্ত নয়,
                         একই পছন্দ দ্বিতীয়বার। ⚠️ আমি জায়গা বদলাতে গিয়ে ছাঁচটাও
                         বদলে ফেলেছিলাম, অথচ তিনি কেবল জায়গার কথা বলেছিলেন।

                         ⛔ `grid-cols-3`, `flex` নয়: তিনটা ঘর **সমান ভাগ** পায়,
                         তাই "ঘর খালি করুন" লম্বা বলে সে বেশি জায়গা টেনে নেয় না।
                         ⓘ কলামটা ১৪rem, তাই প্রতিটা বোতাম ≈৯০px — লম্বা লেখাটা
                         দুই লাইনে ভাঁজ হয়, আর `leading-tight` তাতে উচ্চতা ধরে রাখে। --}}
                    <div class="grid grid-cols-3 items-start gap-1">
                        @if ($show['gift'])
                            {{-- ⚠️ এখানে `:disabled`, একটা কোলন — আর নিচে
                                 "নিশ্চিত করুন" বোতামে `::disabled`, দুইটা।
                                 **দুইটাই ঠিক**: Blade কেবল কম্পোনেন্ট ট্যাগে
                                 `::`-কে `:`-এ নামায়। সাধারণ ট্যাগে দুইটা দিলে
                                 অ্যাট্রিবিউটটা হুবহু `::disabled` হয়ে ব্রাউজারে
                                 যায়, আর **Alpine নীরবে উপেক্ষা করে** — বোতামটা
                                 সক্রিয় দেখাত, চাপলে কিছু হত না।

                                 ⭐ পর্দায় কিছুই ভাঙা দেখাত না, JS ত্রুটিও ছিল না।
                                 ধরেছে `AlpineBindingsReachTheBrowserTest`। --}}
                            <button type="button" @click="openGift()" :disabled="! picked"
                                    class="w-full rounded-(--radius-field) leading-tight border border-(--color-badge-pending-ink)/30 disabled:opacity-40
                                           bg-(--color-badge-pending-bg) px-1 py-1.5 text-2xs font-medium
                                           text-(--color-badge-pending-ink)">
                                {{ __('sales::field.gift') }}
                            </button>
                        @endif

                        {{-- ক্রয়মূল্য — ভেতরের কথা, গ্রাহককে পড়ে শোনানোর
                             জন্য নয়। তাই বোতামের পেছনে: চোখে পড়ে না,
                             কিন্তু দরকার হলে এক চাপ দূরে।
                             ⛔ চাবি (`sales.cost.view`) না থাকলে বোতামটাই নেই —
                             সংখ্যাটাও নিয়ামক পাঠায় না। --}}
                        @can('sales.cost.view')
                        <button type="button" @click="showCosting = ! showCosting"
                                class="w-full rounded-(--radius-field) leading-tight border border-(--color-border)
                                       px-1 py-1.5 text-2xs font-medium">
                            {{ __('sales::field.costing') }}
                        </button>
                        @endcan

                        <button type="button" @click="clearEntry()"
                                class="w-full rounded-(--radius-field) leading-tight bg-(--color-danger)/10 px-2 py-1.5
                                       text-2xs font-medium text-(--color-danger) hover:bg-(--color-danger)/20">
                            {{ __('sales::action.clear_data') }}
                        </button>

                        {{-- ⓘ ক্রয়মূল্যের সংখ্যাটা তিন কলাম জুড়ে, বোতামের সারির নিচে।
                             ⚠️ একটা কলামে বসালে ≈৯০px-এ একটা দাম কাটা পড়ত। --}}
                        @can('sales.cost.view')
                        <span x-show="showCosting" x-cloak
                              class="num col-span-full text-end text-xs text-(--color-ink-muted)"
                              x-text="picked ? money(picked.cost) : ''"></span>
                        @endcan
                    </div>

                    {{-- ⭐ বাকির সীমার সতর্কতা — তিন বোতামের নিচে। মালিকের ছবি,
                         ২৬ সেপ্টেম্বর ২০২৬: তীর এঁকে এই জায়গা, আর *"jast warning
                         but atkabena"*।

                         ⓘ এটা কেবল জানায়, সারি তোলা আটকায় না। ⛔ দেয়াল "নিশ্চিত
                         করুন"-এ (পপআপ আর শব্দ), আর তার পিছনে সেবা
                         ([[CreditExposure::assertRoom()]])। --}}
                    {{-- ⓘ সীমার সতর্কতা এখন পপ-আপ (উপরে, `credit-warning`) — মালিকের ছবি,
                         ২৭ সেপ্টেম্বর ২০২৬ (সন্ধ্যা)। এখানের লেখাটা সরানো হলো। --}}
                    </div>{{-- ডান কলামের মোড়ক শেষ --}}

                    {{-- ⭐ বার্তা তিনটা বাক্সের নিচে, পুরো প্রস্থে — মালিক, ৪ অক্টোবর ২০২৬: ⛔ পরিমাণের সারির ভিতরে থাকলে
                         "এই লাইন" বাক্সটা লম্বা হত। ⓘ "আর N নিলে ফ্রি" কেবল অনুপাত না মিললে ([[fillFreeFromTheRatio()]])। --}}
                    <div class="ds-msgs space-y-1">
                        @if ($show['free_qty'])
                            <div x-show="freeWarning" x-cloak
                                 class="rounded-(--radius-field) bg-(--color-badge-danger-bg)
                                        px-3 py-1.5 text-xs text-(--color-badge-danger-ink)"
                                 x-text="freeWarning" role="alert"></div>
                            {{-- ⭐ ফ্রি-ভাণ্ডারের বাইরে — হলুদ, থামায় না (সুইচ `sales.free_beyond_pool`, ৪ অক্টোবর ২০২৬) --}}
                            <div x-show="freeBeyondNote" x-cloak data-free-beyond-pool
                                 class="rounded-(--radius-field) bg-(--color-badge-pending-bg)
                                        px-3 py-1.5 text-xs font-semibold text-(--color-badge-pending-ink)"
                                 x-text="freeBeyondNote" role="status"></div>
                        @endif
                        <div x-show="lotWarning" x-cloak
                             class="rounded-(--radius-field) bg-(--color-badge-danger-bg)
                                    px-3 py-1.5 text-xs text-(--color-badge-danger-ink)"
                             x-text="lotWarning" role="alert"></div>
                        <div x-show="freeHint" x-cloak
                             class="rounded-(--radius-field) bg-(--color-badge-pending-bg)
                                    px-3 py-1.5 text-xs text-(--color-badge-pending-ink)"
                             x-text="freeHint" role="status"></div>
                    </div>
                    {{--
                        ── ছবির ঘরটা তুলে দেওয়া হলো (৩ সেপ্টেম্বর ২০২৬) ──────

                        মালিকের নির্দেশ: *"একটি পণ্য বেছে নিন — ei box ta
                        utiye daw"*, লাল দাগ দিয়ে ঘেরা।

                        ── কী ছিল, আর কেন গেল ───────────────────────────────
                        বাছা পণ্যের ছবি বসার জায়গা, আর ছবি না থাকায় সেখানে
                        কেবল একটা বাক্সের আইকন আর "একটি পণ্য বেছে নিন" লেখা
                        দেখাত। **অর্থাৎ ৯rem জায়গা নিত, আর বদলে কিছুই বলত
                        না** — পণ্যের নামটা তো বাঁয়ের খোঁজার ঘরেই বড় করে
                        লেখা থাকে।

                        ⚠️ কলামটাও ছকের সংজ্ঞা থেকে গেছে, শুধু ঘরটা নয় —
                        নাহলে ৯rem খালি পড়ে থাকত আর কেউ বুঝত না কেন।
                        এখন `2xl`-এ ডান কলাম একটাই, ২২.৭৫rem — উপরের
                        ক্রেতার বাক্সের ঠিক সমান।

                        ⓘ পণ্যের ছবি ব্যবস্থাটায় আসছে (A3 বসাচ্ছে)। এলে
                        জায়গাটা ফিরিয়ে আনার কথা ভাবা যাবে — কিন্তু তখন
                        ওখানে **সত্যিকারের ছবি** থাকবে, খালি আইকন নয়।
                    --}}

            </div>

            {{-- ── কার্ট ────────────────────────────────────────────── --}}
            @include('sales::direct.partials.cart')

            {{--
                ── কাজের প্যানেল — কার্টের ঠিক নিচে, পুরো প্রস্থে ──────────

                ── মালিকের সিদ্ধান্ত (৩ সেপ্টেম্বর ২০২৬) ────────────────────
                তিনি জিজ্ঞেস করেছিলেন পপআপ ভালো নাকি বাঁয়ে নিচে খোলা, আর
                যুক্তি শুনে **নিচেরটাই** বেছেছেন।

                ── কেন পপআপ নয় ───────────────────────────────────────────
                ⚠️ এই চারটার তিনটাই **টাকার অঙ্ক বদলায়** — খরচ লিখলে "নিট
                পরিশোধযোগ্য", জমা লিখলে "বিলের বকেয়া"। ওই দুইটা সংখ্যা ডান
                প্যানেলে। **পপআপ ঠিক সেগুলোকেই ঢেকে দিত** — টাইপ এক জায়গায়,
                ফল ঢাকা পড়া জায়গায়।

                ── আর জায়গার হিসাব ────────────────────────────────────────
                ডান প্যানেল ৩৪০px, এখানে ১২৪৫px। পরিবহনের তিনটা ঘর (গাড়ি ·
                চালক · ভাড়া) সরু কলামে একটার নিচে আরেকটা নামত।

                ⓘ উপহারের ঘরটাও আজ এভাবেই ইনলাইনে বসেছে — এক পর্দায় দুই
                রকম নিয়ম (কিছু ইনলাইন, কিছু পপআপ) শেখার বোঝা বাড়ায়।
            --}}
            <div x-ref="actionPanel">
                @include('sales::direct.partials.panels')
            </div>

            {{-- ⭐ যা যোগ হলো — চারটা ছোট কার্ড, আগের নকশার মতো (মালিক, ৪ অক্টোবর ২০২৬); চাপলে নিজের পপ-আপ।
                 ⓘ গাড়ি ও ভাড়ার লাইনটা এখানে, "ফেরত"-এর জায়গায় (মালিকের ছবি)। --}}
            <div class="ds-cards" data-counter-cards>
                @if ($show['deposit'])
                    <button type="button" @click="openPanel('deposit')" class="ds-card ds-b8-success">
                        <span class="ds-card-h"><span>{{ __('sales::field.card_money') }}</span><span class="num" x-text="deposits.length"></span></span>
                        <span class="ds-card-r">
                            <span x-text="deposits.length ? depositMethodName(deposits[deposits.length - 1].methodId) : @js(__('sales::field.card_nothing'))"></span>
                            <b class="num" x-show="deposits.length > 0" x-text="'৳' + money(deposit)"></b>
                        </span>
                    </button>
                @endif
                <button type="button" @click="openPanel('delivery')" class="ds-card ds-b8-inventory">
                    <span class="ds-card-h"><span>{{ __('sales::field.btn_delivery') }}</span><span>✓</span></span>
                    <span class="ds-card-r"><span x-text="deliveryModeLabel"></span></span>
                </button>
                <button type="button" @click="openPanel('note')" class="ds-card ds-b8-info">
                    <span class="ds-card-h"><span>{{ __('sales::field.note') }}</span><span x-show="noteText !== ''" x-cloak>✓</span></span>
                    <span class="ds-card-r"><span x-text="noteText || @js(__('sales::field.card_nothing'))"></span></span>
                </button>
                @if ($show['transport'])
                    <button type="button" @click="openPanel('transport')" class="ds-card ds-b8-pending" data-transport-line>
                        <span class="ds-card-h"><span>{{ __('sales::field.btn_transport') }}</span><span x-show="transportLine !== ''" x-cloak>✓</span></span>
                        <span class="ds-card-r"><span x-text="transportLine || @js(__('sales::field.card_nothing'))"></span></span>
                    </button>
                @endif
            </div>

            {{--
                ── নেওয়া জমাগুলোর তালিকা — চার্টের নিচে, বাম পাশে ──────────

                মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"এই list Item Chart-এর
                নিচে বাম পাশে থাকবে, একাধিক payment add করতে পারবে"*।

                ── কেন এখানে, ডান প্যানেলে নয় ─────────────────────────────
                ডান প্যানেলে জমার **যোগফল** থাকে ("জমা নেওয়া হলো ৳১৫,০০০")।
                ⚠️ সারিগুলোও ওখানে বসালে সরু কলামে পাঁচটা ঘর একটার নিচে
                আরেকটা নামত, আর যোগফলটা নিচে ঠেলে যেত — যে সংখ্যাটা সবচেয়ে
                বেশি দেখা হয়।

                ⭐ কার্টের নিচে বসার আসল কারণটা আলাদা: **এটা কার্টেরই মতো
                একটা জিনিস** — যা যা নেওয়া হলো তার তালিকা। মালের তালিকার
                ঠিক নিচে টাকার তালিকা, একই চোখের পথে।

                ⓘ খালি থাকলে দেখাই যায় না — বেশিরভাগ বাকির চালানে কোনো জমা
                নেই, আর একটা স্থায়ী খালি বাক্স রোজ চোখের সামনে থাকত।
            --}}
            @if ($show['deposit'])
                {{-- ⓘ ছকটা লুকানো — জমার আসল ঘরগুলো এখানেই থাকে বলে সার্ভারে যায়; চোখে পড়ে নিচের কার্ড আর
                     "টাকা নিন" পপ-আপের তালিকা (মালিক, ৪ অক্টোবর ২০২৬) --}}
                <div hidden data-deposit-fields
                     class="ds-gold rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-3">
                    <div class="mb-2 flex items-baseline justify-between gap-2">
                        <span class="text-2xs font-semibold text-(--color-ink)">
                            {{ __('sales::field.received_deposit') }}
                        </span>

                        <span class="num text-sm font-bold text-(--color-success)"
                              x-text="'৳' + money(deposit)"></span>
                    </div>

                    <div class="overflow-x-auto">
                        {{-- ⓘ `ui-list` — তিনটা নামের একটা।

                             নাম না থাকলে ছকের মাপ ও ধার **কোনো থিম বদলাতে
                             পারবে না**, আর থিম বদলালে এই ছকটা একা আগের
                             চেহারায় বসে থাকত। ⚠️ ঘরের প্যাডিংও তাই হাতে
                             লেখা হয়নি — ওটা টোকেন থেকে আসে। --}}
                        <table class="ui-list w-full text-2xs">
                            <thead class="text-(--color-ink-muted)">
                                <tr class="border-b border-(--color-border)">
                                    <th class="text-start font-medium">{{ __('sales::field.ref_date') }}</th>
                                    <th class="text-start font-medium">{{ __('sales::field.deposit_method') }}</th>
                                    <th class="text-start font-medium">{{ __('sales::field.account') }}</th>
                                    <th class="text-start font-medium">{{ __('sales::field.deposit_ref') }}</th>
                                    {{-- ⚠️ "বিবরণ", "মন্তব্য" নয় — মালিকের সিদ্ধান্ত
                                         (৪ সেপ্টেম্বর ২০২৬)। লেখাটা
                                         `collections.narration`-এ বসে, আদায়ের
                                         কাগজে ছাপা হয়, আর খতিয়ানের সারিতে যায় —
                                         অর্থাৎ ওটা হিসাবের বক্তব্য, পাশের নোট নয়।
                                         ⓘ এক কলামের দুইটা নাম হলে রিপোর্টে ওরা
                                         মিলত না। --}}
                                    <th class="text-start font-medium">{{ __('sales::field.narration') }}</th>
                                    <th class="text-end font-medium">{{ __('sales::field.amount') }}</th>
                                    <th></th>
                                </tr>
                            </thead>

                            <tbody>
                                <template x-for="(row, i) in deposits" :key="i">
                                    <tr class="border-b border-(--color-border)/50">
                                        <td class="num" x-text="row.refDate || '—'"></td>
                                        <td x-text="depositMethodName(row.methodId)"></td>
                                        <td x-text="depositAccountName(row.accountId)"></td>
                                        {{-- ⓘ ব্যাংকের জমায় ট্রান্সফার মোডও — "NPSB · TRX123" --}}
                                        <td class="num" x-text="depositRefText(row)"></td>
                                        <td x-text="row.narration || '—'"></td>
                                        <td class="num text-end font-semibold"
                                            x-text="money(row.amount)"></td>

                                        <td class="text-end">
                                            <button type="button" @click="dropDeposit(i)"
                                                    class="rounded px-1.5 text-(--color-danger)"
                                                    aria-label="{{ __('sales::action.remove_line') }}">&times;</button>
                                        </td>

                                        {{-- ⚠️ সার্ভারে যা যায় — সারির ভেতরেই।

                                             উপহারের মতো আলাদা করে সমতল করার
                                             দরকার নেই: তালিকাটা এমনিতেই এক
                                             স্তরের, তাই সূচকটা এখানেই বসে।

                                             ⓘ উপায়ের **কোড আর খাত সার্ভার
                                             নিজে বের করে** — পর্দার পাঠানো
                                             নাম বিশ্বাস করা হয় না। --}}
                                        <td class="hidden">
                                            <x-counter.deposit-fields />

                                            {{-- ⭐ ব্যাংক/মোবাইল ব্যাংকিংয়ের তথ্য — মালিকের
                                                 নির্দেশ, ২৭ সেপ্টেম্বর ২০২৬। ⓘ কেবল এই
                                                 পর্দার, তাই ক্রয়ের সাথে ভাগ করা
                                                 কম্পোনেন্টে নয়; কেবল ভরা ঘর, আর সারির
                                                 উপায়ের ধরন ধরে ([[depositDetailsOf()]])। --}}
                                            <template x-for="d in depositDetailsOf(row)" :key="d.key">
                                                <input type="hidden" :name="'deposits[' + i + '][' + d.key + ']'"
                                                       :value="d.value">
                                            </template>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{--
                ── উপহারগুলো সার্ভারে যায় এখান থেকে ─────────────────────

                কার্টের সারিগুলো নিজেরাই লুকানো ঘর বহন করে, কিন্তু উপহার
                পারে না: সার্ভার একটা **সমতল** তালিকা চায়
                (`gifts[0][…]`, `gifts[1][…]`), অথচ পর্দায় ওগুলো লাইনের
                ভেতরে বাসা বেঁধে আছে।

                `payloadGifts` ওই দুইটাকে মেলায় — আর ঠিক ওখানেই
                `against_product_id` বসে **লাইনের পণ্য থেকে**, কোনো
                ড্রপডাউন থেকে নয়।

                ⚠️ খালি productId বা শূন্য পরিমাণের উপহার এখানে ছেঁকে
                ফেলা হয়, নাহলে সার্ভারে অর্ধেক লেখা সারি যেত।
            --}}
            <template x-for="(g, n) in payloadGifts" :key="n">
                <span>
                    <input type="hidden" :name="'gifts[' + (n) + '][product_id]'" :value="g.productId">
                    <input type="hidden" :name="'gifts[' + (n) + '][against_product_id]'" :value="g.againstProductId">
                    <input type="hidden" :name="'gifts[' + (n) + '][qty]'" :value="g.qty">
                    <input type="hidden" :name="'gifts[' + (n) + '][remarks]'" :value="g.remarks">
                </span>
            </template>

        </div>

        {{-- ══ ডান পাশের প্যানেল ═══════════════════════════════════════ --}}
        {{-- ⓘ বিলের মোট — কাগজের **ফল**, তাই নিজের রং আর দাগ।
             ⚠️ `overflow-hidden` লাগে, নাহলে ভিতরের সারিগুলো গোল কোণের
             বাইরে বেরিয়ে যেত। --}}
        {{-- ⭐ ক্রেতার বাক্স বিলের মোটের ঠিক উপরে — মালিক, ৪ অক্টোবর ২০২৬। ⚠️ দুইটাই ফর্মের ভিতরে (লুকানো customer_id) --}}
        <div class="ds-side min-w-0">
            @include('sales::direct.partials.party', ['part' => 'customer'])
            @include('sales::direct.partials.totals')
        </div>

    </form>

    {{-- ⭐ নিশ্চিতের আগে সারাংশের পপ-আপ — মালিক, ৪ অক্টোবর ২০২৬ ([[confirm-overview.js]]) --}}
    <x-ui.confirm-overview />

    {{-- ⭐ বিল বাতিলের ফর্ম (af-এর `sales.direct.void`) — ⓘ পথ না থাকলে আঁকা হয় না, তখন বাতিল মানে পর্দা খালি ([[voidBill()]]) --}}
    @if (Route::has('sales.direct.void'))
        @push('detached-forms')
            <form id="ds-void-form" method="POST" action="{{ route('sales.direct.void') }}" hidden data-no-peek>
                @csrf
                <input type="hidden" name="reason">
                <input type="hidden" name="customer_id">
                <input type="hidden" name="resume_invoice_id">
                <input type="hidden" name="lines">
                <input type="hidden" name="total">
            </form>
        @endpush
    @endif
</x-layouts.app>

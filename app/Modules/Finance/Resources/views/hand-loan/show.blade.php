{{--
    একজন মানুষের হাতধারের খাতা।

    ── কেন উপরে বড় করে কেবল একটা সংখ্যা ────────────────────────────────
    এই পাতাটা খোলা হয় একটাই প্রশ্ন নিয়ে: **করিম কত ফেরত দিয়েছে?** —
    বা উল্টোটা। বাকি সব ইতিহাস, আর ইতিহাস পড়া হয় কেবল ওই সংখ্যাটা
    নিয়ে সন্দেহ হলে।
--}}
@php
    $sign = bccomp($balance, '0', 4);
    $shown = $sign < 0 ? bcmul($balance, '-1', 4) : $balance;
    $money = $accounts->mapWithKeys(fn ($a) => [$a->id => $a->name()]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $account->person?->name() ?? '—' }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$account->person?->name() ?? '—'"
                          :subtitle="$account->person?->mobile ?: __('finance::message.hand_loan_note')">
            <x-slot:actions>
                <x-ui.button tone="secondary" :href="route('finance.hand_loan.index')">
                    {{ __('finance::menu.hand_loan') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <p role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                               text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                                 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- ── একটাই সংখ্যা, বড় করে ─────────────────────────────────────── --}}
    <section data-boxed
             @class([
                 'mb-4 flex flex-wrap items-center gap-4 rounded-(--radius-card) border px-4 py-4',
                 'border-(--color-border) bg-(--color-surface-card)' => $sign >= 0,
                 'border-(--color-danger) bg-(--color-badge-danger-bg)' => $sign < 0,
             ])>
        <div>
            <p class="text-2xs text-(--color-ink-muted)">
                @if ($sign > 0)
                    {{ __('finance::message.hand_loan_they_owe') }}
                @elseif ($sign < 0)
                    {{ __('finance::message.hand_loan_we_owe') }}
                @else
                    {{ __('finance::message.hand_loan_clear') }}
                @endif
            </p>

            <p class="mt-0.5 text-2xl font-semibold tabular-nums">
                {{ \App\Core\Support\Money::format($shown) }}
            </p>
        </div>

        <span class="flex-1"></span>

        @if ($account->isSettled())
            <x-ui.badge tone="draft">{{ __('finance::state.settled') }}</x-ui.badge>
        @elseif ($sign === 0 && $movements->isNotEmpty() && auth()->user()?->can('finance.hand_loan.move'))
            {{-- শূন্য হলে তবেই "চুকে গেছে" বলা যায় — সেবাও একই কথা বলে।
                 বাকি থাকা অবস্থায় চিহ্নিত করলে টাকাটা তালিকা থেকে হারাত
                 অথচ খাতায় থেকে যেত, আর ঠিক ওই টাকাটা ভুলে যাওয়া ঠেকাতেই
                 ফিচারটা। --}}
            <form method="POST" action="{{ route('finance.hand_loan.settle', $account) }}">
                @csrf
                <x-ui.button type="submit" tone="secondary">
                    {{ __('finance::action.mark_settled') }}
                </x-ui.button>
            </form>
        @endif
    </section>

    @if ($account->note)
        <p class="mb-4 text-sm text-(--color-ink-muted)">{{ $account->note }}</p>
    @endif

    {{-- ── ⭐ পক্ষের সাথে জোড়া — মানচিত্র §১৪খ, ২১ সেপ্টেম্বর ২০২৬ ──────

         ⚠️ কেন জোড়াটা দরকার: একই মানুষ প্রায়ই একসাথে ডিলার আর ধারদাতা।
         ⓘ জোড়া থাকলে তাঁর হাতধার আর তাঁর বাকির হিসাব এক নামে মেলানো যায়;
         না থাকলে খাতায় **দুইটা আলাদা মানুষ** মনে হয়, আর টাকাটা দুই জায়গায়
         ভাগ হয়ে থাকে।

         ⓘ নতুন হাতধারের ফর্মে ঘরটা আগে থেকেই ছিল। ⛔ কিন্তু দরকারটা পড়ে
         **পরে** — যখন কেউ খেয়াল করেন ধারদাতা লোকটাই আসলে তাঁদের ডিলার। --}}
    @can('finance.hand_loan.create')
        <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('finance::field.party_link') }}</h2>
            <p class="mb-3 text-2xs text-(--color-ink-muted)">{{ __('finance::message.party_link_hint') }}</p>

            <form method="POST" action="{{ route('finance.hand_loan.link', $account) }}"
                  class="flex flex-wrap items-end gap-2">
                @csrf

                <div class="min-w-64 flex-1">
                    {{-- ⓘ চলতি জোড়াটা বাছা থাকে; খালি ঘর মানে জোড়া খুলে দাও —
                         ভুল জোড়া শোধরানোর পথ না থাকলে কেউ জোড়া লাগাতেই ভয় পেতেন --}}
                    <x-ui.select name="party"
                                 :label="__('finance::field.party_link')"
                                 :options="$parties"
                                 :placeholder="__('finance::field.party_none')"
                                 :selected="$account->partner_type !== null
                                     ? $account->partner_type.':'.$account->partner_id
                                     : null" />
                </div>

                <x-ui.button type="submit" tone="secondary">{{ __('core.action.save') }}</x-ui.button>
            </form>
        </section>
    @endcan

    {{-- ── টাকা দেওয়া বা নেওয়া ───────────────────────────────────────
         একটাই ফর্ম, একটা দিক-বাছাই দিয়ে। চারটা ধরন (ধার দিলাম · ধার
         নিলাম · ফেরত দিলাম · ফেরত পেলাম) রাখলে সাথে একটা নিয়মও লাগত —
         কোনটার পরে কোনটা আসতে পারে — আর সেটা প্রথমবারেই ভুল হত, যখন
         আগেরটা ফেরত আসার আগেই কেউ আবার ধার নেন। --}}
    @if (! $account->isSettled() && auth()->user()?->can('finance.hand_loan.move'))
        <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('finance::field.movement') }}</h2>

            <form method="POST" action="{{ route('finance.hand_loan.move', $account) }}"
                  class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                @csrf

                <x-ui.select name="direction" :label="__('finance::field.which_way')" required
                             :options="[
                                 \App\Modules\Finance\Models\HandLoanMovement::OUT => __('finance::field.money_out'),
                                 \App\Modules\Finance\Models\HandLoanMovement::IN => __('finance::field.money_in'),
                             ]"
                             :selected="old('direction', \App\Modules\Finance\Models\HandLoanMovement::OUT)" />

                <x-ui.field name="amount" type="number" step="0.01" numeric required
                            :label="__('finance::field.amount')" :value="old('amount')" />

                <x-ui.field name="moved_on" type="date" required
                            :label="__('finance::field.date')"
                            :value="old('moved_on', now()->toDateString())" />

                {{-- ⛔ আগে এখানে কেবল খাতের ঘর ছিল, আর ব্যাংক বাছলে
                     সার্ভার লেনদেন নম্বর চেয়ে আটকে দিত — ঘরটা ছাড়াই। --}}
                <x-ui.money-account name="money_account_id" required codes
                                    :label="__('finance::field.money_account')"
                                    {{-- ⛔ আগে এখানে `$money` লেখা ছিল, অথচ কন্ট্রোলার পাঠায়
                                         `accounts` — ফলে খালি লেখা চলে যেত আর কম্পোনেন্ট
                                         অক্ষরের উপর `->id` পড়তে গিয়ে **প্রতিটা চালু হাতধারের
                                         পাতা ৫০০ দিত** (২১ সেপ্টেম্বর ২০২৬)।

                                         ⚠️ পাতাটার কোনো পরীক্ষা ছিল না, তাই ভুলটা চুপচাপ
                                         বসে ছিল — সেটাও এই কমিটে যোগ হলো। --}}
                                    :accounts="$accounts"
                                    :selected="old('money_account_id')" />

                <div class="sm:col-span-2 xl:col-span-5">
                    <x-ui.field name="note" :label="__('finance::field.note')" :value="old('note')" />
                </div>

                {{-- ⭐ বোতাম নিজের সারিতে, নিচে বাঁয়ে — মালিক, ১৯ সেপ্টেম্বর ২০২৬:
                     *"সব পাতাতেই সমস্যা"*।

                     ⛔ আগে বোতামটা একটা `flex items-end`-এর ভেতরে পাঁচ কলামের
                     গ্রিডের **একটা** ঘরে বসত, তাই লেখাটা কয়েক লাইনে ভাঙত।
                     ⓘ এখন পুরো সারি জুড়ে, অন্য ফর্মের মতো। --}}
                <div class="flex flex-wrap items-center gap-2 sm:col-span-2 xl:col-span-5">
                    <x-ui.button type="submit" tone="primary">
                        {{ __('finance::action.record_movement') }}
                    </x-ui.button>
                </div>
            </form>
        </section>
    @endif

    {{-- ── যা যা হয়েছে ───────────────────────────────────────────────
         প্রতিটা সারি তার ভাউচারে নামায় — নিয়ম ১। আর এই সারিগুলোই
         "তুমি তো ফেরত দাওনি" কথাটার উত্তর। --}}
    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::field.contributions') }}
        </h2>

        <x-ui.table
            :empty="__('finance::message.no_hand_loan_yet')"
            :rows="$movements"
            :columns="[
                ['key' => 'moved_on', 'label' => __('finance::field.date'), 'width' => '9rem',
                 'render' => fn ($m) => \App\Core\Support\DateFormat::format($m->moved_on)],
                ['key' => 'direction', 'label' => __('finance::field.which_way'), 'width' => '8rem',
                 'render' => fn ($m) => __('finance::kind.'.$m->direction)],
                ['key' => 'amount', 'label' => __('finance::field.amount'), 'numeric' => true,
                 'width' => '11rem',
                 'render' => fn ($m) => \App\Core\Support\Money::format($m->amount)],
                ['key' => 'money', 'label' => __('finance::field.money_account'),
                 'render' => fn ($m) => $m->moneyAccount?->name() ?? '—'],
                ['key' => 'note', 'label' => __('finance::field.note'),
                 'render' => fn ($m) => $m->note ?: '—'],
                ['key' => 'voucher', 'label' => __('core.print.document_no'), 'width' => '10rem',
                 'render' => fn ($m) => view('finance::deposit.partials.voucher', ['movement' => $m])],
            ]" />
    </section>
    {{-- ⭐ কাগজপত্র — ১৫ সেপ্টেম্বর ২০২৬-এ যোগ হলো।

         ⛔ এতদিন এই খাতায় কাগজ রাখার কোনো জায়গাই ছিল না, কারণ মডেলটা
         `Drillable` ছিল না — আর [[components/ui/attachments]] ঐ চুক্তি
         ধরেই কাগজ খোঁজে।

         ⚠️ ধারের স্ট্যাম্প বা লেখা কাগজ ছাড়া মুখের কথা আদালতে দাঁড়ায় না।
         ⓘ কাগজ বসে খাতায়, নড়াচড়ায় নয় — যতবারই টাকা ওঠানামা করুক,
         মূল কাগজটা একটাই। --}}
    <x-ui.attachments :document="$account" />

</x-layouts.app>

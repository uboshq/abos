{{--
    ⭐ ব্যক্তির তালিকা, হাতধারের চোখে — মালিকের সরাসরি আদেশ, ৫ অক্টোবর ২০২৬ (সমন্বয়কের মারফত), কলাম এই ক্রমে:
    নাম (চাপলে খাতা) · মোবাইল · ঠিকানা · হাতধারে বাকি · মোট পাওনা (সব খাত মিলিয়ে); নিচে মোট।

    ── ⓘ দুই সংখ্যা কেন ──────────────────────────────────────────────
    "হাতধারে বাকি" হাতধারের চলাচল থেকে ([[HandLoanService::people()]], খাতার একই নিয়ম)। "মোট পাওনা" খতিয়ানে তাঁর
    নামের সব সারি ([[AccountsFacts::dueFrom()]]-এর নিয়ম) — জাবেদায় প্রাপ্য খাতে তাঁর নামে বসানো টাকাও। ⚠️ দুইটা আলাদা
    হলে পাশে ছোট সতর্কতা: হাতধারের ভাউচার আজ খতিয়ানে কারও নামে বসে না, আর অন্য খাতের টাকা হাতধারে আসে না।
    ⓘ জের সবসময় "(Dr) 100.00" / "(Cr) …" — খালি বিয়োগ চিহ্ন নয় (মালিক, ৩ অক্টোবর ২০২৬)।

    ── ⛔ তবু দ্বিতীয় কোনো তালিকা নয় ────────────────────────────────
    সারিগুলো একটাই মানুষের তালিকা (`mdm_people`) থেকে। ⚠️ আলাদা টেবিল বানালে “Al Amin”, “Al-Amin” আর “আল আমিন”
    তিনজন হয়ে যেতেন, আর একজনের পাওনা তিন ভাগে ছিঁড়ত।
--}}
@php
    $drCr = fn (string $v) => \App\Core\Support\Money::drCr($v);
    $canBooks = auth()->user()?->can('accounts.report');
@endphp
<div class="border-b border-(--color-border) p-3">
    {{-- ⭐ নতুন নাম এখানেই — তালিকা ছেড়ে কোথাও যেতে হয় না। ⓘ মোবাইলটা ঐচ্ছিক, কিন্তু তাগাদা দেওয়ার দিন ওটাই লাগে। --}}
    @can('finance.hand_loan.create')
        <form method="POST" action="{{ route('finance.hand_loan.person.store') }}" class="flex flex-wrap items-end gap-2" data-new-person>
            @csrf
            <x-ui.field name="name_bn" :label="__('finance::field.hl_new_person')" required />
            <x-ui.field name="mobile" :label="__('finance::field.person_mobile')" />
            <x-ui.field name="address" :label="__('finance::loan_ledger.address')" />
            <x-ui.button type="submit" tone="primary">{{ __('finance::action.add_person') }}</x-ui.button>
        </form>
    @endcan
</div>

<x-ui.table
    :rows="$people['rows']"
    :compact="request()->boolean('compact')"
    :empty="filled(request('q')) ? __('core.empty.no_results') : __('finance::message.no_people_yet')"
    :totals="[
        'person' => __('finance::loan_ledger.total'),
        'balance' => $drCr($people['balance']),
        'books' => $drCr($people['books']),
    ]"
    :totalsLabel="__('finance::loan_ledger.total')"
    :columns="[
        [
            'key' => 'person',
            'label' => __('finance::field.person_name'),
            'render' => fn ($r) => view('finance::hand-loan.partials.person-link', ['row' => $r]),
        ],
        [
            'key' => 'mobile',
            'label' => __('finance::field.person_mobile'),
            'width' => '9rem',
            'render' => fn ($r) => $r['person']->mobile ?: '—',
        ],
        [
            'key' => 'address',
            'label' => __('finance::loan_ledger.address'),
            'render' => fn ($r) => $r['person']->address ?: '—',
        ],
        [
            'key' => 'balance',
            'label' => __('finance::loan_ledger.hand_loan_balance'),
            'numeric' => true,
            'width' => '11rem',
            'render' => fn ($r) => $drCr($r['balance']),
        ],
        [
            'key' => 'books',
            'label' => __('finance::loan_ledger.books_total'),
            'numeric' => true,
            'width' => '12rem',
            'render' => fn ($r) => view('finance::hand-loan.partials.books', ['row' => $r, 'canBooks' => $canBooks]),
        ],
    ]" />

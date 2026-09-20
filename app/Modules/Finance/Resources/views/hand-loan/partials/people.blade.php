{{--
    কার সাথে — মানুষের তালিকা, হাতধারের পর্দাতেই।

    ── ⓘ মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────
    *“ki kotha cilo master e thakbe na eta, eta হাতধার ekta tab e কার
    সাথে list thakbe”*। ⓘ যেখানে কাজ হয় দরজাটা সেখানেই — নাম যোগ করতে
    মাস্টারে যেতে হয় না।

    ── ⛔ তবু দ্বিতীয় কোনো তালিকা নয় ────────────────────────────────
    সারিগুলো একটাই মানুষের তালিকা (`mdm_people`) থেকে আসে। ⚠️ আলাদা
    টেবিল বানালে “Al Amin”, “Al-Amin” আর “আল আমিন” তিনজন হয়ে যেতেন, আর
    একজনের পাওনা তিন ভাগে ছিঁড়ত — মালিকের এক-তালিকার কারণটা এটাই।
--}}
<div class="border-b border-(--color-border) p-3">
    {{-- ⭐ নতুন নাম এখানেই — তালিকা ছেড়ে কোথাও যেতে হয় না।
         ⓘ মোবাইলটা ঐচ্ছিক, কিন্তু তাগাদা দেওয়ার দিন ওটাই লাগে। --}}
    @can('finance.hand_loan.create')
        <form method="POST" action="{{ route('finance.hand_loan.person.store') }}"
              class="flex flex-wrap items-end gap-2">
            @csrf

            <x-ui.field name="name_bn" :label="__('finance::field.hl_new_person')" required />
            <x-ui.field name="mobile" :label="__('finance::field.person_mobile')" />

            <x-ui.button type="submit" tone="primary">{{ __('finance::action.add_person') }}</x-ui.button>
        </form>
    @endcan
</div>

<x-ui.table
    :rows="$people"
    :compact="request()->boolean('compact')"
    :empty="filled(request('q')) ? __('core.empty.no_results') : __('finance::message.no_people_yet')"
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
            'render' => fn ($r) => $r['person']->mobile ?? '—',
        ],
        [
            'key' => 'open',
            'label' => __('finance::field.hl_open_accounts'),
            'numeric' => true,
            'width' => '8rem',
            'render' => fn ($r) => $r['open'],
        ],
        [
            'key' => 'to_us',
            'label' => __('finance::message.hand_loan_they_owe'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($r) => \App\Core\Support\Money::format($r['to_us']),
        ],
        [
            'key' => 'by_us',
            'label' => __('finance::message.hand_loan_we_owe'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($r) => \App\Core\Support\Money::format($r['by_us']),
        ],
    ]" />

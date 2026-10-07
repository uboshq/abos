{{--
    কার সাথে — বাড়িওয়ালার তালিকা, ভাড়ার পর্দাতেই।

    ── ⓘ মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────
    হাতধারের ট্যাবটার হুবহু একই ছাঁদ, আর নিচের তালিকাও একটাই
    (`mdm_people`) — তিনটা দরজা, একটাই ঘর।

    ── ⚠️ কেবল জোড়া দেওয়া চুক্তিগুলো এখানে ─────────────────────────
    যে চুক্তি হাতে লেখা নামে খোলা, সেটা এই সারিগুলোয় গোনা হয় না। ⓘ আর
    সেটাই সৎ: যাঁর নাম তালিকায় নেই, তাঁর সারিও নেই। ⭐ জোড়াটা বসানো যায়
    চুক্তির নিজের পাতা থেকে, আর তারপর তিনি এখানে এসে যান।
--}}
{{--
    ⭐ নতুন বাড়িওয়ালা এখানেই — ২১ সেপ্টেম্বর ২০২৬।

    ⓘ মালিকের নির্দেশ: *"varar chukti o jamanot e কার সাথে creat er
    bebosta koro"*। ⚠️ ট্যাবটা তালিকা দেখাত, কিন্তু নাম যোগ করার কোনো
    পথ ছিল না — মাস্টার ডেটায় গিয়ে বসিয়ে ফিরে আসতে হত, আর কাজের
    মাঝপথে পর্দা ছেড়ে যাওয়াই সবচেয়ে বড় বাধা।

    ⓘ হাতধারের ফর্মটার হুবহু একই — একই ঘর, একই অনুমতির ধরন, একই
    `PersonResolver`। ⛔ দুই পর্দায় দুই রকম হলে একই মানুষ দুইভাবে বসতেন।

    ⓘ মোবাইলটা ঐচ্ছিক, কিন্তু ভাড়া চাইতে গেলে ওটাই লাগে।
--}}
<div class="border-b border-(--color-border) p-3">
    @can('finance.rental.create')
        <form method="POST" action="{{ route('finance.rental.person.store') }}"
              class="flex flex-wrap items-end gap-2">
            @csrf

            <x-ui.field name="name_bn" :label="__('finance::field.rental_new_person')" required />
            <x-ui.field name="mobile" :label="__('finance::field.person_mobile')" />

            {{--
                ⭐ একই নামে আগে কেউ থাকলে এই ঘরটা আসে — ২১ সেপ্টেম্বর ২০২৬।

                ⓘ নকল ঠেকানোর নিয়মটা থামিয়ে বলে *"সত্যিই আলাদা প্রতিষ্ঠান
                হলে ঘরটা টিক দিয়ে আবার সংরক্ষণ করুন"*। ⛔ ঘরটা না থাকায়
                মালিক আটকে গিয়েছিলেন — বার্তাটা এমন কিছুর কথা বলত যা
                পর্দায় নেই।

                ⚠️ ঘরটা কেবল ভুলের পরে, সবসময় নয়: সবসময় থাকলে সবাই
                অভ্যাসবশে টিক দিয়ে রাখতেন আর পাহারাটাই অর্থহীন হত।
                ⓘ শর্তটা চাবির নাম ধরে নয় (`name_en` না `name_bn` —
                সেটা নিয়মের উপর নির্ভর করে), তাই যেকোনো ভুলেই দেখা যায়।
            --}}
            @if ($errors->any())
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="allow_duplicate" value="1"
                           @checked(old('allow_duplicate')) class="size-4">
                    {{ __('core.duplicate.allow') }}
                </label>
            @endif

            <x-ui.button type="submit" tone="primary">{{ __('finance::action.add_person') }}</x-ui.button>
        </form>
    @endcan
</div>

<x-ui.table
    :rows="$people"
    :compact="request()->boolean('compact')"
    :empty="__('finance::message.no_rental_people')"
    :columns="[
        [
            'key' => 'name',
            'label' => __('finance::field.rental_counterparty'),
            'render' => fn ($r) => view('finance::rental.partials.party-link', ['row' => $r]),
        ],
        [
            'key' => 'contracts',
            'label' => __('finance::field.rental_how_many'),
            'numeric' => true,
            'width' => '8rem',
            'render' => fn ($r) => $r['contracts'],
        ],
        [
            'key' => 'running',
            'label' => __('finance::state.active'),
            'numeric' => true,
            'width' => '7rem',
            'render' => fn ($r) => $r['running'],
        ],
        [
            'key' => 'rent',
            'label' => __('finance::field.rental_rent'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($r) => \App\Core\Support\Money::format($r['rent']),
        ],
        [
            'key' => 'deposit',
            'label' => __('finance::field.rental_deposit_left'),
            'numeric' => true,
            'width' => '11rem',
            'render' => fn ($r) => \App\Core\Support\Money::format($r['deposit']),
        ],
    ]" />

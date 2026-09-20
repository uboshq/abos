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

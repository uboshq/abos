{{--
    কোন প্রতিষ্ঠানে কত — এক সারিতে একটা ব্যাংক।

    ── ⓘ মালিকের সংশোধন, ২০ সেপ্টেম্বর ২০২৬ ──────────────────────────
    *“আমানত to আর্থিক প্রতিষ্ঠান theke ase”*। ⓘ হাতধার আর ভাড়া মানুষের
    সাথে, কিন্তু একটা FDR খোলা হয় একটা ব্যাংকে — তাই এই পর্দার ট্যাবটা
    প্রতিষ্ঠানের, আর “কার নামে” কলাম হিসেবেই থাকে।

    ── ⚠️ “প্রতিষ্ঠান বসানো হয়নি” সারিটা কেন ─────────────────────────
    পুরনো আমানতে `institution_id` খালি থাকতে পারে — নাম মিলিয়ে জোড়া
    লাগানোর কাজটা ইচ্ছাকৃতভাবে কেবল প্রস্তাব দেয়, নিজে বসায় না। ⛔ ওগুলো
    ছেঁকে দিলে যোগফল কম দেখাত আর কেউ ধরত না; তাই ওরা একটা সারিতে
    একসাথে থাকে, আর কাজটা বাকি আছে সেটাও চোখে পড়ে।
--}}
<x-ui.table
    :rows="$institutions"
    :compact="request()->boolean('compact')"
    :empty="__('finance::message.no_deposit_yet')"
    :columns="[
        [
            'key' => 'name',
            'label' => __('finance::field.institution'),
            /* ⚠️ আইডি না থাকলে লিংক নয়, তবু লেখাটা থাকে — শূন্য ঘর
               দেখলে কেউ বুঝতেন না ওই সারিটা কিসের। */
            'render' => fn ($r) => $r['id'] === null
                ? $r['name']
                : view('finance::institution.partials.link', [
                    'id' => $r['id'],
                    'label' => $r['name'],
                ]),
        ],
        [
            'key' => 'count',
            'label' => __('finance::field.how_many'),
            'numeric' => true,
            'width' => '7rem',
            'render' => fn ($r) => $r['count'],
        ],
        [
            'key' => 'total',
            'label' => __('finance::field.principal'),
            'numeric' => true,
            'width' => '12rem',
            'render' => fn ($r) => \App\Core\Support\Money::format($r['total']),
        ],
        [
            'key' => 'next',
            'label' => __('finance::field.dep_next_maturity'),
            'width' => '10rem',
            'render' => fn ($r) => $r['next'] === null ? '—' : $r['next']->format('d/m/Y'),
        ],
    ]" />

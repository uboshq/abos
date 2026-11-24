{{--
    অফারের তালিকা — স্পেকের ৫ নম্বর ধারা।

    ⓘ শেয়ার্ড কম্পোনেন্টের উপর, নিজের কিছু নয়। ⚠️ এখানে টেবিল, টুলবার
    বা ব্যাজ নতুন করে লিখতে হলে বুঝতে হবে ভিত্তিতে ফাঁক আছে — সমাধান
    তখন পর্দায় নয়, কম্পোনেন্টে।

    ⛔ স্পেকে চৌদ্দটা কলাম চাওয়া হয়েছে (বাজেট, ব্যবহৃত, অনুমোদনকারী,
    ভূখণ্ড…)। ⓘ আজ বসছে সাতটা — কারণ বাকিগুলোর পিছনে এখনো কোনো টেবিল
    নেই। ⚠️ খালি কলাম বসালে তালিকাটা দেখতে সম্পূর্ণ লাগত, আর কেউ ধরতে
    পারত না কোনটা "শূন্য" আর কোনটা "এখনো নেই"।
--}}
@php
    $columns = [
        [
            'key' => 'sl',
            'label' => __('core.table.serial'),
            'width' => '4rem',
            'numeric' => true,
            'render' => fn ($p, $i) => $rows->firstItem() + $i,
        ],
        [
            'key' => 'code',
            'label' => __('promotion::field.code'),
            'width' => '9rem',
            'render' => fn ($p) => new \Illuminate\Support\HtmlString(
                '<a class="underline" href="'.e(route('promotion.show', $p)).'">'.e($p->code).'</a>'),
        ],
        [
            'key' => 'name_en',
            'label' => __('promotion::field.name'),
            'width' => '18rem',
            'render' => fn ($p) => $p->name(),
        ],
        [
            'key' => 'type',
            'label' => __('promotion::field.type'),
            'width' => '10rem',
            'render' => fn ($p) => $p->type?->label() ?? '—',
        ],
        /*
         * ⓘ তারিখ দুইটা এক ঘরে — মানুষ "কবে থেকে কবে" একসাথেই পড়েন।
         * ⚠️ দুইটা কলামে ভাগ করলে সারিটা চওড়া হত, আর চোখকে দুইবার যেতে
         * হত একটাই প্রশ্নের উত্তর পেতে।
         *
         * ⛔ আর মন্তব্যটা `/* *​/`, `{{-- --}}` নয় — এটা মেপে শেখা।
         * ⓘ `@php` ব্লকের ভিতরে Blade মন্তব্য **ছাঁটা হয় না**, হুবহু
         * বসে যায়, আর ফল হয় অবৈধ PHP: *"unexpected token {"*।
         *
         * ⚠️ আর `php artisan view:cache` ওটা ধরে **না** — সে কেবল
         * কম্পাইল করে, আউটপুটটা বৈধ কি না দেখে না। ⓘ ধরা পড়ে কেবল
         * পাতাটা সত্যিই খুললে, ৫০০ হয়ে।
         */
        [
            'key' => 'starts_on',
            'label' => __('promotion::field.period'),
            'width' => '12rem',
            'render' => fn ($p) => $p->starts_on->format('d/m/y').' — '.$p->ends_on->format('d/m/y'),
        ],
        [
            'key' => 'status',
            'label' => __('promotion::field.status'),
            'width' => '8rem',
            'render' => fn ($p) => view('components.ui.badge', [
                'tone' => $p->status?->tone() ?? 'draft',
                'slot' => $p->status?->label() ?? '—',
            ]),
        ],
        [
            'key' => 'priority',
            'label' => __('promotion::field.priority'),
            'width' => '6rem',
            'numeric' => true,
        ],
    ];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের দুই ঘর, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('status', __('promotion::field.status'),
            collect($statuses)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()),
        \App\Core\ListFilter\Facets::pick('type', __('promotion::field.type'),
            collect($types)->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('promotion::menu.promotions') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div class="space-y-4">
        <form id="list-form" method="GET" class="contents">
        <x-ui.toolbar :title="__('promotion::menu.promotions')" :facets="$facetPanel">
            {{-- ⓘ চাবি না থাকলে বোতামটাই নেই — ক্লিক করে ৪০৩ দেখার চেয়ে ভালো --}}
            @can('promotion.create')
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('promotion.create')">
                        {{ __('promotion::action.new') }}
                    </x-ui.button>
                </x-slot:actions>
            @endcan
        </x-ui.toolbar>
        </form>

        <x-ui.table :view-url="fn ($r) => route('promotion.show', $r)"
            :empty="__('promotion::message.none_yet')"
            :rows="$rows"
            :columns="$columns" />

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>

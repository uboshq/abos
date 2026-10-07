{{--
    ব্যর্থ পোস্টিংয়ের সারি — খাতায় লিখতে গিয়ে ভাঙা, আর এখনো কেউ দেখেননি।

    ⓘ ফিন্যান্স মানচিত্রের §৩১। কেবল ক্লাস, বার্তা, কতবার, শেষবার। ফাইলের
    পথ আর স্ট্যাক ট্রেস ভুলের খাতায় নিজের চাবিতে থাকে
    ([[ErrorLogController]]), কারণ হিসাবরক্ষককে কোডের ভিতরটা দেখানোর কারণ নেই।
--}}
@php
    $columns = [
        ['key' => 'class', 'label' => __('accounts::control.what_broke'), 'width' => '16rem',
         'render' => fn ($e) => class_basename($e->class)],
        ['key' => 'message', 'label' => __('core.table.description'),
         'render' => fn ($e) => \Illuminate\Support\Str::limit((string) $e->message, 200)],
        ['key' => 'times', 'label' => __('accounts::control.times'), 'numeric' => true, 'width' => '6rem'],
        ['key' => 'last_seen_at', 'label' => __('accounts::control.last_seen'), 'width' => '11rem',
         'render' => fn ($e) => \App\Core\Support\DateFormat::formatWithTime($e->last_seen_at)],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::control.failed_title') }}</x-slot:title>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.toolbar :title="__('accounts::control.failed_title')"
                      :subtitle="__('accounts::control.failed_note')"
                      :search="false" :filter="false" :density="false"
                      :export="false" :share="false">
            @can('governance.error.view')
                <x-slot:actions>
                    <x-ui.button :href="route('governance.error.index')">
                        {{ __('accounts::control.open_error_log') }}
                    </x-ui.button>
                </x-slot:actions>
            @endcan
        </x-ui.toolbar>

        @include('accounts::control.partials.posting-tabs', [
            'active' => 'failed',
            'failedCount' => $failures->count(),
        ])

        <x-ui.table :rows="$failures" :columns="$columns" :empty="__('accounts::control.no_failures')" />
    </div>
</x-layouts.app>

@props(['paper', 'href', 'tone' => 'secondary', 'icon' => null])

{{--
    কাগজের পাতার "সম্পাদনা" বোতাম — সইয়ের অপেক্ষায় থাকলে ধূসর, কারণসহ (মালিক, ২০ নভেম্বর ২০২৬; [[WaitingPaper]])।

    ⛔ অপেক্ষার কাগজ কেউ বদলান না — অনুমোদনকারী অনুমোদন দেন, সংশোধনের জন্য ফেরত পাঠান, বা বাতিল করেন। লুকানো নয়, ধূসর:
    মানুষ দেখেন বোতামটা আছে, আর কেন এখন চলে না। ⓘ দরজাও থামায় ([[AWaitingPaperIsNotEdited]])।
    ⓘ বাকি সময় হুবহু আগের `x-ui.button` — অন্য কোনো রূপে একটা পিক্সেলও বদলায় না।
--}}
@if (app(\App\Core\Engines\Approval\WaitingPaper::class)->waits($paper))
    <span class="inline-flex" title="{{ __('core.approval_hold.hint') }}" data-approval-hold>
        <x-ui.button :tone="$tone" :icon="$icon" disabled aria-disabled="true" :aria-label="__('core.approval_hold.hint')"
                     class="cursor-not-allowed opacity-50">
            {{ $slot }}
        </x-ui.button>
    </span>
@else
    <x-ui.button :tone="$tone" :icon="$icon" :href="$href" {{ $attributes }}>
        {{ $slot }}
    </x-ui.button>
@endif

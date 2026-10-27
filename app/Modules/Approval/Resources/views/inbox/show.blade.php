{{--
    একটা অনুরোধ — কাগজের নিজের পাতা (খবর, রিপোর্ট আর ফোনের লিংক এখানে আসে)।

    ⭐ অনুমোদন পর্দা ভাগ ২ (২৭ অক্টোবর ২০২৬): ভেতরটা ইনবক্সের ডান দিকের অবিকল একই ফাইল ([[partials/detail]]) — সারাংশ, কাগজের লাইন,
    সংযুক্তি, যাত্রাপথ, মন্তব্য আর চারটা কাজ। ⛔ দুই জায়গায় আলাদা নকশা থাকলে একদিন ইনবক্সে এক কথা আর এই পাতায় আরেক কথা থাকত।

    ⓘ এখানে "পরের কাগজ" মানে অপেক্ষার ক্রমে পরেরটা ([[ApprovalInboxController::neighboursOf()]]) — কেবল যিনি অনুমোদন দিতে পারেন।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.inbox') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$bundle !== null ? __('approval::field.bundle_title') : ($labels[$approval->module.'.'.$approval->action] ?? app(\App\Core\Engines\Approval\ApprovalLabel::class)->of((string) $approval->module, (string) $approval->action))"
            :subtitle="\App\Core\Support\DateFormat::spoken($approval->requested_at)">
            <x-slot:actions>
                {{-- ⭐ ঘড়িটা অবস্থার পাশে — ইনবক্সে লাল দেখে কেউ এখানে এলে চিহ্নটা যেন থাকে --}}
                @include('approval::inbox.partials.clock', ['approval' => $approval, 'state' => $slaState])

                <x-ui.badge :tone="match ($approval->status) {
                    \App\Models\Approval::APPROVED => 'success',
                    \App\Models\Approval::REJECTED => 'danger',
                    \App\Models\Approval::CANCELLED => 'draft',
                    \App\Models\Approval::RETURNED => 'info',
                    default => 'pending',
                }">
                    {{ __('approval::status.'.$approval->status) }}
                </x-ui.badge>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <x-ui.errors />

    @include('approval::inbox.partials.detail', [
        'nextId' => $next,
        'from' => '',
        'prevUrl' => $prev ? route('approval.inbox.show', $prev) : null,
        'nextUrl' => $next ? route('approval.inbox.show', $next) : null,
    ])

    @include('approval::inbox.partials.keys')
</x-layouts.app>

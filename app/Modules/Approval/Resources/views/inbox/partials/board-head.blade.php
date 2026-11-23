{{--
    ⭐ The board's head — the coloured title and the four tabs as pills with their counts, in the same row as the filter bar
    (owner, 24 Nov 2026: "অনুমোদনের ইনবক্সের বার যেটা আছে ফিল্টার বার, এইটার মধ্যেই দেওয়া আছে আমার পাঠানো … এইভাবেই করবা,
    তাতে করে স্ক্রিনের জায়গাটা বাড়বে" — and the big orange ribbon is gone).
    ⓘ Nothing dropped: the count of each tab is on its pill; the late count is in the filter ("দেরি (n)"); the totals are
      under the list. Another person's inbox shows only "অপেক্ষায়", as before.
--}}
@php
    $headTag = \App\Core\Support\PageHeading::tag();
@endphp
<{{ $headTag }} class="inbox-board-title">{{ $person ? __('approval::menu.inbox_of', ['name' => $personName]) : __('approval::menu.inbox') }}</{{ $headTag }}>

<nav class="inbox-board-tabs" aria-label="{{ __('approval::field.tabs') }}">
    @foreach ($tabCounts as $key => $count)
        <a href="{{ route('approval.inbox.index', array_filter(['person' => $person ?: null, 'tab' => $key !== 'waiting' ? $key : null])) }}"
           @class(['inbox-board-tab', 'is-on' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif data-list-tab="{{ $key }}">
            {{ __('approval::field.tab_'.$key) }}
            <span class="inbox-board-tabcount num">{{ $count }}</span>
        </a>
    @endforeach
</nav>

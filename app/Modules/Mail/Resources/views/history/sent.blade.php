{{--
    ⭐ পাঠানো মেইলের খাতা — ABOS থেকে যাওয়া প্রতিটা মেইল: কে, কোন ঠিকানা থেকে, কাকে, বিষয়, সাথে কোন কাগজ (মালিকের অডিট নির্দেশ)।
    ⛔ মেইলের লেখা নেই।
--}}
@php $H = fn (string $k, array $r = []) => __('mail::field.history.'.$k, $r); @endphp
<x-layouts.app :menu="$menu ?? []">
    <x-slot:title>{{ $H('sent_title') }}</x-slot:title>
    @push('scripts')
        @vite('resources/js/mail-admin.js')
    @endpush

    <div class="mailx mx-page" data-mail-history>
        <div class="mx-head">
            <h1>📤 {{ $H('sent_title') }} <x-ui.page-info :text="$H('sent_info')" /></h1><span class="sp"></span>
            <a class="mx-btn" href="{{ route('mail.history') }}">🕘 {{ $H('title') }}</a>
        </div>

        <form class="mx-filter" method="GET" action="{{ route('mail.sent') }}">
            <label>{{ $H('search') }}<span class="mx-q" style="min-width:280px;background:var(--mx-card)">🔍 <input type="search" name="q" value="{{ $q }}"></span></label>
            <label>{{ $H('from') }}<input class="mx-in num" type="date" name="from" value="{{ $from }}"></label>
            <label>{{ $H('to') }}<input class="mx-in num" type="date" name="to" value="{{ $to }}"></label>
            <label style="flex-direction:row;align-items:center;gap:6px;height:32px"><input type="checkbox" name="paper" value="yes" @checked($paper)> {{ $H('only_papers') }}</label>
            <button type="submit" class="mx-btn p">{{ $H('show') }}</button>
        </form>

        <div class="mx-card" style="overflow:auto">
            <table class="mx-t mx-hist">
                <thead><tr><th>{{ $H('col_when') }}</th><th>{{ $H('col_who') }}</th><th>{{ $H('col_from') }}</th><th>{{ $H('col_to') }}</th><th>{{ $H('col_subject') }}</th><th>{{ $H('col_paper') }}</th><th>{{ $H('col_files') }}</th></tr></thead>
                <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td class="num" style="white-space:nowrap">{{ $r->sent_at?->format('d-m-Y H:i') }}</td>
                        <td>{{ $r->sender?->name ?? $H('system') }}@if ($r->auto) <span class="mx-pill t-grey">{{ $H('auto') }}</span>@endif</td>
                        <td>{{ $r->from_email }}</td>
                        <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis">{{ $r->to }}@if ($r->cc)<div class="muted" style="font-size:11px">cc: {{ $r->cc }}</div>@endif</td>
                        <td>{{ $r->subject }}</td>
                        <td>@if ($r->paper_type)<span class="mx-pill t-teal">{{ __('mail::field.papers.'.$r->paper_type) }}{{ $r->paper_no ? ' · '.$r->paper_no : '' }}</span>@endif</td>
                        <td class="num">{{ $r->files ?: '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="mx-empty">{{ $H('none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :rows="$rows" />
    </div>
</x-layouts.app>

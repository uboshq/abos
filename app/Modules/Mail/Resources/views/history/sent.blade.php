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
        <form class="mx-card" style="overflow:hidden" method="GET" action="{{ route('mail.sent') }}">
            <x-ui.toolbar :title="$H('sent_title')" :subtitle="$H('sent_info')" :search-placeholder="$H('search')" :filter="false" :export="false" :print="false" :share="false" :quiet="['from', 'to', 'paper']">
                <x-slot:actions><a class="mx-btn" href="{{ route('mail.history') }}">🕘 {{ $H('title') }}</a></x-slot:actions>
            </x-ui.toolbar>
            <div class="mx-filter" style="padding:10px 14px">
            <label>{{ $H('from') }}<x-ui.date name="from" :value="$from" /></label>
            <label>{{ $H('to') }}<x-ui.date name="to" :value="$to" /></label>
            <label class="mx-h32" style="flex-direction:row;align-items:center;gap:6px"><input type="checkbox" name="paper" value="yes" @checked($paper)> {{ $H('only_papers') }}</label>
            <button type="submit" class="mx-btn p">{{ $H('show') }}</button>
            </div>
        </form>

        <div class="mx-card" style="overflow:auto">
            <table class="mx-t mx-hist ui-list">
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

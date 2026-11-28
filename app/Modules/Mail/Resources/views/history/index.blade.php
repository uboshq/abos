{{--
    ⭐ মেইলের ইতিহাস — মালিকের অডিট নির্দেশ (২৬ নভেম্বর ২০২৬, "ok"): মেইলবক্স, ঠিকানা, টেমপ্লেট, সই, উৎসবের প্রতিটা বদল —
    কে, কখন, কী করলেন, আগে → পরে। খোঁজা যায়; মোছা যায় না। ⛔ মেইলের লেখা বা কে কোন মেইল পড়লেন — এখানে নেই।
--}}
@php $H = fn (string $k, array $r = []) => __('mail::field.history.'.$k, $r); @endphp
<x-layouts.app :menu="$menu ?? []">
    <x-slot:title>{{ $H('title') }}</x-slot:title>
    @push('scripts')
        @vite('resources/js/mail-admin.js')
    @endpush

    <div class="mailx mx-page" data-mail-history>
        <form class="mx-card" style="overflow:hidden" method="GET" action="{{ route('mail.history') }}">
            <x-ui.toolbar :title="$H('title')" :subtitle="$H('info')" :search-placeholder="$H('search')" :filter="false" :export="false" :print="false" :share="false" :quiet="['type', 'from', 'to']">
                <x-slot:actions><a class="mx-btn" href="{{ route('mail.sent') }}">📤 {{ $H('sent_title') }}</a></x-slot:actions>
            </x-ui.toolbar>
            <div class="mx-filter" style="padding:10px 14px">
            <label>&nbsp;<span style="display:flex;gap:6px;flex-wrap:wrap">
                @foreach (trans('mail::field.history.types') as $k => $label)
                    <a class="mx-chip @if ($type === $k) on @endif" href="{{ route('mail.history', array_filter(['type' => $k ?: null, 'q' => $q ?: null, 'from' => $from, 'to' => $to])) }}">{{ $label }}</a>
                @endforeach
            </span></label>
            <input type="hidden" name="type" value="{{ $type }}">
            <label>{{ $H('from') }}<x-ui.date name="from" :value="$from" /></label>
            <label>{{ $H('to') }}<x-ui.date name="to" :value="$to" /></label>
            <button type="submit" class="mx-btn p">{{ $H('show') }}</button>
            </div>
        </form>

        <div class="mx-card" style="overflow:auto">
            <table class="mx-t mx-hist ui-list">
                <thead><tr><th>{{ $H('col_when') }}</th><th>{{ $H('col_who') }}</th><th>{{ $H('col_what') }}</th><th>{{ $H('col_item') }}</th><th>{{ $H('col_change') }}</th></tr></thead>
                <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td class="num" style="white-space:nowrap">{{ $r->created_at?->format('d-m-Y H:i') }}</td>
                        <td>{{ $r->user?->name ?? $H('system') }}</td>
                        <td><span class="mx-pill t-blue">{{ __('mail::field.history.actions.'.$r->action) !== 'mail::field.history.actions.'.$r->action ? __('mail::field.history.actions.'.$r->action) : $r->action }}</span></td>
                        <td><b>{{ $r->label ?? '—' }}</b><div class="muted" style="font-size:11px">{{ __('mail::field.history.types.'.($kinds[$r->auditable_type] ?? '')) }}</div></td>
                        <td class="ch">
                            @if ($r->reason)<div>{{ $r->reason }}</div>@endif
                            @foreach ($r->changes as $c)
                                {{-- ⛔ অডিটের মান ছাপা কেবল FieldSecurity দিয়ে — ঢাকা ঘর ঢাকাই থাকে (§৮ ⛔১; EveryAuditDoorHidesTheSalaryTest) --}}
                                <div><b>{{ $c->field }}</b>: {{ \Illuminate\Support\Str::limit((string) \App\Core\Security\FieldSecurity::show($r->auditable_type, $c->field, $c->old_value ?? '—', $r->auditable_id), 60) }} → {{ \Illuminate\Support\Str::limit((string) \App\Core\Security\FieldSecurity::show($r->auditable_type, $c->field, $c->new_value ?? '—', $r->auditable_id), 60) }}</div>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="mx-empty">{{ $H('none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <x-ui.pager :rows="$rows" />
    </div>
</x-layouts.app>

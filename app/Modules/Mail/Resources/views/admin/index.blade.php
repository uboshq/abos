{{--
    ⭐ মেইল ঠিকানা ও ডোমেইন — প্রশাসন (নমুনা v4 পর্দা ৭–১২, মালিকের "ok" ২৬ নভেম্বর ২০২৬):
    পাঁচ ঘর (ডোমেইন, চালু, বন্ধ, জায়গা, নজর দিন) · ডোমেইনের স্বাস্থ্য (MX/SPF/DKIM/DMARC) · খোঁজা ও ছাঁকনি · তালিকা ·
    নতুন / বদল (ট্যাব) / ⋯ মেনু / বন্ধ / মোছা — সব জানালা ([[mail-admin.js]])।
    ⛔ সিস্টেমের ঠিকানা বন্ধ বা মোছা যায় না; চালু ঠিকানা আগে বন্ধ; মোছার আগে ব্যাকআপ আর 30 দিন রাখা ([[MailAddresses]])।
--}}
@php
    $zero = '00000000-0000-0000-0000-000000000000';
    $A = fn (string $k, array $r = []) => __('mail::field.admin.'.$k, $r);
    $pct = $kpi['quota_gb'] > 0 ? (int) round($kpi['used_gb'] / $kpi['quota_gb'] * 100) : 0;
    $peopleJson = $people->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'designation' => (string) ($u->designation ?? ''), 'email' => $u->email])->values();
@endphp
<x-layouts.app :menu="$menu ?? []">
    <x-slot:title>{{ $A('title') }}</x-slot:title>
    @push('scripts')
        @vite('resources/js/mail-admin.js')
    @endpush

    <div class="mailx mx-page" data-mail-admin
         data-people="{{ json_encode($peopleJson, JSON_UNESCAPED_UNICODE) }}"
         data-routes="{{ json_encode([
            'update' => route('mail.addresses.update', ['address' => $zero]), 'members' => route('mail.addresses.members', ['address' => $zero]),
            'password' => route('mail.addresses.password', ['address' => $zero]), 'pause' => route('mail.addresses.pause', ['address' => $zero]),
            'resume' => route('mail.addresses.resume', ['address' => $zero]), 'delete' => route('mail.addresses.delete', ['address' => $zero]),
            'restore' => route('mail.addresses.restore', ['address' => $zero]), 'history' => route('mail.history', ['type' => 'address']),
            'auto_reply' => route('mail.addresses.auto_reply', ['address' => $zero]),
         ], JSON_UNESCAPED_SLASHES) }}">

        <div class="mx-head">
            <h1>✉️ {{ $A('title') }} <x-ui.page-info :text="$A('info')" /></h1>
        </div>

        @if (session('mail_flash'))<div class="mx-ok">{{ session('mail_flash') }}</div>@endif
        <x-ui.errors />
        @if (! $configured)<div class="mx-card" style="padding:10px 14px;background:var(--mx-amber-bg);color:var(--mx-amber)"><x-ui.icon name="alert-triangle" :size="16" /> {{ $A('not_set') }}</div>
        @elseif (! $writable)<div class="mx-card" style="padding:10px 14px;background:var(--mx-amber-bg);color:var(--mx-amber)">🧪 {{ $A('demo') }}</div>@endif

        <div class="mx-kpis">
            <div class="mx-kpi k1"><span>{{ $A('kpi_domains') }}</span><strong class="num">{{ $kpi['domains'] }}</strong><small>{{ $A('kpi_domains_sub', ['ok' => $kpi['domains_ok']]) }}</small></div>
            <div class="mx-kpi k2"><span>{{ $A('kpi_active') }}</span><strong class="num">{{ $kpi['active'] }}</strong><small>{{ $A('kpi_active_sub', ['n' => $kpi['new_month']]) }}</small></div>
            <div class="mx-kpi k3"><span>{{ $A('kpi_paused') }}</span><strong class="num">{{ $kpi['paused'] }}</strong><small>{{ $A('kpi_paused_sub') }}</small></div>
            <div class="mx-kpi k4"><span>{{ $A('kpi_space') }}</span><strong class="num">{{ $kpi['used_gb'] }} / {{ $kpi['quota_gb'] }} GB</strong><small>{{ $A('kpi_space_sub', ['pct' => $pct]) }}</small></div>
            <div class="mx-kpi k5"><span>{{ $A('kpi_attention') }}</span><strong class="num">{{ count($kpi['attention']) }}</strong>
                <small>{{ $kpi['attention'] ? $A('kpi_attention_sub', ['domain' => implode(', ', $kpi['attention'])]) : $A('kpi_attention_none') }}</small></div>
        </div>

        @if ($health->isNotEmpty())
            <div class="mx-doms">
                @foreach ($health as $domain => $h)
                    <div class="mx-card mx-dom"><x-ui.icon name="globe" :size="24" /><div><b>{{ $domain }}</b><div class="muted">{{ $A('dom_sub', ['n' => $h['count']]) }}</div></div>
                        <span style="flex:1"></span>
                        @foreach (['mx' => 'MX', 'spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $k => $label)
                            <span class="{{ $h[$k] ? 'ok' : 'bad' }}">{{ $label }} {{ $h[$k] ? '✓' : '✕ '.$A('fix') }}</span>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        <form method="GET" action="{{ route('mail.addresses') }}" class="mx-card" style="overflow:hidden">
            <input type="hidden" name="show" value="{{ $chip }}">
            <x-ui.toolbar :title="$A('title')" :search-placeholder="$A('search')" :filter="false" :export="false" :print="false" :share="false" :quiet="['show']">
                <x-slot:actions>
                    @foreach (['all', 'active', 'paused', 'shared'] as $c)
                        <a class="mx-chip @if ($chip === $c) on @endif" href="{{ route('mail.addresses', ['show' => $c, 'q' => $q ?: null]) }}">{{ __('mail::field.admin.chips.'.$c, ['n' => $counts[$c]]) }}</a>
                    @endforeach
                    <a class="mx-btn" href="{{ route('mail.history', ['type' => 'address']) }}">🕘 {{ $A('history') }}</a>
                    <button type="button" class="mx-btn" data-open="dlg-domain">🗺️ {{ $A('domain_add') }}</button>
                    <button type="button" class="mx-btn" data-open="dlg-import">⬆ {{ __('mail::field.ax_import') }}</button>
                    <a class="mx-btn" href="{{ route('mail.addresses.export') }}">⬇ {{ $A('export') }}</a>
                    <button type="button" class="mx-btn p" data-open="dlg-create">＋ {{ $A('new') }}</button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <div class="mx-card" style="overflow:auto">
            <table class="mx-t ui-list">
                <thead><tr><th>{{ $A('col_address') }}</th><th>{{ $A('col_owner') }}</th><th>{{ $A('col_kind') }}</th><th>{{ $A('col_quota') }}</th><th>{{ $A('col_2fa') }}</th><th>{{ __('mail::field.ax_col_last') }}</th><th>{{ $A('col_status') }}</th><th>{{ $A('col_on') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($rows as $r)
                    @php
                        $srv = $server[$r->email] ?? null;
                        $used = (float) ($srv['used_mb'] ?? 0);
                        $quota = (int) ($srv['quota_mb'] ?? $r->quota_mb);
                        $fill = $quota > 0 ? min(100, (int) round($used / $quota * 100)) : 0;
                        $members = $r->account?->members ?? collect();
                        $rowJson = [
                            'id' => $r->public_id, 'email' => $r->email, 'kind' => $r->kind, 'status' => $r->status, 'display_name' => (string) $r->display_name,
                            'quota_mb' => $r->quota_mb, 'forward_to' => (string) $r->forward_to, 'sender_with_person' => (bool) ($r->account?->sender_with_person),
                            'members' => $members->map(fn ($u) => ['user_id' => $u->id, 'name' => $u->name, 'can_send' => (bool) $u->pivot->can_send])->values(),
                            'system' => $r->isSystem(), 'user_id' => $r->user_id, 'auto_reply' => $r->auto_reply,
                        ];
                    @endphp
                    <tr @class(['off' => $r->status !== 'active']) data-row="{{ json_encode($rowJson, JSON_UNESCAPED_UNICODE) }}">
                        <td><b>{{ $r->email }}</b> @if ($r->isSystem())🔒@endif</td>
                        <td>
                            @if ($r->isSystem()) {{ $A('system_owner') }}
                            @elseif ($r->kind === 'shared') {{ $A('people_see', ['n' => $members->count()]) }}
                            @else {{ $r->user?->name ?? ($r->display_name ?: '—') }}@if ($r->user?->designation) · {{ $r->user->designation }}@endif
                            @endif
                            @if ($r->status === 'paused' && $r->pause_reason)<div class="muted" style="font-size:11.5px">{{ $A('paused_since', ['reason' => $r->pause_reason]) }}</div>@endif
                        </td>
                        <td>
                            @if ($r->status === 'deleting')<span class="mx-pill t-red">{{ $A('deleting_until', ['date' => $r->delete_on?->format('d-m-Y')]) }}</span>
                            @elseif ($r->status === 'paused')<span class="mx-pill t-grey">{{ $A('status_paused') }}</span>
                            @else <span class="mx-pill {{ ['person' => 't-blue', 'shared' => 't-violet', 'system' => 't-grey'][$r->kind] ?? 't-blue' }}">{{ $A('kind_'.$r->kind) }}</span>@endif
                        </td>
                        <td class="num"><span class="mx-use"><i class="{{ $fill > 80 ? 'hi' : ($fill > 60 ? 'mid' : 'lo') }}" style="width:{{ $fill }}%"></i></span>{{ number_format($used / 1024, 1) }} / {{ number_format($quota / 1024, 0) }} GB</td>
                        <td>
                            @if ($r->kind !== 'person') <span class="muted">{{ $A('twofa_shared') }}</span>
                            @elseif ($r->user?->two_factor_secret) <span class="ok">{{ $A('twofa_on') }}</span>
                            @else <span class="bad">{{ $A('twofa_off') }}</span>@endif
                        </td>
                        <td class="num" style="white-space:nowrap">{{ ! empty($srv['last']) ? \App\Core\Support\DateFormat::spoken(\Illuminate\Support\Carbon::createFromTimestamp((int) $srv['last'])) : '—' }}</td>
                        <td>{{ $A('status_'.$r->status) }}</td>
                        <td>
                            @if ($r->isSystem() || $r->status === 'deleting')
                                <button type="button" class="mx-sw @if ($r->status !== 'active') no @endif" disabled aria-label="{{ $A('col_on') }}"></button>
                            @elseif ($r->status === 'active')
                                <button type="button" class="mx-sw" data-row-act="pause" aria-label="{{ __('mail::field.admin.menu.pause') }}"></button>
                            @else
                                <form method="POST" action="{{ route('mail.addresses.resume', $r->public_id) }}" style="display:inline">@csrf<button type="submit" class="mx-sw no" aria-label="{{ __('mail::field.admin.menu.resume') }}"></button></form>
                            @endif
                        </td>
                        <td>@if ($r->isSystem())<span class="muted" style="font-size:11px">{{ $A('never_delete') }}</span>@else<button type="button" class="mx-dots-btn" data-row-act="menu" aria-label="⋯">⋯</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="mx-empty">{{ __('mail::field.history.none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{-- ⋯ মেনু (পর্দা ১০) --}}
        <div class="mx-menu" data-row-menu hidden>
            <button type="button" data-menu-act="edit">✏️ {{ __('mail::field.admin.menu.edit') }}</button>
            <button type="button" data-menu-act="people" data-only-shared>👥 {{ __('mail::field.admin.menu.people') }}</button>
            <button type="button" data-menu-act="security">🔑 {{ __('mail::field.admin.menu.reset') }}</button>
            <button type="button" data-menu-act="forward">↪ {{ __('mail::field.admin.menu.forward') }}</button>
            <button type="button" data-menu-act="auto">🏖 {{ __('mail::field.ax_auto_tab') }}</button>
            <button type="button" data-menu-act="history">🕘 {{ __('mail::field.admin.menu.history') }}</button>
            <div class="sep"></div>
            <button type="button" data-menu-act="pause" class="tone-amber">⏸ {{ __('mail::field.admin.menu.pause') }}</button>
            <button type="button" data-menu-act="resume" class="tone-green">▶ {{ __('mail::field.admin.menu.resume') }}</button>
            @if ($canDelete)
                <button type="button" data-menu-act="delete" class="tone-red">🗑 {{ __('mail::field.admin.menu.delete') }}</button>
                <button type="button" data-menu-act="restore" class="tone-green">↺ {{ __('mail::field.admin.menu.restore') }}</button>
            @endif
        </div>

        {{-- নতুন তৈরি (পর্দা ৮) --}}
        <dialog class="mx-dlg big" id="dlg-create">
            <form method="POST" action="{{ route('mail.addresses.store') }}">
                @csrf
                <div class="hd"><span>＋ {{ __('mail::field.admin.create.title') }}</span><button type="button" data-close>✕</button></div>
                <div class="bd two">
                    <div class="w"><label class="mx-lbl">{{ __('mail::field.admin.create.kind') }}</label>
                        <div class="mx-seg"><label><input type="radio" name="kind" value="person" checked data-kind> {{ __('mail::field.admin.create.person') }}</label><label><input type="radio" name="kind" value="shared" data-kind> {{ __('mail::field.admin.create.shared') }}</label></div></div>
                    <div data-for="person"><label class="mx-lbl">{{ __('mail::field.admin.create.staff') }}</label>
                        <select class="mx-in" name="user_id" data-staff><option value="">—</option>
                            @foreach ($people as $u)<option value="{{ $u->id }}" data-name="{{ $u->name }}">{{ $u->name }}@if ($u->designation) · {{ $u->designation }}@endif</option>@endforeach
                        </select></div>
                    <div><label class="mx-lbl">{{ __('mail::field.admin.create.address') }}</label>
                        <div style="display:flex;gap:6px;align-items:center"><input class="mx-in" name="local" required pattern="[A-Za-z0-9][A-Za-z0-9._\-]*" style="flex:1;font-weight:700" autocomplete="off"><b>@</b>
                            <select class="mx-in" name="domain" style="width:200px">@foreach ($domains as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach</select></div></div>
                    <div><label class="mx-lbl">{{ __('mail::field.admin.create.display') }}</label><input class="mx-in" name="display_name" maxlength="120" data-display></div>
                    <div><label class="mx-lbl">{{ __('mail::field.admin.create.quota') }}</label>
                        <div class="mx-seg"><label><input type="radio" name="quota_mb" value="2048"> 2 GB</label><label><input type="radio" name="quota_mb" value="5120" checked> 5 GB</label><label><input type="radio" name="quota_mb" value="10240"> 10 GB</label><label><input type="radio" name="quota_mb" value="20480"> 20 GB</label></div></div>
                    <div class="w" data-for="shared" hidden><label class="mx-lbl">{{ __('mail::field.admin.create.members') }}</label>
                        <div data-member-list></div>
                        <select class="mx-in" data-add-member style="max-width:320px;margin-top:6px"><option value="">＋ {{ __('mail::field.admin.edit.add_person') }}</option>
                            @foreach ($people as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
                    <div><label class="mx-lbl">{{ __('mail::field.admin.create.password') }} <span class="muted" style="font-weight:400">{{ __('mail::field.admin.create.pass_note') }}</span></label>
                        <label class="mx-radio"><input type="radio" name="deliver" value="messenger" checked><div><b>{{ __('mail::field.admin.create.deliver_messenger') }}</b> <span class="mx-mine">★</span><div class="muted">{{ __('mail::field.admin.create.deliver_messenger_sub') }}</div></div></label>
                        <label class="mx-radio" style="margin-top:8px"><input type="radio" name="deliver" value="screen"><div><b>{{ __('mail::field.admin.create.deliver_screen') }}</b><div class="muted">{{ __('mail::field.admin.create.deliver_screen_sub') }}</div></div></label></div>
                    <div><label class="mx-lbl">{{ __('mail::field.admin.create.more') }}</label>
                        <div class="muted" style="line-height:1.9">☑ {{ __('mail::field.admin.create.auto_add') }}<br>☑ {{ __('mail::field.admin.create.sig') }}</div>
                        <label class="mx-lbl" style="margin-top:8px">{{ __('mail::field.admin.create.forward') }}</label><input class="mx-in" name="forward_to" type="email" placeholder="name@domain"></div>
                </div>
                <div class="ft"><button type="button" class="mx-btn" data-close>{{ __('mail::field.admin.create.cancel') }}</button><button type="submit" class="mx-btn g">✓ {{ __('mail::field.admin.create.do') }}</button></div>
            </form>
        </dialog>

        {{-- বদলান (পর্দা ৯) — ট্যাবসহ --}}
        <dialog class="mx-dlg" id="dlg-edit" style="width:min(680px,calc(100vw - 32px))">
            <div class="hd"><span data-edit-title></span><button type="button" data-close>✕</button></div>
            <div class="mx-tabs">
                @foreach (['general', 'people', 'forward', 'auto', 'security', 'log'] as $tab)
                    <button type="button" data-tab="{{ $tab }}" @if ($tab === 'people') data-only-shared @endif>{{ $tab === 'auto' ? __('mail::field.ax_auto_tab') : __('mail::field.admin.edit.tabs.'.$tab) }}</button>
                @endforeach
            </div>
            <form method="POST" data-pane="general" data-form="update">
                @csrf @method('PUT')
                <div class="bd">
                    <div><label class="mx-lbl">{{ __('mail::field.admin.create.display') }}</label><input class="mx-in" name="display_name" maxlength="120"></div>
                    <div><label class="mx-lbl">{{ __('mail::field.admin.create.quota') }}</label><input class="mx-in num" name="quota_mb" type="number" min="256" max="102400" step="256" style="max-width:200px"> MB</div>
                    <div data-only-shared><label class="mx-lbl">{{ __('mail::field.admin.edit.sender') }}</label>
                        <div class="mx-seg"><label><input type="radio" name="sender_with_person" value="0"> {{ __('mail::field.admin.edit.sender_only') }}</label><label><input type="radio" name="sender_with_person" value="1"> {{ __('mail::field.admin.edit.sender_person') }}</label></div></div>
                    <input type="hidden" name="forward_to">
                </div>
                <div class="ft"><button type="button" class="mx-btn" data-close>{{ __('mail::field.admin.edit.cancel') }}</button><button type="submit" class="mx-btn p">✓ {{ __('mail::field.admin.edit.save') }}</button></div>
            </form>
            <form method="POST" data-pane="people" data-form="members" hidden>
                @csrf
                <div class="bd">
                    <div class="muted">{{ __('mail::field.admin.edit.shared_note') }}</div>
                    <table class="mx-t ui-list"><thead><tr><th>{{ __('mail::field.admin.col_owner') }}</th><th>{{ __('mail::field.admin.create.can_read') }}</th><th>{{ __('mail::field.admin.create.can_send') }}</th><th></th></tr></thead><tbody data-members></tbody></table>
                    <select class="mx-in" data-add-member style="max-width:320px"><option value="">＋ {{ __('mail::field.admin.edit.add_person') }}</option>
                        @foreach ($people as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>
                </div>
                <div class="ft"><button type="button" class="mx-btn" data-close>{{ __('mail::field.admin.edit.cancel') }}</button><button type="submit" class="mx-btn p">✓ {{ __('mail::field.admin.edit.save') }}</button></div>
            </form>
            <form method="POST" data-pane="forward" data-form="update" hidden>
                @csrf @method('PUT')
                <div class="bd">
                    <div><label class="mx-lbl">{{ __('mail::field.admin.edit.forward_to') }}</label><input class="mx-in" name="forward_to" type="email" placeholder="name@domain"><div class="muted" style="font-size:12px">{{ __('mail::field.admin.edit.forward_none') }}</div></div>
                    <input type="hidden" name="display_name"><input type="hidden" name="quota_mb"><input type="hidden" name="sender_with_person">
                </div>
                <div class="ft"><button type="button" class="mx-btn" data-close>{{ __('mail::field.admin.edit.cancel') }}</button><button type="submit" class="mx-btn p">✓ {{ __('mail::field.admin.edit.save') }}</button></div>
            </form>
            <form method="POST" data-pane="auto" data-form="auto_reply" hidden>
                @csrf
                <div class="bd">
                    <div class="muted">{{ __('mail::field.ax_auto_hint') }}</div>
                    <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="on" value="1" data-auto-on> <b>{{ __('mail::field.ax_auto_on') }}</b></label>
                    <div><label class="mx-lbl">{{ __('mail::field.ax_auto_subject') }}</label><input class="mx-in" name="subject" maxlength="200"></div>
                    <div><label class="mx-lbl">{{ __('mail::field.ax_auto_body') }}</label><textarea class="mx-in" name="body" rows="4" maxlength="5000"></textarea></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                        <div><label class="mx-lbl">{{ __('mail::field.ax_auto_start') }}</label><x-ui.date name="start" /></div>
                        <div><label class="mx-lbl">{{ __('mail::field.ax_auto_stop') }}</label><x-ui.date name="stop" /></div>
                    </div>
                </div>
                <div class="ft"><button type="button" class="mx-btn" data-close>{{ __('mail::field.admin.edit.cancel') }}</button><button type="submit" class="mx-btn p">✓ {{ __('mail::field.admin.edit.save') }}</button></div>
            </form>
            <form method="POST" data-pane="security" data-form="password" hidden>
                @csrf
                <div class="bd">
                    <div><b>🔑 {{ __('mail::field.admin.edit.reset') }}</b><div class="muted">{{ __('mail::field.admin.edit.reset_sub') }}</div></div>
                    <label class="mx-radio"><input type="radio" name="deliver" value="messenger" checked><div><b>{{ __('mail::field.admin.create.deliver_messenger') }}</b><div class="muted">{{ __('mail::field.admin.create.deliver_messenger_sub') }}</div></div></label>
                    <label class="mx-radio"><input type="radio" name="deliver" value="screen"><div><b>{{ __('mail::field.admin.create.deliver_screen') }}</b><div class="muted">{{ __('mail::field.admin.create.deliver_screen_sub') }}</div></div></label>
                </div>
                <div class="ft"><button type="button" class="mx-btn" data-close>{{ __('mail::field.admin.edit.cancel') }}</button><button type="submit" class="mx-btn p">🔑 {{ __('mail::field.admin.edit.reset') }}</button></div>
            </form>
            <div data-pane="log" hidden><div class="bd"><a class="mx-btn" data-log-link href="#">🕘 {{ __('mail::field.admin.menu.history') }} →</a></div></div>
        </dialog>

        {{-- বন্ধ (পর্দা ১১) --}}
        <dialog class="mx-dlg" id="dlg-pause">
            <form method="POST" data-form="pause">
                @csrf
                <div class="hd"><span>⏸ <span data-title-tpl="{{ __('mail::field.admin.pause.title', ['email' => ':email']) }}"></span></span><button type="button" data-close>✕</button></div>
                <div class="bd">
                    <div class="mx-step"><span class="n num">1</span><div>{{ __('mail::field.admin.pause.step1') }}</div></div>
                    <div class="mx-step"><span class="n num">2</span><div>{{ __('mail::field.admin.pause.step2') }}</div></div>
                    <div><label class="mx-lbl">{{ __('mail::field.admin.pause.incoming') }}</label>
                        <label class="mx-radio"><input type="radio" name="mode" value="forward" checked><div style="flex:1"><b>{{ __('mail::field.admin.pause.forward') }}</b>
                            <select class="mx-in" name="forward_to" style="margin-top:6px"><option value="">— {{ __('mail::field.admin.pause.forward_to') }} —</option>
                                @foreach ($people as $u)<option value="{{ $u->email }}">{{ $u->name }} ({{ $u->email }})</option>@endforeach</select></div></label>
                        <label class="mx-radio" style="margin-top:8px"><input type="radio" name="mode" value="bounce"><div><b>{{ __('mail::field.admin.pause.bounce') }}</b></div></label></div>
                    <div><label class="mx-lbl">{{ __('mail::field.admin.pause.reason') }}</label><input class="mx-in" name="reason" maxlength="200" placeholder="{{ __('mail::field.admin.pause.reason_ph') }}"></div>
                    <div><label class="mx-lbl">{{ __('mail::field.admin.pause.resume') }}</label><x-ui.date name="resume_on" /></div>
                </div>
                <div class="ft"><button type="button" class="mx-btn" data-close>{{ __('mail::field.admin.pause.cancel') }}</button><button type="submit" class="mx-btn am">⏸ {{ __('mail::field.admin.pause.do') }}</button></div>
            </form>
        </dialog>

        {{-- মুছুন (পর্দা ১২) --}}
        <dialog class="mx-dlg" id="dlg-delete">
            <form method="POST" data-form="delete">
                @csrf
                <div class="hd"><span>🗑 <span data-title-tpl="{{ __('mail::field.admin.delete.title', ['email' => ':email']) }}"></span></span><button type="button" data-close>✕</button></div>
                <div class="bd">
                    <div class="mx-card mx-warncard">{{ __('mail::field.admin.delete.rule') }}</div>
                    <div class="mx-step"><span class="n num">1</span><div>{{ __('mail::field.admin.delete.step1') }}</div></div>
                    <div class="mx-step"><span class="n num">2</span><div>{{ __('mail::field.admin.delete.step2') }}</div></div>
                    <div class="mx-step"><span class="n num">3</span><div>{{ __('mail::field.admin.delete.step3') }}</div></div>
                    <div><label class="mx-lbl">{{ __('mail::field.admin.delete.hand') }}</label><select class="mx-in" name="hand_to"><option value="">{{ __('mail::field.admin.delete.hand_none') }}</option>
                        @foreach ($people as $u)<option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>@endforeach</select></div>
                    <div><label class="mx-lbl">{{ __('mail::field.admin.delete.typed') }}</label><input class="mx-in" name="typed" required autocomplete="off" data-typed></div>
                    <div><label class="mx-lbl">{{ __('mail::field.admin.delete.password') }}</label><input class="mx-in" name="password" type="password" required autocomplete="current-password"></div>
                    <div class="muted">{{ __('mail::field.admin.delete.who') }}</div>
                </div>
                <div class="ft"><button type="button" class="mx-btn" data-close>{{ __('mail::field.admin.delete.cancel') }}</button><button type="submit" class="mx-btn r" data-delete-btn disabled>{{ __('mail::field.admin.delete.do') }}</button></div>
            </form>
        </dialog>

        {{-- ফিরিয়ে আনা — ছোট নিশ্চিত --}}
        <form method="POST" data-form="restore" hidden>@csrf</form>

        {{-- একসাথে অনেক (Excel → CSV) --}}
        <dialog class="mx-dlg" id="dlg-import">
            <form method="POST" action="{{ route('mail.addresses.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="hd"><span>⬆ {{ __('mail::field.ax_import_title') }}</span><button type="button" data-close>✕</button></div>
                <div class="bd"><p class="muted" style="margin:0">{{ __('mail::field.ax_import_hint') }}</p>
                    <x-ui.file-input name="file" accept=".csv,text/csv" :required="true" /></div>
                <div class="ft"><button type="button" class="mx-btn" data-close>{{ __('mail::field.admin.create.cancel') }}</button><button type="submit" class="mx-btn g">✓ {{ __('mail::field.ax_import_do') }}</button></div>
            </form>
        </dialog>

        {{-- ডোমেইন যোগ — কী হয় --}}
        <dialog class="mx-dlg" id="dlg-domain">
            <div class="hd"><span>🗺️ {{ $A('domain_add') }}</span><button type="button" data-close>✕</button></div>
            <div class="bd"><p style="margin:0">{{ $A('domain_note') }}</p></div>
            <div class="ft"><button type="button" class="mx-btn p" data-close>OK</button></div>
        </dialog>

        {{-- একবার দেখানো পাসওয়ার্ড --}}
        @if ($password)
            <dialog class="mx-dlg" id="dlg-password" data-open-now>
                <div class="hd"><span>🔑 {{ $password['email'] }}</span><button type="button" data-close>✕</button></div>
                <div class="bd"><div>{{ $A('password_shown', ['email' => $password['email']]) }}</div><div class="mx-pw num">{{ $password['password'] }}</div></div>
                <div class="ft"><button type="button" class="mx-btn p" data-close>OK</button></div>
            </dialog>
        @endif
    </div>
</x-layouts.app>

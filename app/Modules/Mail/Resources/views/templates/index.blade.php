{{--
    ⭐ মেইলের টেমপ্লেট — নমুনা v4 পর্দা ৫ (কাজ ধরে) আর ৬ (উৎসব ও দিবস), মালিকের "ok" ২৬ নভেম্বর ২০২৬।
    ⓘ বাঁয়ে কাজ আর "কার বানানো"; মাঝে কার্ড (ছোট ছবি); ডানে বাছা টেমপ্লেট আসল তথ্যে — গ্রাহক বাছলে ঘরগুলো তার তথ্যে বসে।
    ⛔ "কে খুলেছে" গোনা নেই; নিজে থেকে পাঠানো প্রথমে বন্ধ, কেবল প্রশাসক চালু করেন; জাতীয় দিবসে শ্রদ্ধা, অফার নয়।
    ⓘ টেমপ্লেটের HTML সার্ভারে তৈরি ([[MailTemplates::render]] — লেখা আর মান e() দিয়ে নিরাপদ), তাই {!! !!}।
--}}
@php
    $T = fn (string $k, array $r = []) => __('mail::field.tpl.'.$k, $r);
    $F = fn (string $k) => __('mail::field.tpl.form.'.$k);
    $icons = ['collect' => '💰', 'sales' => '🛒', 'delivery' => '🚚', 'purchase' => '🏭', 'hr' => '👥', 'festival' => '🎉', 'offer' => '🏷️', 'papers' => '🧾', 'reply' => '💬'];
    $sel = $selected;
    $canEditSel = $sel && ($sel->user_id === auth()->id() || ($sel->user_id === null && $canManage));
    $auto = (array) ($sel?->auto ?? []);
    $q = fn (array $extra) => route('mail.templates', array_filter(array_merge(['cat' => $category ?: null, 'whose' => $whose ?: null, 'look' => $look !== 'all' ? $look : null,
        'customer' => $customer?->id], $extra), fn ($v) => $v !== null && $v !== ''));
@endphp
<x-layouts.app :menu="$menu ?? []">
    <x-slot:title>{{ $T('title') }}</x-slot:title>
    @push('scripts')
        @vite('resources/js/mail-admin.js')
    @endpush

    <div class="mailx mx-page" data-mail-tpl>
        <div class="mx-head">
            <h1>📄 {{ $T('title') }}</h1><span class="sp"></span>
            <a class="mx-btn" href="{{ route('mail.index', ['counter' => 1]) }}" target="_blank" rel="noopener">✉️ {{ __('mail::field.title') }} ↗</a>
        </div>
        @if (session('mail_flash'))<div class="mx-ok">{{ session('mail_flash') }}</div>@endif
        <x-ui.errors />

        <div class="mx-tpl">
            <nav class="cat" aria-label="{{ $T('by_work') }}">
                <div class="h">{{ $T('by_work') }}</div>
                @foreach (\App\Modules\Mail\Models\MailTemplate::CATEGORIES as $c)
                    <a href="{{ route('mail.templates', ['cat' => $c]) }}" @class(['on' => $category === $c])><span>{{ $icons[$c] }} {{ __('mail::field.tpl.cats.'.$c) }}</span><span class="num">{{ $counts[$c] ?? 0 }}</span></a>
                @endforeach
                <div class="h">{{ $T('by_whom') }}</div>
                <a href="{{ route('mail.templates', ['whose' => 'company']) }}" @class(['on' => $whose === 'company'])><span>🏢 {{ $T('company') }}</span><span class="num">{{ $companyCount }}</span></a>
                <a href="{{ route('mail.templates', ['whose' => 'mine']) }}" @class(['on' => $whose === 'mine'])><span>👤 {{ $T('mine') }}</span><span class="num">{{ $mineCount }}</span></a>
                <div style="margin-top:auto" class="mx-card"><div style="padding:10px;font-size:12px;line-height:1.6;color:#5b6688">{{ $T('note') }}</div></div>
            </nav>

            <div class="mid">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    @foreach (['all', 'bn', 'en', 'designed', 'plain'] as $l)
                        <a class="mx-chip @if ($look === $l) on @endif" href="{{ $q(['look' => $l === 'all' ? null : $l]) }}">{{ in_array($l, ['bn', 'en'], true) ? __('mail::field.tx_'.$l) : __('mail::field.tpl.look.'.$l) }}</a>
                    @endforeach
                    <span style="flex:1"></span>
                    <button type="button" class="mx-btn" data-open="dlg-tpl-html">⬆ {{ __('mail::field.tx_html') }}</button>
                    <button type="button" class="mx-btn p" data-open="dlg-tpl-new">＋ {{ $T('new') }}</button>
                </div>
                <div class="g">
                    @forelse ($list as $row)
                        @php $t = $row['t']; @endphp
                        <a class="t @if ($sel && $sel->id === $t->id) on @endif" href="{{ $q(['t' => $t->public_id]) }}">
                            <div class="th"><div>{!! $row['html'] !!}</div></div>
                            <div class="f"><div style="min-width:0"><b style="font-size:13px">{{ $t->name }}</b><div class="muted" style="font-size:11px">{{ __('mail::field.tpl.cats.'.$t->category) }} · {{ $t->user_id ? $T('mine_tag') : $T('company_tag') }}</div></div>
                                <span class="muted num" style="font-size:11px;white-space:nowrap">{{ $T('times', ['count' => $t->used]) }}</span></div>
                        </a>
                    @empty
                        <p class="mx-empty">{{ __('mail::field.history.none') }}</p>
                    @endforelse
                </div>

                @if ($category === 'festival' && $festivals->isNotEmpty())
                    <div class="mx-card" style="padding:12px 14px">
                        <div class="muted" style="font-size:12.5px;line-height:1.7;margin-bottom:8px">🙏 {{ $T('fest_rules') }}</div>
                        @foreach ($festivals as $f)
                            <form class="mx-fest-row" method="POST" action="{{ route('mail.festivals.update', $f->id) }}">
                                @csrf
                                <span><span style="font-size:18px">{{ $f->mark }}</span> <b>{{ $f->name }}</b> <span class="muted" style="font-size:11.5px">{{ $f->when }}@if ($f->quiet) · {{ $T('quiet') }}@endif</span></span>
                                <input class="mx-in num" type="date" name="on_date" value="{{ $f->on_date?->format('Y-m-d') }}" aria-label="{{ $T('fest_date') }}" @disabled(! $canManage)>
                                <label style="display:flex;gap:4px;align-items:center;font-size:12px"><input type="checkbox" name="active" value="1" @checked($f->active) @disabled(! $canManage)> {{ $T('fest_active') }}</label>
                                @if ($canManage)<button type="submit" class="mx-btn" style="height:28px">{{ $T('fest_save') }}</button>@endif
                            </form>
                        @endforeach
                    </div>
                @endif
            </div>

            <aside class="ed">
                @if ($sel)
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><b style="font-size:17px">{{ $sel->name }}</b>
                        <span class="mx-pill t-green">{{ $sel->user_id ? $T('mine_tag') : $T('company_tag') }} · {{ __('mail::field.tpl.form.locales.'.$sel->locale) }}</span></div>
                    <div><div class="muted" style="font-size:12px;margin-bottom:5px">{{ $T('fields_hint') }}</div>
                        <div class="var">@foreach ($fields as $f)<span class="mx-pill t-blue">{{ $f }}</span>@endforeach</div></div>
                    <form method="GET" action="{{ route('mail.templates') }}" class="muted" style="font-size:12px;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                        <input type="hidden" name="t" value="{{ $sel->public_id }}">@if ($category)<input type="hidden" name="cat" value="{{ $category }}">@endif
                        {{ $T('see_as') }}
                        <select name="customer" class="mx-in" style="height:28px;width:auto;max-width:260px" data-auto-submit>
                            <option value="">{{ $T('see_none') }}</option>
                            @foreach ($customers as $c)<option value="{{ $c->id }}" @selected($customer?->id === $c->id)>{{ $c->name() }}</option>@endforeach
                        </select>
                        @if ($customer)<span>{{ $T('see_hint') }}</span>@endif
                    </form>
                    @if ($rendered['subject'] !== '')<div><span class="muted">{{ __('mail::field.compose.subject') }}:</span> <b>{{ $rendered['subject'] }}</b></div>@endif
                    <div class="mail">{!! $rendered['html'] !!}</div>
                    @if ($auto)
                        <div class="mx-card" style="padding:10px 12px;font-size:12px;line-height:1.75;background:#f4f6fb;color:#2c3655">
                            ⚙️ <b>{{ $T('auto_title') }}</b>
                            {{ ($auto['rule'] ?? '') === 'festival' ? $T('auto_fest', ['at' => $auto['at'] ?? '09:00']) : $T('auto_due', ['day' => __('mail::field.tpl.days.'.($auto['day'] ?? 'mon')), 'at' => $auto['at'] ?? '10:00']) }}
                            <b style="color:{{ ($auto['on'] ?? false) ? 'var(--mx-green-ink)' : 'var(--mx-muted)' }}">({{ ($auto['on'] ?? false) ? $T('auto_now_on') : $T('auto_now_off') }})</b>
                        </div>
                    @endif
                    <div class="muted" style="font-size:12px">📊 {{ $T('stats', ['count' => $sentCount]) }}</div>
                    <div style="margin-top:auto;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
                        @if ($canEditSel)
                            <button type="button" class="mx-btn" data-open="dlg-tpl-edit">✎ {{ $T('edit') }}</button>
                            <form method="POST" action="{{ route('mail.templates.destroy', $sel->public_id) }}">@csrf @method('DELETE')<button type="submit" class="mx-btn">🙈 {{ $T('hide') }}</button></form>
                        @endif
                        <form method="POST" action="{{ route('mail.templates.copy', $sel->public_id) }}">@csrf<button type="submit" class="mx-btn">⧉ {{ $T('copy') }}</button></form>
                        <a class="mx-btn p" href="{{ route('mail.index', ['counter' => 1, 'compose' => 1, 'template' => $sel->public_id]) }}" target="_blank" rel="noopener">{{ $T('use') }} ➤</a>
                    </div>
                @else
                    <p class="mx-empty">{{ __('mail::field.history.none') }}</p>
                @endif
            </aside>
        </div>

        {{-- ⬆ HTML আনুন — নিজের নকশা --}}
        <dialog class="mx-dlg" id="dlg-tpl-html">
            <form method="POST" action="{{ route('mail.templates.store') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="format" value="html"><input type="hidden" name="locale" value="bn">
                <div class="hd"><span>⬆ {{ __('mail::field.tx_html') }}</span><button type="button" data-close>✕</button></div>
                <div class="bd">
                    <p class="muted" style="margin:0">{{ __('mail::field.tx_html_hint') }}</p>
                    <div><label class="mx-lbl">{{ $F('name') }}</label><input class="mx-in" name="name" required maxlength="120"></div>
                    <div><label class="mx-lbl">{{ $F('category') }}</label><select class="mx-in" name="category">
                        @foreach (\App\Modules\Mail\Models\MailTemplate::CATEGORIES as $c)<option value="{{ $c }}" @selected(($category ?: 'offer') === $c)>{{ __('mail::field.tpl.cats.'.$c) }}</option>@endforeach</select></div>
                    <div><label class="mx-lbl">{{ $F('subject') }}</label><input class="mx-in" name="subject" maxlength="255"></div>
                    <input class="mx-in" type="file" name="html_file" accept=".html,.htm,text/html" required style="padding:6px">
                </div>
                <div class="ft"><button type="button" class="mx-btn" data-close>{{ $F('cancel') }}</button><button type="submit" class="mx-btn p">✓ {{ $F('save') }}</button></div>
            </form>
        </dialog>

        {{-- নতুন / বদল — একই ফর্ম --}}
        @foreach (array_filter(['new' => true, 'edit' => $canEditSel]) as $mode => $_)
            @php $t = $mode === 'edit' ? $sel : null; $ta = (array) ($t?->auto ?? []); @endphp
            <dialog class="mx-dlg big" id="dlg-tpl-{{ $mode }}">
                <form method="POST" action="{{ $mode === 'edit' ? route('mail.templates.update', $t->public_id) : route('mail.templates.store') }}">
                    @csrf @if ($mode === 'edit') @method('PUT') @endif
                    <div class="hd"><span>{{ $mode === 'edit' ? $F('title_edit') : $F('title_new') }}</span><button type="button" data-close>✕</button></div>
                    <div class="bd two">
                        <div><label class="mx-lbl">{{ $F('name') }}</label><input class="mx-in" name="name" required maxlength="120" value="{{ $t?->name }}"></div>
                        <div><label class="mx-lbl">{{ $F('category') }}</label><select class="mx-in" name="category">
                            @foreach (\App\Modules\Mail\Models\MailTemplate::CATEGORIES as $c)<option value="{{ $c }}" @selected(($t?->category ?? ($category ?: 'collect')) === $c)>{{ __('mail::field.tpl.cats.'.$c) }}</option>@endforeach</select></div>
                        <div><label class="mx-lbl">{{ $F('locale') }}</label><select class="mx-in" name="locale">
                            @foreach (trans('mail::field.tpl.form.locales') as $k => $l)<option value="{{ $k }}" @selected(($t?->locale ?? 'bn') === $k)>{{ $l }}</option>@endforeach</select></div>
                        <div><label class="mx-lbl">{{ $F('format') }}</label><div class="mx-seg">
                            <label><input type="radio" name="format" value="designed" @checked(($t?->format ?? 'designed') === 'designed')> {{ __('mail::field.tpl.look.designed') }}</label>
                            <label><input type="radio" name="format" value="plain" @checked($t?->format === 'plain')> {{ __('mail::field.tpl.look.plain') }}</label></div></div>
                        <div><label class="mx-lbl">{{ $F('colour') }}</label><input class="mx-in" type="color" name="colour" value="{{ $t?->colour ?? '#1a3fd0' }}" style="padding:2px;width:80px"></div>
                        <div><label class="mx-lbl">{{ $F('button') }}</label><input class="mx-in" name="button" maxlength="60" value="{{ $t?->button }}"></div>
                        <div class="w"><label class="mx-lbl">{{ $F('subject') }}</label><input class="mx-in" name="subject" maxlength="255" value="{{ $t?->subject }}"></div>
                        <div class="w"><label class="mx-lbl">{{ $F('body') }}</label>
                            <div class="var" style="margin-bottom:6px">@foreach ($fields as $f)<button type="button" data-insert="{{ $f }}">{{ $f }}</button>@endforeach</div>
                            <textarea class="mx-in" name="body" rows="8" required maxlength="20000" data-tpl-body>{{ $t?->body }}</textarea></div>
                        <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="attach_paper" value="1" @checked($t?->attach_paper)> {{ $F('attach') }}</label>
                        @if (! $t && $canManage)<label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="mine" value="1"> {{ $F('mine_only') }}</label>@endif
                        @if ($canManage && ($t === null || $t->user_id === null))
                            <div class="w mx-card" style="padding:10px 12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                                <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="auto_on" value="1" @checked($ta['on'] ?? false)> ⚙️ {{ $F('auto_on') }}</label>
                                <label>{{ $F('auto_day') }} <select class="mx-in" name="auto_day" style="width:auto;height:30px">@foreach (trans('mail::field.tpl.days') as $k => $d)<option value="{{ $k }}" @selected(($ta['day'] ?? 'mon') === $k)>{{ $d }}</option>@endforeach</select></label>
                                <label>{{ $F('auto_at') }} <input class="mx-in num" type="time" name="auto_at" value="{{ $ta['at'] ?? '10:00' }}" style="width:auto;height:30px"></label>
                            </div>
                        @endif
                    </div>
                    <div class="ft"><button type="button" class="mx-btn" data-close>{{ $F('cancel') }}</button><button type="submit" class="mx-btn p">✓ {{ $F('save') }}</button></div>
                </form>
            </dialog>
        @endforeach
    </div>
</x-layouts.app>

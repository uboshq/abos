{{--
    ⭐ মেইলের সই — নমুনা v4 পর্দা ৪ (মালিকের "ok"): বাঁয়ে নিজের তথ্য ও পছন্দ, ডানে 20টা নকশা (10 সাধারণ + 10 সুপার স্পেশাল) আর উৎসব ও দিবসের সই।
    ⓘ ছবি না থাকলে নামের আদ্যক্ষর; লোগো কোম্পানির নামের পাশে; প্রশাসক চাইলে নতুন মেইলের নকশা সবার জন্য বাঁধা ([[MailSignatureController]])।
    ⓘ নকশাগুলো সার্ভারে তৈরি ([[MailSignatures]]) — নাম, পদ, ফোন সব e() দিয়ে নিরাপদ, তাই এখানে {!! !!}।
--}}
@php
    $S = fn (string $k, array $r = []) => __('mail::field.sign.'.$k, $r);
    $styles = trans('mail::field.sign.styles') + [21 => __('mail::field.sx_custom_name')];
@endphp
<x-layouts.app :menu="$menu ?? []">
    <x-slot:title>{{ $S('title') }}</x-slot:title>
    @push('scripts')
        @vite('resources/js/mail-admin.js')
    @endpush

    <div class="mailx mx-page" data-mail-sign>
        <div class="mx-head">
            <h1>✍️ {{ $S('title') }}</h1><span class="sp"></span>
            <a class="mx-btn" href="{{ route('mail.index', ['counter' => 1]) }}" target="abos-mail">✉️ {{ __('mail::field.title') }} ↗</a>
        </div>
        @if (session('mail_flash'))<div class="mx-ok">{{ session('mail_flash') }}</div>@endif
        <x-ui.errors />

        <div class="mx-sign">
            <form class="set" method="POST" action="{{ route('mail.signature.update') }}" enctype="multipart/form-data" data-sign-form>
                @csrf
                @if ($forced > 0)<div class="mx-card" style="padding:8px 10px;font-size:12px;background:var(--mx-amber-bg);color:var(--mx-amber)">🔒 {{ $S('forced', ['n' => $forced]) }}</div>@endif
                <div><label class="mx-lbl">{{ $S('new') }}</label>
                    <select class="mx-in" name="style_new" @disabled($forced > 0)>@foreach ($styles as $n => $name)<option value="{{ $n }}" @selected(($forced ?: $profile->style_new ?: 1) == $n)>{{ $n }} · {{ $name }}</option>@endforeach</select>
                    @if ($forced > 0)<input type="hidden" name="style_new" value="{{ $forced }}">@endif</div>
                <div><label class="mx-lbl">{{ $S('reply') }}</label>
                    <select class="mx-in" name="style_reply">@foreach ($styles as $n => $name)<option value="{{ $n }}" @selected(($profile->style_reply ?: 10) == $n)>{{ $n }} · {{ $name }}</option>@endforeach</select></div>
                <div><label class="mx-lbl">{{ $S('photo') }}</label>
                    <div class="mx-photo">
                        @if ($hasPhoto)<span class="ini" style="background:var(--mx-green)">✓</span>@else<span class="ini">{{ $facts['initials'] }}</span>@endif
                        <div style="flex:1;min-width:0;font-size:12px">{{ $hasPhoto ? '✓' : $S('photo_none') }}</div>
                        <label class="mx-btn mx-h28">{{ $S('photo_change') }}<input type="file" name="photo" accept="image/jpeg,image/png" hidden data-auto-submit></label>
                    </div>
                    @if ($hasPhoto)<label style="font-size:12px;display:flex;gap:6px;margin-top:4px"><input type="checkbox" name="remove_photo" value="1"> {{ $S('photo_remove') }}</label>@endif</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                    <div><label class="mx-lbl">{{ $S('title_field') }}</label><input class="mx-in" name="title" maxlength="120" value="{{ old('title', $profile->title) }}" placeholder="{{ $facts['title'] }}"></div>
                    <div><label class="mx-lbl">{{ $S('phone') }}</label><input class="mx-in num" name="phone" maxlength="40" value="{{ old('phone', $profile->phone) }}" placeholder="{{ $facts['phone'] }}"></div>
                </div>
                <div><label class="mx-lbl">{{ $S('links') }}</label>
                    @foreach (\App\Modules\Mail\Models\MailProfile::LINKS as $l)
                        <input class="mx-in" name="links[{{ $l }}]" maxlength="191" value="{{ old('links.'.$l, $profile->links[$l] ?? '') }}" placeholder="{{ __('mail::field.sign.link_names.'.$l) }}" class="mx-h30" style="margin-bottom:5px">
                    @endforeach
                </div>
                <div><label class="mx-lbl">✎ {{ __('mail::field.sx_custom') }}</label>
                    <textarea class="mx-in" name="custom" rows="3" maxlength="5000" placeholder="{{ __('mail::field.sx_custom_hint') }}">{{ old('custom', strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string) $profile->custom))) }}</textarea></div>
                <label style="display:flex;gap:8px;align-items:flex-start;font-size:12.5px"><input type="checkbox" name="festival" value="1" @checked($profile->festival !== false)> {{ $S('festival_on') }}</label>
                @if ($canForce)
                    <div><label class="mx-lbl">🔒 {{ $S('force') }}</label>
                        <select class="mx-in" name="force"><option value="0">{{ $S('force_none') }}</option>@foreach ($styles as $n => $name)<option value="{{ $n }}" @selected($forced == $n)>{{ $n }} · {{ $name }}</option>@endforeach</select></div>
                @endif
                <div class="mx-card mx-softcard">{{ $S('hr_note') }}</div>
                <div style="margin-top:auto"><button type="submit" class="mx-btn p" style="width:100%;justify-content:center">✓ {{ $S('save') }}</button></div>
            </form>

            <div class="grid">
                @foreach ($previews as $n => $html)
                    @if ($n === 11)<div class="hdr">★ {{ $S('premium') }} <span>{{ $S('premium_sub') }}</span></div>@endif
                    @if ($n === 21)<div class="hdr">✎ {{ __('mail::field.sx_custom_name') }}</div>@endif
                    @php $isNew = ($forced ?: $profile->style_new ?: 1) == $n; $isReply = ($profile->style_reply ?: 10) == $n; @endphp
                    <div @class(['s', 'on' => $isNew || $isReply, 'sp' => $n >= 11])>
                        <div class="t"><span>{{ $n }} · {{ $styles[$n] ?? '' }}</span>
                            <span>@if ($isNew)<span style="color:var(--mx-brand)">{{ $S('chosen') }} · {{ $S('new') }}</span>@endif @if ($isReply)<span style="color:var(--mx-green-ink)">{{ $S('chosen') }} · {{ $S('reply') }}</span>@endif</span></div>
                        <div class="p">{!! $html !!}</div>
                        <div class="act">
                            @if ($forced === 0)<button type="button" class="mx-btn" data-pick-style="{{ $n }}" data-pick-for="new">{{ $S('use_new', ['n' => $n]) }}</button>@endif
                            <button type="button" class="mx-btn" data-pick-style="{{ $n }}" data-pick-for="reply">{{ $S('use_reply') }}</button>
                        </div>
                    </div>
                @endforeach

                <div class="hdr fest">🎉 {{ $S('fest_head', ['count' => $festivals->count()]) }} <span>{{ $S('fest_sub') }}</span></div>
                @foreach ($festivals as $i => $f)
                    <div class="s"><div class="t"><span>{{ 21 + $i }} · {{ $f['f']->name }}</span><span class="muted" style="font-weight:400">{{ $f['f']->when }}{{ $f['f']->on_date ? ' · '.$f['f']->on_date->format('d-m-Y') : '' }}</span></div>
                        <div class="p">{!! $f['html'] !!}</div></div>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts.app>

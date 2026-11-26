{{--
    ⭐ মেইল — মালিকের অনুমোদিত নমুনা v4 (২৬ নভেম্বর ২০২৬, "ok"): বাঁয়ে অ্যাকাউন্ট ও ফোল্ডার (সব ইনবক্স একসাথে, অনুমতির অনুরোধ) ·
    তালিকা (ছাঁকনি, একসাথে অনেক, দিন ধরে ভাগ, গ্রাহকের চিহ্ন) · পড়া (বাইরের প্রেরক, ছবি বন্ধ, কথোপকথন, দ্রুত উত্তর) ·
    ডানে গ্রাহকের কার্ড (বকেয়া, সীমা, খোলা বিল, টাকা জমা নিন)। নতুন মেইল ডান কোণে ভাসমান; অ্যাকাউন্ট জোড়া পুরো পর্দায়।
    ⓘ নিজের ট্যাবে খোলে (মেনু → `?counter=1`, [[counter-mode.js]])। স্ক্রিপ্ট [[mail-page.js]] — Alpine নয়; শব্দ `data-words`-এ।
    ⛔ মেইলের লেখা দেখানো হয় স্যান্ডবক্স করা iframe-এ (স্ক্রিপ্ট নয়), বাইরের ছবি প্রথমে বন্ধ।
--}}
@php
    $zero = '00000000-0000-0000-0000-000000000000';
    $mailRoutes = [
        'accounts' => route('mail.accounts'), 'party' => route('mail.party'), 'parties' => route('mail.parties'), 'compose' => route('mail.compose'),
        'trust' => route('mail.trust'), 'add' => route('mail.accounts.add'), 'signature' => route('mail.signature'), 'templates' => route('mail.templates'),
        'decide' => route('mail.accounts.decide', ['account' => $zero]), 'off' => route('mail.accounts.off', ['account' => $zero]),
        'folders' => route('mail.folders', ['account' => $zero]), 'list' => route('mail.list', ['account' => $zero]),
        'read' => route('mail.read', ['account' => $zero]), 'file' => route('mail.file', ['account' => $zero]), 'raw' => route('mail.raw', ['account' => $zero]),
        'flag' => route('mail.flag', ['account' => $zero]), 'move' => route('mail.move', ['account' => $zero]),
        'bulk' => route('mail.bulk', ['account' => $zero]), 'link' => route('mail.link', ['account' => $zero]),
        'send' => route('mail.send', ['account' => $zero]),
    ];
    $mailWords = trans('mail::field') + ['msg' => trans('mail::message')];
    $prefill = array_filter([
        'compose' => request()->boolean('compose'), 'to' => (string) request('to', ''), 'paper' => (string) request('paper', ''),
        'id' => (int) request('id', 0), 'party' => (string) request('party', ''), 'subject' => (string) request('subject', ''),
        'template' => (string) request('template', ''),
    ]);
@endphp
<x-layouts.app :menu="$menu ?? []">
    <x-slot:title>{{ __('mail::field.title') }}</x-slot:title>
    @push('scripts')
        @vite('resources/js/mail-page.js')
    @endpush

    <div class="mailx mx-inbox" data-mailx data-live-off
         data-words="{{ json_encode($mailWords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
         data-routes="{{ json_encode($mailRoutes, JSON_UNESCAPED_SLASHES) }}"
         data-prefill="{{ json_encode((object) $prefill, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
         data-csrf="{{ csrf_token() }}"
         data-manage="{{ $canManage ? '1' : '0' }}"
         data-approve="{{ $canApprove ? '1' : '0' }}">

        {{-- বাঁয়ে: নতুন মেইল, অ্যাকাউন্ট, অনুরোধ, ফোল্ডার --}}
        <aside class="mx-side" aria-label="{{ __('mail::field.my_mail') }}">
            <button type="button" class="mx-btn p mx-new" data-act="compose">✎ {{ __('mail::field.new_mail') }}</button>
            <div data-accounts></div>
            <button type="button" class="mx-addacc" data-act="connect">＋ {{ __('mail::field.add_box') }}</button>
            <div data-requests></div>
            <div class="mx-sect">{{ __('mail::field.folders_title') }}</div>
            <div data-folders></div>
        </aside>

        {{-- মেইলের তালিকা --}}
        <section class="mx-list" aria-label="{{ __('mail::field.folders.inbox') }}">
            <div class="mx-bar">
                <label class="mx-q"><x-ui.icon name="search" :size="16" /> <input type="search" data-q autocomplete="off" placeholder="{{ __('mail::field.search_folder') }}" aria-label="{{ __('mail::field.search_folder') }}"></label>
                <button type="button" class="mx-btn" data-act="refresh" title="{{ __('mail::field.refresh') }}">⟳</button>
                <div data-chips style="display:flex;gap:6px;flex-wrap:wrap"></div>
            </div>
            <div class="mx-bar mx-bulk">
                <label style="display:flex;gap:6px;align-items:center;cursor:pointer"><input type="checkbox" data-pick-all> {{ __('mail::field.bulk.all') }}</label>
                <span class="muted">|</span>
                <button type="button" data-bulk="archive" disabled>🗄️ {{ __('mail::field.bulk.archive') }}</button>
                <button type="button" data-bulk="move" disabled>📁 {{ __('mail::field.bulk.move') }}</button>
                <button type="button" data-bulk="read" disabled>✓ {{ __('mail::field.bulk.read') }}</button>
                <button type="button" data-bulk="later" disabled>🕒 {{ __('mail::field.bulk.later') }}</button>
                <button type="button" data-bulk="delete" disabled>🗑️ {{ __('mail::field.bulk.delete') }}</button>
                <span class="mx-pg num" data-pager></span>
            </div>
            <div class="mx-rows" data-rows></div>
        </section>

        {{-- পড়া --}}
        <article class="mx-read" data-reader>
            <div class="mx-empty">{{ __('mail::message.pick_mail') }}</div>
        </article>

        {{-- প্রেরকের কার্ড --}}
        <aside class="mx-ctx" data-ctx>
            <p class="muted" style="font-size:11.5px;line-height:1.6;margin:0">🔒 {{ __('mail::message.private_note') }}</p>
        </aside>

        {{-- নতুন মেইল / উত্তর — ডান কোণে ভাসমান --}}
        <form class="mx-compose" data-compose hidden>
            <div class="h"><span data-compose-title>{{ __('mail::field.compose.title') }}</span>
                <span><button type="button" data-act="compose-small" title="{{ __('mail::field.compose.small') }}">—</button><button type="button" data-act="compose-wide" title="{{ __('mail::field.compose.wide') }}">⤢</button><button type="button" data-act="compose-close" title="{{ __('mail::field.compose.close') }}">✕</button></span></div>
            <div data-compose-body>
                <div class="r"><span>{{ __('mail::field.compose.from') }}</span><select name="from" data-from></select></div>
                <div class="r"><span>{{ __('mail::field.compose.to') }}</span><input name="to" type="text" autocomplete="off" required data-to><span data-to-pill></span>
                    <button type="button" class="mx-chip" data-act="show-cc" style="border:0;color:var(--mx-brand)">{{ __('mail::field.compose.cc_bcc') }}</button></div>
                <div class="r" data-cc-row hidden><span>{{ __('mail::field.compose.cc') }}</span><input name="cc" type="text" autocomplete="off"></div>
                <div class="r" data-cc-row hidden><span>{{ __('mail::field.compose.bcc') }}</span><input name="bcc" type="text" autocomplete="off"></div>
                <div class="r"><span>{{ __('mail::field.compose.subject') }}</span><input name="subject" type="text" required maxlength="255" style="font-weight:700">
                    <select data-template style="flex:0 0 auto;max-width:180px;font-weight:400" aria-label="{{ __('mail::field.compose.template') }}"></select></div>
                <div class="r mx-auto" data-paper-line hidden></div>
                <div class="r fmt">
                    <button type="button" data-fmt="bold" title="{{ __('mail::field.compose.bold') }}"><b>B</b></button>
                    <button type="button" data-fmt="italic" title="{{ __('mail::field.compose.italic') }}"><i>I</i></button>
                    <button type="button" data-fmt="underline" title="{{ __('mail::field.compose.underline') }}"><u>U</u></button>
                    <button type="button" data-fmt="insertUnorderedList">• {{ __('mail::field.compose.list') }}</button>
                    <button type="button" data-fmt="createLink">🔗 {{ __('mail::field.compose.link') }}</button>
                    <label style="cursor:pointer">🖼 {{ __('mail::field.x_image') }}<input type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden data-image></label>
                    <label style="cursor:pointer">📎 {{ __('mail::field.compose.file') }}<input type="file" multiple hidden data-files></label>
                    <button type="button" data-act="emoji" title="{{ __('mail::field.x_emoji') }}">😊</button>
                    <span class="muted" data-file-names style="font-size:11.5px"></span>
                    <select data-sig style="margin-left:auto;border:0;background:transparent;color:var(--mx-brand)" aria-label="{{ __('mail::field.compose.signature') }}">
                        <option value="new">{{ __('mail::field.compose.sig_new') }}</option>
                        <option value="reply">{{ __('mail::field.compose.sig_reply') }}</option>
                        <option value="none">{{ __('mail::field.compose.sig_none') }}</option>
                    </select>
                </div>
                <div class="mx-editor" contenteditable="true" data-editor></div>
                <div class="mx-sig" data-sig-view></div>
                <input type="hidden" name="inReplyTo">
                <div class="mx-nx">
                    <b style="font-size:12px">{{ __('mail::field.compose.attach_from') }}</b>
                    <span data-paper-chips style="display:flex;gap:6px;flex-wrap:wrap"></span>
                    <span style="flex:1"></span>
                    <span class="muted" style="font-size:12px" data-compose-msg></span>
                    <button type="submit" class="mx-btn p" data-send>{{ __('mail::field.compose.send') }} ➤</button>
                    <span style="position:relative">
                        <button type="button" class="mx-btn p" style="padding:0 8px" data-act="later-menu" title="{{ __('mail::field.x_later_send') }}">🕒▾</button>
                        <div class="mx-menu" data-later-menu hidden style="right:0;bottom:38px;min-width:230px">
                            <button type="button" data-later="1h">🕐 {{ __('mail::field.x_later_1h') }}</button>
                            <button type="button" data-later="tomorrow">🌅 {{ __('mail::field.x_later_tomorrow') }}</button>
                            <div class="sep"></div>
                            <label style="display:flex;flex-direction:column;gap:4px;padding:6px 10px;font-size:12px">{{ __('mail::field.x_later_pick') }}
                                <input type="datetime-local" class="mx-in num mx-h30" data-later-at></label>
                            <button type="button" data-later="pick" style="justify-content:center;color:var(--mx-brand);font-weight:700">🕒 {{ __('mail::field.x_later_send') }}</button>
                        </div>
                    </span>
                </div>
            </div>
        </form>

        {{-- অ্যাকাউন্ট জোড়া (পর্দা ২) --}}
        <form class="mx-connect" data-connect hidden>
            <div class="l">
                <div style="display:flex;align-items:center;gap:10px"><b style="font-size:20px">{{ __('mail::field.add.title') }}</b><span style="flex:1"></span>
                    <button type="button" class="mx-btn" data-act="connect-close">✕ {{ __('mail::field.add.cancel') }}</button></div>
                <div class="cards">
                    <button type="button" class="c on" data-provider="company"><span class="ic mx-ic-g">🏢</span><span><h3>{{ __('mail::field.add.company') }}</h3><p>{{ __('mail::field.add.company_sub') }}</p></span></button>
                    <button type="button" class="c" data-provider="gmail"><span class="ic mx-ic-r">✉️</span><span><h3>{{ __('mail::field.add.gmail') }} <span class="mx-pill t-amber">{{ __('mail::field.add.needs_ok') }}</span></h3><p>{{ __('mail::field.add.gmail_sub') }}</p></span></button>
                    <button type="button" class="c" data-provider="outlook"><span class="ic mx-ic-b">📨</span><span><h3>{{ __('mail::field.add.outlook') }} <span class="mx-pill t-amber">{{ __('mail::field.add.needs_ok') }}</span></h3><p>{{ __('mail::field.add.outlook_sub') }}</p></span></button>
                    <button type="button" class="c" data-provider="imap"><span class="ic mx-ic-a">🔧</span><span><h3>{{ __('mail::field.add.imap') }}</h3><p>{{ __('mail::field.add.imap_sub') }}</p></span></button>
                </div>
                <div class="mx-card mx-lockcard"><b>🔒 {{ __('mail::field.add.lock_title') }}</b><br>{{ __('mail::field.add.lock_body') }}</div>
            </div>
            <div class="r">
                <div style="font-size:18px;font-weight:700" data-connect-title></div>
                <div data-connect-steps style="display:flex;flex-direction:column;gap:10px"></div>
                <input type="hidden" name="provider" value="company"><input type="hidden" name="kind" value="own"><input type="hidden" name="colour" value="{{ \App\Modules\Mail\Models\MailAccount::COLOURS[0] }}">
                <div data-kind-row style="display:flex;gap:6px;flex-wrap:wrap">
                    <label class="mx-chip"><input type="radio" name="kind_pick" value="own" checked hidden> {{ __('mail::field.add.work') }}</label>
                    @if ($canManage)<label class="mx-chip"><input type="radio" name="kind_pick" value="shared" hidden> {{ __('mail::field.add.shared') }}</label>@endif
                    <label class="mx-chip"><input type="radio" name="kind_pick" value="personal" hidden> {{ __('mail::field.add.mine') }}</label>
                </div>
                <div><label class="mx-lbl">{{ __('mail::field.add.email') }}</label><input class="mx-in" name="email" type="email" required autocomplete="off"></div>
                <div><label class="mx-lbl">{{ __('mail::field.add.password') }}</label><input class="mx-in" name="secret" type="password" required autocomplete="new-password"></div>
                <div data-only="imap" hidden style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div><label class="mx-lbl">{{ __('mail::field.add.imap_host') }}</label><input class="mx-in" name="imap_host" type="text"></div>
                    <div><label class="mx-lbl">{{ __('mail::field.add.smtp_host') }}</label><input class="mx-in" name="smtp_host" type="text"></div>
                </div>
                <div><label class="mx-lbl">{{ __('mail::field.add.label') }}</label>
                    <div class="mx-dots"><input class="mx-in" name="label" type="text" maxlength="80" placeholder="{{ __('mail::field.add.label_ph') }}" style="flex:1">
                        @foreach (\App\Modules\Mail\Models\MailAccount::COLOURS as $i => $c)
                            <button type="button" data-colour="{{ $c }}" class="mx-dot-c{{ $i + 1 }} @if ($loop->first) on @endif" aria-label="{{ $c }}"></button>
                        @endforeach
                    </div></div>
                <div data-only="personal" hidden style="display:grid;grid-template-columns:1fr 150px;gap:10px">
                    <div><label class="mx-lbl">{{ __('mail::field.add.reason') }}</label><input class="mx-in" name="reason" type="text" maxlength="500"></div>
                    <div><label class="mx-lbl">{{ __('mail::field.add.months') }}</label><select class="mx-in" name="months">
                        @foreach ([1, 3, 6, 12, 24] as $m)<option value="{{ $m }}" @selected($m === 6)>{{ __('mail::field.add.months_n', ['n' => $m]) }}</option>@endforeach
                    </select></div>
                </div>
                <div data-only="personal" hidden class="mx-card mx-warncard">{{ __('mail::field.add.approver_note') }}</div>
                <div class="muted" style="line-height:1.7">{{ __('mail::field.add.check_note') }}</div>
                <div class="muted" data-connect-msg style="font-weight:600"></div>
                <div style="margin-top:auto;display:flex;gap:10px;justify-content:flex-end">
                    <button type="button" class="mx-btn" data-act="connect-close">{{ __('mail::field.add.cancel') }}</button>
                    <button type="submit" class="mx-btn p" data-connect-btn>{{ __('mail::field.add.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</x-layouts.app>

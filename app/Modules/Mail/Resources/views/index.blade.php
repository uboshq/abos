{{--
    ⭐ মেইল — মালিকের অনুমোদিত নমুনা v3 (২৬ নভেম্বর ২০২৬, "ok"): বাঁয়ে মেইলবক্স ও ফোল্ডার · তালিকা (দিন ধরে ভাগ, পতাকা, সংযুক্তি) ·
    পড়া (কাজের সারি, বাইরের প্রেরকের সতর্কতা, দ্রুত উত্তর) · ডানে প্রেরকের গ্রাহক-কার্ড। নিজের ট্যাবে খোলে (মেনু → `?counter=1`, [[counter-mode.js]])।
    ⓘ স্ক্রিপ্ট [[mail-page.js]] — Alpine নয়; শব্দ `data-words`-এ, ঠিকানা `data-routes`-এ। সাজ কেবল `.mailx`-এর ভিতরে ([[mail.css]])।
    ⛔ মেইলের লেখা দেখানো হয় স্যান্ডবক্স করা iframe-এ (স্ক্রিপ্ট নয়), বাইরের ছবি প্রথমে বন্ধ।
--}}
@php
    $mailRoutes = [
        'accounts' => route('mail.accounts'),
        'party' => route('mail.party'),
        'add' => route('mail.accounts.add'),
        'decide' => route('mail.accounts.decide', ['account' => '00000000-0000-0000-0000-000000000000']),
        'off' => route('mail.accounts.off', ['account' => '00000000-0000-0000-0000-000000000000']),
        'folders' => route('mail.folders', ['account' => '00000000-0000-0000-0000-000000000000']),
        'list' => route('mail.list', ['account' => '00000000-0000-0000-0000-000000000000']),
        'read' => route('mail.read', ['account' => '00000000-0000-0000-0000-000000000000']),
        'file' => route('mail.file', ['account' => '00000000-0000-0000-0000-000000000000']),
        'flag' => route('mail.flag', ['account' => '00000000-0000-0000-0000-000000000000']),
        'move' => route('mail.move', ['account' => '00000000-0000-0000-0000-000000000000']),
        'send' => route('mail.send', ['account' => '00000000-0000-0000-0000-000000000000']),
    ];
    $mailWords = trans('mail::field') + ['msg' => trans('mail::message')];
@endphp
<x-layouts.app :menu="$menu ?? []">
    <x-slot:title>{{ __('mail::field.title') }}</x-slot:title>
    @push('scripts')
        @vite('resources/js/mail-page.js')
    @endpush

    <div class="mailx" data-mailx data-live-off
         data-words="{{ json_encode($mailWords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
         data-routes="{{ json_encode($mailRoutes, JSON_UNESCAPED_SLASHES) }}"
         data-csrf="{{ csrf_token() }}"
         data-manage="{{ $canManage ? '1' : '0' }}"
         data-approve="{{ $canApprove ? '1' : '0' }}">

        <div class="mx-bar">
            <h1>{{ __('mail::field.title') }}</h1>
            <button type="button" class="mx-new" data-act="compose">✎ {{ __('mail::field.new_mail') }}</button>
            <label class="mx-search"><span aria-hidden="true">⌕</span>
                <input type="search" data-q autocomplete="off" placeholder="{{ __('mail::field.search') }}" aria-label="{{ __('mail::field.search') }}">
            </label>
            <button type="button" class="mx-ib" data-act="refresh" title="{{ __('mail::field.refresh') }}">⟳</button>
        </div>

        <div class="mx-panes">
            {{-- বাঁয়ে: মেইলবক্স ও ফোল্ডার --}}
            <aside class="mx-folders" data-folders aria-label="{{ __('mail::field.my_mail') }}"></aside>

            {{-- মেইলের তালিকা --}}
            <section class="mx-list" aria-label="{{ __('mail::field.folders.inbox') }}">
                <div class="mx-lt"><b data-folder-name></b><span class="mx-pager" data-pager></span></div>
                <div data-rows></div>
            </section>

            {{-- পড়া --}}
            <article class="mx-reader" data-reader>
                <div class="mx-empty">{{ __('mail::message.pick_mail') }}</div>
            </article>

            {{-- প্রেরকের কার্ড --}}
            <aside class="mx-ctx" data-ctx>
                <p class="mx-lock">🔒 {{ __('mail::message.private_note') }}</p>
            </aside>
        </div>

        {{-- নতুন মেইল / উত্তর --}}
        <div class="mx-ov" data-compose hidden>
            <form class="mx-win" data-compose-form>
                <div class="mx-wh"><b data-compose-title>{{ __('mail::field.new_mail') }}</b><button type="button" class="mx-x" data-act="close-compose" aria-label="✕">✕</button></div>
                <label class="mx-f"><span>{{ __('mail::field.from') }}</span><select name="account" data-from></select></label>
                <label class="mx-f"><span>{{ __('mail::field.to') }}</span><input name="to" type="text" required autocomplete="off"></label>
                <label class="mx-f"><span>{{ __('mail::field.cc') }}</span><input name="cc" type="text" autocomplete="off"></label>
                <label class="mx-f"><span>{{ __('mail::field.subject') }}</span><input name="subject" type="text" required maxlength="255"></label>
                <div class="mx-editor" contenteditable="true" data-editor></div>
                <input type="hidden" name="inReplyTo">
                <div class="mx-wf">
                    <button type="submit" class="mx-send">{{ __('mail::field.send') }}</button>
                    <label class="mx-attach">📎 {{ __('mail::field.attach') }}<input type="file" name="files[]" multiple hidden data-files></label>
                    <span class="mx-files" data-file-names></span>
                    <span class="mx-hint" data-compose-msg></span>
                </div>
            </form>
        </div>

        {{-- মেইলবক্স যোগ --}}
        <div class="mx-ov" data-add hidden>
            <form class="mx-win" data-add-form>
                <div class="mx-wh"><b>{{ __('mail::field.add.title') }}</b><button type="button" class="mx-x" data-act="close-add" aria-label="✕">✕</button></div>
                <div class="mx-prov" data-prov>
                    @foreach (['company', 'gmail', 'outlook', 'yahoo', 'imap'] as $p)
                        <button type="button" data-provider="{{ $p }}" @class(['on' => $p === 'company'])>{{ __('mail::field.add.'.$p) }}</button>
                    @endforeach
                </div>
                <div class="mx-kind" data-kind>
                    <button type="button" data-kind-v="own" class="on"><b>{{ __('mail::field.add.work') }}</b><small>{{ __('mail::field.add.work_hint') }}</small></button>
                    @if ($canManage)
                        <button type="button" data-kind-v="shared"><b>{{ __('mail::field.add.shared') }}</b><small>{{ __('mail::field.add.work_hint') }}</small></button>
                    @endif
                    <button type="button" data-kind-v="personal"><b>{{ __('mail::field.add.mine') }}</b><small>{{ __('mail::field.add.mine_hint') }}</small></button>
                </div>
                <input type="hidden" name="provider" value="company"><input type="hidden" name="kind" value="own">
                <div class="mx-grid2">
                    <label class="mx-f"><span>{{ __('mail::field.add.email') }}</span><input name="email" type="email" required></label>
                    <label class="mx-f"><span>{{ __('mail::field.add.label') }}</span><input name="label" type="text" maxlength="80"></label>
                    <label class="mx-f"><span>{{ __('mail::field.add.password') }}</span><input name="secret" type="password" required autocomplete="new-password"></label>
                    <label class="mx-f" data-only="personal" hidden><span>{{ __('mail::field.add.months') }}</span><input name="months" type="number" min="1" max="24" value="6"></label>
                    <label class="mx-f" data-only="imap" hidden><span>{{ __('mail::field.add.imap_host') }}</span><input name="imap_host" type="text"></label>
                    <label class="mx-f" data-only="imap" hidden><span>{{ __('mail::field.add.smtp_host') }}</span><input name="smtp_host" type="text"></label>
                </div>
                <label class="mx-f" data-only="personal" hidden><span>{{ __('mail::field.add.reason') }}</span><input name="reason" type="text" maxlength="500"></label>
                <p class="mx-small">{{ __('mail::field.add.password_hint') }}</p>
                <p class="mx-policy" data-only="personal" hidden>{{ __('mail::field.add.policy') }}</p>
                <div class="mx-wf"><button type="submit" class="mx-send" data-add-btn>{{ __('mail::field.add.save') }}</button><span class="mx-hint" data-add-msg></span></div>
            </form>
        </div>
    </div>
</x-layouts.app>

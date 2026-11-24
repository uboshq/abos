{{--
    The web messages page — owner-approved sample M1UddRxK v7 (tabs ক and খ).

    ⭐ Drawn as the sample: the list pane (its own "বার্তা" heading, search, chips, rows) and the chat pane straight in
    the work area — no page head, no description line. The page script (resources/js/messenger-page.js) fills the rows
    and the bubbles from the JSON doors under /messages/x — the SAME doors as the phone (contract §2).
    ⓘ No inline script: words, door addresses and the signed-in person ride on data-* attributes of the root.
    ⓘ Everything is styled inside `.msgr` only (resources/css/messenger.css), so no look's chrome changes.
    ⓘ The call buttons only announce `messenger:call` {person_id, video}; the call screen is another part.
--}}
@php
    $msgrWords = trans('messaging::web');
    $msgrRoutes = [
        'threads' => route('messaging.x.threads'),
        'thread' => route('messaging.x.thread', ['thread' => '__T__']),
        'messages' => route('messaging.x.messages', ['thread' => '__T__']),
        'send' => route('messaging.x.send', ['thread' => '__T__']),
        'read' => route('messaging.x.read', ['thread' => '__T__']),
        'typing' => route('messaging.x.typing', ['thread' => '__T__']),
        'mute' => route('messaging.x.mute', ['thread' => '__T__']),
        'members' => route('messaging.x.members', ['thread' => '__T__']),
        'media' => route('messaging.x.media', ['thread' => '__T__']),
        'search' => route('messaging.x.search', ['thread' => '__T__']),
        'people' => route('messaging.x.people'),
        'direct' => route('messaging.x.direct'),
        'forward' => route('messaging.x.forward'),
        'papers' => route('messaging.x.papers'),
        'paper_find' => route('messaging.x.papers.find'),
        'delete' => route('messaging.x.message.delete', ['message' => 987654321]),
        'info' => route('messaging.x.message.info', ['message' => 987654321]),
        'page' => route('messaging.index'),
        'calls' => route('messaging.x.calls'),
        'call_people' => route('messaging.x.calls.people'),
    ];
    $msgrUser = auth()->user();
    $msgrMe = [
        'id' => (string) $msgrUser?->id,
        'name' => (string) $msgrUser?->name,
    ];
@endphp
<x-layouts.app :menu="$menu ?? []">
    <x-slot:title>{{ __('messaging::web.title') }}</x-slot:title>
    @push('scripts')
        @vite('resources/js/messenger-page.js')
    @endpush

    <div class="msgr" data-msgr data-live-off
         data-words="{{ json_encode($msgrWords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
         data-routes="{{ json_encode($msgrRoutes, JSON_UNESCAPED_SLASHES) }}"
         data-me="{{ json_encode($msgrMe, JSON_UNESCAPED_UNICODE) }}"
         data-open="{{ $open ?? '' }}"
         data-calls="{{ ($callsOn ?? false) ? '1' : '0' }}"
         data-meetings="{{ ($meetingsOn ?? false) ? '1' : '0' }}"
         data-locale="{{ app()->getLocale() }}"
         data-csrf="{{ csrf_token() }}">
        @include('messaging::partials.sprite')

        {{-- ── the list (left pane) ── --}}
        <section class="mlist" aria-label="{{ __('messaging::web.title') }}">
            <div class="mlh">
                <h1>{{ __('messaging::web.title') }}</h1>
                <button type="button" class="newb" data-act="new">
                    <svg aria-hidden="true"><use href="#mg-pencil"/></svg>{{ __('messaging::web.new_message') }}
                </button>
                @if ($callsOn ?? false)
                    <button type="button" class="newb" data-act="new-call" hidden>＋ {{ __('messaging::web.calls.new') }}</button>
                @endif
                @if ($meetingsOn ?? false)
                    <button type="button" class="newb" data-act="new-meeting">{{ __('messaging::meeting.new') }}</button>
                @endif
            </div>
            <label class="msrch">
                <svg aria-hidden="true"><use href="#mg-search"/></svg>
                <input type="search" data-list-q autocomplete="off" placeholder="{{ __('messaging::web.search_list') }}" aria-label="{{ __('messaging::web.search_list') }}">
            </label>
            <div class="mchips" role="tablist" data-msg-chips>
                <button type="button" role="tab" class="on" aria-selected="true" data-filter="all">{{ __('messaging::web.chip_all') }}</button>
                <button type="button" role="tab" aria-selected="false" data-filter="unread">{{ __('messaging::web.chip_unread') }} <b class="n" data-unread-total></b></button>
                <button type="button" role="tab" aria-selected="false" data-filter="groups">{{ __('messaging::web.chip_groups') }}</button>
                <button type="button" role="tab" aria-selected="false" data-act="calls">{{ __('messaging::web.calls.chip') }} <b class="n red" data-calls-new></b></button>
            </div>
            {{-- ⭐ the call list's own chips (sample VxwPoxm77whS6ToNqsyZPU tab ১); the first goes back to the messages --}}
            <div class="mchips" role="tablist" data-call-chips hidden>
                <button type="button" data-act="calls-x" aria-label="{{ __('messaging::web.calls.back') }}" title="{{ __('messaging::web.calls.back') }}">‹</button>
                <button type="button" role="tab" data-act="calls-kind" data-call-kind="all">{{ __('messaging::web.calls.all') }}</button>
                <button type="button" role="tab" data-act="calls-kind" data-call-kind="missed">{{ __('messaging::web.calls.missed') }} <b class="n red" data-calls-missed></b></button>
                <button type="button" role="tab" data-act="calls-kind" data-call-kind="in">{{ __('messaging::web.calls.in') }}</button>
                <button type="button" role="tab" data-act="calls-kind" data-call-kind="out">{{ __('messaging::web.calls.out') }}</button>
            </div>
            <div class="mrows" data-rows role="list"></div>
        </section>

        {{-- ── the chat (right pane) ── --}}
        <section class="mchat" data-chat>
            <div class="mempty" data-empty>
                <svg class="wall" aria-hidden="true"><rect width="100%" height="100%" fill="url(#mg-doodle)"/></svg>
                <p>{{ __('messaging::web.pick_chat') }}</p>
            </div>

            {{-- ⭐ one person's calls: totals and log (sample tab ১) — drawn by messenger-calls.js --}}
            <div class="mcalls" data-call-pane hidden></div>

            <div class="wh" data-head hidden>
                <button type="button" class="hb back" data-act="back" aria-label="{{ __('messaging::web.back') }}"><svg><use href="#mg-back"/></svg></button>
                <span class="av" data-head-av></span>
                <button type="button" class="who" data-act="info" aria-label="{{ __('messaging::web.group_info') }}">
                    <b data-head-title></b><small data-head-sub></small>
                </button>
                {{-- ⓘ calls only when the office LiveKit is set up (LIVEKIT_URL/KEY/SECRET in .env) — otherwise no call button
                     and no group-call badge at all (calls_off, [[LiveKitToken::configured()]]) --}}
                @if ($callsOn ?? false)
                    @unless ($meetingsOn ?? false)<span class="soon2" data-group-only hidden><svg aria-hidden="true"><use href="#mg-clock"/></svg>{{ __('messaging::web.group_calls_soon') }}</span>@endunless
                    <button type="button" class="hb" data-act="call-video" aria-label="{{ __('messaging::web.video_call') }}" title="{{ __('messaging::web.video_call') }}"><svg><use href="#mg-video"/></svg></button>
                    <button type="button" class="hb" data-act="call-voice" aria-label="{{ __('messaging::web.voice_call') }}" title="{{ __('messaging::web.voice_call') }}"><svg><use href="#mg-phone"/></svg></button>
                @endif
                <button type="button" class="hb" data-act="kebab" aria-label="{{ __('messaging::web.more') }}" aria-haspopup="menu" aria-expanded="false"><svg><use href="#mg-kebab"/></svg></button>
                <div class="km" data-kebab role="menu" hidden>
                    <button type="button" role="menuitem" data-act="k-search"><svg class="i" aria-hidden="true"><use href="#mg-search"/></svg>{{ __('messaging::web.menu_search') }}</button>
                    <button type="button" role="menuitem" data-act="k-media"><svg class="i" aria-hidden="true"><use href="#mg-image"/></svg>{{ __('messaging::web.menu_media') }}</button>
                    <button type="button" role="menuitem" data-act="k-mute"><svg class="i" aria-hidden="true"><use href="#mg-belloff" data-mute-icon/></svg><span data-mute-label>{{ __('messaging::web.menu_mute') }}</span></button>
                    <button type="button" role="menuitem" data-act="k-members" data-group-only><svg class="i" aria-hidden="true"><use href="#mg-users"/></svg>{{ __('messaging::web.menu_members') }}</button>
                </div>
            </div>

            <div class="cwrap" data-cwrap hidden>
                <svg class="wall" aria-hidden="true"><rect width="100%" height="100%" fill="url(#mg-doodle)"/></svg>
                <div class="cbody" data-scroll>
                    <div class="msgs" data-msgs role="log" aria-live="polite"></div>
                </div>
            </div>

            <div class="emo" data-emo role="dialog" aria-label="{{ __('messaging::web.emoji') }}" hidden>
                <div class="ecat" data-ecats>
                    <button type="button" data-cat="recent" aria-label="{{ __('messaging::web.emoji_cats.recent') }}"><svg><use href="#mg-clock"/></svg></button>
                    <button type="button" data-cat="smileys" aria-label="{{ __('messaging::web.emoji_cats.smileys') }}"><svg><use href="#mg-smile"/></svg></button>
                    <button type="button" data-cat="people" aria-label="{{ __('messaging::web.emoji_cats.people') }}"><svg><use href="#mg-users"/></svg></button>
                    <button type="button" data-cat="nature" aria-label="{{ __('messaging::web.emoji_cats.nature') }}"><svg><use href="#mg-leaf"/></svg></button>
                    <button type="button" data-cat="food" aria-label="{{ __('messaging::web.emoji_cats.food') }}"><svg><use href="#mg-cup"/></svg></button>
                    <button type="button" data-cat="activity" aria-label="{{ __('messaging::web.emoji_cats.activity') }}"><svg><use href="#mg-ball"/></svg></button>
                    <button type="button" data-cat="travel" aria-label="{{ __('messaging::web.emoji_cats.travel') }}"><svg><use href="#mg-car"/></svg></button>
                    <button type="button" data-cat="objects" aria-label="{{ __('messaging::web.emoji_cats.objects') }}"><svg><use href="#mg-bulb"/></svg></button>
                    <button type="button" data-cat="symbols" aria-label="{{ __('messaging::web.emoji_cats.symbols') }}"><svg><use href="#mg-heart"/></svg></button>
                    <button type="button" data-cat="flags" aria-label="{{ __('messaging::web.emoji_cats.flags') }}"><svg><use href="#mg-flag"/></svg></button>
                </div>
                <label class="esr">
                    <svg aria-hidden="true"><use href="#mg-search"/></svg>
                    <input type="search" data-emo-q autocomplete="off" placeholder="{{ __('messaging::web.emoji_search') }}" aria-label="{{ __('messaging::web.emoji_search') }}">
                </label>
                <div class="elb" data-emo-label></div>
                <div class="egrid" data-emo-grid></div>
            </div>

            <div class="rbar" data-replybar hidden>
                <div class="quote" data-reply-quote></div>
                <button type="button" class="hb" data-act="reply-x" aria-label="{{ __('messaging::web.close') }}"><svg><use href="#mg-x"/></svg></button>
            </div>
            <div class="stage" data-stage hidden></div>

            <div class="comp" data-comp hidden>
                <div class="atray" data-atray role="menu" hidden>
                    <button type="button" role="menuitem" data-act="a-file"><i class="c1"><svg aria-hidden="true"><use href="#mg-doc"/></svg></i>{{ __('messaging::web.att_file') }}</button>
                    <button type="button" role="menuitem" data-act="a-image"><i class="c2"><svg aria-hidden="true"><use href="#mg-image"/></svg></i>{{ __('messaging::web.att_image') }}</button>
                    <button type="button" role="menuitem" data-act="a-paper"><i class="c3"><svg aria-hidden="true"><use href="#mg-paper"/></svg></i>{{ __('messaging::web.att_paper') }}</button>
                    <button type="button" role="menuitem" data-act="a-camera"><i class="c4"><svg aria-hidden="true"><use href="#mg-camera"/></svg></i>{{ __('messaging::web.att_camera') }}</button>
                </div>
                <button type="button" class="hb" data-act="emoji" aria-label="{{ __('messaging::web.emoji') }}" aria-expanded="false"><svg><use href="#mg-smile" data-emo-icon/></svg></button>
                <button type="button" class="hb att" data-act="attach" aria-label="{{ __('messaging::web.attach') }}" aria-expanded="false"><svg><use href="#mg-clip"/></svg></button>
                <div class="cpill">
                    <textarea data-input rows="1" placeholder="{{ __('messaging::web.type_message') }}" aria-label="{{ __('messaging::web.type_message') }}"></textarea>
                    <div class="rec" data-rec hidden>
                        <i class="rdot" aria-hidden="true"></i>
                        <span>{{ __('messaging::web.recording') }}</span>
                        <b class="n" data-rec-time>0:00</b>
                        <span class="rlvl" data-rec-level aria-hidden="true"></span>
                        <button type="button" class="hb" data-act="rec-cancel" aria-label="{{ __('messaging::web.cancel_recording') }}" title="{{ __('messaging::web.cancel_recording') }}"><svg><use href="#mg-trash"/></svg></button>
                    </div>
                    <button type="button" class="dict" data-act="dictate" title="{{ __('messaging::web.dictate_title') }}" aria-label="{{ __('messaging::web.dictate') }}" hidden>
                        <svg aria-hidden="true"><use href="#mg-dictate"/></svg><span data-dict-label>{{ __('messaging::web.dictate') }}</span>
                    </button>
                    <div class="dmenu" data-dict-menu role="menu" hidden>
                        <b>{{ __('messaging::web.dictate_lang') }}</b>
                        <button type="button" role="menuitemradio" data-dict-lang="bn-BD">{{ __('messaging::web.lang_bn') }}</button>
                        <button type="button" role="menuitemradio" data-dict-lang="en-US">{{ __('messaging::web.lang_en') }}</button>
                    </div>
                </div>
                <button type="button" class="hb" data-act="voice" aria-label="{{ __('messaging::web.voice_message') }}" title="{{ __('messaging::web.voice_message') }}"><svg><use href="#mg-micf"/></svg></button>
                <button type="button" class="mic" data-act="send" aria-label="{{ __('messaging::web.send') }}" title="{{ __('messaging::web.send_hint') }}" disabled><svg><use href="#mg-send"/></svg></button>
                <input type="file" data-pick="file" accept=".pdf,.xlsx,.xls,.docx,.doc,.csv,.txt,.zip" multiple hidden>
                <input type="file" data-pick="image" accept="image/jpeg,image/png,image/webp" multiple hidden>
                <input type="file" data-pick="camera" accept="image/*" capture="environment" hidden>
            </div>
        </section>

        {{-- ── the info drawer (group: members + shared papers; ⋮ → search / media) ── --}}
        <aside class="minfo" data-info hidden>
            <div class="mih">
                <button type="button" class="hb" data-act="info-x" aria-label="{{ __('messaging::web.close') }}"><svg><use href="#mg-x"/></svg></button>
                <h3 data-info-title></h3>
            </div>
            <div class="mibody" data-info-body></div>
        </aside>

        <div data-dialog-host></div>
        <div class="mtoast" data-toast role="status" aria-live="polite" hidden></div>
    </div>
</x-layouts.app>

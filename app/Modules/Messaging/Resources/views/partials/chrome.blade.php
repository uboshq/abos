{{--
    বার্তার খোলস-অংশ — প্রতিটা ABOS পাতার শেষে একবার (বার্তার ওয়েব নমুনা M1UddRxK v7, ট্যাব গ আর ঘ):
      · কল আসার পপআপ — যে পাতাতেই থাকুন, উপরে ডানে
      · ব্রাউজারের কলের পর্দা — একজনের সাথে ভয়েস আর ভিডিও (LiveKit, অফিসের নিজের সার্ভারে)
      · "বার্তায় পাঠান" — কাকে পাঠাবেন, তার পপআপ
    ⓘ যুক্তি [[messenger-call.js]] আর [[messenger-share.js]]-এ; শব্দ আর ঠিকানা এখান থেকে data-*-এ যায়।
    ⛔ কেবল তিন ABOS রূপে আর `messaging.use` চাবিতে ([[MessengerChrome::on()]]) — লেআউট এটা অন্য রূপে বসায়ই না।
--}}
@php
    $msgrUrls = [
        'index' => route('messaging.index'),
        'unread' => route('messaging.x.unread'),
        'calls' => route('messaging.x.calls'),
        'start' => route('messaging.x.calls.start'),
        'answer' => route('messaging.x.calls.answer', ['call' => '__CALL__']),
        'decline' => route('messaging.x.calls.decline', ['call' => '__CALL__']),
        'end' => route('messaging.x.calls.end', ['call' => '__CALL__']),
        'people' => route('messaging.x.people'),
        'threads' => route('messaging.x.threads'),
        'papers' => route('messaging.x.papers'),
        'direct' => route('messaging.x.direct'),
    ];
    $msgrWords = trans('messaging::call');
@endphp
<div class="msgr" data-msgr-root
     data-csrf="{{ csrf_token() }}"
     data-me="{{ auth()->id() }}"
     data-urls="{{ json_encode($msgrUrls, JSON_UNESCAPED_SLASHES) }}"
     data-words="{{ json_encode($msgrWords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}">

    {{-- ── কল আসছে — যে পাতাতেই থাকুন (নমুনা: "কল আসছে — যে পাতাতেই থাকুন") ── --}}
    <div class="msgr-inc" data-msgr-incoming role="dialog" aria-label="{{ __('messaging::call.incoming.title') }}" hidden>
        <div class="msgr-inc__r">
            <span class="msgr-av msgr-av--56" data-msgr-inc-avatar aria-hidden="true"></span>
            <div class="msgr-inc__tx">
                <b data-msgr-inc-name></b>
                <small>
                    <span data-msgr-inc-icon="voice"><x-ui.icon name="phone" :size="15" /></span>
                    <span data-msgr-inc-icon="video" hidden><x-ui.icon name="video" :size="15" /></span>
                    <span data-msgr-inc-what></span>
                </small>
            </div>
        </div>
        <div class="msgr-inc__bt">
            <button type="button" class="msgr-inc__no" data-msgr-decline>
                <x-ui.icon name="phone_fill" :size="20" />{{ __('messaging::call.incoming.decline') }}
            </button>
            <button type="button" class="msgr-inc__yes" data-msgr-answer>
                <x-ui.icon name="phone_fill" :size="20" />{{ __('messaging::call.incoming.answer') }}
            </button>
        </div>
    </div>

    {{-- ── কলের পর্দা — কাজের জায়গাজুড়ে, খোলস চারপাশে থাকে (নমুনা: "ভিডিও কল / ভয়েস কল — একজনের সাথে") ── --}}
    <section class="msgr-call" data-msgr-call data-mode="voice" data-phase="ringing"
             role="dialog" aria-modal="true" aria-label="{{ __('messaging::call.screen.title') }}" hidden>

        {{-- ভিডিও: সামনের মানুষ বড়, নিজে নিচে ডানে, বোতামের বার নিচে মাঝখানে --}}
        <div class="msgr-vcall" data-msgr-stage="video">
            <svg class="msgr-vcall__ph" viewBox="0 0 160 90" preserveAspectRatio="xMidYMax meet" aria-hidden="true" data-msgr-remote-ph>
                <circle class="msgr-ph" cx="80" cy="50" r="9"/>
                <path class="msgr-ph" d="M60 92c2-14 10-21 20-21s18 7 20 21z"/>
            </svg>
            <video class="msgr-vcall__remote" data-msgr-remote-video autoplay playsinline hidden></video>
            <div class="msgr-vct">
                <span class="msgr-av msgr-av--48" data-msgr-peer-avatar aria-hidden="true"></span>
                <div><b data-msgr-peer-name></b><small data-msgr-sub></small></div>
            </div>
            <div class="msgr-vcname">
                <span data-msgr-remote-mic="on"><x-ui.icon name="mic" :size="15" /></span>
                <span data-msgr-remote-mic="off" hidden><x-ui.icon name="mic_off" :size="15" /></span>
                <span data-msgr-peer-name></span>
            </div>
            <div class="msgr-selfv">
                <svg viewBox="0 0 160 90" preserveAspectRatio="xMidYMax slice" aria-hidden="true">
                    <circle class="msgr-ph" cx="80" cy="40" r="16"/>
                    <path class="msgr-ph" d="M44 92c3-23 18-35 36-35s33 12 36 35z"/>
                </svg>
                <video data-msgr-self-video autoplay playsinline muted hidden></video>
                <span>{{ __('messaging::call.screen.you') }}</span>
            </div>
            <div class="msgr-vbar">
                <button type="button" class="msgr-cc" data-msgr-mute aria-pressed="false" aria-label="{{ __('messaging::call.screen.mute') }}" title="{{ __('messaging::call.screen.mute') }}">
                    <x-ui.icon name="mic_off" :size="25" />
                </button>
                <button type="button" class="msgr-cc" data-msgr-camera aria-pressed="false" aria-label="{{ __('messaging::call.screen.camera_off') }}" title="{{ __('messaging::call.screen.camera_off') }}">
                    <x-ui.icon name="video_off" :size="25" />
                </button>
                <button type="button" class="msgr-cc" data-msgr-screen aria-pressed="false" aria-label="{{ __('messaging::call.screen.screen_share') }}" title="{{ __('messaging::call.screen.screen_share') }}">
                    <x-ui.icon name="screen_share" :size="25" />
                </button>
                <button type="button" class="msgr-cc msgr-cc--no" data-msgr-end aria-label="{{ __('messaging::call.screen.end') }}" title="{{ __('messaging::call.screen.end') }}">
                    <x-ui.icon name="phone_fill" :size="25" />
                </button>
            </div>
        </div>

        {{-- ভয়েস: বড় ছবি, নাম, সময়; নিচে মিউট · স্পিকার ▾ · ভিডিওতে যান · কল শেষ — প্রতিটার নিচে নাম --}}
        <div class="msgr-vvc" data-msgr-stage="voice">
            <span class="msgr-av msgr-av--180" data-msgr-peer-avatar aria-hidden="true"></span>
            <h3 data-msgr-peer-name></h3>
            <div class="msgr-vvc__sub" data-msgr-sub></div>
            <div class="msgr-vvbar">
                <div class="msgr-cb">
                    <button type="button" class="msgr-cc" data-msgr-mute aria-pressed="false" aria-label="{{ __('messaging::call.screen.mute') }}">
                        <x-ui.icon name="mic_off" :size="27" />
                    </button>
                    <span data-msgr-mute-label>{{ __('messaging::call.screen.mute') }}</span>
                </div>
                <div class="msgr-cb msgr-cb--dev">
                    <button type="button" class="msgr-cc" data-msgr-speaker aria-haspopup="menu" aria-expanded="false" aria-label="{{ __('messaging::call.screen.speaker') }}">
                        <x-ui.icon name="speaker" :size="27" />
                    </button>
                    <span class="msgr-dv" data-msgr-speaker-label>{{ __('messaging::call.screen.speaker_is', ['name' => __('messaging::call.screen.speaker_default')]) }}</span>
                    <div class="msgr-devs" role="menu" data-msgr-devices hidden></div>
                </div>
                <div class="msgr-cb">
                    <button type="button" class="msgr-cc" data-msgr-to-video aria-label="{{ __('messaging::call.screen.to_video') }}">
                        <x-ui.icon name="video" :size="27" />
                    </button>
                    <span>{{ __('messaging::call.screen.to_video') }}</span>
                </div>
                <div class="msgr-cb">
                    <button type="button" class="msgr-cc msgr-cc--no" data-msgr-end aria-label="{{ __('messaging::call.screen.end') }}">
                        <x-ui.icon name="phone_fill" :size="27" />
                    </button>
                    <span>{{ __('messaging::call.screen.end') }}</span>
                </div>
            </div>
        </div>

        {{-- সামনের মানুষের শব্দ — LiveKit নিজের <audio> এখানে বসায় --}}
        <div data-msgr-audio hidden></div>
    </section>

    {{-- ── বার্তায় পাঠান — কাকে পাঠাবেন (নমুনা ট্যাব ঘ, ছবি ২) ── --}}
    <div class="msgr-share" data-msgr-share-dialog hidden>
        <div class="msgr-share__scrim" data-msgr-share-close></div>
        <div class="msgr-share__dlg" role="dialog" aria-modal="true" aria-labelledby="msgr-share-title">
            <div class="msgr-share__tr">
                <h4 id="msgr-share-title">{{ __('messaging::call.share.title') }}</h4>
                <button type="button" class="msgr-share__x" data-msgr-share-close aria-label="{{ __('messaging::call.share.close') }}" title="{{ __('messaging::call.share.close') }}">
                    <x-ui.icon name="x" :size="20" />
                </button>
            </div>
            <div class="msgr-share__pc">
                <span class="msgr-share__pic"><x-ui.icon name="paper" :size="21" /></span>
                <div class="min-w-0"><b data-msgr-share-paper></b><small data-msgr-share-meta></small></div>
            </div>
            <label class="msgr-share__srch">
                <x-ui.icon name="search" :size="18" />
                <input type="search" autocomplete="off" data-msgr-share-q
                       placeholder="{{ __('messaging::call.share.search') }}" aria-label="{{ __('messaging::call.share.search') }}">
            </label>
            <div class="msgr-share__lbl" data-msgr-share-recent>{{ __('messaging::call.share.recent') }}</div>
            <div class="msgr-share__list" role="listbox" aria-multiselectable="true" aria-label="{{ __('messaging::call.share.search') }}" data-msgr-share-list></div>
            <p class="msgr-share__empty" data-msgr-share-empty hidden>{{ __('messaging::call.share.nobody') }}</p>
            <input type="text" class="msgr-share__note" maxlength="4000" data-msgr-share-note
                   placeholder="{{ __('messaging::call.share.note') }}" aria-label="{{ __('messaging::call.share.note') }}">
            <div class="msgr-share__fb">
                <button type="button" class="msgr-share__cn" data-msgr-share-close>{{ __('messaging::call.share.cancel') }}</button>
                <button type="button" class="msgr-share__go" data-msgr-share-send disabled>
                    <x-ui.icon name="send" :size="18" /><span data-msgr-share-send-label></span>
                </button>
            </div>
        </div>
    </div>

    {{-- ⓘ JS-এর আঁকা সারির চিহ্ন — সেটের একই আঁকা, নকল নয় --}}
    <template data-msgr-icon="lock"><x-ui.icon name="lock" :size="13" /></template>
    <template data-msgr-icon="check"><x-ui.icon name="check" :size="14" /></template>
    <template data-msgr-icon="group"><x-ui.icon name="people" :size="20" /></template>

    <div class="msgr-toast" data-msgr-toast role="status" aria-live="polite" hidden></div>
</div>

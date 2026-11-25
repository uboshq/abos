{{--
    ⭐ A meeting (or a group call) — sample VxwPoxm77whS6ToNqsyZPU tab 3. Drawn by resources/js/meeting-page.js from the
    server's state; no inline script. ⛔ The buttons only ask — every power is checked on the server ([[MeetingService]]).
--}}
@php
    $meetBase = ['meeting' => $meeting->public_id];
    $meetUrls = ['state' => route('messaging.x.meetings.state', $meetBase), 'people' => route('messaging.x.people'),
        'messages' => route('messaging.x.messages', ['thread' => '__T__']), 'send' => route('messaging.x.send', ['thread' => '__T__']),
        'page' => route('messaging.meeting', $meetBase), 'back' => route('messaging.index')];
    foreach (['join', 'renew', 'leave', 'end', 'invite', 'lobby', 'remove', 'role', 'set', 'mute', 'share', 'stop_share'] as $meetDoor) {
        $meetUrls[$meetDoor] = route('messaging.x.meetings.'.$meetDoor, $meetBase);
    }
    $meetWords = trans('messaging::meeting');
@endphp
<x-layouts.app :menu="$menu ?? []">
    <x-slot:title>{{ $meeting->title }}</x-slot:title>
    @push('scripts')
        @vite('resources/js/meeting-page.js')
    @endpush

    <div class="mmeet" data-meeting data-me="{{ auth()->id() }}" data-csrf="{{ csrf_token() }}" data-title="{{ $meeting->title }}" data-video="{{ $meeting->video ? '1' : '0' }}"
         data-urls="{{ json_encode($meetUrls, JSON_UNESCAPED_SLASHES) }}"
         data-words="{{ json_encode($meetWords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}">
        <div class="mmeet-door" data-mm-door>
            <h1>{{ $meeting->title }}</h1>
            <p data-mm-say role="status" aria-live="polite"></p>
            <button type="button" class="mmeet-go" data-mm-act="join">{{ __('messaging::meeting.join') }}</button>
        </div>

        <div class="mmeet-stage" data-mm-stage hidden>
            <div class="mmeet-head">
                <b>{{ $meeting->title }}</b>
                <span class="mmeet-pill num" data-mm-clock></span>
                <span class="mmeet-pill num" data-mm-count></span>
                <span class="mmeet-sp"></span>
                <button type="button" class="mmeet-pill" data-mm-act="link">{{ __('messaging::meeting.link') }}</button>
                <button type="button" class="mmeet-pill" data-mm-act="lock" data-mm-lock hidden>{{ __('messaging::meeting.lock') }}</button>
            </div>
            <div class="mmeet-body" data-mm-body>
                <div class="mmeet-slide" data-mm-slide hidden>
                    <header><span data-mm-share-who></span>
                        <button type="button" class="mmeet-mini" data-mm-act="window" data-mm-window hidden>{{ __('messaging::meeting.other_window') }}</button>
                        <button type="button" class="mmeet-mini" data-mm-act="stop-share" data-mm-stop hidden>{{ __('messaging::meeting.stop_share') }}</button>
                    </header>
                    <video data-mm-share-video autoplay playsinline muted></video>
                </div>
                <div class="mmeet-tiles" data-mm-tiles></div>
                <aside class="mmeet-side" data-mm-side hidden><h2 data-mm-side-title></h2><div data-mm-side-body></div></aside>
            </div>
            <div class="mmeet-foot" data-mm-foot></div>
            <div data-mm-audio hidden></div>
        </div>
        <p class="mmeet-toast" data-mm-toast role="status" aria-live="polite" hidden></p>
    </div>
</x-layouts.app>

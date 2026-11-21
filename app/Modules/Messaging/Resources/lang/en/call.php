<?php

declare(strict_types=1);

/*
 * Top-bar chat, incoming-call popup, the browser call screen and "Send in a message" — web sample M1UddRxK v7.
 * ⓘ The same keys as bn/call.php.
 */
return [
    'topbar' => [
        'title' => 'Messages',
    ],

    'incoming' => [
        'title' => 'Incoming call',
        'voice' => 'ABOS voice call…',
        'video' => 'ABOS video call…',
        'answer' => 'Answer',
        'decline' => 'Decline',
    ],

    'screen' => [
        'title' => 'Call',
        'voice' => 'Voice call',
        'video' => 'Video call',
        'ringing' => 'Ringing…',
        'connecting' => 'Connecting…',
        'you' => 'You',
        'mute' => 'Mute',
        'unmute' => 'Unmute',
        'camera_off' => 'Camera off',
        'camera_on' => 'Camera on',
        'screen_share' => 'Share screen',
        'screen_stop' => 'Stop sharing',
        'speaker' => 'Speaker and devices',
        'speaker_is' => 'Speaker: :name',
        'speaker_default' => 'This computer',
        'to_video' => 'Switch to video',
        'end' => 'End call',
    ],

    'toast' => [
        'ended' => 'Call ended',
        'declined' => 'Call declined',
        'missed' => 'No answer',
        'no_media' => 'Microphone or camera did not start — check the browser permission.',
        'failed' => 'The call did not connect — try again.',
        'busy' => 'Another call is on.',
    ],

    'share' => [
        'menu' => 'Send in a message',
        'title' => 'Send in a message',
        'close' => 'Close',
        'search' => 'Send to whom? Search a name',
        'recent' => 'Recent',
        'no_open' => 'No right to open this paper — will see only the number',
        'note' => 'Add a note (optional)',
        'cancel' => 'Cancel',
        'send' => 'Send · :count',
        'sent' => 'Sent · :count',
        'nobody' => 'Nobody found',
        'failed' => 'Could not send — try again.',
    ],
];

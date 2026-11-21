<?php

declare(strict_types=1);

return [
    'title' => 'Messages',
    'group_subtitle' => 'Group · :count people',
    'file_required' => 'The file did not arrive — send it again.',
    'bad_target' => 'Could not tell whom to send it to.',
    'too_big' => 'The file is too big — :mb MB at most.',
    'bad_type' => 'This kind of file cannot be sent.',
    'deleted' => 'This message was deleted',
    'client_id_used' => 'This message id was already used in another conversation.',
    'kind' => [
        'image' => 'Photo',
        'file' => 'File',
        'voice' => 'Voice',
        'location' => 'Location',
    ],
    'system' => [
        'members_added' => ':actor added :names to the group',
        'group_created' => ':actor created the group ":title"',
    ],
    'refused' => [
        'forbidden' => 'You are not in this conversation.',
        'may_not_talk' => 'Your role may not message or call this person.',
        'group_create_denied' => 'You may not create groups.',
        'paper_denied' => 'You may not open this paper, so you cannot send it.',
        'delete_not_allowed' => 'Only your own message, within an hour of sending, can be deleted for everyone.',
        'call_over' => 'This call is no longer ringing — it is over.',
        'calls_off' => 'Calls are not set up yet.',
    ],
    'call' => [
        'incoming_voice' => 'ABOS voice call…',
        'incoming_video' => 'ABOS video call…',
        'missed' => 'Missed call — :name',
    ],
];

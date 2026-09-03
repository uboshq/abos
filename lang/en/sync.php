<?php

declare(strict_types=1);

/**
 * Offline sync, in English.
 *
 * The Bangla file is the one that reaches a phone — the app shows Bangla
 * server sentences and swallows English framework noise on purpose. This
 * file exists because every key must say the same thing in both languages
 * (BothLanguagesSayTheSameThingTest), and because somebody reading a log or
 * a test failure in English should read the same meaning.
 */
return [
    'change_needs_id' => 'This entry carries no identifier of its own, so it could not be sent. Update the app and try again.',
    'change_needs_entity_type' => 'What kind of entry this is could not be read. Update the app and try again.',
    'change_needs_payload' => 'The entry arrived empty — there is nothing inside it.',
    'payload_is_not_readable' => 'The entry could not be read; its contents are damaged. It has to be written again.',
    'unknown_operation' => 'That is not something that can be done (:operation) — a new entry or a correction, those two only.',
    'update_needs_entity_id' => 'Which entry is being corrected has not been said.',

    'unknown_entity_type' => 'The server does not know this kind of entry (:type). The app may need updating.',

    'not_allowed_offline' => 'Only orders can be written without a network (not :type). Come back into coverage and do this again.',
    'push_needs_permission' => 'You are not allowed to send this kind of entry. Ask the office to grant it, then try again — nothing was recorded.',

    'refused_without_reason' => 'The server did not accept this, and did not say why. Tell the office.',

    'device_unknown' => 'This handset is not registered yet. Sign out once and sign in again.',
    'module_unknown' => 'This part (:module) cannot be synchronised.',
];

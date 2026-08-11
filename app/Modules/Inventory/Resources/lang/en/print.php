<?php

declare(strict_types=1);

/*
 * Words that appear only on the transfer paper.
 *
 * Two signatures, and they must be two different people. One warehouse
 * sends, another receives — the same separation the permissions
 * enforce (transfer.create against transfer.receive). One person doing
 * both could write "sent, arrived" and move the goods somewhere else
 * entirely, with the paperwork agreeing.
 */

return [
    'dispatched_by' => 'Dispatched by',
    'received_by' => 'Received by',

    /*
     * Stamped across a cancelled transfer.
     *
     * It can still be printed — somebody asks what happened to that
     * consignment and the answer has to be showable. But a cancelled
     * transfer that looks identical to a live one is a piece of paper
     * somebody can carry goods out of a gate with.
     */
    'cancelled' => 'CANCELLED',
];

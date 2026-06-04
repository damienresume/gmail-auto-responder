<?php

use Illuminate\Support\Facades\Schedule;

// -------------------------------------------------------------------------
// Gmail Polling Schedule
// -------------------------------------------------------------------------
// WHY every 30 seconds: This is the fallback for when Pub/Sub is not
// configured. 30 seconds keeps responsiveness high (new emails appear
// within half a minute) while staying well within Gmail API quotas
// (2 calls per account per minute vs. the 250 queries/sec quota).
// With Pub/Sub enabled, this schedule is unnecessary but harmless —
// FetchNewEmailsJob uses incremental sync, so polling an already-synced
// account returns zero new messages instantly.
//
// WHY withoutOverlapping: If a poll takes longer than 30 seconds (slow
// API, many accounts), we don't want a second poll to start and create
// duplicate jobs. withoutOverlapping ensures only one instance runs.
//
// WHY onOneServer: In a multi-server deployment, only one server should
// run the scheduler. Without this, every server would poll every account,
// multiplying API calls unnecessarily.
// -------------------------------------------------------------------------
Schedule::command('gmail:poll')
    ->everyThirtySeconds()
    ->withoutOverlapping()
    ->onOneServer();

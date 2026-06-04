<?php

use Illuminate\Support\Facades\Schedule;

// -------------------------------------------------------------------------
// Gmail Polling Schedule
// -------------------------------------------------------------------------
// WHY every 60 seconds: This is the fallback for when Pub/Sub is not
// configured. 60 seconds balances responsiveness (new emails appear within
// a minute) against Gmail API quota consumption (1 API call per account
// per minute). With Pub/Sub enabled, this schedule is unnecessary but
// harmless — FetchNewEmailsJob uses incremental sync, so polling an
// already-synced account returns zero new messages instantly.
//
// WHY withoutOverlapping: If a poll takes longer than 60 seconds (slow
// API, many accounts), we don't want a second poll to start and create
// duplicate jobs. withoutOverlapping ensures only one instance runs.
//
// WHY onOneServer: In a multi-server deployment, only one server should
// run the scheduler. Without this, every server would poll every account,
// multiplying API calls unnecessarily.
// -------------------------------------------------------------------------
Schedule::command('gmail:poll')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

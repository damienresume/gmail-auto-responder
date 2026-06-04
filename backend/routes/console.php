<?php

use Illuminate\Support\Facades\Schedule;

// -------------------------------------------------------------------------
// Gmail Polling Schedule
// -------------------------------------------------------------------------
// WHY every 15 seconds: Keeps new-email detection fast — incoming emails
// appear in the dashboard within 15 seconds of landing in Gmail. This is
// well within Gmail API quotas (4 calls per account per minute vs. the
// 250 queries/sec quota). FetchNewEmailsJob uses incremental sync via
// history.list, so each poll for an already-synced account is a single
// lightweight API call that returns zero results instantly.
// With Pub/Sub enabled, this schedule is unnecessary but harmless.
//
// WHY withoutOverlapping: If a poll takes longer than 15 seconds (slow
// API, many accounts), we don't want a second poll to start and create
// duplicate jobs. withoutOverlapping ensures only one instance runs.
//
// WHY onOneServer: In a multi-server deployment, only one server should
// run the scheduler. Without this, every server would poll every account,
// multiplying API calls unnecessarily.
// -------------------------------------------------------------------------
Schedule::command('gmail:poll')
    ->everyFifteenSeconds()
    ->withoutOverlapping()
    ->onOneServer();

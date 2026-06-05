<?php

namespace App\Console\Commands;

use App\Jobs\FetchNewEmailsJob;
use App\Models\GmailAccount;
use Illuminate\Console\Command;

/**
 * PollGmailAccounts
 *
 * PURPOSE:
 * Polls all active Gmail accounts for new emails by dispatching
 * FetchNewEmailsJob for each account. This is the fallback mechanism
 * when Google Cloud Pub/Sub is not configured.
 *
 * WHY polling as a fallback:
 * Pub/Sub push notifications are the primary delivery method (real-time,
 * efficient). But Pub/Sub requires Google Cloud project setup, topic/
 * subscription configuration, and a publicly accessible webhook URL.
 * For local development and environments without Pub/Sub, this polling
 * command checks for new emails on a schedule.
 *
 * HOW it works:
 * The Laravel scheduler (defined in routes/console.php) runs this
 * command every 15 seconds. For each active Gmail account, it dispatches
 * a FetchNewEmailsJob to the gmail-ingest queue. Horizon workers pick
 * up the jobs and call the Gmail API to check for new messages.
 *
 * WHY dispatch jobs instead of fetching inline:
 * If we fetched emails directly in this command, a slow Gmail API call
 * for one account would block all other accounts. By dispatching jobs,
 * each account is processed independently and in parallel by Horizon
 * workers. A rate-limited account doesn't slow down others.
 */
class PollGmailAccounts extends Command
{
    protected $signature = 'gmail:poll';
    protected $description = 'Poll all active Gmail accounts for new emails';

    public function handle(): int
    {
        $accounts = GmailAccount::active()->get();

        if ($accounts->isEmpty()) {
            $this->info('No active Gmail accounts to poll.');
            return self::SUCCESS;
        }

        $this->info("Polling {$accounts->count()} active Gmail account(s)...");

        foreach ($accounts as $account) {
            FetchNewEmailsJob::dispatch($account);
            $this->line("  Dispatched fetch job for: {$account->gmail_email}");
        }

        $this->info('All poll jobs dispatched.');
        return self::SUCCESS;
    }
}

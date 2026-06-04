<?php

namespace App\Jobs;

use App\Models\EmailThread;
use App\Services\Llm\LlmServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ClassifyEmailJob
 *
 * PURPOSE:
 * Takes an email thread and sends it to the LLM for classification into
 * one of four categories: interested, not_interested, meeting_request, or
 * unclear. This is the second step in the pipeline:
 *   FetchNewEmailsJob -> ClassifyEmailJob -> GenerateDraftJob
 *
 * WHY a separate job from FetchNewEmailsJob:
 *   - Different failure domains: Gmail API failures (network, auth) are
 *     unrelated to LLM failures (model down, bad response). Separate jobs
 *     mean a Groq outage doesn't block email fetching, and a Gmail rate
 *     limit doesn't block classification of already-fetched emails.
 *
 *   - Different queues: Email fetching runs on 'gmail-ingest' (high priority,
 *     3 workers). Classification runs on 'classification' (medium priority,
 *     2 workers). This separation prevents a flood of LLM calls from
 *     starving the email fetching pipeline.
 *
 *   - Different retry strategies: Gmail errors need fast retries (rate
 *     limits clear quickly). LLM errors may need longer backoffs (model
 *     loading, API throttling). Each job can tune its own retry behavior.
 *
 * HOW it works:
 *   1. Loads the thread's latest inbound message body.
 *   2. Calls LlmServiceInterface::classifyEmail() (resolved by the
 *      container to Groq, Ollama, or Stub based on .env).
 *   3. Updates the thread with the classification result.
 *   4. If the classification warrants a reply (interested, meeting_request,
 *      or unclear), dispatches GenerateDraftJob.
 *
 * QUEUE: 'classification', medium priority, 2 workers.
 */
class ClassifyEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [15, 60, 180];

    public function __construct(
        private readonly EmailThread $thread,
    ) {
        $this->onQueue('classification');
    }

    /**
     * WHY type-hinting LlmServiceInterface in handle():
     * Laravel's container automatically injects the bound implementation
     * when the job is executed. The job doesn't know or care whether it's
     * talking to Groq, Ollama, or Stub. This is the Dependency Inversion
     * Principle: the job depends on an abstraction (interface),
     * not a concrete class.
     */
    public function handle(LlmServiceInterface $llm): void
    {
        // Guard: skip if already classified. This handles the case where
        // a duplicate Pub/Sub notification caused FetchNewEmailsJob to
        // dispatch this job twice for the same thread.
        if ($this->thread->isClassified()) {
            Log::info('Thread already classified, skipping', [
                'thread_id' => $this->thread->id,
            ]);
            return;
        }

        // Get the latest inbound message for classification.
        // We classify based on the most recent message because it has the
        // most current context (a thread might start as "interested" but
        // the latest message says "never mind").
        $latestMessage = $this->thread->messages()
            ->where('direction', 'inbound')
            ->latest('received_at')
            ->first();

        if (!$latestMessage) {
            Log::warning('No inbound messages found for classification', [
                'thread_id' => $this->thread->id,
            ]);
            return;
        }

        Log::info('Classifying email thread', [
            'thread_id' => $this->thread->id,
            'subject' => $this->thread->subject,
        ]);

        // Call the LLM. The interface hides which provider is running.
        // The DTO returned is pre-validated by the factory method.
        $result = $llm->classifyEmail(
            subject: $this->thread->subject,
            body: $latestMessage->body_text ?? '',
            fromEmail: $this->thread->from_email,
        );

        // Update the thread with classification data in one query.
        // toArray() maps DTO properties to database column names.
        $this->thread->update($result->toArray());

        Log::info('Email classified', [
            'thread_id' => $this->thread->id,
            'classification' => $result->classification,
            'confidence' => $result->confidence,
        ]);

        // Dispatch draft generation if the classification warrants a reply.
        // 'not_interested' emails stop here, no draft is generated.
        // This decision logic lives on the model (shouldGenerateDraft) so
        // it can be reused if we add manual reclassification later.
        if ($this->thread->shouldGenerateDraft()) {
            GenerateDraftJob::dispatch($this->thread);
        }
    }
}

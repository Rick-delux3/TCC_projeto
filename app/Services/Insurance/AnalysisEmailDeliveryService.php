<?php

namespace App\Services\Insurance;

use App\Exceptions\ObsoleteInsuranceAnalysisAttempt;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class AnalysisEmailDeliveryService
{
    public function send(InsuranceAnalysisBatch $batch, string $attemptId, string $body, array $attachments): void
    {
        Cache::lock('analysis-email-delivery:'.$batch->id.':'.hash('sha256', $attemptId), 210)
            ->block(5, function () use ($batch, $attemptId, $body, $attachments): void {
                $prepared = app(AnalysisResultPreparationService::class)->prepare($batch, $attemptId);
                $failure = null;

                foreach ($prepared['recipients']['to'] as $recipient) {
                    $shouldSend = InsuranceAnalysisAttempt::runBatch($batch, $attemptId, function () use ($batch, $attemptId, $recipient): bool {
                        if ($this->sent($batch, $attemptId, $recipient)) {
                            return false;
                        }
                        $this->record($batch, $attemptId, $recipient, 'email_recipient_attempt');

                        return true;
                    });
                    if (! $shouldSend) {
                        continue;
                    }

                    try {
                        $sent = Mail::raw($body, function (Message $message) use ($recipient, $attachments, $prepared): void {
                            $message->to($recipient)->subject($prepared['is_reanalysis']
                                ? 'Resultado da sua reanálise de Seguro Fiança'
                                : 'Resultado da sua análise de Seguro Fiança');
                            foreach ($attachments as $attachment) {
                                $message->attach($attachment['path'], ['as' => $attachment['name'], 'mime' => 'application/pdf']);
                            }
                        });
                        if ($sent === null) {
                            throw new RuntimeException('O transporte de e-mail não confirmou o envio.');
                        }
                        InsuranceAnalysisAttempt::runBatch($batch, $attemptId, function () use ($batch, $attemptId, $recipient, $sent): void {
                            $this->record($batch, $attemptId, $recipient, 'email_recipient_sent', ['message_id' => $sent->getMessageId()]);
                        });
                    } catch (ObsoleteInsuranceAnalysisAttempt $obsolete) {
                        throw $obsolete;
                    } catch (Throwable $exception) {
                        InsuranceAnalysisAttempt::runBatch($batch, $attemptId, function () use ($batch, $attemptId, $recipient, $exception): void {
                            $this->record($batch, $attemptId, $recipient, 'email_recipient_retry', ['exception' => $exception::class]);
                        });
                        $failure = $exception;
                    }
                }

                if ($failure) {
                    throw new RuntimeException('Existem destinatários sem confirmação de envio.', previous: $failure);
                }
            });
    }

    public function allSent(InsuranceAnalysisBatch $batch, string $attemptId): bool
    {
        $recipients = $this->preparedRecipients($batch, $attemptId);
        $sent = $this->events($batch, $attemptId)->where('event_type', 'email_recipient_sent')->get()
            ->pluck('payload.recipient')->all();

        return $recipients !== [] && array_diff($recipients, $sent) === [];
    }

    public function failRemaining(InsuranceAnalysisBatch $batch, string $attemptId): void
    {
        foreach ($this->preparedRecipients($batch, $attemptId) as $recipient) {
            if ($this->sent($batch, $attemptId, $recipient)) {
                continue;
            }
            $events = $this->events($batch, $attemptId)->where('payload->recipient', $recipient);
            $latestFailure = (clone $events)->where('event_type', 'email_recipient_failed')->max('id');
            $latestAttempt = (clone $events)->where('event_type', 'email_recipient_attempt')->max('id');
            if (! $latestFailure || $latestAttempt > $latestFailure) {
                $this->record($batch, $attemptId, $recipient, 'email_recipient_failed');
            }
        }
    }

    /** @return list<string> */
    private function preparedRecipients(InsuranceAnalysisBatch $batch, string $attemptId): array
    {
        return data_get($this->events($batch, $attemptId)->where('event_type', 'result_prepared')->first()?->payload, 'prepared.recipients.to', []);
    }

    private function sent(InsuranceAnalysisBatch $batch, string $attemptId, string $recipient): bool
    {
        return $this->events($batch, $attemptId)->where('event_type', 'email_recipient_sent')
            ->where('payload->recipient', $recipient)->exists();
    }

    private function record(InsuranceAnalysisBatch $batch, string $attemptId, string $recipient, string $type, array $extra = []): void
    {
        $batch->analyses()->orderBy('id')->firstOrFail()->events()->create([
            'event_type' => $type,
            'payload' => array_merge([
                'attempt_id' => $attemptId,
                'recipient' => $recipient,
                'recorded_at' => now()->toIso8601String(),
            ], $extra),
        ]);
    }

    private function events(InsuranceAnalysisBatch $batch, string $attemptId): Builder
    {
        return InsuranceAnalysisEvent::query()
            ->whereHas('analysis', fn (Builder $query) => $query->where('insurance_analysis_batch_id', $batch->id))
            ->where('payload->attempt_id', $attemptId);
    }
}

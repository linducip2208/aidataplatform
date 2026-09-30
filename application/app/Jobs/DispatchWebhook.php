<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Deliver one webhook event in the background with retry.
 */
class DispatchWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== WebhookDelivery::STATUS_PENDING) {
            return;
        }

        $delivery->forceFill([
            'attempts' => $delivery->attempts + 1,
        ])->save();

        try {
            $dispatcher->send($delivery);
        } catch (Throwable $exception) {
            $delivery->forceFill([
                'error' => substr($exception->getMessage(), 0, 512),
            ])->save();

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        WebhookDelivery::query()->whereKey($this->deliveryId)->update([
            'status' => WebhookDelivery::STATUS_FAILED,
            'error' => substr($exception->getMessage(), 0, 512),
        ]);
    }
}

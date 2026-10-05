<?php

namespace FLAIRUK\SumUp\Webhooks;

use Closure;
use FLAIRUK\SumUp\Events\CheckoutStatusChanged;
use FLAIRUK\SumUp\Events\ReaderCheckoutStatusChanged;
use FLAIRUK\SumUp\Events\WebhookReceived;
use FLAIRUK\SumUp\Exceptions\ApiException;
use FLAIRUK\SumUp\SumUp;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * Turns SumUp's notifications into Laravel events.
 *
 * SumUp does not sign webhook payloads. Its documentation says that after a
 * notification "your application must always verify if the event really took
 * place, by calling a relevant SumUp's API". So the handler treats the body
 * only as a hint: it fetches the checkout (or reader transaction) from SumUp
 * and dispatches the event with what the API returned.
 *
 * @see https://developer.sumup.com/online-payments/webhooks
 */
class WebhookHandler
{
    /** @var (Closure(Notification, Request): SumUp)|null */
    protected ?Closure $clientResolver = null;

    public function __construct(
        protected Dispatcher $events,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Choose the client (and so the merchant and token) used to verify a notification.
     * Needed when checkouts are created for OAuth-connected merchants: put something
     * that identifies the merchant in the return_url, e.g. SumUp::webhookUrl(['merchant' => $code]).
     *
     * @param  Closure(Notification, Request): SumUp  $resolver
     */
    public function resolveClientUsing(Closure $resolver): static
    {
        $this->clientResolver = $resolver;

        return $this;
    }

    /**
     * Handle one notification. Returns false if the body was not a SumUp notification.
     *
     * @throws ApiException when SumUp could not be asked (other than a 404), so the request fails and SumUp retries
     */
    public function handle(Request $request): bool
    {
        $body = $request->json()->all();
        $notification = is_array($body) ? Notification::fromArray($body) : null;

        if ($notification === null) {
            return false;
        }

        $this->events->dispatch(new WebhookReceived($notification));

        try {
            match (true) {
                $notification->isCheckoutStatusChange() => $this->checkoutStatusChanged($notification, $request),
                $notification->isReaderTransactionUpdate() => $this->readerTransactionUpdated($notification, $request),
                default => null,
            };
        } catch (ApiException $e) {
            if (! $e->notFound()) {
                throw $e;
            }

            // Nothing to confirm: a forged or stale notification, or one for another merchant.
            $this->logger->warning('Ignored a SumUp notification that the API could not confirm.', [
                'event_type' => $notification->eventType,
                'id' => $notification->id,
            ]);
        }

        return true;
    }

    protected function checkoutStatusChanged(Notification $notification, Request $request): void
    {
        $checkout = $this->client($notification, $request)->checkouts()->find($notification->id);

        $this->events->dispatch(new CheckoutStatusChanged($checkout, $notification));
    }

    protected function readerTransactionUpdated(Notification $notification, Request $request): void
    {
        $clientTransactionId = $notification->clientTransactionId();

        if ($clientTransactionId === null) {
            return;
        }

        $client = $this->client($notification, $request);

        // Look the transaction up under the merchant the notification names. The token
        // still has to have access to that merchant, so a forged code gets a 404 or 403.
        if ($notification->merchantCode() !== null && $notification->merchantCode() !== $client->merchantCode()) {
            $client = $client->forMerchant($notification->merchantCode());
        }

        $transaction = $client->transactions()->findByClientTransactionId($clientTransactionId);

        $this->events->dispatch(new ReaderCheckoutStatusChanged($transaction, $notification));
    }

    protected function client(Notification $notification, Request $request): SumUp
    {
        return $this->clientResolver
            ? ($this->clientResolver)($notification, $request)
            : app(SumUp::class);
    }
}

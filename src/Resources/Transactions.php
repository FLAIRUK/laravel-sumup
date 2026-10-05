<?php

namespace FLAIRUK\SumUp\Resources;

use DateTimeInterface;
use FLAIRUK\SumUp\Money;
use InvalidArgumentException;
use SumUp\ResponseDecoder;
use SumUp\Services\TransactionsGetParams;
use SumUp\Services\TransactionsListResponse;
use SumUp\SumUp as Sdk;
use SumUp\Types\TransactionFull;

/**
 * Transactions are the authoritative record of a payment, online or card-present.
 *
 * @see https://developer.sumup.com/api/transactions
 */
class Transactions extends Resource
{
    /**
     * Filters accepted by list(). The array ones are sent as `statuses[]=A&statuses[]=B`.
     */
    protected const FILTERS = [
        'transaction_code', 'order', 'limit', 'changes_since',
        'newest_time', 'newest_ref', 'oldest_time', 'oldest_ref',
    ];

    protected const ARRAY_FILTERS = ['users', 'statuses', 'payment_types', 'entry_modes', 'types'];

    /**
     * By the transaction's `id`.
     */
    public function find(string $transactionId): TransactionFull
    {
        return $this->get('id', $transactionId);
    }

    /**
     * By the transaction code shown on receipts and in the dashboard.
     */
    public function findByCode(string $transactionCode): TransactionFull
    {
        return $this->get('transactionCode', $transactionCode);
    }

    /**
     * By `client_transaction_id`, which reader checkouts and their webhooks return.
     */
    public function findByClientTransactionId(string $clientTransactionId): TransactionFull
    {
        return $this->get('clientTransactionId', $clientTransactionId);
    }

    /**
     * The transaction history, newest first by default.
     *
     * Filters: transaction_code, order (ascending|descending), limit, users, statuses,
     * payment_types, entry_modes, types, changes_since, newest_time, newest_ref,
     * oldest_time, oldest_ref. Dates are ISO 8601 strings.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): TransactionsListResponse
    {
        $query = [];

        foreach ($filters as $filter => $value) {
            if (in_array($filter, self::ARRAY_FILTERS, true)) {
                foreach ((array) $value as $item) {
                    $query[] = rawurlencode($filter.'[]').'='.rawurlencode((string) $item);
                }
            } elseif (in_array($filter, self::FILTERS, true)) {
                $value = $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : $value;
                $query[] = rawurlencode($filter).'='.rawurlencode((string) $value);
            } else {
                throw new InvalidArgumentException("[{$filter}] is not a SumUp transaction filter.");
            }
        }

        $path = '/v2.1/merchants/'.rawurlencode($this->merchantCode()).'/transactions/history'.($query ? '?'.implode('&', $query) : '');

        // Sent raw because the SDK (0.1.6) encodes array filters as `statuses[][0]=…`.
        return $this->call(fn (Sdk $sdk) => ResponseDecoder::decodeOrThrow(
            $sdk->request('GET', $path), TransactionsListResponse::class, null, 'GET', $path,
        ));
    }

    /**
     * Refund a transaction in full, or partly when given an amount (major units or Money).
     *
     * @return array<string, mixed>
     */
    public function refund(string $transactionId, Money|float|int|null $amount = null): array
    {
        $body = $amount === null ? null : ['amount' => $amount instanceof Money ? $amount->amount() : (float) $amount];

        return $this->call(fn (Sdk $sdk) => $sdk->transactions()->refund($this->merchantCode(), $transactionId, $body));
    }

    protected function get(string $property, string $value): TransactionFull
    {
        $params = new TransactionsGetParams;
        $params->{$property} = $value;

        return $this->call(fn (Sdk $sdk) => $sdk->transactions()->get($this->merchantCode(), $params));
    }
}

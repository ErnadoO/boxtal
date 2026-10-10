<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Exception;

/**
 * Boxtal rejected the request (4xx / 5xx with an error body).
 *
 * Errors come from the response body: `{"errors": [{"code": "RequestValidationException", "parameters":
 * [{"field": "shipment.pickupPointCode", "value": "…missing value"}]}]}`.
 */
final class ApiException extends BoxtalException
{
    /**
     * @param list<array{code: string, field: ?string, message: ?string}> $errors
     * @param list<string>                                                  $codes  Top-level error codes, e.g. "ShippingOrderNotFoundException"
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly array $errors = [],
        ?\Throwable $previous = null,
        private readonly array $codes = [],
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * @param array<mixed> $body Decoded JSON body of a v3 error response
     */
    public static function fromResponse(int $statusCode, array $body): self
    {
        $errors = [];
        $codes = [];
        foreach ((array) ($body['errors'] ?? []) as $error) {
            if (!\is_array($error)) {
                continue;
            }
            $codes[] = (string) ($error['code'] ?? 'UNKNOWN');
            $parameters = array_filter((array) ($error['parameters'] ?? []), 'is_array');
            if ([] === $parameters) {
                $errors[] = ['code' => (string) ($error['code'] ?? 'UNKNOWN'), 'field' => null, 'message' => null];
            }
            foreach ($parameters as $parameter) {
                $errors[] = [
                    'code' => (string) ($parameter['code'] ?? $error['code'] ?? 'UNKNOWN'),
                    'field' => isset($parameter['field']) ? (string) $parameter['field'] : null,
                    'message' => isset($parameter['value']) ? (string) $parameter['value'] : (isset($parameter['message']) ? (string) $parameter['message'] : null),
                ];
            }
        }

        $summary = implode('; ', array_map(
            static fn (array $e): string => $e['code'].(null !== $e['field'] ? ' ('.$e['field'].')' : '').(null !== $e['message'] ? ': '.$e['message'] : ''),
            $errors,
        ));

        return new self(sprintf('Boxtal API error (HTTP %d)%s', $statusCode, '' !== $summary ? ': '.$summary : ''), $statusCode, $errors, null, $codes);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return list<array{code: string, field: ?string, message: ?string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** True when one of the errors has this code (e.g. "ShippingOrderNotFoundException"). */
    public function hasErrorCode(string $code): bool
    {
        if (\in_array($code, $this->codes, true)) {
            return true;
        }
        foreach ($this->errors as $error) {
            if ($error['code'] === $code) {
                return true;
            }
        }

        return false;
    }
}

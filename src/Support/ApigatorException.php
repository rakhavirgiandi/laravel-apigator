<?php

namespace Virgiandi\Apigator\Support;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class ApigatorException extends Exception
{
    /**
     * HTTP status code for the response.
     */
    protected int $statusCode;

    /**
     * Additional context/data to include in the response.
     */
    protected array $context;

    /**
     * Translation key for i18n support.
     */
    protected ?string $translationKey;

    /**
     * Translation parameters for i18n substitution.
     */
    protected array $translationParams;

    /**
     * Error code identifier (e.g. "USER_NOT_FOUND", "PAYMENT_FAILED").
     */
    protected ?string $errorCode;

    /**
     * @param  string       $message          Plain message (used as fallback if no translationKey)
     * @param  int          $statusCode       HTTP status code (default: 400)
     * @param  string|null  $translationKey   Laravel i18n key, e.g. "errors.user.not_found"
     * @param  array        $translationParams  Replacement params for the translation string
     * @param  string|null  $errorCode        Machine-readable error code
     * @param  array        $context          Extra data to expose in the JSON response
     * @param  Throwable|null $previous       Previous exception for chaining
     */
    public function __construct(
        string     $message = '',
        int        $statusCode = Response::HTTP_BAD_REQUEST,
        ?string    $translationKey = null,
        array      $translationParams = [],
        ?string    $errorCode = null,
        array      $context = [],
        ?Throwable $previous = null,
    ) {
        $this->statusCode        = $statusCode;
        $this->translationKey    = $translationKey;
        $this->translationParams = $translationParams;
        $this->errorCode         = $errorCode;
        $this->context           = $context;

        // Resolve final message: translation key takes priority over plain message
        $resolvedMessage = $this->resolveMessage($message);

        parent::__construct($resolvedMessage, $statusCode, $previous);
    }

    // -------------------------------------------------------------------------
    // Named constructors — convenient shortcuts for common scenarios
    // -------------------------------------------------------------------------

    /**
     * Create from a plain message only.
     */
    public static function withMessage(
        string $message,
        int $statusCode = Response::HTTP_BAD_REQUEST,
        ?string $errorCode = null,
        array $context = [],
    ): static {
        return new static($message, $statusCode, null, [], $errorCode, $context);
    }

    /**
     * Create from a Laravel i18n translation key.
     *
     * @example ApigatorException::withTranslation('errors.user.not_found', ['id' => 42])
     */
    public static function withTranslation(
        string $translationKey,
        array $translationParams = [],
        int $statusCode = Response::HTTP_BAD_REQUEST,
        ?string $errorCode = null,
        array $context = [],
    ): static {
        return new static('', $statusCode, $translationKey, $translationParams, $errorCode, $context);
    }

    /**
     * Shorthand for 401 Unauthorized.
     */
    public static function unauthorized(
        ?string $translationKey = 'apigator.unauthorized',
        array $translationParams = [],
        ?string $errorCode = 'UNAUTHORIZED',
    ): static {
        return new static('Unauthorized.', Response::HTTP_UNAUTHORIZED, $translationKey, $translationParams, $errorCode);
    }

    /**
     * Shorthand for 403 Forbidden.
     */
    public static function forbidden(
        ?string $translationKey = 'apigator.forbidden',
        array $translationParams = [],
        ?string $errorCode = 'FORBIDDEN',
    ): static {
        return new static('Forbidden.', Response::HTTP_FORBIDDEN, $translationKey, $translationParams, $errorCode);
    }

    /**
     * Shorthand for 404 Not Found.
     */
    public static function notFound(
        ?string $translationKey = 'apigator.not_found',
        array $translationParams = [],
        ?string $errorCode = 'NOT_FOUND',
    ): static {
        return new static('Not found.', Response::HTTP_NOT_FOUND, $translationKey, $translationParams, $errorCode);
    }

    /**
     * Shorthand for 422 Unprocessable Entity.
     */
    public static function unprocessable(
        ?string $translationKey = 'apigator.unprocessable',
        array $translationParams = [],
        ?string $errorCode = 'UNPROCESSABLE',
    ): static {
        return new static('Unprocessable.', Response::HTTP_UNPROCESSABLE_ENTITY, $translationKey, $translationParams, $errorCode);
    }

    /**
     * Shorthand for 500 Internal Server Error.
     */
    public static function serverError(
        ?string $translationKey = 'apigator.server_error',
        array $translationParams = [],
        ?string $errorCode = 'SERVER_ERROR',
    ): static {
        return new static('Server error.', Response::HTTP_INTERNAL_SERVER_ERROR, $translationKey, $translationParams, $errorCode);
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getTranslationKey(): ?string
    {
        return $this->translationKey;
    }

    // -------------------------------------------------------------------------
    // Laravel integration
    // -------------------------------------------------------------------------

    /**
     * Render the exception as a JSON response.
     * Laravel calls this automatically when the exception is thrown inside a request cycle.
     */
    public function render(Request $request): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $this->getMessage(),
        ];

        if ($this->errorCode !== null) {
            $payload['error_code'] = $this->errorCode;
        }

        if (! empty($this->context)) {
            $payload['context'] = $this->context;
        }

        return response()->json($payload, $this->statusCode);
    }

    /**
     * Report the exception to Laravel's log / error tracker.
     * Return false to suppress logging for expected/business exceptions,
     * or remove this method to let Laravel decide based on $dontReport.
     */
    public function report(): bool
    {
        // Return false for "expected" business-logic exceptions (4xx) so they
        // don't pollute your logs. Change to true (or remove this method) if
        // you want every ApigatorException logged.
        return $this->statusCode >= Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the final human-readable message.
     * Translation key wins over plain message; falls back gracefully.
     */
    protected function resolveMessage(string $fallback): string
    {
        if ($this->translationKey === null) {
            return $fallback;
        }

        $translated = __($this->translationKey, $this->translationParams);

        // If the key was not found, __(…) returns the key itself — use fallback
        if ($translated === $this->translationKey && $fallback !== '') {
            return $fallback;
        }

        return (string) $translated;
    }
}

<?php

namespace Virgiandi\Apigator\Support;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Virgiandi\Apigator\Support\ApigatorException;

class ApigatorValidationException extends ApigatorException
{
    /**
     * Field-level validation errors.
     * Structure: ['field' => ['error message', ...], ...]
     */
    protected array $errors;

    /**
     * @param  string        $message    Human-readable summary message
     * @param  array         $errors     Field-level errors (same shape as $validator->errors()->toArray())
     * @param  string|null   $errorCode  Machine-readable error code
     * @param  string|null   $translationKey  i18n key for the summary message
     * @param  array         $translationParams  i18n substitution params
     * @param  array         $context    Extra data to expose in the response
     * @param  Throwable|null $previous  Previous exception for chaining
     */
    public function __construct(
        string     $message = '',
        array      $errors = [],
        ?string    $errorCode = 'VALIDATION_FAILED',
        ?string    $translationKey = null,
        array      $translationParams = [],
        array      $context = [],
        ?Throwable $previous = null,
    ) {
        $this->errors = $errors;

        parent::__construct(
            message:           $message,
            statusCode:        Response::HTTP_UNPROCESSABLE_ENTITY,
            translationKey:    $translationKey,
            translationParams: $translationParams,
            errorCode:         $errorCode,
            context:           $context,
            previous:          $previous,
        );
    }

    // -------------------------------------------------------------------------
    // Named constructors
    // -------------------------------------------------------------------------

    /**
     * Build from a Laravel ValidationException (most common use-case).
     *
     * @example
     *   throw ApigatorValidationException::fromValidation($validationException);
     */
    public static function fromValidation(
        ValidationException $e,
        ?string $errorCode = 'VALIDATION_FAILED',
        ?string $translationKey = null,
        array   $translationParams = [],
    ): static {
        return new static(
            message:           $e->getMessage(),
            errors:            $e->errors(),
            errorCode:         $errorCode,
            translationKey:    $translationKey,
            translationParams: $translationParams,
        );
    }

    /**
     * Build from a Validator instance directly.
     *
     * @example
     *   $validator = Validator::make($data, $rules);
     *   if ($validator->fails()) {
     *       throw ApigatorValidationException::fromValidator($validator);
     *   }
     */
    public static function fromValidator(
        Validator $validator,
        ?string   $errorCode = 'VALIDATION_FAILED',
        ?string   $translationKey = null,
        array     $translationParams = [],
    ): static {
        $validationException = new ValidationException($validator);

        return static::fromValidation($validationException, $errorCode, $translationKey, $translationParams);
    }

    /**
     * Build with manually supplied field errors.
     * Useful for business-rule violations in service layer.
     *
     * @example
     *   throw ApigatorValidationException::withErrors([
     *       'email' => ['This email is already taken.'],
     *       'items' => ['Cart cannot be empty.'],
     *   ]);
     */
    public static function withErrors(
        array   $errors,
        string  $message = '',
        ?string $errorCode = 'VALIDATION_FAILED',
        ?string $translationKey = null,
        array   $translationParams = [],
        array   $context = [],
    ): static {
        return new static(
            message:           $message ?: 'The given data was invalid.',
            errors:            $errors,
            errorCode:         $errorCode,
            translationKey:    $translationKey,
            translationParams: $translationParams,
            context:           $context,
        );
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get errors for a specific field (empty array if field has no errors).
     */
    public function getFieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Check whether a specific field has errors.
     */
    public function hasFieldError(string $field): bool
    {
        return ! empty($this->errors[$field]);
    }

    // -------------------------------------------------------------------------
    // Laravel integration
    // -------------------------------------------------------------------------

    /**
     * Render as a JSON response.
     * Laravel calls this automatically when the exception propagates to the HTTP layer.
     */
    public function render(Request $request): JsonResponse
    {
        $payload = [
            'success'    => false,
            'message'    => $this->getMessage(),
            'error_code' => $this->getErrorCode(),
            'errors'     => $this->errors,
        ];

        if (! empty($this->getContext())) {
            $payload['context'] = $this->getContext();
        }

        return response()->json($payload, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Validation exceptions are expected — never log them.
     */
    public function report(): bool
    {
        return false;
    }
}
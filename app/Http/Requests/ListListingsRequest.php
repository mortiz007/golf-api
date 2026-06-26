<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/**
 * Query-string validation for GET /api/listings (SPECS §4.4).
 *
 * Public endpoint (no auth); validates filters and pagination params. On
 * failure emits the normative error envelope (VALIDATION_ERROR / 422).
 */
final class ListListingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize show_all so the boolean rule accepts query-string values such as
     * "true"/"false" (which Laravel's boolean rule rejects on its own).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('show_all')) {
            $this->merge([
                'show_all' => filter_var($this->query('show_all'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'condition' => ['nullable', 'string', 'in:New,Used,Refurbished,Like New'],
            'q' => ['nullable', 'string', 'max:255'],
            'show_all' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Emit the normative error envelope (SPECS §4 / DESIGN §VI):
     * { "error": { "code": "VALIDATION_ERROR", "message": ..., "details": {...} } }
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            new JsonResponse([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $validator->errors()->toArray(),
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}

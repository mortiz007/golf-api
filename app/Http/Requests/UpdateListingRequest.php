<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/**
 * Input validation for PATCH /api/listings/{id} (SPECS §4.2).
 *
 * Partial update: only the fields present in the payload are validated
 * (`sometimes`), each with the same frozen rules as creation. Owner-only
 * authorization is enforced in the use case (DESIGN §III); Sanctum auth is
 * handled by route middleware. On failure emits the normative error envelope
 * (VALIDATION_ERROR / 422).
 */
final class UpdateListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize input before validation: trim title, strip tags from
     * description (sanitization #21). Only touches fields actually present so
     * the partial-update semantics are preserved.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('title') && is_string($this->title)) {
            $normalized['title'] = trim($this->title);
        }

        if ($this->has('description') && is_string($this->description)) {
            $normalized['description'] = trim(strip_tags($this->description));
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:3', 'max:255', 'regex:/^[A-Za-z ]+$/'],
            'price' => ['sometimes', 'numeric', 'min:0.01', 'max:99999999.99'],
            'condition' => ['sometimes', 'string', 'in:New,Used,Refurbished,Like New'],
            'description' => ['sometimes', 'string', 'min:10', 'max:1000'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.regex' => 'The title may only contain letters and spaces.',
            'end_date.after_or_equal' => 'The end date must be today or a future date.',
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

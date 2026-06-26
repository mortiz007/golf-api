<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/**
 * Input validation for POST /api/listings (SPECS §4.1).
 *
 * Enforces the frozen field rules (#2 title, #21 description, #3 end_date).
 * Authorization (owner-only) is not relevant here (creation); Sanctum auth is
 * handled by route middleware. On failure emits the normative error envelope
 * (VALIDATION_ERROR / 422).
 */
final class StoreListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize input before validation: trim title, strip tags from
     * description (sanitization required by decision #21).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->title) ? trim($this->title) : $this->title,
            'description' => is_string($this->description)
                ? trim(strip_tags($this->description))
                : $this->description,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:255', 'regex:/^[A-Za-z ]+$/'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'condition' => ['required', 'string', 'in:New,Used,Refurbished,Like New'],
            'description' => ['required', 'string', 'min:10', 'max:1000'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
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

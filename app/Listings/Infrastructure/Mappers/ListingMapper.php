<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Mappers;

use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\ValueObjects\AiEnrichmentStatus;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\EndDate;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\ModerationStatus;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;
use App\Listings\Infrastructure\Eloquent\ListingModel;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Bidirectional translator between the Listing domain entity and the
 * ListingModel Eloquent model. Used ONLY by EloquentListingRepository (S1-11).
 *
 * Bridges domain enums (ModerationStatus/AiEnrichmentStatus) and the Price VO
 * (cents) to the model's string/decimal column representation, and back.
 */
final class ListingMapper
{
    /**
     * Domain entity → array of persistable attributes (for insert/update).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(Listing $listing): array
    {
        return [
            'user_id' => $listing->userId(),
            'category_id' => $listing->categoryId(),
            'title' => (string) $listing->title(),
            'price' => $listing->price()->value(),
            'condition' => (string) $listing->condition(),
            'description' => (string) $listing->description(),
            'end_date' => $listing->endDate()?->toString(),
            'moderation_status' => $listing->moderationStatus()->value,
            'ai_enrichment_status' => $listing->aiEnrichmentStatus()->value,
        ];
    }

    /**
     * Eloquent model → domain entity (rehydration via Listing::fromState).
     */
    public function toDomain(ListingModel $model): Listing
    {
        $endDate = $model->end_date !== null
            ? new EndDate($this->normalizeDate($model->end_date))
            : null;

        return Listing::fromState(
            id: (int) $model->id,
            userId: (int) $model->user_id,
            categoryId: (int) $model->category_id,
            title: new Title($model->title),
            price: new Price((string) $model->price),
            condition: new ListingCondition($model->condition),
            description: new Description($model->description),
            endDate: $endDate,
            moderationStatus: ModerationStatus::from($model->moderation_status),
            aiEnrichmentStatus: AiEnrichmentStatus::from($model->ai_enrichment_status),
            createdAt: $this->toImmutable($model->created_at),
            cancelledAt: $model->cancelled_at !== null
                ? $this->toImmutable($model->cancelled_at)
                : null,
        );
    }

    /**
     * Normalizes a date value (string or Carbon, depending on cast) to Y-m-d.
     */
    private function normalizeDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }

    /**
     * Converts the model's created_at (Carbon|string|null) to DateTimeImmutable (UTC).
     */
    private function toImmutable(mixed $value): DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

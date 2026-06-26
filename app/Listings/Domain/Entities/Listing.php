<?php

declare(strict_types=1);

namespace App\Listings\Domain\Entities;

use App\Listings\Domain\ValueObjects\AiEnrichmentStatus;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\EndDate;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\ModerationStatus;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Listing aggregate root (lightweight). Framework-agnostic: accepts only
 * Value Objects and primitive identifiers. Eloquent never touches this class.
 *
 * Identity (`id`) is null until the entity is persisted (assigned by the
 * repository in S1-11 via withId()).
 */
final class Listing
{
    private function __construct(
        private ?int $id,
        private readonly int $userId,
        private readonly int $categoryId,
        private readonly Title $title,
        private readonly Price $price,
        private readonly ListingCondition $condition,
        private readonly Description $description,
        private readonly ?EndDate $endDate,
        private readonly ModerationStatus $moderationStatus,
        private readonly AiEnrichmentStatus $aiEnrichmentStatus,
        private readonly DateTimeImmutable $createdAt,
    ) {}

    /**
     * Factory for a brand-new listing.
     * Forces moderation_status=pending and ai_enrichment_status=pending. (SPECS §4.1)
     */
    public static function create(
        int $userId,
        int $categoryId,
        Title $title,
        Price $price,
        ListingCondition $condition,
        Description $description,
        ?EndDate $endDate = null,
        ?DateTimeImmutable $createdAt = null,
    ): self {
        return new self(
            id: null,
            userId: $userId,
            categoryId: $categoryId,
            title: $title,
            price: $price,
            condition: $condition,
            description: $description,
            endDate: $endDate,
            moderationStatus: ModerationStatus::PENDING,
            aiEnrichmentStatus: AiEnrichmentStatus::PENDING,
            createdAt: $createdAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    /**
     * Rehydration factory (used by the mapper/repository in S1-10/S1-11).
     * Allows reconstructing an existing listing with all its persisted state.
     */
    public static function fromState(
        ?int $id,
        int $userId,
        int $categoryId,
        Title $title,
        Price $price,
        ListingCondition $condition,
        Description $description,
        ?EndDate $endDate,
        ModerationStatus $moderationStatus,
        AiEnrichmentStatus $aiEnrichmentStatus,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            userId: $userId,
            categoryId: $categoryId,
            title: $title,
            price: $price,
            condition: $condition,
            description: $description,
            endDate: $endDate,
            moderationStatus: $moderationStatus,
            aiEnrichmentStatus: $aiEnrichmentStatus,
            createdAt: $createdAt,
        );
    }

    /**
     * Returns a copy of this listing with the persisted identity assigned.
     * Keeps the entity immutable (no setters); used by the repository (S1-11).
     */
    public function withId(int $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function categoryId(): int
    {
        return $this->categoryId;
    }

    public function title(): Title
    {
        return $this->title;
    }

    public function price(): Price
    {
        return $this->price;
    }

    public function condition(): ListingCondition
    {
        return $this->condition;
    }

    public function description(): Description
    {
        return $this->description;
    }

    public function endDate(): ?EndDate
    {
        return $this->endDate;
    }

    public function moderationStatus(): ModerationStatus
    {
        return $this->moderationStatus;
    }

    public function aiEnrichmentStatus(): AiEnrichmentStatus
    {
        return $this->aiEnrichmentStatus;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

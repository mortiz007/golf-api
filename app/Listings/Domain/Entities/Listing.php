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
        private readonly ?DateTimeImmutable $cancelledAt = null,
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
            cancelledAt: null,
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
        ?DateTimeImmutable $cancelledAt = null,
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
            cancelledAt: $cancelledAt,
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

    /**
     * Returns a copy with a new title (immutable update, SPECS §4.2).
     */
    public function withTitle(Title $title): self
    {
        return $this->cloneWith(['title' => $title]);
    }

    public function withPrice(Price $price): self
    {
        return $this->cloneWith(['price' => $price]);
    }

    public function withCondition(ListingCondition $condition): self
    {
        return $this->cloneWith(['condition' => $condition]);
    }

    public function withDescription(Description $description): self
    {
        return $this->cloneWith(['description' => $description]);
    }

    public function withEndDate(?EndDate $endDate): self
    {
        return $this->cloneWith(['endDate' => $endDate]);
    }

    public function withCategoryId(int $categoryId): self
    {
        return $this->cloneWith(['categoryId' => $categoryId]);
    }

    public function withModerationStatus(ModerationStatus $moderationStatus): self
    {
        return $this->cloneWith(['moderationStatus' => $moderationStatus]);
    }

    public function withAiEnrichmentStatus(AiEnrichmentStatus $aiEnrichmentStatus): self
    {
        return $this->cloneWith(['aiEnrichmentStatus' => $aiEnrichmentStatus]);
    }

    /**
     * Returns a cancelled (soft-deleted) copy of this listing (SPECS §4.3).
     * Defaults the cancellation timestamp to now (UTC).
     */
    public function cancel(?DateTimeImmutable $cancelledAt = null): self
    {
        return $this->cloneWith([
            'cancelledAt' => $cancelledAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ]);
    }

    /**
     * Rebuilds the entity preserving every property except the overridden ones,
     * keeping immutability for the readonly fields.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function cloneWith(array $overrides): self
    {
        return new self(
            id: $this->id,
            userId: $this->userId,
            categoryId: $overrides['categoryId'] ?? $this->categoryId,
            title: $overrides['title'] ?? $this->title,
            price: $overrides['price'] ?? $this->price,
            condition: $overrides['condition'] ?? $this->condition,
            description: $overrides['description'] ?? $this->description,
            endDate: array_key_exists('endDate', $overrides) ? $overrides['endDate'] : $this->endDate,
            moderationStatus: $overrides['moderationStatus'] ?? $this->moderationStatus,
            aiEnrichmentStatus: $overrides['aiEnrichmentStatus'] ?? $this->aiEnrichmentStatus,
            createdAt: $this->createdAt,
            cancelledAt: array_key_exists('cancelledAt', $overrides) ? $overrides['cancelledAt'] : $this->cancelledAt,
        );
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

    public function cancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    /**
     * A cancelled (soft-deleted) listing is treated as not found (SPECS §4.2).
     */
    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null;
    }
}

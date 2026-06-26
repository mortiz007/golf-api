<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreListingRequest;
use App\Http\Requests\UpdateListingRequest;
use App\Http\Resources\ListingResource;
use App\Listings\Application\Commands\CreateListingCommand;
use App\Listings\Application\Commands\UpdateListingCommand;
use App\Listings\Application\UseCases\CancelListingUseCase;
use App\Listings\Application\UseCases\CreateListingUseCase;
use App\Listings\Application\UseCases\UpdateListingUseCase;
use App\Listings\Domain\Entities\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * HTTP entry point for the Listings context (SPECS §4).
 *
 * Thin controller: validates via FormRequest, maps to an Application Command,
 * invokes the Use Case and returns the HTTP response. No business rules here.
 */
final class ListingController extends Controller
{
    /**
     * POST /api/listings — create a listing (SPECS §4.1).
     *
     * Persists with moderation_status/ai_enrichment_status = pending, publishes
     * ListingCreated after commit and enqueues moderation + enrichment jobs.
     * Returns 201 with a Location header.
     */
    public function store(StoreListingRequest $request, CreateListingUseCase $useCase): JsonResponse
    {
        $command = CreateListingCommand::fromArray(
            data: $request->validated(),
            actorUserId: (int) $request->user()->id,
        );

        /** @var Listing $listing */
        $listing = DB::transaction(
            static fn (): Listing => $useCase->execute($command)
        );

        return ListingResource::make($listing)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED)
            ->header('Location', "/api/listings/{$listing->id()}");
    }

    /**
     * PATCH /api/listings/{id} — partial update, owner-only (SPECS §4.2).
     *
     * Applies only the submitted fields. Changing title/description re-queues
     * moderation; changing price/condition re-queues enrichment. Publishes
     * ListingUpdated after commit. Returns 200; 403/404/422 are mapped from
     * domain exceptions at the boundary (bootstrap/app.php).
     */
    public function update(UpdateListingRequest $request, int $id, UpdateListingUseCase $useCase): JsonResponse
    {
        $command = UpdateListingCommand::fromArray(
            actorUserId: (int) $request->user()->id,
            listingId: $id,
            validated: $request->validated(),
        );

        /** @var Listing $listing */
        $listing = DB::transaction(
            static fn (): Listing => $useCase->execute($command)
        );

        return ListingResource::make($listing)->response();
    }

    /**
     * DELETE /api/listings/{id} — cancel (soft-delete), owner-only (SPECS §4.3).
     *
     * Sets cancelled_at and publishes ListingDeleted after commit. Cancelling an
     * already-cancelled listing is idempotent (still 204, decision #15).
     * Returns 204; 403/404 are mapped from domain exceptions at the boundary
     * (bootstrap/app.php).
     */
    public function destroy(int $id, Request $request, CancelListingUseCase $useCase): Response
    {
        DB::transaction(
            static fn () => $useCase->execute($id, (int) $request->user()->id)
        );

        return response()->noContent();
    }
}

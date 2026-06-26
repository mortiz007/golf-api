<?php

declare(strict_types=1);

use App\Listings\Application\Contracts\ListingQueryPort;
use App\Listings\Application\Queries\ListListingsQuery;
use App\Listings\Application\UseCases\ListListingsUseCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;

it('applies defaults when no filters are provided', function () {
    $query = ListListingsQuery::fromValidated([]);

    expect($query->minPrice)->toBeNull()
        ->and($query->maxPrice)->toBeNull()
        ->and($query->categoryId)->toBeNull()
        ->and($query->condition)->toBeNull()
        ->and($query->q)->toBeNull()
        ->and($query->showAll)->toBeFalse()
        ->and($query->page)->toBe(1)
        ->and($query->perPage)->toBe(20);
});

it('casts and reads the provided filters', function () {
    $query = ListListingsQuery::fromValidated([
        'min_price' => '10.5',
        'max_price' => '99.99',
        'category_id' => '3',
        'condition' => 'Used',
        'q' => 'driver',
        'show_all' => 'true',
        'page' => '2',
        'per_page' => '50',
    ]);

    expect($query->minPrice)->toBe(10.5)
        ->and($query->maxPrice)->toBe(99.99)
        ->and($query->categoryId)->toBe(3)
        ->and($query->condition)->toBe('Used')
        ->and($query->q)->toBe('driver')
        ->and($query->showAll)->toBeTrue()
        ->and($query->page)->toBe(2)
        ->and($query->perPage)->toBe(50);
});

it('delegates to the query port', function () {
    $paginator = new ConcretePaginator([], 0, 20);

    $port = new class($paginator) implements ListingQueryPort
    {
        public ?ListListingsQuery $received = null;

        public function __construct(private readonly LengthAwarePaginator $paginator) {}

        public function search(ListListingsQuery $query): LengthAwarePaginator
        {
            $this->received = $query;

            return $this->paginator;
        }
    };

    $query = ListListingsQuery::fromValidated(['q' => 'putter']);
    $result = (new ListListingsUseCase($port))->execute($query);

    expect($result)->toBe($paginator)
        ->and($port->received)->toBe($query);
});

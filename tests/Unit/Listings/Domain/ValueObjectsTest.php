<?php

declare(strict_types=1);

use App\Listings\Domain\Exceptions\InvalidListingDataException;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\EndDate;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;

/* ---------------------------- Title (SPECS #2) ---------------------------- */

it('accepts a valid title and trims it', function () {
    $title = new Title('  Driver Pro  ');
    expect((string) $title)->toBe('Driver Pro');
});

it('rejects an empty title', function () {
    expect(fn () => new Title('   '))
        ->toThrow(InvalidListingDataException::class);
});

it('rejects a title shorter than 3 chars', function () {
    expect(fn () => new Title('ab'))->toThrow(InvalidListingDataException::class);
});

it('rejects a title longer than 255 chars', function () {
    expect(fn () => new Title(str_repeat('a', 256)))->toThrow(InvalidListingDataException::class);
});

it('rejects a title with digits or symbols', function () {
    expect(fn () => new Title('Driver 3000'))->toThrow(InvalidListingDataException::class)
        ->and(fn () => new Title('Driver!'))->toThrow(InvalidListingDataException::class);
});

it('reports the title field in the error bag', function () {
    try {
        new Title('a1');
        $this->fail('Expected InvalidListingDataException.');
    } catch (InvalidListingDataException $e) {
        expect($e->errors())->toHaveKey('title');
    }
});

/* ---------------------------- Price (SPECS #2/#19) ------------------------ */

it('accepts a valid price and stores cents', function () {
    $price = new Price(199.99);
    expect($price->cents)->toBe(19999)
        ->and($price->value())->toBe(199.99);
});

it('accepts a numeric string price', function () {
    expect((new Price('10.50'))->cents)->toBe(1050);
});

it('rejects price below the minimum 0.01', function () {
    expect(fn () => new Price(0))->toThrow(InvalidListingDataException::class)
        ->and(fn () => new Price(0.004))->toThrow(InvalidListingDataException::class);
});

it('rejects a non-numeric string price', function () {
    expect(fn () => new Price('abc'))->toThrow(InvalidListingDataException::class);
});

it('rejects a price exceeding DECIMAL(10,2) range', function () {
    expect(fn () => new Price(100_000_000))->toThrow(InvalidListingDataException::class);
});

/* ------------------------- ListingCondition (ENUM) ------------------------ */

it('accepts the four allowed conditions', function (string $value) {
    expect((string) new ListingCondition($value))->toBe($value);
})->with(['New', 'Used', 'Refurbished', 'Like New']);

it('rejects an invalid condition', function () {
    expect(fn () => new ListingCondition('Broken'))->toThrow(InvalidListingDataException::class);
});

/* --------------------------- Description (SPECS #21) ---------------------- */

it('accepts a valid description and strips tags', function () {
    $desc = new Description('<b>Great</b> club for sale here');
    expect($desc->value)->toBe('Great club for sale here');
});

it('rejects a description shorter than 10 chars', function () {
    expect(fn () => new Description('short'))->toThrow(InvalidListingDataException::class);
});

it('rejects a description longer than 1000 chars', function () {
    expect(fn () => new Description(str_repeat('a', 1001)))->toThrow(InvalidListingDataException::class);
});

/* ----------------------------- EndDate (SPECS #3) ------------------------- */

it('accepts a future end date', function () {
    $future = (new DateTimeImmutable('+5 days'))->format('Y-m-d');
    expect((new EndDate($future))->toString())->toBe($future);
});

it('accepts today as end date', function () {
    $today = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');
    expect((new EndDate($today))->toString())->toBe($today);
});

it('rejects a past end date', function () {
    $past = (new DateTimeImmutable('-1 day'))->format('Y-m-d');
    expect(fn () => new EndDate($past))->toThrow(InvalidListingDataException::class);
});

it('rejects a malformed end date', function () {
    expect(fn () => new EndDate('2026/01/01'))->toThrow(InvalidListingDataException::class)
        ->and(fn () => new EndDate('not-a-date'))->toThrow(InvalidListingDataException::class);
});

<?php

namespace STS\HubSpot\Api;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Traits\Conditionable;

class Builder
{
    use Conditionable;

    protected array $filters = [];
    protected string $query;
    protected array $sort;
    protected int $after;
    protected int $limit = 50;

    protected Model $object;
    protected string $objectClass;

    protected array $properties = [];
    protected array $associations = [];

    public function __construct(protected Client $client)
    {}

    public function for(Model $object): static
    {
        $this->object = $object;
        $this->objectClass = get_class($object);

        return $this;
    }

    public function include($properties): static
    {
        $this->properties = is_array($properties)
            ? $properties
            : func_get_args();

        return $this;
    }

    public function with($associations): static
    {
        $this->associations = is_array($associations)
            ? $associations
            : func_get_args();

        return $this;
    }

    public function find($id, $idProperty = null): Model
    {
        $response = $this->client->get(
            $this->object->endpoint('read', ['id' => $id]),
            [
                'properties' => implode(",", $this->properties()),
                'associations' => implode(",", $this->associations()),
                'idProperty' => $idProperty
            ]
        )->json();

        return new $this->objectClass($response);
    }

    public function findMany(array $ids, $idProperty = null): Collection
    {
        $ids = array_unique($ids);

        if(count($ids) === 1) {
            return new Collection([$this->find($ids[0], $idProperty)]);
        }

        if(!count($ids)) {
            return new Collection();
        }

        $response = $this->client->post(
            $this->object->endpoint('batchRead'),
            [
                'properties' => $this->properties(),
                'idProperty' => $idProperty,
                'inputs' => array_map(fn($id) => ['id' => $id], $ids)
            ]
        )->json();

        return Collection::hydrate($response, $this->objectClass);
    }

    public function where($property, $condition, $value = null): static
    {
        $this->filters[] = new Filter($property, $condition, $value);

        return $this;
    }

    public function orderBy($property, $direction = 'ASC'): static
    {
        $this->sort = [
            'propertyName' => $property,
            'direction' => strtoupper($direction) === 'DESC' ? 'DESCENDING' : 'ASCENDING'
        ];

        return $this;
    }

    public function take(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function skip(int $after): static
    {
        $this->after = $after;

        return $this;
    }

    public function search($input): static
    {
        $this->query = $input;

        return $this;
    }

    public function fetch($after = null, $limit = null): array
    {
        return $this->client->post(
            $this->object->endpoint('search'),
            [
                'limit' => $limit ?? $this->limit,
                'after' => $after ?? $this->after ?? null,
                'query' => $this->query ?? null,
                'properties' => $this->properties(),
                'sorts' => isset($this->sort) ? [$this->sort] : null,
                'filterGroups' => [[
                    'filters' => array_map(fn($filter) => $filter->toArray(), $this->filters)
                ]]
            ]
        )->json();
    }

    public function get()
    {
        return Collection::hydrate(
            $this->fetch(),
            $this->objectClass
        );
    }

    public function cursor(): LazyCollection
    {
        return new LazyCollection(function() {
            $after = 0;

            do {
                $response = $this->fetch($after);
                $after = Arr::get($response, 'paging.next.after');

                foreach($response['results'] AS $payload) {
                    yield new $this->objectClass($payload);
                }
            } while($after !== null);
        });
    }

    public function paginate($perPage = 50, $pageName = 'page', $page = null): LengthAwarePaginator
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $results = Collection::hydrate(
            $this->fetch($perPage * $page, $perPage),
            $this->objectClass
        );

        return new LengthAwarePaginator(
            $results, $results->total(), $perPage, $page, [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        );
    }

    public function count(): int
    {
        return Arr::get($this->get(1, 0, false), 'total', 0);
    }

    protected function properties(): array
    {
        return array_merge(
            config("hubspot.{$this->object->type()}.include_properties"),
            $this->properties
        );
    }

    protected function associations(): array
    {
        return array_merge(
            config("hubspot.{$this->object->type()}.include_associations"),
            $this->associations
        );
    }
}
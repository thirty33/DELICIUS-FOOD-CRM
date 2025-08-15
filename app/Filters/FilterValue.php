<?php

namespace App\Filters;

class FilterValue
{
    public function __construct(private readonly array|string|bool|null $value)
    {}

    public function getValue(): array|string|bool|null
    {
        return $this->value;
    }
}
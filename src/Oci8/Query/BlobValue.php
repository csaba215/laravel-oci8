<?php

namespace Yajra\Oci8\Query;

final readonly class BlobValue
{
    public function __construct(public string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}

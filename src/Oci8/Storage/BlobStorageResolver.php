<?php

namespace Yajra\Oci8\Storage;

interface BlobStorageResolver
{
    public function table(string $path, array $config): string;

    public function pathColumn(string $path, array $config): string;

    public function blobColumn(string $path, array $config): string;

    public function path(string $path, array $config): string;
}

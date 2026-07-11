<?php

namespace Yajra\Oci8\Storage;

use InvalidArgumentException;

class DefaultBlobStorageResolver implements BlobStorageResolver
{
    public function table(string $path, array $config): string
    {
        return $this->required($config, 'table');
    }

    public function pathColumn(string $path, array $config): string
    {
        return $config['path_column'] ?? 'path';
    }

    public function blobColumn(string $path, array $config): string
    {
        return $config['blob_column'] ?? 'contents';
    }

    public function path(string $path, array $config): string
    {
        return trim($path, '/');
    }

    private function required(array $config, string $key): string
    {
        if (empty($config[$key])) {
            throw new InvalidArgumentException("Missing required oracle-blob disk configuration [{$key}].");
        }

        return $config[$key];
    }
}

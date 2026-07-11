<?php

namespace Yajra\Oci8\Storage;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Throwable;

class BlobStorageAdapter implements FilesystemAdapter
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly BlobStorageResolver $resolver,
        private readonly array $config = []
    ) {}

    public function fileExists(string $path): bool
    {
        try {
            return $this->baseQuery($path)->exists();
        } catch (Throwable $e) {
            throw UnableToCheckExistence::forLocation($path, $e);
        }
    }

    public function directoryExists(string $path): bool
    {
        $path = trim($path, '/');

        if ($path === '') {
            return true;
        }

        try {
            return $this->tableQuery($path)
                ->where($this->pathColumn($path), 'like', $this->storedPath($path).'/%')
                ->exists();
        } catch (Throwable $e) {
            throw UnableToCheckExistence::forLocation($path, $e);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->writeContents($path, $contents);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $contents = stream_get_contents($contents);

        if ($contents === false) {
            throw UnableToWriteFile::atLocation($path, 'Unable to read the input stream.');
        }

        $this->write($path, $contents, $config);
    }

    public function read(string $path): string
    {
        try {
            $row = $this->baseQuery($path)->first([$this->blobColumn($path)]);
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }

        if ($row === null) {
            throw UnableToReadFile::fromLocation($path, 'File does not exist.');
        }

        return $this->blobToString($this->rowValue($row, $this->blobColumn($path)));
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        try {
            $this->baseQuery($path)->delete();
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $path = trim($path, '/');

        try {
            if ($path === '') {
                $this->tableQuery($path)->delete();

                return;
            }

            $this->tableQuery($path)
                ->where($this->pathColumn($path), 'like', $this->storedPath($path).'/%')
                ->delete();
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function createDirectory(string $path, Config $config): void {}

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'The oracle-blob driver does not store visibility.');
    }

    public function visibility(string $path): FileAttributes
    {
        if (! $this->fileExists($path)) {
            throw UnableToRetrieveMetadata::visibility($path, 'File does not exist.');
        }

        return new FileAttributes($path, null, Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        if (! $this->fileExists($path)) {
            throw UnableToRetrieveMetadata::mimeType($path, 'File does not exist.');
        }

        return new FileAttributes($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        if (! $this->fileExists($path)) {
            throw UnableToRetrieveMetadata::lastModified($path, 'File does not exist.');
        }

        return new FileAttributes($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path, strlen($this->read($path)));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $path = trim($path, '/');
        $directories = [];

        $query = $this->tableQuery($path);
        if ($path !== '') {
            $query->where($this->pathColumn($path), 'like', $this->storedPath($path).'/%');
        }

        foreach ($query->get([$this->pathColumn($path)]) as $row) {
            $storedPath = $this->rowValue($row, $this->pathColumn($path));

            if (! is_string($storedPath)) {
                continue;
            }

            $relativePath = trim($storedPath, '/');
            $childPath = $path === '' ? $relativePath : substr($relativePath, strlen($path) + 1);
            if (! $deep && str_contains($childPath, '/')) {
                $directory = ($path === '' ? '' : $path.'/').strtok($childPath, '/');

                if (! isset($directories[$directory])) {
                    $directories[$directory] = true;
                    yield new DirectoryAttributes($directory);
                }

                continue;
            }

            yield new FileAttributes($relativePath);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->write($destination, $this->read($source), $config);
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    private function writeContents(string $path, string $contents): void
    {
        $pathColumn = $this->pathColumn($path);
        $blobColumn = $this->blobColumn($path);
        $storedPath = $this->storedPath($path);

        if ($this->baseQuery($path)->exists()) {
            $query = $this->baseQuery($path);

            if (method_exists($query, 'updateLob')) {
                $query->updateLob([], [$blobColumn => $contents], $this->config['sequence'] ?? 'id');

                return;
            }

            $query->update([$blobColumn => $contents]);

            return;
        }

        $query = $this->tableQuery($path);
        if (method_exists($query, 'insertLob')) {
            $query->insertLob([$pathColumn => $storedPath], [$blobColumn => $contents], $this->config['sequence'] ?? 'id');

            return;
        }

        $query->insert([$pathColumn => $storedPath, $blobColumn => $contents]);
    }

    private function tableQuery(string $path): mixed
    {
        return $this->connection->table($this->resolver->table($path, $this->config));
    }

    private function baseQuery(string $path): mixed
    {
        return $this->tableQuery($path)->where($this->pathColumn($path), $this->storedPath($path));
    }

    private function storedPath(string $path): string
    {
        return $this->resolver->path($path, $this->config);
    }

    private function pathColumn(string $path): string
    {
        return $this->resolver->pathColumn($path, $this->config);
    }

    private function blobColumn(string $path): string
    {
        return $this->resolver->blobColumn($path, $this->config);
    }

    private function blobToString(mixed $blob): string
    {
        if (is_string($blob)) {
            return $blob;
        }

        if (is_resource($blob)) {
            return stream_get_contents($blob) ?: '';
        }

        if (is_object($blob) && method_exists($blob, 'load')) {
            return (string) $blob->load();
        }

        return (string) $blob;
    }

    private function rowValue(mixed $row, string $column): mixed
    {
        $row = (array) $row;

        return Arr::get($row, $column)
            ?? Arr::get($row, strtoupper($column))
            ?? Arr::get($row, strtolower($column));
    }
}

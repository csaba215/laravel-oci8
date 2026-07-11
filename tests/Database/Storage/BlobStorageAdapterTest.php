<?php

namespace Yajra\Oci8\Tests\Database\Storage;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use League\Flysystem\Config;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Yajra\Oci8\Storage\BlobStorageAdapter;
use Yajra\Oci8\Storage\BlobStorageResolver;
use Yajra\Oci8\Storage\DefaultBlobStorageResolver;

class BlobStorageAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();

        parent::tearDown();
    }

    public function test_default_resolver_uses_configured_table_and_columns(): void
    {
        $resolver = new DefaultBlobStorageResolver;

        $this->assertSame('filesystem_blobs', $resolver->table('docs/report.pdf', [
            'table' => 'filesystem_blobs',
        ]));
        $this->assertSame('path', $resolver->pathColumn('docs/report.pdf', []));
        $this->assertSame('contents', $resolver->blobColumn('docs/report.pdf', []));
        $this->assertSame('docs/report.pdf', $resolver->path('/docs/report.pdf', []));
    }

    public function test_it_reads_blob_contents_from_configured_columns(): void
    {
        $connection = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);

        $connection->shouldReceive('table')->once()->with('filesystem_blobs')->andReturn($query);
        $query->shouldReceive('where')->once()->with('path', 'docs/report.pdf')->andReturnSelf();
        $query->shouldReceive('first')->once()->with(['contents'])->andReturn((object) [
            'CONTENTS' => 'binary contents',
        ]);

        $adapter = new BlobStorageAdapter($connection, new DefaultBlobStorageResolver, [
            'table' => 'filesystem_blobs',
        ]);

        $this->assertSame('binary contents', $adapter->read('docs/report.pdf'));
    }

    public function test_it_inserts_new_blob_records(): void
    {
        $connection = m::mock(ConnectionInterface::class);
        $existsQuery = m::mock(Builder::class);
        $insertQuery = m::mock(Builder::class);

        $connection->shouldReceive('table')->once()->with('filesystem_blobs')->andReturn($existsQuery);
        $existsQuery->shouldReceive('where')->once()->with('path', 'docs/report.pdf')->andReturnSelf();
        $existsQuery->shouldReceive('exists')->once()->andReturnFalse();

        $connection->shouldReceive('table')->once()->with('filesystem_blobs')->andReturn($insertQuery);
        $insertQuery->shouldReceive('insert')->once()->with([
            'path' => 'docs/report.pdf',
            'contents' => 'binary contents',
        ])->andReturnTrue();

        $adapter = new BlobStorageAdapter($connection, new DefaultBlobStorageResolver, [
            'table' => 'filesystem_blobs',
        ]);

        $adapter->write('docs/report.pdf', 'binary contents', new Config);

        $this->addToAssertionCount(1);
    }

    public function test_it_updates_existing_blob_records(): void
    {
        $connection = m::mock(ConnectionInterface::class);
        $existsQuery = m::mock(Builder::class);
        $updateQuery = m::mock(Builder::class);

        $connection->shouldReceive('table')->once()->with('filesystem_blobs')->andReturn($existsQuery);
        $existsQuery->shouldReceive('where')->once()->with('path', 'docs/report.pdf')->andReturnSelf();
        $existsQuery->shouldReceive('exists')->once()->andReturnTrue();

        $connection->shouldReceive('table')->once()->with('filesystem_blobs')->andReturn($updateQuery);
        $updateQuery->shouldReceive('where')->once()->with('path', 'docs/report.pdf')->andReturnSelf();
        $updateQuery->shouldReceive('update')->once()->with([
            'contents' => 'updated contents',
        ])->andReturn(1);

        $adapter = new BlobStorageAdapter($connection, new DefaultBlobStorageResolver, [
            'table' => 'filesystem_blobs',
        ]);

        $adapter->write('docs/report.pdf', 'updated contents', new Config);

        $this->addToAssertionCount(1);
    }

    public function test_it_allows_custom_resolvers_for_path_table_and_columns(): void
    {
        $connection = m::mock(ConnectionInterface::class);
        $query = m::mock(Builder::class);
        $resolver = new CustomBlobStorageResolver;

        $connection->shouldReceive('table')->once()->with('tenant_files')->andReturn($query);
        $query->shouldReceive('where')->once()->with('file_key', 'tenant-a:docs/report.pdf')->andReturnSelf();
        $query->shouldReceive('first')->once()->with(['payload'])->andReturn((object) [
            'PAYLOAD' => 'tenant contents',
        ]);

        $adapter = new BlobStorageAdapter($connection, $resolver, [
            'tenant' => 'tenant-a',
        ]);

        $this->assertSame('tenant contents', $adapter->read('docs/report.pdf'));
    }
}

class CustomBlobStorageResolver implements BlobStorageResolver
{
    public function table(string $path, array $config): string
    {
        return 'tenant_files';
    }

    public function pathColumn(string $path, array $config): string
    {
        return 'file_key';
    }

    public function blobColumn(string $path, array $config): string
    {
        return 'payload';
    }

    public function path(string $path, array $config): string
    {
        return $config['tenant'].':'.trim($path, '/');
    }
}

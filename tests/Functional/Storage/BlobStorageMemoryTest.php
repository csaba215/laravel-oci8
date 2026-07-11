<?php

namespace Yajra\Oci8\Tests\Functional\Storage;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Depends;
// use PHPUnit\Framework\Attributes\RequiresEnvironmentVariable;
use PHPUnit\Framework\Attributes\Test;
use Yajra\Oci8\Tests\TestCase;

// #[RequiresEnvironmentVariable('ORACLE_BLOB_1GB_TESTS', 'true')]
class BlobStorageMemoryTest extends TestCase
{
    private const DISK = 'oracle_blob_memory';

    private const PATH = 'memory/one-gb.bin';

    private const SIZE = 1073741824;

    private const MAX_MEMORY_INCREASE = 134217728;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('filesystems.disks.'.self::DISK, [
            'driver' => 'oracle-blob',
            'connection' => 'oracle',
            'table' => 'blob_storage_memory_files',
            'path_column' => 'path',
            'blob_column' => 'contents',
            'sequence' => 'id',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->app['db']->connection()->getDriverName() !== 'oracle') {
            $this->markTestSkipped('The 1 GB BLOB storage memory test requires the Oracle connection.');
        }

        if (! Schema::hasTable('blob_storage_memory_files')) {
            Schema::create('blob_storage_memory_files', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('path')->unique();
                $table->binary('contents')->nullable();
            });
        }
    }

    #[Test]
    public function it_uploads_a_one_gb_file_without_materializing_it_in_memory(): string
    {
        Storage::disk(self::DISK)->delete(self::PATH);

        $source = $this->createSparseOneGbFile();
        $stream = fopen($source, 'rb');

        try {
            $memoryIncrease = $this->measurePeakMemoryIncrease(function () use ($stream): void {
                Storage::disk(self::DISK)->put(self::PATH, $stream);
            });
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            unlink($source);
        }

        $this->assertTrue(Storage::disk(self::DISK)->exists(self::PATH));
        $this->assertLessThan(
            self::MAX_MEMORY_INCREASE,
            $memoryIncrease,
            'Uploading a 1 GB BLOB should not increase PHP peak memory by more than 128 MB.'
        );

        return self::PATH;
    }

    #[Test]
    #[Depends('it_uploads_a_one_gb_file_without_materializing_it_in_memory')]
    public function it_downloads_a_one_gb_file_without_materializing_it_in_memory(string $path): void
    {
        $bytesRead = 0;

        try {
            $memoryIncrease = $this->measurePeakMemoryIncrease(function () use ($path, &$bytesRead): void {
                $stream = Storage::disk(self::DISK)->readStream($path);

                try {
                    while (! feof($stream)) {
                        $bytesRead += strlen(fread($stream, 1048576));
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            });
        } finally {
            Storage::disk(self::DISK)->delete($path);
            Schema::dropIfExists('blob_storage_memory_files');
        }

        $this->assertSame(self::SIZE, $bytesRead);
        $this->assertLessThan(
            self::MAX_MEMORY_INCREASE,
            $memoryIncrease,
            'Downloading a 1 GB BLOB should not increase PHP peak memory by more than 128 MB.'
        );
    }

    private function createSparseOneGbFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oci8-blob-1gb-');
        $file = fopen($path, 'wb');

        try {
            ftruncate($file, self::SIZE);
        } finally {
            fclose($file);
        }

        return $path;
    }

    private function measurePeakMemoryIncrease(callable $callback): int
    {
        gc_collect_cycles();

        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $start = memory_get_usage(true);

        $callback();

        return memory_get_peak_usage(true) - $start;
    }
}

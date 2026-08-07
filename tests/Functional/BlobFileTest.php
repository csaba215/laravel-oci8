<?php

namespace Yajra\Oci8\Tests\Functional;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Yajra\Oci8\Eloquent\OracleEloquent;
use Yajra\Oci8\Tests\TestCase;

class BlobFileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('blob_files', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->binary('contents');
        });
    }

    protected function tearDown(): void
    {
        Schema::drop('blob_files');

        parent::tearDown();
    }

    #[Test]
    public function it_can_create_and_get_a_file_stored_in_a_blob(): void
    {
        $contents = str_repeat("\x00\xFFLaravel OCI8 BLOB\x10", 40000);
        $file = UploadedFile::fake()->createWithContent('payload.bin', $contents);

        $blobFile = BlobFile::create([
            'filename' => $file->getClientOriginalName(),
            'contents' => $file->getContent(),
        ]);
        $storedBlobFile = BlobFile::findOrFail($blobFile->id);

        $this->assertSame('payload.bin', $storedBlobFile->filename);
        $this->assertSame($contents, $storedBlobFile->contents);
    }

    #[Test]
    public function it_can_insert_and_get_a_file_stored_in_a_blob(): void
    {
        $contents = str_repeat("\x00\xFFLaravel OCI8 BLOB\x10", 40000);
        $file = UploadedFile::fake()->createWithContent('inserted-payload.bin', $contents);

        $inserted = DB::table('blob_files')->insert([
            'filename' => $file->getClientOriginalName(),
            'contents' => $file->getContent(),
        ]);
        $storedBlobFile = DB::table('blob_files')
            ->where('filename', 'inserted-payload.bin')
            ->first();

        $this->assertTrue($inserted);
        $this->assertNotNull($storedBlobFile);
        $this->assertSame('inserted-payload.bin', $storedBlobFile->filename);
        $this->assertSame($contents, $storedBlobFile->contents);
    }
}

class BlobFile extends OracleEloquent
{
    public $timestamps = false;

    protected $guarded = [];

    protected $binaries = ['contents'];
}

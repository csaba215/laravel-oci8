<?php

namespace Yajra\Oci8\Tests\Functional;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Yajra\Oci8\Tests\MultiBlob;
use Yajra\Oci8\Tests\TestCase;

class ModelTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_can_update_model_with_multiple_blob_columns()
    {
        $multiBlob = MultiBlob::create();
        $multiBlob->blob_1 = ['test'];
        $multiBlob->blob_2 = ['test2'];
        $multiBlob->status = 1;
        $multiBlob->save();

        $this->assertDatabaseHas('multi_blobs', ['status' => 1]);
    }
}

<?php

namespace App\Core\Test;

/**
 * @group cache
 * Class CacheTest
 */
class CacheTest extends \App\Core\Components\UnitTestApp
{

    public function testCacheSet()
    {
        $this->assertEquals(
            true,
            $this->di->getCache()->set("unittest", "Punit", "test")
        );
    }

    public function testCacheGet()
    {
        $this->assertEquals(
            "Punit",
            $this->di->getCache()->get("unittest", "test")
        );
    }
    public function testCacheDelete()
    {
        $this->assertEquals(
            true,
            $this->di->getCache()->delete("unittest", "test")
        );
    }

    public function testCacheGetMultiple()
    {
        $this->di->getCache()->set("random", "100", "test");
        $this->di->getCache()->set("random2", "100", "test");
        $this->assertContains(
            100,
            array_values($this->di->getCache()->getAll("test"))
        );
    }

    public function testCacheFlush()
    {
        $this->di->getCache()->set("random", "100");
        $this->di->getCache()->set("random2", "120");
        $this->assertEquals(
            true,
            $this->di->getCache()->flush()
        );
    }

    public function testCacheFlushByType()
    {
        $this->di->getCache()->set("random", "100", "unit");
        $this->assertEquals(
            true,
            $this->di->getCache()->flushByType("unit")
        );
    }

    public function testCacheFlushAll()
    {
        $this->assertEquals(
            true,
            $this->di->getCache()->flushAll()
        );
    }

    public function testCacheDefault()
    {
        $this->assertEquals(
            "123",
            $this->di->getCache()->get("cool", "default", "123")
        );

        $this->assertEquals(
            false,
            $this->di->getCache()->get("nothing", "default")
        );
    }
    /**
     * @covers Cache::blockCache
     * @return void
     */
    public function testCacheBlocked()
    {
        $this->di->getCache()->setMultiple([
            "id" => "28",
            "key" => "some_secret_key",
        ], "useless");
        $this->di->getCache()->set("key1", "value", "useless");
        $this->assertEquals(3, count($this->di->getCache()->getAll("useless")));
        $this->di->getCache()->blockCache("useless", 1);
        $this->di->getCache()->setMultiple([
            "id" => "28",
            "key" => "some_secret_key",
        ], "useless");
        $this->di->getCache()->set("key1", "value", "useless");
        $this->assertEquals(0, count($this->di->getCache()->getAll("useless")));
    }
}

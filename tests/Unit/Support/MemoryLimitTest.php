<?php

namespace Tests\Unit\Support;

use App\Support\MemoryLimit;
use PHPUnit\Framework\TestCase;

class MemoryLimitTest extends TestCase
{
    public function test_it_reads_the_sizes_php_uses(): void
    {
        $this->assertSame(-1, MemoryLimit::bytes('-1'));
        $this->assertSame(128 * 1024 * 1024, MemoryLimit::bytes('128M'));
        $this->assertSame(256 * 1024 * 1024, MemoryLimit::bytes('256m'));
        $this->assertSame(64 * 1024 * 1024, MemoryLimit::bytes('64M'));
        $this->assertSame(768 * 1024 * 1024, MemoryLimit::bytes('768M'));
        $this->assertSame(1024 * 1024 * 1024, MemoryLimit::bytes('1G'));
        $this->assertSame(512 * 1024, MemoryLimit::bytes('512K'));
        $this->assertSame(1000, MemoryLimit::bytes('1000'));
        $this->assertSame(134217728, MemoryLimit::bytes(' 134217728 '));
    }

    public function test_it_rejects_a_value_that_is_not_a_size(): void
    {
        $this->assertNull(MemoryLimit::bytes(''));
        $this->assertNull(MemoryLimit::bytes('128MB'));
        $this->assertNull(MemoryLimit::bytes('secret-value'));
        $this->assertNull(MemoryLimit::bytes('128 M'));
    }

    public function test_the_shared_host_floor_is_128m(): void
    {
        $this->assertTrue(MemoryLimit::meetsSharedFloor('-1'));
        $this->assertTrue(MemoryLimit::meetsSharedFloor('128M'));
        $this->assertTrue(MemoryLimit::meetsSharedFloor('768M'));
        $this->assertFalse(MemoryLimit::meetsSharedFloor('64M'));
        $this->assertFalse(MemoryLimit::meetsSharedFloor('127M'));
        $this->assertFalse(MemoryLimit::meetsSharedFloor('secret-value'));
    }
}

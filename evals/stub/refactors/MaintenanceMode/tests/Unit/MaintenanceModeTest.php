<?php

namespace App\Tests\Unit;

use App\Service\MaintenanceMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class MaintenanceModeTest extends TestCase
{
    public function test_the_last_second_of_a_window_is_active(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mm');
        file_put_contents($file, json_encode(['from' => 1000, 'until' => 2000]));

        $status = (new MaintenanceMode(new MockClock('@1999'), $file, '/nowhere'))->status();
        unlink($file);

        $this->assertSame(['active' => true, 'message' => 'Down for maintenance', 'retry_after' => 1], $status);
    }
}

<?php

namespace App\Service;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class MaintenanceMode
{
    public function __construct(
        private ClockInterface $clock,
        #[Autowire(env: 'default::MAINTENANCE_FILE')]
        private ?string $file,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * Whether a maintenance window from the maintenance file is in progress right now.
     *
     * @return array{active: bool, message: ?string, retry_after: ?int}
     */
    public function status(): array
    {
        $inactive = ['active' => false, 'message' => null, 'retry_after' => null];

        $path = $this->file ?? $this->projectDir.'/var/maintenance.json';
        if (!is_file($path)) {
            return $inactive;
        }

        $window = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $now = $this->clock->now()->getTimestamp();
        if ($now < $window['from'] || $now >= $window['until']) {
            return $inactive;
        }

        return [
            'active' => true,
            'message' => $window['message'] ?? 'Down for maintenance',
            'retry_after' => $window['until'] - $now,
        ];
    }
}

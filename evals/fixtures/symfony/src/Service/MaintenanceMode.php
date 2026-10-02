<?php

namespace App\Service;

class MaintenanceMode
{
    /**
     * Whether a maintenance window from the maintenance file is in progress right now.
     *
     * @return array{active: bool, message: ?string, retry_after: ?int}
     */
    public function status(): array
    {
        $inactive = ['active' => false, 'message' => null, 'retry_after' => null];

        $path = $_SERVER['MAINTENANCE_FILE'] ?? dirname(__DIR__, 2).'/var/maintenance.json';
        if (!is_file($path)) {
            return $inactive;
        }

        $window = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $now = time();
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

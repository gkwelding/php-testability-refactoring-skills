<?php

namespace App\Controller;

use App\Service\MaintenanceMode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class StatusController
{
    #[Route('/status', methods: ['GET'])]
    public function __invoke(MaintenanceMode $maintenance): JsonResponse
    {
        $status = $maintenance->status();

        return $status['active']
            ? new JsonResponse($status, 503, ['Retry-After' => (string) $status['retry_after']])
            : new JsonResponse($status);
    }
}

<?php

namespace App\Controller;

use App\Service\ReorderPlanner;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ReorderController
{
    #[Route('/reorders', methods: ['POST'])]
    public function __invoke(Request $request, ReorderPlanner $planner): JsonResponse
    {
        $body = $request->toArray();

        try {
            return new JsonResponse($planner->plan($body['par'], $body['pack_size'] ?? 1));
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 503);
        }
    }
}

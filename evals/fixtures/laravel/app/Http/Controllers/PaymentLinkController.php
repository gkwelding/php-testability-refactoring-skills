<?php

namespace App\Http\Controllers;

use App\Services\PaymentLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentLinkController extends Controller
{
    public function store(Request $request, PaymentLinkService $links): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        try {
            $link = $links->create($data['order'], $data['amount'], $data['currency'] ?? 'GBP');
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($link, $link['reused'] ? 200 : 201);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\OrderSummaryExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OrderSummaryController extends Controller
{
    public function __invoke(Request $request, string $currency): Response
    {
        $lines = $request->validate([
            'lines' => ['present', 'array'],
            'lines.*.sku' => ['required', 'string'],
            'lines.*.amount' => ['required', 'integer'],
            'lines.*.currency' => ['required', 'string', 'size:3'],
        ])['lines'];

        $csv = (new OrderSummaryExporter())->export($lines, $currency);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

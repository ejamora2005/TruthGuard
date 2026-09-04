<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function complete(Request $request): JsonResponse
    {
        $request->user()->completeCurrentOnboarding();

        return response()->json([
            'ok' => true,
            'status' => 'completed',
        ]);
    }

    public function skip(Request $request): JsonResponse
    {
        $request->user()->skipCurrentOnboarding();

        return response()->json([
            'ok' => true,
            'status' => 'skipped',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Detection;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $userDetections = Detection::query()
            ->where('user_id', $request->user()->id);

        $totalDetections = (clone $userDetections)->count();
        $fakeDetections = (clone $userDetections)->where('verdict', 'fake')->count();
        $reviewDetections = (clone $userDetections)->where('verdict', 'review')->count();
        $averageFakeScore = (int) round((clone $userDetections)->avg('fake_score') ?? 0);

        $recentDetections = Detection::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(8)
            ->get();

        $fakeImages = Detection::query()
            ->where('user_id', $request->user()->id)
            ->where('verdict', 'fake')
            ->where('media_type', 'image')
            ->latest()
            ->limit(12)
            ->get();

        return view('dashboard', [
            'totalDetections' => $totalDetections,
            'fakeDetections' => $fakeDetections,
            'reviewDetections' => $reviewDetections,
            'averageFakeScore' => $averageFakeScore,
            'recentDetections' => $recentDetections,
            'fakeImages' => $fakeImages,
        ]);
    }
}

@include('errors.layout', [
    'code' => '503',
    'title' => 'TruthGuard is Temporarily Unavailable',
    'description' => "We're performing maintenance or deploying an update. Please check again shortly.",
    'illustration' => 'maintenance',
    'actions' => [
        ['label' => 'Try Again', 'type' => 'retry'],
    ],
])

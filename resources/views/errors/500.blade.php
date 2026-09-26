@include('errors.layout', [
    'code' => '500',
    'title' => 'Something Went Wrong',
    'description' => "TruthGuard encountered an unexpected error. Our system couldn't complete your request.",
    'variant' => 'system',
    'actions' => [
        ['label' => 'Try Again', 'type' => 'retry'],
        ['label' => 'Return Home', 'type' => 'home', 'url' => url('/'), 'primary' => false],
    ],
])

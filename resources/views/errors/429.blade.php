@include('errors.layout', [
    'code' => '429',
    'title' => 'Too Many Requests',
    'description' => "You're sending requests a little too quickly. Please wait a moment and try again.",
    'variant' => 'rate-limit',
    'actions' => [
        ['label' => 'Try Again', 'type' => 'retry'],
    ],
])

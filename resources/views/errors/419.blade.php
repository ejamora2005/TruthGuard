@include('errors.layout', [
    'code' => '419',
    'title' => 'Session Expired',
    'description' => 'Your session has expired for security reasons. Please refresh the page and try again.',
    'illustration' => 'session',
    'actions' => [
        ['label' => 'Refresh Page', 'type' => 'refresh'],
        ['label' => 'Return Home', 'type' => 'home', 'url' => url('/'), 'primary' => false],
    ],
])

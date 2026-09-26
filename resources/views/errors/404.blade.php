@include('errors.layout', [
    'code' => '404',
    'title' => 'Page Not Found',
    'description' => "The page you're looking for may have been moved, deleted, or doesn't exist.",
    'variant' => 'not-found',
    'actions' => [
        ['label' => 'Return Home', 'type' => 'home', 'url' => url('/')],
        ['label' => 'Go Back', 'type' => 'back'],
    ],
])

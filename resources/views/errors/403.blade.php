@include('errors.layout', [
    'code' => '403',
    'title' => 'Access Restricted',
    'description' => "You don't have permission to access this page.",
    'variant' => 'restricted',
    'actions' => [
        ['label' => 'Return Home', 'type' => 'home', 'url' => url('/')],
    ],
])

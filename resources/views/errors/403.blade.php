@include('errors.layout', [
    'code' => '403',
    'title' => 'Access Restricted',
    'description' => "You don't have permission to access this page.",
    'illustration' => 'restricted',
    'actions' => [
        ['label' => 'Return Home', 'type' => 'home', 'url' => url('/')],
    ],
])

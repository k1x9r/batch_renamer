<?php
return [
    'routes' => [
        ['name' => 'rename#process', 'url' => '/api/v1/rename', 'verb' => 'POST'],
        ['name' => 'rename#undo',    'url' => '/api/v1/undo',   'verb' => 'POST'],
    ]
];
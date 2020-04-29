<?php

include 'init.php';

$app->cdn['atk'] = '../public';
$mc = $app->add([
    '\atk4\mastercrud\MasterCRUD',
    'ipp' => 5,
    'quickSearch' => ['name'],
]);
$mc->setModel(
    new Client($app->db),
    [
        'Invoices' => [
            'Lines' => [
                ['_crud' => ['CRUD', 'displayFields' => ['item', 'total']]]
            ],
            'Allocations' => []
        ],
        'Payments' => [
            'Allocations' => []
        ]
    ]
);

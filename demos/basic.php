<?php
include'init.php';

$app->cdn['atk'] = '../public';
$mc = $app->add([
    '\atk4\mastercrud\MasterCRUD'
]);
$mc->setModel(new Client($app->db),
  [
    'Invoices'=>[
      'Lines'=>[
        ['_crud'=>['canDelete'=>false]]
      ],
      'Allocations'=>[]
    ],
    'Payments'=>[
      'Allocations'=>[]
    ],
    '_crud'=>[
        'ipp'=>5,
        'quickSearch'=>['name']
       ]
  ]
);



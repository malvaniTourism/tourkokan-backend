<?php

$models_directory = '';
$model_namespace  = 'App\\';
if (file_exists(app_path('Models'))) {
    $models_directory .= DIRECTORY_SEPARATOR . 'Models';
    $model_namespace .= 'Models\\';
}

return [
    'models_directory' => $models_directory,
    'model_namespace'  => $model_namespace,
    'length'           => 13,
];

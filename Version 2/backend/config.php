<?php
declare(strict_types=1);

return [

    // Base de datos
    'db_dsn'  => 'mysql:host=127.0.0.1;dbname=devforge;charset=utf8mb4',
    'db_user' => 'root',
    'db_pass' => '',

    // Directorio donde se generan los proyectos
    'files_dir' => __DIR__ . '/files',

    // Modelo de Ollama
    'ollama_model' => 'llama3', // cambia si usas otro

    'max_generated_files_per_step' => 30
];


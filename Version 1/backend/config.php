<?php
// config.php
declare(strict_types=1);

return [
    // Database
    'db_dsn' => 'mysql:host=127.0.0.1;dbname=devforge;charset=utf8mb4',
    'db_user' => 'root',
    'db_pass' => '',

    // Remote AI (pon aquí la URL de tu proxy/IA)
    // call_remote_ai() en ai_client.php usará este endpoint y API key.
    'ai_api_url' => 'https://your-ai-proxy.example/api', // CAMBIA
    'ai_api_key' => 'REPLACE_WITH_KEY',

    // Files dir
    'files_dir' => __DIR__ . '/files',

    // Security / limits
    'max_iterations_per_call' => 1, // control por petición
    'max_generated_files_per_step' => 30,
];


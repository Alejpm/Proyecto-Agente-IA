<?php
declare(strict_types=1);

function call_remote_ai_for_step(PDO $pdo, string $prompt, array $config): array {

    $url = "http://localhost:11434/api/generate";

    $payload = json_encode([
        "model" => $config['ollama_model'],
        "prompt" => $prompt,
        "stream" => false
    ]);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 120
    ]);

    $raw = curl_exec($ch);

    if ($raw === false) {
        throw new RuntimeException("Error conectando con Ollama: " . curl_error($ch));
    }

    curl_close($ch);

    $data = json_decode($raw, true);

    if (!isset($data["response"])) {
        throw new RuntimeException("Respuesta inválida de Ollama.");
    }

    return extract_json_from_response($data["response"]);
}

function extract_json_from_response(string $text): array {

    $start = strpos($text, '{');
    $end   = strrpos($text, '}');

    if ($start === false || $end === false) {
        throw new RuntimeException("No se encontró JSON en la respuesta.");
    }

    $json = substr($text, $start, $end - $start + 1);

    $data = json_decode($json, true);

    if (!$data) {
        throw new RuntimeException("JSON inválido generado por el modelo.");
    }

    return $data;
}



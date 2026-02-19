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
        throw new RuntimeException("No se pudo conectar con Ollama: " . curl_error($ch));
    }

    curl_close($ch);

    $decoded = json_decode($raw, true);

    if (!$decoded) {
        throw new RuntimeException("Ollama no devolvió JSON válido. Respuesta: " . $raw);
    }

    // A veces Ollama devuelve 'response'
    if (!isset($decoded['response'])) {
        throw new RuntimeException("Respuesta inválida de Ollama: " . $raw);
    }

    $text = $decoded['response'];

    return extract_json_from_text($text);
}

function extract_json_from_text(string $text): array {

    $start = strpos($text, '{');
    $end   = strrpos($text, '}');

    if ($start === false || $end === false) {
        throw new RuntimeException("El modelo no devolvió JSON estructurado. Respuesta completa:\n" . $text);
    }

    $json = substr($text, $start, $end - $start + 1);

    $data = json_decode($json, true);

    if (!$data) {
        throw new RuntimeException("El JSON generado no es válido:\n" . $json);
    }

    return $data;
}



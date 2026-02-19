<?php
// ai_client.php
declare(strict_types=1);

function call_remote_ai_for_step(PDO $pdo, string $prompt, array $config): array {
    $url = $config['ai_api_url'];
    $key = $config['ai_api_key'];

    $payload = ['prompt' => $prompt];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => [
            "X-API-Key: {$key}",
            "Content-Type: application/x-www-form-urlencoded"
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("AI request failed: {$err}");
    }
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) {
        throw new RuntimeException("AI returned HTTP {$http}: {$raw}");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("AI returned non-JSON: {$raw}");
    }
    return $data;
}


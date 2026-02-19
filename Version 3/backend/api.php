<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';
require __DIR__ . '/ai_client.php';

function json_ok($data = []) {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* =========================
   CREAR MISIÓN
========================= */
if ($action === 'create_mission') {

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($title === '' || $description === '') {
        json_err('Título y descripción obligatorios.');
    }

    $stmt = $pdo->prepare("INSERT INTO missions (title, description, status) VALUES (?, ?, 'pending')");
    $stmt->execute([$title, $description]);
    $missionId = (int)$pdo->lastInsertId();

    $dir = $config['files_dir'] . "/mission_{$missionId}";
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    json_ok(['mission_id' => $missionId]);
}

/* =========================
   LISTAR MISIONES
========================= */
if ($action === 'list_missions') {

    $stmt = $pdo->query("SELECT id, title, status, created_at, updated_at FROM missions ORDER BY created_at DESC");
    json_ok(['missions' => $stmt->fetchAll()]);
}

/* =========================
   OBTENER MISIÓN
========================= */
if ($action === 'get_mission') {

    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) json_err('ID inválido.');

    $stmt = $pdo->prepare("SELECT * FROM missions WHERE id = ?");
    $stmt->execute([$id]);
    $mission = $stmt->fetch();

    if (!$mission) json_err('Misión no encontrada', 404);

    $s = $pdo->prepare("SELECT * FROM mission_steps WHERE mission_id = ? ORDER BY step_index ASC");
    $s->execute([$id]);
    $steps = $s->fetchAll();

    json_ok(['mission' => $mission, 'steps' => $steps]);
}

/* =========================
   EJECUTAR PASO
========================= */
if ($action === 'run_step') {

    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) json_err('ID inválido.');

    $stmt = $pdo->prepare("SELECT * FROM missions WHERE id = ?");
    $stmt->execute([$id]);
    $mission = $stmt->fetch();

    if (!$mission) json_err('Misión no encontrada', 404);
    if ($mission['status'] === 'completed') {
        json_ok(['message' => 'Misión ya completada', 'mission_completed' => true]);
    }

    if ($mission['status'] !== 'running') {
        $pdo->prepare("UPDATE missions SET status='running' WHERE id=?")->execute([$id]);
    }

    $s = $pdo->prepare("SELECT * FROM mission_steps WHERE mission_id=? ORDER BY step_index ASC");
    $s->execute([$id]);
    $prevSteps = $s->fetchAll();

    $context = "Misión: {$mission['title']}\n{$mission['description']}\n\n";

    foreach ($prevSteps as $ps) {
        $context .= "Paso {$ps['step_index']}: {$ps['description']}\n";
    }

    $prompt = build_step_prompt($mission, $context);

    try {
        $aiData = call_remote_ai_for_step($pdo, $prompt, $config);
    } catch (Throwable $e) {
        json_err("Error IA: " . $e->getMessage(), 500);
    }

    if (!isset($aiData['next_step'])) {
        json_err("La IA no devolvió JSON válido.");
    }

    $nextStep = $aiData['next_step'];
    $generatedFiles = $aiData['generated_files'] ?? [];
    $missionCompleted = !empty($aiData['mission_completed']);
    $evaluation = $aiData['evaluation'] ?? '';

    $stmt = $pdo->prepare("SELECT COALESCE(MAX(step_index),0) as maxidx FROM mission_steps WHERE mission_id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $nextIndex = (int)$row['maxidx'] + 1;

    $insert = $pdo->prepare("INSERT INTO mission_steps (mission_id, step_index, description, generated_files, evaluation, status) VALUES (?, ?, ?, ?, ?, 'done')");
    $insert->execute([$id, $nextIndex, $nextStep, json_encode($generatedFiles), $evaluation]);

    $dir = $config['files_dir'] . "/mission_{$id}";
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    foreach ($generatedFiles as $f) {
        if (!isset($f['path'], $f['content'])) continue;

        $path = ltrim(str_replace(['..','\\'], '', $f['path']), '/');
        $full = $dir . '/' . $path;

        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }

        file_put_contents($full, $f['content']);
    }

    if ($missionCompleted) {
        $pdo->prepare("UPDATE missions SET status='completed', current_step=? WHERE id=?")
            ->execute([$nextIndex, $id]);
    } else {
        $pdo->prepare("UPDATE missions SET current_step=? WHERE id=?")
            ->execute([$nextIndex, $id]);
    }

    json_ok([
        'step_index' => $nextIndex,
        'mission_completed' => $missionCompleted
    ]);
}

/* =========================
   LOGS
========================= */
if ($action === 'logs') {

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) json_err('ID inválido.');

    $stmt = $pdo->prepare("SELECT * FROM agent_logs WHERE mission_id = ? ORDER BY created_at DESC LIMIT 200");
    $stmt->execute([$id]);

    json_ok(['logs' => $stmt->fetchAll()]);
}

/* =========================
   DESCARGAR ZIP
========================= */
if ($action === 'download_project') {

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) json_err('ID inválido.');

    $dir = $config['files_dir'] . "/mission_{$id}";
    if (!is_dir($dir)) json_err('No hay archivos generados.');

    $zipPath = sys_get_temp_dir() . "/mission_{$id}.zip";
    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        json_err('No se pudo crear ZIP.', 500);
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    foreach ($files as $file) {
        if ($file->isDir()) continue;
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($dir) + 1);
        $zip->addFile($filePath, $relativePath);
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="mission_' . $id . '.zip"');
    readfile($zipPath);
    unlink($zipPath);
    exit;
}

json_err('Acción no válida', 400);


/* =========================
   PROMPT
========================= */
function build_step_prompt(array $mission, string $context): string {

    return "
Eres un agente autónomo desarrollador backend.

MISIÓN:
{$mission['description']}

PROGRESO ACTUAL:
{$context}

Devuelve SOLO JSON válido:

{
  \"next_step\": \"descripcion clara\",
  \"generated_files\": [
    {\"path\": \"archivo.php\", \"content\": \"codigo\"}
  ],
  \"evaluation\": \"breve evaluacion\",
  \"mission_completed\": false
}
";
}



<?php
// api.php
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

if ($action === 'create_mission') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if ($title === '' || $description === '') json_err('Título y descripción obligatorios.');

    $stmt = $pdo->prepare("INSERT INTO missions (title, description, status) VALUES (?, ?, 'pending')");
    $stmt->execute([$title, $description]);
    $missionId = (int)$pdo->lastInsertId();

    // create files dir
    $dir = $config['files_dir'] . "/mission_{$missionId}";
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    json_ok(['mission_id' => $missionId]);
}

if ($action === 'get_mission') {
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) json_err('ID inválido.');

    $stmt = $pdo->prepare("SELECT * FROM missions WHERE id = ?");
    $stmt->execute([$id]);
    $m = $stmt->fetch();
    if (!$m) json_err('Misión no encontrada', 404);

    // steps count and latest steps
    $s = $pdo->prepare("SELECT * FROM mission_steps WHERE mission_id = ? ORDER BY step_index ASC");
    $s->execute([$id]);
    $steps = $s->fetchAll();

    json_ok(['mission' => $m, 'steps' => $steps]);
}

if ($action === 'list_missions') {
    $stmt = $pdo->query("SELECT id, title, status, created_at, updated_at FROM missions ORDER BY created_at DESC");
    $list = $stmt->fetchAll();
    json_ok(['missions' => $list]);
}

if ($action === 'run_step') {
    // Ejecuta UNA iteración en la misión (control por petición)
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) json_err('ID inválido.');

    // Load mission
    $stmt = $pdo->prepare("SELECT * FROM missions WHERE id = ?");
    $stmt->execute([$id]);
    $mission = $stmt->fetch();
    if (!$mission) json_err('Misión no encontrada', 404);
    if ($mission['status'] === 'completed') json_ok(['message' => 'Misión ya completada']);

    // mark running if pending
    if ($mission['status'] !== 'running') {
        $pdo->prepare("UPDATE missions SET status='running' WHERE id = ?")->execute([$id]);
    }

    // Build context from previous steps
    $s = $pdo->prepare("SELECT * FROM mission_steps WHERE mission_id = ? ORDER BY step_index ASC");
    $s->execute([$id]);
    $prevSteps = $s->fetchAll();

    $context = "Misión: " . $mission['title'] . "\nDescripción: " . $mission['description'] . "\n\n";
    foreach ($prevSteps as $ps) {
        $context .= "Paso {$ps['step_index']}: {$ps['description']}\nEvaluación: " . ($ps['evaluation'] ?? '') . "\n\n";
    }

    // Create prompt for the AI
    $prompt = build_step_prompt($mission, $context);

    // Call remote AI
    try {
        $aiData = call_remote_ai_for_step($pdo, $prompt, $config);
    } catch (Throwable $e) {
        // log and return error (do not crash)
        $pdo->prepare("INSERT INTO agent_logs (mission_id, level, message) VALUES (?, 'error', ?)")->execute([$id, $e->getMessage()]);
        json_err('Error contactando al servicio de IA: ' . $e->getMessage(), 502);
    }

    // Expect AI to return a structured response, try to be tolerant
    /**
     * Expected aiData structure (suggested):
     * {
     *   "next_step":"Escribir archivo X",
     *   "generated_files": [{"path":"src/index.php","content":"<?php ... ?>"}],
     *   "evaluation":"OK" | "NEEDS_FIX",
     *   "mission_completed": false
     * }
     */

    if (!isset($aiData['next_step'])) {
        // fallback: wrap whole text as next step
        $nextStepDesc = substr($aiData['text'] ?? json_encode($aiData), 0, 2000);
        $generatedFiles = [];
        $mission_completed = false;
        $evaluation = 'no-structured-response';
    } else {
        $nextStepDesc = $aiData['next_step'];
        $generatedFiles = $aiData['generated_files'] ?? [];
        $mission_completed = !empty($aiData['mission_completed']);
        $evaluation = $aiData['evaluation'] ?? '';
    }

    // store step
    $pdo->beginTransaction();
    try {
        // Determine next step_index
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(step_index), 0) as maxidx FROM mission_steps WHERE mission_id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $nextIndex = (int)$row['maxidx'] + 1;

        $insert = $pdo->prepare("INSERT INTO mission_steps (mission_id, step_index, description, generated_files, evaluation, status) VALUES (?, ?, ?, ?, ?, ?)");
        $gfiles_json = json_encode($generatedFiles, JSON_UNESCAPED_UNICODE);
        $insert->execute([$id, $nextIndex, $nextStepDesc, $gfiles_json, $evaluation, 'done']);

        // write generated files to disk (safe: limit number)
        $filesWritten = [];
        $dir = $config['files_dir'] . "/mission_{$id}";
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        $written = 0;
        foreach ($generatedFiles as $f) {
            if ($written >= $config['max_generated_files_per_step']) break;
            if (!isset($f['path']) || !isset($f['content'])) continue;
            // sanitize path
            $path = ltrim(str_replace(['..','\\'], ['', '/'], $f['path']), '/');
            $full = $dir . '/' . $path;
            $basedir = dirname($full);
            if (!is_dir($basedir)) mkdir($basedir, 0775, true);
            file_put_contents($full, $f['content']);
            $filesWritten[] = $path;
            $written++;
        }

        // update mission status
        if ($mission_completed) {
            $pdo->prepare("UPDATE missions SET status='completed', current_step = ?, updated_at = NOW() WHERE id = ?")->execute([$nextIndex, $id]);
        } else {
            $pdo->prepare("UPDATE missions SET current_step = ?, updated_at = NOW() WHERE id = ?")->execute([$nextIndex, $id]);
        }

        // log
        $pdo->prepare("INSERT INTO agent_logs (mission_id, level, message) VALUES (?, 'info', ?)")->execute([$id, "Step {$nextIndex} executed. Files: " . implode(',', $filesWritten)]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $pdo->prepare("INSERT INTO agent_logs (mission_id, level, message) VALUES (?, 'error', ?)")->execute([$id, "DB error: " . $e->getMessage()]);
        json_err('Error guardando el paso: ' . $e->getMessage(), 500);
    }

    json_ok([
        'mission_id' => $id,
        'step_index' => $nextIndex,
        'next_step' => $nextStepDesc,
        'files' => $filesWritten,
        'mission_completed' => (bool)$mission_completed
    ]);
}

if ($action === 'download_project') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) json_err('ID inválido.');
    $dir = $config['files_dir'] . "/mission_{$id}";
    if (!is_dir($dir)) json_err('No hay archivos generados.');

    $zipname = "/tmp/mission_{$id}_" . time() . ".zip";
    $zip = new ZipArchive();
    if ($zip->open($zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
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

    // stream file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="mission_' . $id . '.zip"');
    readfile($zipname);
    unlink($zipname);
    exit;
}

if ($action === 'logs') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) json_err('ID inválido.');
    $stmt = $pdo->prepare("SELECT * FROM agent_logs WHERE mission_id = ? ORDER BY created_at DESC LIMIT 200");
    $stmt->execute([$id]);
    $logs = $stmt->fetchAll();
    json_ok(['logs' => $logs]);
}

json_err('Acción no válida', 400);

// -----------------------------
// Helpers
// -----------------------------
function build_step_prompt(array $mission, string $context): string {
    // Prompt template: pide al modelo que devuelva JSON con next_step, generated_files, evaluation, mission_completed.
    $desc = $mission['description'];
    $title = $mission['title'];

    $template = <<<TXT
Eres un agente autónomo de desarrollo. Tu misión: {$title}
Descripcion: {$desc}

Contexto de progreso:
{$context}

REGLAS:
- Devuélveme SOLO un JSON válido con las siguientes claves:
  1) next_step: string describiendo la siguiente acción concreta a realizar.
  2) generated_files: array de objetos { "path":"ruta/archivo.ext", "content":"...contenido..." } (puedes enviar uno o varios archivos).
  3) evaluation: texto corto que evalúe si la solución propuesta resuelve la necesidad.
  4) mission_completed: boolean (true si con este paso se completa la misión).
- Si no puedes generar código, usa generated_files: [] y explica en evaluation.
- Evita incluir explicaciones fuera del JSON.

Devuelve el JSON en la respuesta principal.
TXT;

    // include recent context
    return $template;
}


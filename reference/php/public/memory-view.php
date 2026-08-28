<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Referrer-Policy: no-referrer");

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function envRequired(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Falta la variable de entorno: {$name}");
    }
    return trim($value);
}

function normalizeGithubPath(
    string $input,
    string $expectedOwner,
    string $expectedRepo,
    string $expectedBranch
): string {
    $input = trim($input);

    if ($input === '') {
        throw new InvalidArgumentException('Debes indicar la memoria a consultar.');
    }

    if (preg_match('#^https?://github\.com/#i', $input)) {
        $parts = parse_url($input);

        if (!is_array($parts) || !isset($parts['path'])) {
            throw new InvalidArgumentException('La URL de GitHub no es válida.');
        }

        $segments = array_values(array_filter(
            explode('/', trim((string) $parts['path'], '/')),
            static fn(string $part): bool => $part !== ''
        ));

        if (count($segments) < 5 || $segments[2] !== 'blob') {
            throw new InvalidArgumentException(
                'Usa una URL tipo https://github.com/OWNER/REPO/blob/BRANCH/ruta/archivo.mcma'
            );
        }

        [$owner, $repo, , $branch] = array_slice($segments, 0, 4);

        if (
            !hash_equals($expectedOwner, $owner) ||
            !hash_equals($expectedRepo, $repo) ||
            !hash_equals($expectedBranch, $branch)
        ) {
            throw new InvalidArgumentException(
                'La URL no corresponde al repositorio/rama configurados en MCMA.'
            );
        }

        $input = implode('/', array_slice($segments, 4));
    }

    $path = trim(str_replace('\\', '/', $input), '/');

    if (
        $path === '' ||
        str_contains($path, '..') ||
        !preg_match('#^[A-Za-z0-9/_\-.]+\.mcma$#', $path)
    ) {
        throw new InvalidArgumentException(
            'Ruta inválida. Debe apuntar a un archivo .mcma dentro del repositorio configurado.'
        );
    }

    return $path;
}

function readMemory(string $path, string $token): array
{
    $payload = json_encode(
        ['github_path' => $path],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($payload === false) {
        throw new RuntimeException('No fue posible preparar la solicitud.');
    }

    $ch = curl_init('http://127.0.0.1/mcma/v2/memory-read');

    if ($ch === false) {
        throw new RuntimeException('No fue posible iniciar cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Host: mailit.click',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'User-Agent: Mozilla/5.0 MCMA-Memory-View',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Error al consultar memory-read: ' . $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            'memory-read respondió algo que no es JSON válido. HTTP ' . $status
        );
    }

    if ($status !== 200 || ($data['ok'] ?? false) !== true) {
        $error = (string) ($data['error'] ?? 'unknown_error');
        $detail = isset($data['detail']) ? ' - ' . (string) $data['detail'] : '';
        throw new RuntimeException(
            'No se pudo leer la memoria: ' . $error . $detail
        );
    }

    return $data;
}

$input = '';
$error = '';
$result = null;

try {
    $owner = envRequired('MCMA_GITHUB_OWNER');
    $repo = envRequired('MCMA_GITHUB_REPO');
    $branch = getenv('MCMA_GITHUB_BRANCH') ?: 'main';
    $bridgeToken = envRequired('MCMA_BRIDGE_TOKEN');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $input = trim((string) ($_POST['memory'] ?? ''));

        $githubPath = normalizeGithubPath($input, $owner, $repo, $branch);
        $result = readMemory($githubPath, $bridgeToken);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$text = '';
$logicalPath = '';
$file = '';
$temperature = '';
$createdAt = '';

if (is_array($result)) {
    $memory = $result['memory'] ?? [];

    if (is_array($memory)) {
        $text = (string) ($memory['text'] ?? '');
        $logicalPath = (string) ($memory['logical_path'] ?? '');
        $file = (string) ($memory['file'] ?? '');
        $temperature = (string) ($memory['temperature'] ?? '');
        $createdAt = (string) ($memory['created_at'] ?? '');
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MCMA Memory Reader</title>
<style>
    :root {
        color-scheme: light dark;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    body {
        margin: 0;
        background: Canvas;
        color: CanvasText;
    }
    main {
        max-width: 900px;
        margin: 40px auto;
        padding: 0 20px 40px;
    }
    h1 {
        margin-bottom: 8px;
    }
    .muted {
        opacity: .72;
        margin-top: 0;
    }
    form, .result, .error {
        border: 1px solid color-mix(in srgb, CanvasText 18%, transparent);
        border-radius: 12px;
        padding: 20px;
        margin-top: 22px;
    }
    label {
        display: block;
        font-weight: 700;
        margin-bottom: 8px;
    }
    input[type="text"],
    input[type="password"],
    textarea {
        width: 100%;
        box-sizing: border-box;
        padding: 12px;
        border: 1px solid color-mix(in srgb, CanvasText 25%, transparent);
        border-radius: 8px;
        font: inherit;
        background: Canvas;
        color: CanvasText;
    }
    input[type="text"] {
        margin-bottom: 16px;
    }
    input[type="password"] {
        margin-bottom: 16px;
    }
    button {
        padding: 11px 18px;
        border: 0;
        border-radius: 8px;
        font: inherit;
        font-weight: 700;
        cursor: pointer;
    }
    textarea {
        min-height: 320px;
        resize: vertical;
        white-space: pre-wrap;
    }
    .meta {
        display: grid;
        grid-template-columns: 160px 1fr;
        gap: 6px 12px;
        margin-bottom: 18px;
        word-break: break-word;
    }
    .error {
        border-color: #b00020;
    }
    code {
        word-break: break-all;
    }
</style>
</head>
<body>
<main>
    <h1>MCMA Memory Reader</h1>
    <p class="muted">
        Consulta una memoria cifrada en GitHub y muestra el texto descifrado.
        Puedes pegar la ruta interna o la URL completa de GitHub.
    </p>

    <form method="post" autocomplete="off">
        <label for="memory">Memoria a consultar</label>
        <input
            id="memory"
            name="memory"
            type="text"
            required
            value="<?= h($input) ?>"
            placeholder="memories/hot/manual/archivo.mcma o https://github.com/.../archivo.mcma"
        >

        <button type="submit">Leer y descifrar</button>
    </form>

    <?php if ($error !== ''): ?>
        <div class="error">
            <strong>Error:</strong> <?= h($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($text !== ''): ?>
        <section class="result">
            <h2>Memoria descifrada</h2>

            <div class="meta">
                <strong>Ruta lógica</strong>
                <code><?= h($logicalPath) ?></code>

                <strong>Archivo</strong>
                <code><?= h($file) ?></code>

                <strong>Temperatura</strong>
                <span><?= h($temperature) ?></span>

                <strong>Creada</strong>
                <span><?= h($createdAt) ?></span>
            </div>

            <label for="decoded">Texto</label>
            <textarea id="decoded" readonly><?= h($text) ?></textarea>
        </section>
    <?php endif; ?>
</main>
</body>
</html>

<?php
// index.php – API completa de gestão de documentos

// --- CORS (deve ficar no topo) ---
header("Access-Control-Allow-Origin: *"); 
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 86400); // 1 dia

// Pré-flight (OPTIONS) – responde e sai
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}


declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/db.php';

/**
 * Helpers
 */
function sendJson($data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Normalização da URI
 */
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove o caminho do script (ex: /api/index.php -> /)
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
if ($scriptName !== '/' && strpos($uri, $scriptName) === 0) {
    $uri = substr($uri, strlen($scriptName));
}

// Garante que não termina com / desnecessário (exceto raiz)
if ($uri !== '/' && substr($uri, -1) === '/') {
    $uri = rtrim($uri, '/');
}

/**
 * ROTAS:
 * POST   /register                -> cadastro de usuário
 * POST   /login                   -> login
 * POST   /folders                 -> criar pasta
 * GET    /folders                 -> listar pastas
 * DELETE /folders/{id}            -> deletar pasta
 * GET    /folders/{id}/documents  -> listar docs da pasta
 * POST   /documents               -> upload de documento
 * GET    /documents/{id}          -> download (arquivo)
 */

// 1) Cadastro de usuário
if ($method === 'POST' && $uri === '/register') {
    $data = getJsonInput();

    $name     = trim($data['name'] ?? '');
    $email    = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        sendJson(['error' => 'Nome, email e senha são obrigatórios'], 400);
    }

    // Verifica se email já existe
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        sendJson(['error' => 'Email já cadastrado'], 409);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)');
    $stmt->execute([
        ':name'          => $name,
        ':email'         => $email,
        ':password_hash' => $passwordHash,
    ]);

    $userId = (int)$pdo->lastInsertId();

    sendJson([
        'message' => 'Usuário cadastrado com sucesso',
        'user' => [
            'id'    => $userId,
            'name'  => $name,
            'email' => $email,
        ]
    ], 201);
}

// 2) Login
if ($method === 'POST' && $uri === '/login') {
    $data = getJsonInput();

    $email    = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if ($email === '' || $password === '') {
        sendJson(['error' => 'Email e senha são obrigatórios'], 400);
    }

    $stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM users WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        sendJson(['error' => 'Credenciais inválidas'], 401);
    }

    // Aqui você poderia gerar um token de sessão/JWT. 
    // Para simplificar, apenas retorna os dados do usuário.
    sendJson([
        'message' => 'Login realizado com sucesso',
        'user' => [
            'id'    => (int)$user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
        ]
    ]);
}

// 3) Pastas

// 3.1 Criar pasta
if ($method === 'POST' && $uri === '/folders') {
    $data = getJsonInput();
    $name = trim($data['name'] ?? '');
    $createdBy = isset($data['created_by']) ? (int)$data['created_by'] : null; // opcional

    if ($name === '') {
        sendJson(['error' => 'Nome da pasta é obrigatório'], 400);
    }

    $stmt = $pdo->prepare('INSERT INTO folders (name, created_by) VALUES (:name, :created_by)');
    $stmt->execute([
        ':name'       => $name,
        ':created_by' => $createdBy,
    ]);

    $folderId = (int)$pdo->lastInsertId();

    sendJson([
        'message' => 'Pasta criada com sucesso',
        'folder' => [
            'id'         => $folderId,
            'name'       => $name,
            'created_by' => $createdBy,
        ]
    ], 201);
}

// 3.2 Listar todas as pastas
if ($method === 'GET' && $uri === '/folders') {
    $stmt = $pdo->query('SELECT f.id, f.name, f.created_at, f.created_by, u.name AS created_by_name
                         FROM folders f
                         LEFT JOIN users u ON u.id = f.created_by
                         ORDER BY f.created_at DESC');

    $folders = $stmt->fetchAll();

    sendJson($folders);
}

// 3.3 Deletar pasta
if ($method === 'DELETE' && preg_match('#^/folders/(\d+)$#', $uri, $matches)) {
    $folderId = (int)$matches[1];

    // Verifica se existe
    $stmt = $pdo->prepare('SELECT id FROM folders WHERE id = :id');
    $stmt->execute([':id' => $folderId]);
    if (!$stmt->fetch()) {
        sendJson(['error' => 'Pasta não encontrada'], 404);
    }

    // Deleta (os documentos serão apagados por causa do ON DELETE CASCADE)
    $stmt = $pdo->prepare('DELETE FROM folders WHERE id = :id');
    $stmt->execute([':id' => $folderId]);

    sendJson(['message' => 'Pasta deletada com sucesso']);
}

// 3.4 Listar todos os arquivos dentro da pasta
if ($method === 'GET' && preg_match('#^/folders/(\d+)/documents$#', $uri, $matches)) {
    $folderId = (int)$matches[1];

    // Verifica se pasta existe
    $stmt = $pdo->prepare('SELECT id, name FROM folders WHERE id = :id');
    $stmt->execute([':id' => $folderId]);
    $folder = $stmt->fetch();

    if (!$folder) {
        sendJson(['error' => 'Pasta não encontrada'], 404);
    }

    $stmt = $pdo->prepare('SELECT id, filename, mime_type, size, created_at
                           FROM documents
                           WHERE folder_id = :folder_id
                           ORDER BY created_at DESC');
    $stmt->execute([':folder_id' => $folderId]);

    $documents = $stmt->fetchAll();

    sendJson([
        'folder'    => $folder,
        'documents' => $documents
    ]);
}

// 4) Documentos

// 4.1 Upload de documento
// Espera: multipart/form-data com campos:
// - folder_id (int)
// - uploaded_by (int opcional)
// - file (arquivo)
if ($method === 'POST' && $uri === '/documents') {

    if (!isset($_POST['folder_id']) || !is_numeric($_POST['folder_id'])) {
        sendJson(['error' => 'folder_id é obrigatório e deve ser numérico'], 400);
    }

    $folderId   = (int)$_POST['folder_id'];
    $uploadedBy = isset($_POST['uploaded_by']) ? (int)$_POST['uploaded_by'] : null;

    // Verifica pasta
    $stmt = $pdo->prepare('SELECT id FROM folders WHERE id = :id');
    $stmt->execute([':id' => $folderId]);
    if (!$stmt->fetch()) {
        sendJson(['error' => 'Pasta não encontrada'], 404);
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        sendJson(['error' => 'Arquivo é obrigatório e deve ser enviado corretamente'], 400);
    }

    $fileTmp  = $_FILES['file']['tmp_name'];
    $filename = $_FILES['file']['name'];
    $mimeType = $_FILES['file']['type'] ?: 'application/octet-stream';
    $size     = (int)$_FILES['file']['size'];

    // Lê o conteúdo do arquivo
    $content = file_get_contents($fileTmp);
    if ($content === false) {
        sendJson(['error' => 'Falha ao ler o arquivo'], 500);
    }

    $stmt = $pdo->prepare('INSERT INTO documents (folder_id, uploaded_by, filename, mime_type, size, content)
                           VALUES (:folder_id, :uploaded_by, :filename, :mime_type, :size, :content)');
    $stmt->bindParam(':folder_id', $folderId, PDO::PARAM_INT);
    if ($uploadedBy === null) {
        $stmt->bindValue(':uploaded_by', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindParam(':uploaded_by', $uploadedBy, PDO::PARAM_INT);
    }
    $stmt->bindParam(':filename', $filename, PDO::PARAM_STR);
    $stmt->bindParam(':mime_type', $mimeType, PDO::PARAM_STR);
    $stmt->bindParam(':size', $size, PDO::PARAM_INT);
    $stmt->bindParam(':content', $content, PDO::PARAM_LOB);

    $stmt->execute();

    $documentId = (int)$pdo->lastInsertId();

    sendJson([
        'message'  => 'Documento enviado com sucesso',
        'document' => [
            'id'         => $documentId,
            'folder_id'  => $folderId,
            'filename'   => $filename,
            'mime_type'  => $mimeType,
            'size'       => $size,
            'uploaded_by'=> $uploadedBy,
        ]
    ], 201);
}

// 4.2 Download de documento
if ($method === 'GET' && preg_match('#^/documents/(\d+)$#', $uri, $matches)) {
    $documentId = (int)$matches[1];

    $stmt = $pdo->prepare('SELECT filename, mime_type, size, content FROM documents WHERE id = :id');
    $stmt->execute([':id' => $documentId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        sendJson(['error' => 'Documento não encontrado'], 404);
    }

    // Para download, mudamos os headers e enviamos o conteúdo binário
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Length: ' . $doc['size']);
    header('Content-Disposition: attachment; filename="' . basename($doc['filename']) . '"');

    echo $doc['content'];
    exit;
}

// Se nenhuma rota foi atendida
sendJson(['error' => 'Rota não encontrada'], 404);

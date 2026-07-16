<?php
require_once 'core.php';

// Конфигурация БД
$core = new Core('localhost', 5432, 'repro', 'app_user', 'secret');
$core->connect();

// Маршрутизация (пример для REST API)
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = trim($path, '/');

try {
    switch ($path) {
        case 'documents':
            if ($method === 'GET') {
                // список с фильтрацией
                $filters = [
                    'search' => $_GET['search'] ?? null,
                    'from'   => $_GET['from'] ?? null,
                    'to'     => $_GET['to'] ?? null,
                ];
                $limit = min((int)($_GET['limit'] ?? 20), 100);
                $offset = (int)($_GET['offset'] ?? 0);
                $list = $core->listDocuments($filters, $limit, $offset);
                header('Content-Type: application/json');
                echo json_encode($list);
            } elseif ($method === 'POST') {
                // загрузка файла
                if (!isset($_FILES['image'])) {
                    throw new Exception('Файл не передан');
                }
                $file = $_FILES['image'];
                $description = $_POST['description'] ?? '';
                $metadata = isset($_POST['metadata']) ? json_decode($_POST['metadata'], true) : [];
                $docId = $core->uploadDocument($file['tmp_name'], $file['name'], $description, $metadata, true);
                header('Content-Type: application/json');
                http_response_code(201);
                echo json_encode(['id' => $docId]);
            }
            break;

        case (preg_match('#^documents/(\d+)$#', $path, $m) ? true : false):
            $id = (int)$m[1];
            if ($method === 'GET') {
                $doc = $core->getDocument($id);
                if (!$doc) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Не найден']);
                } else {
                    header('Content-Type: application/json');
                    echo json_encode($doc);
                }
            } elseif ($method === 'PUT') {
                $input = json_decode(file_get_contents('php://input'), true);
                $core->updateDocument($id, $input);
                http_response_code(204);
            } elseif ($method === 'DELETE') {
                $core->deleteDocument($id);
                http_response_code(204);
            }
            break;

        case (preg_match('#^documents/(\d+)/image$#', $path, $m) ? true : false):
            $id = (int)$m[1];
            if ($method === 'GET') {
                $img = $core->getImageData($id);
                if (!$img) {
                    http_response_code(404);
                } else {
                    header('Content-Type: ' . $img['mime']);
                    header('Content-Length: ' . strlen($img['data']));
                    header('Cache-Control: public, max-age=86400');
                    echo $img['data'];
                }
            }
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    $core->close();
}
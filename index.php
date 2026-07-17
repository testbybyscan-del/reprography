<?php
/**
 * index.php – REST API Front-Controller для системы репрографии
 * 
 * Версия 2.0 – адаптация под новую версию Core, улучшение маршрутизации,
 * добавление эндпоинтов для превью и статистики, обработка CORS, валидация.
 */

require_once 'core.php';

// ----------------------------------------------------------------------------
// 1. Инициализация Core (автоматическое чтение переменных окружения)
// ----------------------------------------------------------------------------
try {
    // Создаём экземпляр Core без параметров – он сам возьмёт DB_* из getenv()
    $core = new Core();
    $core->connect();
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Ошибка подключения к БД: ' . $e->getMessage()]);
    exit;
}

// ----------------------------------------------------------------------------
// 2. Базовые настройки ответа
// ----------------------------------------------------------------------------
// Для локальной разработки разрешаем CORS (в продакшене заменить на конкретные домены)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Обработка предварительных OPTIONS-запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ----------------------------------------------------------------------------
// 3. Маршрутизация
// ----------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = trim($path, '/');

// Если путь начинается с "index.php/" – убираем этот префикс для удобства
if (strpos($path, 'index.php/') === 0) {
    $path = substr($path, 10);
}

try {
    switch (true) {
        // --------------------------------------------------------------
        // GET  /documents         – список документов с фильтрацией
        // POST /documents         – загрузка нового документа
        // --------------------------------------------------------------
        case $path === 'documents' && $method === 'GET':
            $filters = [
                'search' => $_GET['search'] ?? null,
                'from'   => $_GET['from'] ?? null,
                'to'     => $_GET['to'] ?? null,
            ];
            // Если передан параметр metadata в виде JSON-строки
            if (!empty($_GET['metadata'])) {
                $meta = json_decode($_GET['metadata'], true);
                if (is_array($meta)) {
                    $filters['metadata'] = $meta;
                }
            }
            $limit  = min((int)($_GET['limit'] ?? 20), 100);
            $offset = (int)($_GET['offset'] ?? 0);
            
            $list = $core->listDocuments($filters, $limit, $offset);
            header('Content-Type: application/json');
            echo json_encode($list);
            break;

        case $path === 'documents' && $method === 'POST':
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['error' => 'Файл не загружен или ошибка при загрузке']);
                break;
            }
            $file = $_FILES['image'];
            $description = $_POST['description'] ?? '';
            $metadata = [];
            if (isset($_POST['metadata'])) {
                $meta = json_decode($_POST['metadata'], true);
                if (is_array($meta)) {
                    $metadata = $meta;
                }
            }
            // Валидация размера файла (например, не более 50 МБ)
            if ($file['size'] > 50 * 1024 * 1024) {
                http_response_code(400);
                echo json_encode(['error' => 'Файл слишком большой (макс. 50 МБ)']);
                break;
            }
            $docId = $core->uploadDocument($file['tmp_name'], $file['name'], $description, $metadata, true);
            header('Content-Type: application/json');
            http_response_code(201);
            echo json_encode(['id' => $docId]);
            break;

        // --------------------------------------------------------------
        // GET /documents/{id}       – метаданные документа
        // PUT /documents/{id}       – обновление описания/метаданных
        // DELETE /documents/{id}    – удаление документа
        // --------------------------------------------------------------
        case preg_match('#^documents/(\d+)$#', $path, $m) && $method === 'GET':
            $id = (int)$m[1];
            $doc = $core->getDocument($id);
            if (!$doc) {
                http_response_code(404);
                echo json_encode(['error' => 'Документ не найден']);
            } else {
                header('Content-Type: application/json');
                echo json_encode($doc);
            }
            break;

        case preg_match('#^documents/(\d+)$#', $path, $m) && $method === 'PUT':
            $id = (int)$m[1];
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                http_response_code(400);
                echo json_encode(['error' => 'Неверный формат JSON']);
                break;
            }
            $core->updateDocument($id, $input);
            http_response_code(204);
            break;

        case preg_match('#^documents/(\d+)$#', $path, $m) && $method === 'DELETE':
            $id = (int)$m[1];
            $core->deleteDocument($id);
            http_response_code(204);
            break;

        // --------------------------------------------------------------
        // GET /documents/{id}/image – оригинальное изображение
        // GET /documents/{id}/thumb – превью (миниатюра)
        // --------------------------------------------------------------
        case preg_match('#^documents/(\d+)/image$#', $path, $m) && $method === 'GET':
            $id = (int)$m[1];
            $img = $core->getImageData($id);
            if (!$img) {
                http_response_code(404);
            } else {
                header('Content-Type: ' . $img['mime']);
                header('Content-Length: ' . strlen($img['data']));
                header('Cache-Control: public, max-age=86400');
                echo $img['data'];
            }
            break;

        case preg_match('#^documents/(\d+)/thumb$#', $path, $m) && $method === 'GET':
            $id = (int)$m[1];
            $thumb = $core->getThumbnailData($id);
            if (!$thumb) {
                http_response_code(404);
            } else {
                header('Content-Type: ' . $thumb['mime']);
                header('Content-Length: ' . strlen($thumb['data']));
                header('Cache-Control: public, max-age=86400');
                echo $thumb['data'];
            }
            break;

        // --------------------------------------------------------------
        // GET /stats – статистика по документам
        // --------------------------------------------------------------
        case $path === 'stats' && $method === 'GET':
            $stats = $core->getStatistics();
            header('Content-Type: application/json');
            echo json_encode($stats);
            break;

        // --------------------------------------------------------------
        // Если ничего не подошло – 404
        // --------------------------------------------------------------
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    $core->close();
}
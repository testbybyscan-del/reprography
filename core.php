<?php
/**
 * Core.php – ядро системы репрографии документов
 * 
 * Версия 2.0 – адаптация под Docker, улучшение производительности,
 * поддержка переменных окружения, оптимизация работы с памятью.
 * 
 * Использует чистый PHP + расширение pgsql.
 * Хранение изображений – в поле BYTEA (с автоматическим сжатием TOAST).
 * Метаданные – JSONB, полнотекстовый поиск – GIN-индексы.
 * 
 * @version 2.0
 */

class Core
{
    /**
     * Ресурс подключения к PostgreSQL
     * @var resource|false
     */
    private $dbconn = false;

    /**
     * Конфигурация БД
     * @var array
     */
    private $config = [];

    /**
     * Настройки генерации превью
     * @var array
     */
    private $thumbConfig = [
        'maxWidth'  => 200,
        'maxHeight' => 200,
        'quality'   => 80,
    ];

    /**
     * Конструктор – инициализация с параметрами или через переменные окружения
     * 
     * @param array|null $config Ассоциативный массив с ключами:
     *                           host, port, dbname, user, password
     *                           Если null – берёт из getenv()
     */
    public function __construct(?array $config = null)
    {
        if ($config === null) {
            // Чтение из переменных окружения (Docker)
            $this->config = [
                'host'     => getenv('DB_HOST') ?: 'localhost',
                'port'     => (int)(getenv('DB_PORT') ?: 5432),
                'dbname'   => getenv('DB_NAME') ?: 'repro',
                'user'     => getenv('DB_USER') ?: 'app_user',
                'password' => getenv('DB_PASSWORD') ?: 'secret',
            ];
        } else {
            // Ручная передача параметров (с валидацией)
            $required = ['host', 'port', 'dbname', 'user', 'password'];
            foreach ($required as $key) {
                if (!isset($config[$key])) {
                    throw new InvalidArgumentException("Отсутствует обязательный параметр: $key");
                }
            }
            $this->config = $config;
        }

        // Проверка доступности расширений
        if (!extension_loaded('pgsql')) {
            throw new RuntimeException('Расширение pgsql не загружено');
        }
        if (!extension_loaded('gd')) {
            throw new RuntimeException('Расширение GD не загружено');
        }
    }

    /**
     * Устанавливает соединение с БД
     * 
     * @throws Exception Если подключение не удалось
     * @return void
     */
    public function connect()
    {
        $connString = sprintf(
            "host=%s port=%d dbname=%s user=%s password=%s",
            $this->config['host'],
            $this->config['port'],
            $this->config['dbname'],
            $this->config['user'],
            $this->config['password']
        );
        $this->dbconn = pg_connect($connString);
        if (!$this->dbconn) {
            throw new Exception('Ошибка подключения к PostgreSQL: ' . pg_last_error());
        }
        // Устанавливаем кодировку UTF-8
        pg_set_client_encoding($this->dbconn, 'UTF8');
    }

    /**
     * Закрывает соединение с БД
     */
    public function close()
    {
        if ($this->dbconn) {
            pg_close($this->dbconn);
            $this->dbconn = false;
        }
    }

    /**
     * Деструктор – автоматическое закрытие
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Устанавливает параметры генерации превью
     * 
     * @param int $maxWidth
     * @param int $maxHeight
     * @param int $quality Качество JPEG (0-100)
     */
    public function setThumbConfig($maxWidth, $maxHeight, $quality = 80)
    {
        $this->thumbConfig = [
            'maxWidth'  => (int)$maxWidth,
            'maxHeight' => (int)$maxHeight,
            'quality'   => (int)$quality,
        ];
    }

    /* ---------- Работа с документами ---------- */

    /**
     * Загружает новый документ в систему
     * 
     * @param string $filePath    Путь к временному файлу (из $_FILES['image']['tmp_name'])
     * @param string $filename    Оригинальное имя файла
     * @param string $description Описание документа (опционально)
     * @param array  $metadata    Дополнительные метаданные (ключ→значение)
     * @param bool   $generateThumb Создавать ли превью (по умолчанию true)
     * 
     * @throws Exception При ошибках загрузки или записи в БД
     * @return int ID созданного документа
     */
    public function uploadDocument($filePath, $filename, $description = '', $metadata = [], $generateThumb = true)
    {
        // Проверка файла
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new Exception('Файл не найден или недоступен для чтения');
        }

        // Определяем MIME-тип
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        // Допустимые типы
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/tiff'];
        if (!in_array($mime, $allowed)) {
            throw new Exception('Недопустимый формат файла: ' . $mime);
        }

        // Читаем файл (для больших файлов > 10 МБ можно использовать поток, но оставим так)
        $data = file_get_contents($filePath);
        if ($data === false) {
            throw new Exception('Не удалось прочитать файл');
        }
        $fileSize = strlen($data);

        // Получаем размеры изображения
        $imgInfo = getimagesize($filePath);
        $width = $imgInfo[0] ?? 0;
        $height = $imgInfo[1] ?? 0;

        // Извлекаем EXIF (если JPEG)
        $exif = @exif_read_data($filePath);
        if ($exif !== false) {
            $metadata = array_merge($metadata, $exif);
        }

        // Генерируем превью
        $thumbData = null;
        if ($generateThumb) {
            $thumbData = $this->generateThumbnail($data, $mime);
        }

        // Экранируем данные для BYTEA
        $escapedData = pg_escape_bytea($this->dbconn, $data);
        $escapedThumb = $thumbData ? pg_escape_bytea($this->dbconn, $thumbData) : null;

        // Подготавливаем JSONB для метаданных
        $jsonMetadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        if ($jsonMetadata === false) {
            throw new Exception('Ошибка кодирования метаданных в JSON');
        }

        // Начинаем транзакцию
        pg_query($this->dbconn, 'BEGIN');

        try {
            $query = "INSERT INTO documents 
                      (filename, image_data, thumb_data, mime_type, file_size, width, height, metadata, description)
                      VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)
                      RETURNING id";
            $result = pg_query_params($this->dbconn, $query, [
                $filename,
                $escapedData,
                $escapedThumb,
                $mime,
                $fileSize,
                $width,
                $height,
                $jsonMetadata,
                $description
            ]);
            if (!$result) {
                throw new Exception('Ошибка INSERT: ' . pg_last_error($this->dbconn));
            }

            $row = pg_fetch_assoc($result);
            $docId = (int)$row['id'];

            pg_query($this->dbconn, 'COMMIT');
            return $docId;

        } catch (Exception $e) {
            pg_query($this->dbconn, 'ROLLBACK');
            throw $e;
        }
    }

    /**
     * Возвращает метаданные документа по ID
     * 
     * @param int $id
     * @return array|null Ассоциативный массив с данными, либо null, если не найден
     */
    public function getDocument($id)
    {
        $query = "SELECT id, filename, mime_type, file_size, width, height, 
                         metadata, description, uploaded_at, updated_at
                  FROM documents WHERE id = $1";
        $result = pg_query_params($this->dbconn, $query, [$id]);
        if (!$result || pg_num_rows($result) === 0) {
            return null;
        }
        $row = pg_fetch_assoc($result);
        $row['metadata'] = json_decode($row['metadata'], true) ?: [];
        return $row;
    }

    /**
     * Обновляет описание и/или метаданные документа
     * 
     * @param int   $id
     * @param array $fields Ассоциативный массив с полями для обновления
     *                      (допустимые ключи: description, metadata)
     * @return bool
     */
    public function updateDocument($id, $fields)
    {
        $allowed = ['description', 'metadata'];
        $updates = [];
        $params = [];
        $i = 1;

        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed)) {
                continue;
            }
            if ($key === 'metadata') {
                if (!is_array($value)) {
                    throw new Exception('metadata должен быть массивом');
                }
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                if ($value === false) {
                    throw new Exception('Ошибка кодирования metadata');
                }
                $updates[] = "metadata = $".$i."::jsonb";
            } else {
                $updates[] = "$key = $".$i;
            }
            $params[] = $value;
            $i++;
        }

        if (empty($updates)) {
            return true;
        }

        $params[] = $id;
        $query = "UPDATE documents SET " . implode(', ', $updates) . " WHERE id = $".$i;
        $result = pg_query_params($this->dbconn, $query, $params);
        if (!$result) {
            throw new Exception('Ошибка UPDATE: ' . pg_last_error($this->dbconn));
        }
        return pg_affected_rows($result) > 0;
    }

    /**
     * Удаляет документ по ID
     * 
     * @param int $id
     * @return bool
     */
    public function deleteDocument($id)
    {
        $query = "DELETE FROM documents WHERE id = $1";
        $result = pg_query_params($this->dbconn, $query, [$id]);
        if (!$result) {
            throw new Exception('Ошибка DELETE: ' . pg_last_error($this->dbconn));
        }
        return pg_affected_rows($result) > 0;
    }

    /**
     * Получает список документов с фильтрацией, поиском и пагинацией
     * 
     * @param array  $filters Ассоциативный массив фильтров:
     *                        - search (string) – поисковая фраза (полнотекстовый)
     *                        - from (string) – дата начала (Y-m-d)
     *                        - to (string)   – дата конца (Y-m-d)
     *                        - metadata (array) – фильтр по полям метаданных (ключ=>значение)
     * @param int    $limit   Максимальное количество записей
     * @param int    $offset  Смещение
     * @return array Массив документов (без бинарных данных)
     */
    public function listDocuments($filters = [], $limit = 20, $offset = 0)
    {
        $conditions = [];
        $params = [];

        // Поиск через полнотекстовый индекс
        if (!empty($filters['search'])) {
            $conditions[] = "search_vector @@ to_tsquery('russian', $".(count($params)+1).")";
            $params[] = $filters['search'];
        }

        // Фильтр по дате
        if (!empty($filters['from']) && !empty($filters['to'])) {
            $conditions[] = "uploaded_at BETWEEN $".(count($params)+1)." AND $".(count($params)+2)."";
            $params[] = $filters['from'];
            $params[] = $filters['to'];
        }

        // Фильтр по метаданным
        if (!empty($filters['metadata']) && is_array($filters['metadata'])) {
            foreach ($filters['metadata'] as $key => $value) {
                $conditions[] = "metadata->>$".(count($params)+1)." = $".(count($params)+2);
                $params[] = $key;
                $params[] = $value;
            }
        }

        $where = $conditions ? "WHERE " . implode(' AND ', $conditions) : "";
        $query = "SELECT id, filename, mime_type, file_size, width, height, 
                         metadata, description, uploaded_at, updated_at
                  FROM documents
                  $where
                  ORDER BY uploaded_at DESC
                  LIMIT $".(count($params)+1)." OFFSET $".(count($params)+2);
        $params[] = $limit;
        $params[] = $offset;

        $result = pg_query_params($this->dbconn, $query, $params);
        if (!$result) {
            throw new Exception('Ошибка SELECT: ' . pg_last_error($this->dbconn));
        }

        $rows = pg_fetch_all($result) ?: [];
        foreach ($rows as &$row) {
            $row['metadata'] = json_decode($row['metadata'], true) ?: [];
        }
        return $rows;
    }

    /**
     * Возвращает бинарные данные изображения и MIME-тип
     * 
     * @param int $id
     * @return array|null Ассоциативный массив с ключами 'data' (строка) и 'mime', либо null
     */
    public function getImageData($id)
    {
        $query = "SELECT image_data, mime_type FROM documents WHERE id = $1";
        $result = pg_query_params($this->dbconn, $query, [$id]);
        if (!$result || pg_num_rows($result) === 0) {
            return null;
        }
        $row = pg_fetch_assoc($result);
        $row['image_data'] = pg_unescape_bytea($row['image_data']);
        return [
            'data' => $row['image_data'],
            'mime' => $row['mime_type']
        ];
    }

    /**
     * Возвращает данные превью (если сгенерировано)
     * 
     * @param int $id
     * @return array|null аналогично getImageData, но для thumb_data
     */
    public function getThumbnailData($id)
    {
        $query = "SELECT thumb_data, mime_type FROM documents WHERE id = $1 AND thumb_data IS NOT NULL";
        $result = pg_query_params($this->dbconn, $query, [$id]);
        if (!$result || pg_num_rows($result) === 0) {
            return null;
        }
        $row = pg_fetch_assoc($result);
        $row['thumb_data'] = pg_unescape_bytea($row['thumb_data']);
        return [
            'data' => $row['thumb_data'],
            'mime' => $row['mime_type']
        ];
    }

    /**
     * Получает статистику по документам (количество, общий размер и т.п.)
     * 
     * @return array
     */
    public function getStatistics()
    {
        $query = "SELECT 
                    COUNT(*) AS total_docs,
                    SUM(file_size) AS total_bytes,
                    AVG(file_size) AS avg_size,
                    MIN(uploaded_at) AS first_upload,
                    MAX(uploaded_at) AS last_upload
                  FROM documents";
        $result = pg_query($this->dbconn, $query);
        if (!$result) {
            throw new Exception('Ошибка статистики: ' . pg_last_error($this->dbconn));
        }
        return pg_fetch_assoc($result) ?: [];
    }

    /* ---------- Вспомогательные методы ---------- */

    /**
     * Генерирует превью изображения (уменьшенную копию)
     * 
     * @param string $imageData   Бинарные данные исходного изображения
     * @param string $mimeType    MIME-тип оригинала
     * @return string|null Бинарные данные превью, либо null при ошибке
     */
    protected function generateThumbnail($imageData, $mimeType)
    {
        $maxWidth  = $this->thumbConfig['maxWidth'];
        $maxHeight = $this->thumbConfig['maxHeight'];
        $quality   = $this->thumbConfig['quality'];

        $src = @imagecreatefromstring($imageData);
        if (!$src) {
            return null;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // Если изображение уже меньше или равно лимитам – используем оригинал (но сжимаем)
        if ($srcW <= $maxWidth && $srcH <= $maxHeight) {
            // Просто пересохраняем с нужным качеством
            $dst = $src;
            $newW = $srcW;
            $newH = $srcH;
        } else {
            // Вычисляем пропорции
            $ratio = min($maxWidth / $srcW, $maxHeight / $srcH);
            $newW = (int)($srcW * $ratio);
            $newH = (int)($srcH * $ratio);

            $dst = imagecreatetruecolor($newW, $newH);
            if ($dst === false) {
                imagedestroy($src);
                return null;
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
            imagedestroy($src);
        }

        // Сохраняем в буфер
        ob_start();
        $format = $this->detectFormat($mimeType);
        if ($format === 'jpeg') {
            imagejpeg($dst, null, $quality);
        } elseif ($format === 'png') {
            // Для PNG качество не применяется, используем сжатие 6 (среднее)
            imagepng($dst, null, 6);
        } elseif ($format === 'gif') {
            imagegif($dst);
        } else {
            // fallback
            imagejpeg($dst, null, $quality);
        }
        $thumbData = ob_get_clean();
        imagedestroy($dst);

        return $thumbData ?: null;
    }

    /**
     * Определяет формат для GD по MIME-типу
     */
    private function detectFormat($mime)
    {
        $map = [
            'image/jpeg' => 'jpeg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/tiff' => 'jpeg' // TIFF не поддерживается GD, конвертируем в JPEG
        ];
        return $map[$mime] ?? 'jpeg';
    }
}
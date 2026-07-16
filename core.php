<?php
/**
 * Core.php – ядро системы репрографии документов
 * 
 * Использует чистый PHP + расширение pgsql.
 * Хранение изображений – в поле BYTEA (с автоматическим сжатием TOAST).
 * Метаданные – JSONB, полнотекстовый поиск – GIN-индексы.
 * 
 * @version 1.0
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
     * Конструктор – сохраняет параметры подключения
     * 
     * @param string $host     Хост БД (например, 'localhost')
     * @param int    $port     Порт (по умолчанию 5432)
     * @param string $dbname   Имя базы данных
     * @param string $user     Пользователь
     * @param string $password Пароль
     */
    public function __construct($host, $port = 5432, $dbname, $user, $password)
    {
        $this->config = [
            'host'     => $host,
            'port'     => $port,
            'dbname'   => $dbname,
            'user'     => $user,
            'password' => $password
        ];
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

        // Читаем файл
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

        // Генерируем превью (уменьшенная копия)
        $thumbData = null;
        if ($generateThumb) {
            $thumbData = $this->generateThumbnail($data, $mime, 200, 200);
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

            // Фиксируем транзакцию
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
        // Декодируем метаданные
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
                // метаданные приходят как массив – преобразуем в JSON
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
            return true; // ничего не меняем
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

        // Фильтр по метаданным (например, camera => 'Canon')
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
        // Распаковываем BYTEA
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

    /* ---------- Вспомогательные методы ---------- */

    /**
     * Генерирует превью изображения (уменьшенную копию)
     * 
     * @param string $imageData   Бинарные данные исходного изображения
     * @param string $mimeType    MIME-тип оригинала
     * @param int    $maxWidth    Максимальная ширина превью
     * @param int    $maxHeight   Максимальная высота превью
     * @param int    $quality     Качество JPEG (0-100)
     * @return string|null Бинарные данные превью, либо null при ошибке
     */
    protected function generateThumbnail($imageData, $mimeType, $maxWidth = 200, $maxHeight = 200, $quality = 80)
    {
        // Создаём ресурс из данных
        $src = @imagecreatefromstring($imageData);
        if (!$src) {
            return null;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // Вычисляем пропорции
        $ratio = min($maxWidth / $srcW, $maxHeight / $srcH);
        $newW = (int)($srcW * $ratio);
        $newH = (int)($srcH * $ratio);

        $dst = imagecreatetruecolor($newW, $newH);
        if ($dst === false) {
            imagedestroy($src);
            return null;
        }

        // Копирование с ресемплингом
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
        imagedestroy($src);

        // Сохраняем в буфер
        ob_start();
        $format = $this->detectFormat($mimeType);
        if ($format === 'jpeg') {
            imagejpeg($dst, null, $quality);
        } elseif ($format === 'png') {
            imagepng($dst, null, 9);
        } elseif ($format === 'gif') {
            imagegif($dst);
        } else {
            // для других форматов – сохраняем как JPEG
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
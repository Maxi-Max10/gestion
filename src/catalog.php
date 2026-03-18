<?php

declare(strict_types=1);

/**
 * Catálogo de productos (lista de precios).
 */

function catalog_parse_price_to_cents(string|float|int $price): int
{
    $raw = trim((string)$price);
    if ($raw === '') {
        throw new InvalidArgumentException('Precio inválido.');
    }

    $s = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    $s = str_replace(['$', '€'], '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = is_string($s) ? trim($s) : trim($raw);

    // Aceptar miles con espacio (ej: "13 000")
    $sNoSpaces = str_replace(' ', '', $s);
    if ($sNoSpaces !== '') {
        $s = $sNoSpaces;
    }

    $mult = 1.0;
    if (preg_match('/^([0-9]+(?:[\.,][0-9]{1,2})?)(?:\s*)?(mil|miles|k)\b/u', $s, $m) === 1) {
        $mult = 1000.0;
        $s = (string)$m[1];
    }

    // Normalizar separadores
    $hasDot = str_contains($s, '.');
    $hasComma = str_contains($s, ',');
    if ($hasDot && $hasComma) {
        // 13.000,50 -> 13000.50
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif ($hasComma && !$hasDot) {
        // 13,50 -> 13.50
        $s = str_replace(',', '.', $s);
    } else {
        // dejar '.' como decimal
    }

    // Validar formato numérico final
    if (preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/', $s) !== 1) {
        throw new InvalidArgumentException('Precio inválido.');
    }

    $value = (float)$s;
    if (!is_finite($value) || $value < 0) {
        throw new InvalidArgumentException('Precio inválido.');
    }

    $cents = (int)round(($value * $mult) * 100);
    return $cents;
}

function catalog_supports_table(PDO $pdo): bool
{
    static $cache = null;
    if (is_bool($cache)) {
        return $cache;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
             LIMIT 1"
        );
        $stmt->execute(['t' => 'catalog_products']);
        $cache = (bool)$stmt->fetchColumn();
        if ($cache) {
            return true;
        }

        catalog_try_create_table($pdo);

        $stmt->execute(['t' => 'catalog_products']);
        $cache = (bool)$stmt->fetchColumn();
        return $cache;
    } catch (Throwable $e) {
        $cache = false;
        return false;
    }
}

function catalog_try_create_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS catalog_products (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_by INT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            description VARCHAR(255) NULL,
            image_path VARCHAR(255) NULL,
            unit VARCHAR(24) NULL,
            price_cents INT UNSIGNED NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'ARS',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_catalog_products_user_name (created_by, name),
            KEY idx_catalog_products_created_by (created_by),
            CONSTRAINT fk_catalog_products_users
              FOREIGN KEY (created_by) REFERENCES users(id)
              ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function catalog_ensure_description_column(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!catalog_supports_table($pdo)) {
        throw new RuntimeException('No se encontró la tabla del catálogo.');
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :t
           AND COLUMN_NAME = :c
         LIMIT 1"
    );
    $stmt->execute(['t' => 'catalog_products', 'c' => 'description']);
    $has = (bool)$stmt->fetchColumn();
    if ($has) {
        $done = true;
        return;
    }

    try {
        $pdo->exec('ALTER TABLE catalog_products ADD COLUMN description VARCHAR(255) NULL AFTER name');
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Falta la columna description en catalog_products. Ejecutá: ALTER TABLE catalog_products ADD COLUMN description VARCHAR(255) NULL AFTER name;',
            0,
            $e
        );
    }

    $done = true;
}

function catalog_normalize_unit(string $unit): string
{
    $u = trim($unit);
    if ($u === '') {
        return '';
    }

    $u = function_exists('mb_strtolower') ? mb_strtolower($u, 'UTF-8') : strtolower($u);
    $u = str_replace(['.', ' '], '', $u);

    // Alias comunes
    if (in_array($u, ['u', 'un', 'uni', 'unidad', 'unidades', 'unit'], true)) {
        return 'un';
    }
    if (in_array($u, ['kg', 'kilo', 'kilos', 'kgs'], true)) {
        return 'kg';
    }
    if (in_array($u, ['g', 'gr', 'gramo', 'gramos'], true)) {
        return 'g';
    }
    if (in_array($u, ['l', 'lt', 'lts', 'litro', 'litros'], true)) {
        return 'l';
    }
    if (in_array($u, ['ml', 'mililitro', 'mililitros'], true)) {
        return 'ml';
    }

    // Si no coincide, rechazar (evitamos texto libre largo)
    throw new InvalidArgumentException('Unidad inválida. Usá: un, kg, g, l o ml.');
}

function catalog_ensure_unit_column(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!catalog_supports_table($pdo)) {
        throw new RuntimeException('No se encontró la tabla del catálogo.');
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :t
           AND COLUMN_NAME = :c
         LIMIT 1"
    );
    $stmt->execute(['t' => 'catalog_products', 'c' => 'unit']);
    $has = (bool)$stmt->fetchColumn();
    if ($has) {
        $done = true;
        return;
    }

    try {
        $pdo->exec('ALTER TABLE catalog_products ADD COLUMN unit VARCHAR(24) NULL AFTER description');
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Falta la columna unit en catalog_products. Ejecutá: ALTER TABLE catalog_products ADD COLUMN unit VARCHAR(24) NULL AFTER description;',
            0,
            $e
        );
    }

    $done = true;
}

function catalog_ensure_image_column(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!catalog_supports_table($pdo)) {
        throw new RuntimeException('No se encontró la tabla del catálogo.');
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :t
           AND COLUMN_NAME = :c
         LIMIT 1"
    );
    $stmt->execute(['t' => 'catalog_products', 'c' => 'image_path']);
    $has = (bool)$stmt->fetchColumn();
    if ($has) {
        $done = true;
        return;
    }

    try {
        $pdo->exec('ALTER TABLE catalog_products ADD COLUMN image_path VARCHAR(255) NULL AFTER description');
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Falta la columna image_path en catalog_products. Ejecutá: ALTER TABLE catalog_products ADD COLUMN image_path VARCHAR(255) NULL AFTER description;',
            0,
            $e
        );
    }

    $done = true;
}

function catalog_image_storage_dir(): string
{
    $dirs = catalog_image_storage_dirs();
    return $dirs[0];
}

/**
 * @return array<int, string>
 */
function catalog_image_storage_dirs(): array
{
    $projectRoot = dirname(__DIR__);
    $dirs = [];

    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (is_string($docRoot) && $docRoot !== '' && is_dir($docRoot)) {
        $dirs[] = rtrim($docRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'catalog';
    }

    $dirs[] = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'catalog';
    $dirs[] = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'catalog';

    $unique = [];
    foreach ($dirs as $dir) {
        $normalized = rtrim($dir, DIRECTORY_SEPARATOR);
        if ($normalized === '' || in_array($normalized, $unique, true)) {
            continue;
        }
        $unique[] = $normalized;
    }

    return $unique;
}

function catalog_ensure_image_storage_dirs(): void
{
    foreach (catalog_image_storage_dirs() as $dir) {
        if (is_dir($dir)) {
            continue;
        }
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear la carpeta de imágenes.');
        }
    }
}

function catalog_write_image_file(string $filename, string $bytes): void
{
    catalog_ensure_image_storage_dirs();

    foreach (catalog_image_storage_dirs() as $dir) {
        $target = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($target, $bytes) === false) {
            throw new RuntimeException('No se pudo guardar la imagen.');
        }
    }
}

function catalog_sanitize_image_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    $base = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path));
    $base = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)$base);
    return is_string($base) ? $base : '';
}

function catalog_image_url(string $imagePath): string
{
    $safe = catalog_sanitize_image_path($imagePath);
    if ($safe === '') {
        return '';
    }
    return '/uploads/catalog/' . rawurlencode($safe);
}

function catalog_store_uploaded_image(array $file): string
{
    $error = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir la imagen.');
    }

    $tmp = $file['tmp_name'] ?? '';
    if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Archivo de imagen inválido.');
    }

    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0) {
        throw new RuntimeException('La imagen está vacía.');
    }
    if ($size > (4 * 1024 * 1024)) {
        throw new RuntimeException('La imagen supera 4 MB.');
    }

    $info = @getimagesize($tmp);
    $mime = is_array($info) && isset($info['mime']) ? (string)$info['mime'] : '';
    $extMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if ($mime === '' || !isset($extMap[$mime])) {
        throw new RuntimeException('Formato de imagen no soportado. Usá JPG, PNG o WebP.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extMap[$mime];
    $bytes = @file_get_contents($tmp);
    if (!is_string($bytes) || $bytes === '') {
        throw new RuntimeException('No se pudo guardar la imagen.');
    }

    catalog_write_image_file($filename, $bytes);

    return $filename;
}

function catalog_store_image_bytes(string $bytes, string $mime = ''): string
{
    if ($bytes === '') {
        throw new RuntimeException('La imagen está vacía.');
    }
    if (strlen($bytes) > (4 * 1024 * 1024)) {
        throw new RuntimeException('La imagen supera 4 MB.');
    }

    $info = @getimagesizefromstring($bytes);
    $detectedMime = is_array($info) && isset($info['mime']) ? (string)$info['mime'] : trim($mime);
    $extMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if ($detectedMime === '' || !isset($extMap[$detectedMime])) {
        throw new RuntimeException('Formato de imagen no soportado. Usá JPG, PNG o WebP.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extMap[$detectedMime];
    catalog_write_image_file($filename, $bytes);

    return $filename;
}

function catalog_delete_image_file(string $imagePath): void
{
    $safe = catalog_sanitize_image_path($imagePath);
    if ($safe === '') {
        return;
    }
    foreach (catalog_image_storage_dirs() as $dir) {
        $path = $dir . DIRECTORY_SEPARATOR . $safe;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function catalog_find_id_by_name(PDO $pdo, int $createdBy, string $name): int
{
    catalog_ensure_description_column($pdo);
    catalog_ensure_unit_column($pdo);
    catalog_ensure_image_column($pdo);

    $name = trim($name);
    if ($name === '') {
        return 0;
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM catalog_products
         WHERE created_by = :created_by AND name = :name
         LIMIT 1'
    );
    $stmt->execute([
        'created_by' => $createdBy,
        'name' => $name,
    ]);

    return (int)($stmt->fetchColumn() ?: 0);
}

function catalog_import_column_label(int $columnIndex): string
{
    $n = $columnIndex + 1;
    $label = '';
    while ($n > 0) {
        $mod = ($n - 1) % 26;
        $label = chr(65 + $mod) . $label;
        $n = intdiv($n - 1, 26);
    }
    return $label;
}

function catalog_import_existing_image_reference(string $source): string
{
    $safe = catalog_sanitize_image_path($source);
    if ($safe === '') {
        throw new InvalidArgumentException('La referencia de imagen no es válida.');
    }

    foreach (catalog_image_storage_dirs() as $dir) {
        $path = $dir . DIRECTORY_SEPARATOR . $safe;
        if (is_file($path)) {
            return $safe;
        }
    }

    throw new InvalidArgumentException('La imagen indicada no existe en uploads/catalog.');
}

/**
 * @return array{path:string,is_new:bool}
 */
function catalog_import_image_from_source(string $source): array
{
    $source = trim($source);
    if ($source === '') {
        return ['path' => '', 'is_new' => false];
    }

    if (preg_match('#^https?://#i', $source) === 1) {
        if (!filter_var($source, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('La URL de la imagen no es válida.');
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) && ini_get('allow_url_fopen') !== '1') {
            throw new RuntimeException('El servidor no permite descargar imágenes por URL.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'follow_location' => 1,
                'user_agent' => 'DieteticCatalogImport/1.0',
            ],
            'https' => [
                'method' => 'GET',
                'timeout' => 15,
                'follow_location' => 1,
                'user_agent' => 'DieteticCatalogImport/1.0',
            ],
        ]);

        $bytes = @file_get_contents($source, false, $context, 0, (4 * 1024 * 1024) + 1);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('No se pudo descargar la imagen desde la URL indicada.');
        }

        return [
            'path' => catalog_store_image_bytes($bytes),
            'is_new' => true,
        ];
    }

    return [
        'path' => catalog_import_existing_image_reference($source),
        'is_new' => false,
    ];
}

/**
 * @param object $drawing
 * @return array{path:string,is_new:bool}
 */
function catalog_import_image_from_drawing(object $drawing): array
{
    if ($drawing instanceof \PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing) {
        $imageResource = $drawing->getImageResource();
        $renderer = $drawing->getRenderingFunction();
        if ($imageResource === null || !is_callable($renderer)) {
            throw new RuntimeException('No se pudo leer la imagen incrustada.');
        }

        ob_start();
        $renderer($imageResource);
        $bytes = (string)ob_get_clean();
        return [
            'path' => catalog_store_image_bytes($bytes, (string)$drawing->getMimeType()),
            'is_new' => true,
        ];
    }

    if ($drawing instanceof \PhpOffice\PhpSpreadsheet\Worksheet\Drawing) {
        $drawingPath = (string)$drawing->getPath();
        $bytes = @file_get_contents($drawingPath);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('No se pudo leer la imagen incrustada.');
        }

        return [
            'path' => catalog_store_image_bytes($bytes),
            'is_new' => true,
        ];
    }

    throw new RuntimeException('Tipo de imagen incrustada no soportado.');
}

/**
 * @return array<int, array{path:string,is_new:bool}>
 */
function catalog_import_collect_embedded_images(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, ?string $imageColumnLabel = null): array
{
    $out = [];
    foreach ($sheet->getDrawingCollection() as $drawing) {
        $coordinates = strtoupper((string)$drawing->getCoordinates());
        if (preg_match('/^([A-Z]+)(\d+)$/', $coordinates, $m) !== 1) {
            continue;
        }

        $columnLabel = $m[1];
        $rowIndex = (int)$m[2];
        if ($rowIndex < 2) {
            continue;
        }
        if ($imageColumnLabel !== null && $columnLabel !== strtoupper($imageColumnLabel)) {
            continue;
        }

        $out[$rowIndex] = catalog_import_image_from_drawing($drawing);
    }

    return $out;
}

function catalog_import_normalize_header(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = strtr($value, [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ñ' => 'N', 'ñ' => 'n',
    ]);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    return trim((string)$value, '_');
}

/**
 * @param array<int, mixed> $headerRow
 * @return array{name:int,price:int,description:?int,unit:?int,currency:?int,image:?int}
 */
function catalog_import_resolve_columns(array $headerRow): array
{
    $aliases = [
        'name' => ['producto', 'productos', 'nombre', 'name', 'product'],
        'price' => ['precio', 'price', 'importe', 'valor', 'monto'],
        'description' => ['descripcion', 'descrip', 'detalle', 'description'],
        'unit' => ['unidad', 'unidades', 'unit', 'medida'],
        'currency' => ['moneda', 'currency', 'divisa'],
        'image' => ['imagen', 'foto', 'image', 'image_url', 'imagen_url', 'url_imagen'],
    ];

    $columns = [
        'name' => null,
        'price' => null,
        'description' => null,
        'unit' => null,
        'currency' => null,
        'image' => null,
    ];

    foreach ($headerRow as $index => $headerCell) {
        $header = catalog_import_normalize_header((string)$headerCell);
        if ($header === '') {
            continue;
        }

        foreach ($aliases as $field => $acceptedHeaders) {
            if ($columns[$field] === null && in_array($header, $acceptedHeaders, true)) {
                $columns[$field] = (int)$index;
                break;
            }
        }
    }

    if ($columns['name'] === null || $columns['price'] === null) {
        throw new InvalidArgumentException('El Excel debe tener al menos las columnas Producto y Precio en la primera fila.');
    }

    return $columns;
}

/**
 * @return array{created:int,updated:int,skipped:int,errors:array<int,string>}
 */
function catalog_import_spreadsheet(PDO $pdo, int $createdBy, array $file): array
{
    catalog_ensure_description_column($pdo);
    catalog_ensure_unit_column($pdo);
    catalog_ensure_image_column($pdo);

    if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        throw new RuntimeException('Falta instalar dependencias para importar Excel (PhpSpreadsheet).');
    }

    $errorCode = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Seleccioná un archivo Excel para importar.');
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir el archivo Excel.');
    }

    $tmpName = $file['tmp_name'] ?? '';
    if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('El archivo Excel subido no es válido.');
    }

    $originalName = trim((string)($file['name'] ?? ''));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
        throw new InvalidArgumentException('Formato no soportado. Usá un archivo XLSX, XLS o CSV.');
    }

    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0) {
        throw new InvalidArgumentException('El archivo Excel está vacío.');
    }
    if ($size > (8 * 1024 * 1024)) {
        throw new InvalidArgumentException('El archivo supera 8 MB.');
    }

    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmpName);
        $spreadsheet = $reader->load($tmpName);
    } catch (Throwable $e) {
        throw new RuntimeException('No se pudo leer el archivo Excel.', 0, $e);
    }

    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);
    if (!is_array($rows) || count($rows) < 2) {
        throw new InvalidArgumentException('El archivo no tiene filas para importar.');
    }

    $headerRow = array_shift($rows);
    if (!is_array($headerRow)) {
        throw new InvalidArgumentException('No se pudo leer la cabecera del archivo.');
    }

    $columns = catalog_import_resolve_columns($headerRow);
    $imageColumnLabel = $columns['image'] !== null ? catalog_import_column_label((int)$columns['image']) : null;
    $embeddedImages = catalog_import_collect_embedded_images($sheet, $imageColumnLabel);
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];

    foreach ($rows as $rowOffset => $row) {
        $sheetRow = $rowOffset + 2;
        if (!is_array($row)) {
            $skipped++;
            continue;
        }

        $name = trim((string)($row[$columns['name']] ?? ''));
        $price = trim((string)($row[$columns['price']] ?? ''));
        $description = $columns['description'] !== null ? trim((string)($row[$columns['description']] ?? '')) : '';
        $unit = $columns['unit'] !== null ? trim((string)($row[$columns['unit']] ?? '')) : '';
        $currency = $columns['currency'] !== null ? trim((string)($row[$columns['currency']] ?? '')) : 'ARS';
        $imageSource = $columns['image'] !== null ? trim((string)($row[$columns['image']] ?? '')) : '';

        $rowValues = [$name, $price, $description, $unit, $currency, $imageSource];
        $isEmptyRow = true;
        foreach ($rowValues as $rowValue) {
            if (trim((string)$rowValue) !== '') {
                $isEmptyRow = false;
                break;
            }
        }
        if ($isEmptyRow) {
            $skipped++;
            continue;
        }

        $preparedImage = ['path' => '', 'is_new' => false];
        try {
            if ($name === '') {
                throw new InvalidArgumentException('Falta el nombre del producto.');
            }
            if ($price === '') {
                throw new InvalidArgumentException('Falta el precio.');
            }

            if (isset($embeddedImages[$sheetRow])) {
                $preparedImage = $embeddedImages[$sheetRow];
            } elseif ($imageSource !== '') {
                $preparedImage = catalog_import_image_from_source($imageSource);
            }

            $existingId = catalog_find_id_by_name($pdo, $createdBy, $name);
            if ($existingId > 0) {
                catalog_update(
                    $pdo,
                    $createdBy,
                    $existingId,
                    $name,
                    $price,
                    $currency,
                    $description,
                    $unit,
                    $preparedImage['path'] !== '' ? $preparedImage['path'] : null,
                    false
                );
                $updated++;
            } else {
                catalog_create(
                    $pdo,
                    $createdBy,
                    $name,
                    $price,
                    $currency,
                    $description,
                    $unit,
                    $preparedImage['path']
                );
                $created++;
            }
        } catch (Throwable $e) {
            if (!empty($preparedImage['is_new']) && !empty($preparedImage['path'])) {
                catalog_delete_image_file((string)$preparedImage['path']);
            }
            $errors[] = 'Fila ' . $sheetRow . ': ' . $e->getMessage();
        }
    }

    return [
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}

/**
 * @return array<int, array{id:int,name:string,description:string,price_cents:int,currency:string,updated_at:string,created_at:string}>
 */
function catalog_list(PDO $pdo, int $createdBy, string $search = '', int $limit = 200): array
{
    catalog_ensure_description_column($pdo);
    catalog_ensure_unit_column($pdo);
    catalog_ensure_image_column($pdo);

    $limit = max(1, min(5000, (int)$limit));
    $search = trim($search);

    $where = 'created_by = :created_by';
    $params = ['created_by' => $createdBy];

    $rows = null;
    $lastError = null;

    if ($search !== '') {
        $params['q'] = '%' . $search . '%';

        $searchVariants = [
            ' AND (CONVERT(name USING utf8mb4) LIKE CONVERT(:q USING utf8mb4) OR CONVERT(description USING utf8mb4) LIKE CONVERT(:q USING utf8mb4))',
            ' AND (CONVERT(name USING utf8) LIKE CONVERT(:q USING utf8) OR CONVERT(description USING utf8) LIKE CONVERT(:q USING utf8))',
            ' AND (name LIKE :q OR description LIKE :q)',
        ];

        foreach ($searchVariants as $searchWhere) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT id, name, description, COALESCE(image_path, "") AS image_path, COALESCE(unit, "") AS unit, price_cents, currency, updated_at, created_at
                     FROM catalog_products
                     WHERE ' . $where . $searchWhere . '
                     ORDER BY name ASC, id ASC
                     LIMIT ' . $limit
                );
                $stmt->execute($params);
                $rows = $stmt->fetchAll();
                break;
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, name, description, COALESCE(image_path, "") AS image_path, COALESCE(unit, "") AS unit, price_cents, currency, updated_at, created_at
             FROM catalog_products
             WHERE ' . $where . '
             ORDER BY name ASC, id ASC
             LIMIT ' . $limit
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    }

    if ($rows === null) {
        throw $lastError ?? new RuntimeException('No se pudo cargar el catálogo.');
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)($r['id'] ?? 0),
            'name' => (string)($r['name'] ?? ''),
            'description' => (string)($r['description'] ?? ''),
            'image_path' => (string)($r['image_path'] ?? ''),
            'unit' => (string)($r['unit'] ?? ''),
            'price_cents' => (int)($r['price_cents'] ?? 0),
            'currency' => (string)($r['currency'] ?? 'ARS'),
            'updated_at' => (string)($r['updated_at'] ?? ''),
            'created_at' => (string)($r['created_at'] ?? ''),
        ];
    }
    return $out;
}

/** @return array{id:int,name:string,description:string,price_cents:int,currency:string,updated_at:string,created_at:string} */
function catalog_get(PDO $pdo, int $createdBy, int $id): array
{
    catalog_ensure_description_column($pdo);
    catalog_ensure_unit_column($pdo);
    catalog_ensure_image_column($pdo);

    $id = (int)$id;
    if ($id <= 0) {
        throw new InvalidArgumentException('Producto inválido.');
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, description, COALESCE(image_path, "") AS image_path, COALESCE(unit, "") AS unit, price_cents, currency, updated_at, created_at
         FROM catalog_products
         WHERE id = :id AND created_by = :created_by
         LIMIT 1'
    );
    $stmt->execute(['id' => $id, 'created_by' => $createdBy]);
    $r = $stmt->fetch();
    if (!$r) {
        throw new RuntimeException('Producto no encontrado.');
    }

    return [
        'id' => (int)($r['id'] ?? 0),
        'name' => (string)($r['name'] ?? ''),
        'description' => (string)($r['description'] ?? ''),
        'image_path' => (string)($r['image_path'] ?? ''),
        'unit' => (string)($r['unit'] ?? ''),
        'price_cents' => (int)($r['price_cents'] ?? 0),
        'currency' => (string)($r['currency'] ?? 'ARS'),
        'updated_at' => (string)($r['updated_at'] ?? ''),
        'created_at' => (string)($r['created_at'] ?? ''),
    ];
}

function catalog_create(PDO $pdo, int $createdBy, string $name, string|float|int $price, string $currency = 'ARS', string $description = '', string $unit = '', string $imagePath = ''): int
{
    catalog_ensure_description_column($pdo);
    catalog_ensure_unit_column($pdo);
    catalog_ensure_image_column($pdo);

    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Nombre requerido.');
    }
    $nameLen = function_exists('mb_strlen') ? (int)mb_strlen($name, 'UTF-8') : strlen($name);
    if ($nameLen > 190) {
        throw new InvalidArgumentException('Nombre demasiado largo.');
    }

    $priceCents = catalog_parse_price_to_cents($price);

    $currency = strtoupper(trim($currency));
    if ($currency === '') {
        $currency = 'ARS';
    }

    $description = trim($description);
    $descLen = function_exists('mb_strlen') ? (int)mb_strlen($description, 'UTF-8') : strlen($description);
    if ($descLen > 255) {
        throw new InvalidArgumentException('Descripción demasiado larga.');
    }

    $unitNorm = '';
    if (trim($unit) !== '') {
        $unitNorm = catalog_normalize_unit($unit);
    }

    $unitLen = function_exists('mb_strlen') ? (int)mb_strlen($unitNorm, 'UTF-8') : strlen($unitNorm);
    if ($unitLen > 24) {
        throw new InvalidArgumentException('Unidad demasiado larga.');
    }

    $imagePath = catalog_sanitize_image_path($imagePath);
    $imgLen = function_exists('mb_strlen') ? (int)mb_strlen($imagePath, 'UTF-8') : strlen($imagePath);
    if ($imgLen > 255) {
        throw new InvalidArgumentException('Ruta de imagen demasiado larga.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO catalog_products (created_by, name, description, image_path, unit, price_cents, currency)
         VALUES (:created_by, :name, :description, :image_path, :unit, :price_cents, :currency)'
    );
    $stmt->execute([
        'created_by' => $createdBy,
        'name' => $name,
        'description' => ($description === '' ? null : $description),
        'image_path' => ($imagePath === '' ? null : $imagePath),
        'unit' => ($unitNorm === '' ? null : $unitNorm),
        'price_cents' => $priceCents,
        'currency' => $currency,
    ]);

    return (int)$pdo->lastInsertId();
}

function catalog_update(PDO $pdo, int $createdBy, int $id, string $name, string|float|int $price, string $currency = 'ARS', string $description = '', string $unit = '', ?string $imagePath = null, bool $removeImage = false): void
{
    catalog_ensure_description_column($pdo);
    catalog_ensure_unit_column($pdo);
    catalog_ensure_image_column($pdo);

    $id = (int)$id;
    if ($id <= 0) {
        throw new InvalidArgumentException('Producto inválido.');
    }

    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Nombre requerido.');
    }
    $nameLen = function_exists('mb_strlen') ? (int)mb_strlen($name, 'UTF-8') : strlen($name);
    if ($nameLen > 190) {
        throw new InvalidArgumentException('Nombre demasiado largo.');
    }

    $priceCents = catalog_parse_price_to_cents($price);

    $currency = strtoupper(trim($currency));
    if ($currency === '') {
        $currency = 'ARS';
    }

    $description = trim($description);
    $descLen = function_exists('mb_strlen') ? (int)mb_strlen($description, 'UTF-8') : strlen($description);
    if ($descLen > 255) {
        throw new InvalidArgumentException('Descripción demasiado larga.');
    }

    $unitNorm = '';
    if (trim($unit) !== '') {
        $unitNorm = catalog_normalize_unit($unit);
    }

    $unitLen = function_exists('mb_strlen') ? (int)mb_strlen($unitNorm, 'UTF-8') : strlen($unitNorm);
    if ($unitLen > 24) {
        throw new InvalidArgumentException('Unidad demasiado larga.');
    }

    $setImage = false;
    $imageDbValue = null;
    $currentImage = '';

    if ($imagePath !== null) {
        $imagePath = catalog_sanitize_image_path($imagePath);
        $imgLen = function_exists('mb_strlen') ? (int)mb_strlen($imagePath, 'UTF-8') : strlen($imagePath);
        if ($imgLen > 255) {
            throw new InvalidArgumentException('Ruta de imagen demasiado larga.');
        }
        $setImage = true;
        $imageDbValue = ($imagePath === '' ? null : $imagePath);
    } elseif ($removeImage) {
        $setImage = true;
        $imageDbValue = null;
    }

    if ($setImage) {
        $current = catalog_get($pdo, $createdBy, $id);
        $currentImage = (string)($current['image_path'] ?? '');
    }

    $sql = 'UPDATE catalog_products
         SET name = :name, description = :description, unit = :unit, price_cents = :price_cents, currency = :currency';
    $params = [
        'id' => $id,
        'created_by' => $createdBy,
        'name' => $name,
        'description' => ($description === '' ? null : $description),
        'unit' => ($unitNorm === '' ? null : $unitNorm),
        'price_cents' => $priceCents,
        'currency' => $currency,
    ];

    if ($setImage) {
        $sql .= ', image_path = :image_path';
        $params['image_path'] = $imageDbValue;
    }

    $sql .= ' WHERE id = :id AND created_by = :created_by';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        // Puede ser que no exista o que no haya cambios; validamos existencia.
        catalog_get($pdo, $createdBy, $id);
    }

    if ($setImage && $currentImage !== '' && $currentImage !== (string)$imageDbValue) {
        catalog_delete_image_file($currentImage);
    }
}

function catalog_delete(PDO $pdo, int $createdBy, int $id): void
{
    $id = (int)$id;
    if ($id <= 0) {
        throw new InvalidArgumentException('Producto inválido.');
    }

    $current = catalog_get($pdo, $createdBy, $id);
    $currentImage = (string)($current['image_path'] ?? '');

    $stmt = $pdo->prepare('DELETE FROM catalog_products WHERE id = :id AND created_by = :created_by');
    $stmt->execute(['id' => $id, 'created_by' => $createdBy]);

    if ($stmt->rowCount() > 0 && $currentImage !== '') {
        catalog_delete_image_file($currentImage);
    }
}

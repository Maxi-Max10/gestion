<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

auth_require_login();

$config = app_config();
$appName = (string)($config['app']['name'] ?? 'Dietetic');
$userId = (int)auth_user_id();
$csrf = csrf_token();

$newOrdersCount = 0;
try {
  $pdoNav = db($config);
  $newOrdersCount = orders_count_new($pdoNav, $userId);
} catch (Throwable $e) {
  error_log('catalogo.php nav count error: ' . $e->getMessage());
  $newOrdersCount = 0;
}

$flash = (string)($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);
$flashError = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_error']);
$error = '';

$accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
$wantsJson = (
  ((string)($_GET['ajax'] ?? '') === '1')
  || ((string)($_POST['ajax'] ?? '') === '1')
  || (stripos($accept, 'application/json') !== false)
);

$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$jsonBody = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && stripos($contentType, 'application/json') !== false) {
  $raw = file_get_contents('php://input');
  $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
  if (is_array($decoded)) {
    $jsonBody = $decoded;
  }
}

$q = trim((string)($_GET['q'] ?? ''));
$format = strtolower(trim((string)($_GET['format'] ?? '')));
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

$edit = null;

// API JSON (para carga dinámica y buscador en vivo)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $wantsJson) {
  try {
    $pdo = db($config);
    if (!catalog_supports_table($pdo)) {
      throw new RuntimeException('No se encontró la tabla del catálogo. ¿Ejecutaste el schema.sql?');
    }

    $qAjax = trim((string)($_GET['q'] ?? ''));
    $items = catalog_list($pdo, $userId, $qAjax, 300);
    $out = [];
    foreach ($items as $r) {
      $unit = trim((string)($r['unit'] ?? ''));
      $imagePath = trim((string)($r['image_path'] ?? ''));
      $priceFormatted = money_format_cents((int)($r['price_cents'] ?? 0), (string)($r['currency'] ?? 'ARS'));
      $out[] = [
        'id' => (int)($r['id'] ?? 0),
        'name' => (string)($r['name'] ?? ''),
        'description' => (string)($r['description'] ?? ''),
        'image_path' => $imagePath,
        'image_url' => $imagePath !== '' ? catalog_image_url($imagePath) : '',
        'unit' => $unit,
        'price_cents' => (int)($r['price_cents'] ?? 0),
        'currency' => (string)($r['currency'] ?? 'ARS'),
        'price_formatted' => $priceFormatted,
        'price_label' => $priceFormatted . ($unit !== '' ? (' / ' . $unit) : ''),
      ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'items' => $out], JSON_UNESCAPED_UNICODE);
    exit;
  } catch (Throwable $e) {
    error_log('catalogo.php ajax load error: ' . $e->getMessage());
    $rawMsg = $e->getMessage();
    if (stripos($rawMsg, 'Falta la columna description') !== false) {
      $msg = 'Falta actualizar la base de datos del catálogo. Ejecutá: ALTER TABLE catalog_products ADD COLUMN description VARCHAR(255) NULL AFTER name;';
    } elseif (stripos($rawMsg, 'Falta la columna image_path') !== false) {
      $msg = 'Falta actualizar la base de datos del catálogo. Ejecutá: ALTER TABLE catalog_products ADD COLUMN image_path VARCHAR(255) NULL AFTER description;';
    } else {
      $msg = ($config['app']['env'] ?? 'production') === 'production'
        ? 'No se pudo cargar el catálogo.'
        : ('Error: ' . $rawMsg);
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($format, ['csv', 'xlsx'], true) && !$wantsJson) {
  try {
    $pdo = db($config);
    if (!catalog_supports_table($pdo)) {
      throw new RuntimeException('No se encontró la tabla del catálogo. ¿Ejecutaste el schema.sql?');
    }

    $catalogRows = catalog_list($pdo, $userId, $q, 5000);
    $exportRows = catalog_export_rows($catalogRows);
    $headers = ['producto', 'precio', 'unidad', 'descripcion', 'moneda', 'imagen'];
    $stamp = date('Ymd-His');
    $base = 'catalogo-productos-' . $stamp;

    if ($format === 'csv') {
      reports_export_csv($headers, $exportRows, $base . '.csv');
      exit;
    }

    catalog_export_xlsx_import_format('Catalogo', $catalogRows, $base . '.xlsx');
    exit;
  } catch (Throwable $e) {
    error_log('catalogo.php export error: ' . $e->getMessage());
    $error = ($config['app']['env'] ?? 'production') === 'production'
      ? 'No se pudo exportar el catálogo.'
      : ('Error: ' . $e->getMessage());
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $data = array_merge($_POST, $jsonBody);
  $token = (string)($data['csrf_token'] ?? '');
    if (!csrf_verify($token)) {
    $error = 'Sesión inválida. Recargá e intentá de nuevo.';
    if ($wantsJson) {
      http_response_code(400);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
      exit;
    }
    } else {
    $action = (string)($data['action'] ?? '');
    $returnQ = trim((string)($data['q'] ?? $q));

        try {
            $pdo = db($config);

        $uploadedImagePath = '';
        $uploadedImageProvided = false;
        if (isset($_FILES['image']) && is_array($_FILES['image'])) {
          $fileError = (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE);
          if ($fileError !== UPLOAD_ERR_NO_FILE) {
            $uploadedImagePath = catalog_store_uploaded_image($_FILES['image']);
            $uploadedImageProvided = $uploadedImagePath !== '';
          }
        }

            if (!catalog_supports_table($pdo)) {
                throw new RuntimeException('No se encontró la tabla del catálogo. ¿Ejecutaste el schema.sql?');
            }

            if ($action === 'create') {
              $name = trim((string)($data['name'] ?? ''));
              $description = trim((string)($data['description'] ?? ''));
              $unit = trim((string)($data['unit'] ?? ''));
              $price = (string)($data['price'] ?? '0');
              $currency = trim((string)($data['currency'] ?? 'ARS'));

                catalog_create(
                  $pdo,
                  $userId,
                  $name,
                  $price,
                  $currency,
                  $description,
                  $unit,
                  $uploadedImageProvided ? $uploadedImagePath : ''
                );
              if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => 'Producto agregado al catálogo.'], JSON_UNESCAPED_UNICODE);
                exit;
              }

              $_SESSION['flash'] = 'Producto agregado al catálogo.';
              header('Location: /catalogo' . ($returnQ !== '' ? ('?q=' . rawurlencode($returnQ)) : ''));
              exit;
            }

            if ($action === 'update') {
              $id = (int)($data['id'] ?? 0);
              $name = trim((string)($data['name'] ?? ''));
              $description = trim((string)($data['description'] ?? ''));
              $unit = trim((string)($data['unit'] ?? ''));
              $price = (string)($data['price'] ?? '0');
              $currency = trim((string)($data['currency'] ?? 'ARS'));
              $removeImage = ((string)($data['image_remove'] ?? '')) === '1';

                catalog_update(
                  $pdo,
                  $userId,
                  $id,
                  $name,
                  $price,
                  $currency,
                  $description,
                  $unit,
                  $uploadedImageProvided ? $uploadedImagePath : null,
                  $removeImage
                );
              if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => 'Producto actualizado.'], JSON_UNESCAPED_UNICODE);
                exit;
              }

              $_SESSION['flash'] = 'Producto actualizado.';
              header('Location: /catalogo' . ($returnQ !== '' ? ('?q=' . rawurlencode($returnQ)) : ''));
              exit;
            }

            if ($action === 'delete') {
              $id = (int)($data['id'] ?? 0);
                catalog_delete($pdo, $userId, $id);
              if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => 'Producto eliminado.'], JSON_UNESCAPED_UNICODE);
                exit;
              }

              $_SESSION['flash'] = 'Producto eliminado.';
              header('Location: /catalogo' . ($returnQ !== '' ? ('?q=' . rawurlencode($returnQ)) : ''));
              exit;
            }

            if ($action === 'import_excel') {
              $result = catalog_import_spreadsheet($pdo, $userId, $_FILES['catalog_file'] ?? []);
              $summary = 'Importación completada: ' . $result['created'] . ' creados, ' . $result['updated'] . ' actualizados';
              if ($result['skipped'] > 0) {
                $summary .= ', ' . $result['skipped'] . ' filas vacías omitidas';
              }
              $summary .= '.';

              if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                  'ok' => true,
                  'message' => $summary,
                  'result' => $result,
                ], JSON_UNESCAPED_UNICODE);
                exit;
              }

              $_SESSION['flash'] = $summary;
              if (count($result['errors']) > 0) {
                $_SESSION['flash_error'] = 'Algunas filas no se importaron: ' . implode(' | ', array_slice($result['errors'], 0, 8));
              }
              header('Location: /catalogo');
              exit;
            }

            throw new InvalidArgumentException('Acción inválida.');
        } catch (Throwable $e) {
            error_log('catalogo.php error: ' . $e->getMessage());
            if (!empty($uploadedImageProvided) && !empty($uploadedImagePath)) {
              catalog_delete_image_file($uploadedImagePath);
            }
            $rawMsg = $e->getMessage();
            if (stripos($rawMsg, 'Falta la columna description') !== false) {
              $error = 'Falta actualizar la base de datos del catálogo. Ejecutá: ALTER TABLE catalog_products ADD COLUMN description VARCHAR(255) NULL AFTER name;';
            } elseif (stripos($rawMsg, 'Falta la columna image_path') !== false) {
              $error = 'Falta actualizar la base de datos del catálogo. Ejecutá: ALTER TABLE catalog_products ADD COLUMN image_path VARCHAR(255) NULL AFTER description;';
            } else {
              $error = ($config['app']['env'] ?? 'production') === 'production'
                  ? 'No se pudo procesar el catálogo.'
                  : ('Error: ' . $rawMsg);
            }

          if ($wantsJson) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
            exit;
          }
        }
    }
}

$rows = [];
try {
    $pdo = db($config);

    if (!catalog_supports_table($pdo)) {
        throw new RuntimeException('No se encontró la tabla del catálogo. ¿Ejecutaste el schema.sql?');
    }

    if ($editId > 0) {
        $edit = catalog_get($pdo, $userId, $editId);
    }

    $rows = catalog_list($pdo, $userId, $q, 300);
} catch (Throwable $e) {
    error_log('catalogo.php load error: ' . $e->getMessage());
    $rows = [];
    if ($error === '') {
      $rawMsg = $e->getMessage();
      $error = stripos($rawMsg, 'Falta la columna description') !== false
        ? 'Falta actualizar la base de datos del catálogo. Ejecutá: ALTER TABLE catalog_products ADD COLUMN description VARCHAR(255) NULL AFTER name;'
        : (stripos($rawMsg, 'Falta la columna image_path') !== false
          ? 'Falta actualizar la base de datos del catálogo. Ejecutá: ALTER TABLE catalog_products ADD COLUMN image_path VARCHAR(255) NULL AFTER description;'
          : (
            ($config['app']['env'] ?? 'production') === 'production'
              ? 'No se pudo cargar el catálogo. ¿Ejecutaste el schema.sql?'
              : ('Error: ' . $rawMsg)
          )
        );
    }
}

$defaultName = is_array($edit) ? (string)($edit['name'] ?? '') : '';
$defaultDescription = is_array($edit) ? (string)($edit['description'] ?? '') : '';
$defaultImagePath = is_array($edit) ? (string)($edit['image_path'] ?? '') : '';
$defaultImageUrl = $defaultImagePath !== '' ? catalog_image_url($defaultImagePath) : '';
$defaultUnit = is_array($edit) ? (string)($edit['unit'] ?? '') : '';
$defaultCurrency = is_array($edit) ? (string)($edit['currency'] ?? 'ARS') : 'ARS';
$defaultPrice = '';
if (is_array($edit)) {
    $defaultPrice = number_format(((int)($edit['price_cents'] ?? 0)) / 100, 2, '.', '');
}

function catalog_capitalize_first(string $value): string
{
  $value = trim($value);
  if ($value === '') {
    return '';
  }
  if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
    $first = mb_substr($value, 0, 1, 'UTF-8');
    $rest = mb_substr($value, 1, null, 'UTF-8');
    return mb_strtoupper($first, 'UTF-8') . $rest;
  }
  return strtoupper(substr($value, 0, 1)) . substr($value, 1);
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, string>>
 */
function catalog_export_rows(array $rows): array
{
  $out = [];
  foreach ($rows as $r) {
    $priceCents = (int)($r['price_cents'] ?? 0);
    $imagePath = (string)($r['image_path'] ?? '');
    $out[] = [
      'producto' => (string)($r['name'] ?? ''),
      'precio' => number_format($priceCents / 100, 2, '.', ''),
      'unidad' => (string)($r['unit'] ?? ''),
      'descripcion' => (string)($r['description'] ?? ''),
      'moneda' => (string)($r['currency'] ?? 'ARS'),
      'imagen' => $imagePath,
    ];
  }
  return $out;
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function catalog_export_xlsx_import_format(string $sheetName, array $rows, string $filename): void
{
  if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
    throw new RuntimeException('Falta instalar dependencias para XLSX (PhpSpreadsheet).');
  }

  $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle($sheetName);

  $headers = ['Producto', 'Precio', 'Unidad', 'Descripción', 'Moneda', 'Imagen'];
  $sheet->fromArray($headers, null, 'A1');
  $sheet->getStyle('A1:F1')->getFont()->setBold(true);

  $rowIndex = 2;
  foreach ($rows as $r) {
    $priceCents = (int)($r['price_cents'] ?? 0);
    $imagePath = (string)($r['image_path'] ?? '');

    $sheet->fromArray([
      (string)($r['name'] ?? ''),
      number_format($priceCents / 100, 2, '.', ''),
      (string)($r['unit'] ?? ''),
      (string)($r['description'] ?? ''),
      (string)($r['currency'] ?? 'ARS'),
      $imagePath,
    ], null, 'A' . $rowIndex);

    $localImagePath = $imagePath !== '' ? catalog_image_file_path($imagePath) : '';
    if ($localImagePath !== '') {
      $sheet->getRowDimension($rowIndex)->setRowHeight(64);
      $drawing = new PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
      $drawing->setPath($localImagePath);
      $drawing->setName((string)($r['name'] ?? 'Imagen'));
      $drawing->setDescription((string)($r['name'] ?? 'Imagen del producto'));
      $drawing->setCoordinates('F' . $rowIndex);
      $drawing->setOffsetX(8);
      $drawing->setOffsetY(6);
      $drawing->setHeight(52);
      $drawing->setWorksheet($sheet);
    }

    $rowIndex++;
  }

  foreach (['A' => 28, 'B' => 12, 'C' => 12, 'D' => 34, 'E' => 12, 'F' => 18] as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
  }
  $sheet->freezePane('A2');

  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('X-Content-Type-Options: nosniff');

  $writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
  $writer->save('php://output');
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e($csrf) ?>">
  <title>Catálogo</title>
  <link rel="icon" type="image/png" href="/logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link rel="stylesheet" href="/brand.css?v=20260318-1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --accent:#1E3A8A; --accent-rgb:30,58,138; --accent-dark:#2563EB; --accent-2:#3B82F6; --accent-2-rgb:59,130,246; --ink:#111827; --muted:#111827; --card:rgba(255,255,255,.9); }
    body { font-family:'Space Grotesk','Segoe UI',sans-serif; background: radial-gradient(circle at 10% 20%, rgba(var(--accent-2-rgb),.22), transparent 38%), radial-gradient(circle at 90% 10%, rgba(var(--accent-rgb),.12), transparent 40%), linear-gradient(120deg,#F3F4F6,#F3F4F6); color:var(--ink); min-height:100vh; }
    .navbar-glass { background:rgba(255,255,255,.9); backdrop-filter:blur(12px); border:1px solid rgba(15,23,42,.06); box-shadow:0 10px 40px rgba(15,23,42,.08); }
    .navbar-glass .container { padding-left: calc(.75rem + env(safe-area-inset-left)); padding-right: calc(.75rem + env(safe-area-inset-right)); }
    .page-shell { padding:2.5rem 0; }
    .card-lift { background:var(--card); border:1px solid rgba(15,23,42,.06); box-shadow:0 18px 50px rgba(15,23,42,.07); border-radius:18px; }
    .card-header-clean { border-bottom:1px solid rgba(15,23,42,.06); }
    .pill { display:inline-flex; align-items:center; gap:.4rem; padding:.35rem .75rem; border-radius:999px; background:rgba(var(--accent-rgb),.1); color:var(--accent); font-weight:600; font-size:.9rem; }
    .action-btn { border-radius:12px; padding-inline:1.25rem; font-weight:600; }
    .btn-primary, .btn-primary:hover, .btn-primary:focus { background:linear-gradient(135deg,var(--accent),var(--accent-dark)); border:none; box-shadow:0 10px 30px rgba(var(--accent-rgb),.25); }
    .btn-outline-primary { border-color:var(--accent); color:var(--accent); }
    .btn-outline-primary:hover, .btn-outline-primary:focus { background:rgba(var(--accent-rgb),.1); color:var(--accent); border-color:var(--accent); }
    .table thead th { background:rgba(var(--accent-rgb),.08); border-bottom:none; font-weight:600; color:var(--ink); }
    .table td, .table th { border-color:rgba(148,163,184,.35); }
    .muted-label { color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.04em; font-size:.8rem; }
    @media (max-width:768px){ .page-shell{padding:1.5rem .75rem; padding-left:calc(.75rem + env(safe-area-inset-left)); padding-right:calc(.75rem + env(safe-area-inset-right));} .card-lift{border-radius:14px} }
    .navbar-logo { height:34px; width:auto; display:inline-block; }

    .nav-toggle-btn { border-radius:12px; font-weight:600; }
    .offcanvas-nav .list-group-item { border:1px solid rgba(15,23,42,.06); border-radius:14px; margin-bottom:.6rem; background:rgba(255,255,255,.85); box-shadow:0 10px 30px rgba(15,23,42,.06); }
    .offcanvas-nav .list-group-item:active { transform: translateY(1px); }

    .nav-shell {
      display: inline-flex;
      align-items: center;
      gap: .25rem;
      padding: .25rem;
      border-radius: 999px;
      background: rgba(15, 23, 42, .04);
      border: 1px solid rgba(15, 23, 42, .08);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, .65);
    }

    .nav-link-pill {
      appearance: none;
      -webkit-appearance: none;
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      padding: .45rem .85rem;
      border-radius: 999px;
      font-weight: 650;
      font-size: .92rem;
      line-height: 1;
      text-decoration: none;
      white-space: nowrap;
      cursor: pointer;
      color: rgba(15, 23, 42, .78);
      background: transparent;
      border: 1px solid transparent;
      transition: background .15s ease, box-shadow .15s ease, color .15s ease, transform .05s ease;
    }

    .nav-link-pill:hover,
    .nav-link-pill:focus {
      background: rgba(15, 23, 42, .06);
      color: var(--ink);
    }

    .nav-link-pill.is-active,
    .nav-link-pill[aria-current="page"] {
      background: linear-gradient(135deg, var(--accent), var(--accent-dark));
      color: #fff;
      box-shadow: 0 12px 30px rgba(var(--accent-rgb), .28);
    }

    .nav-link-pill:active { transform: translateY(1px); }

    .nav-link-pill--danger {
      color: #b91c1c;
      background: rgba(220, 38, 38, .08);
      border-color: rgba(220, 38, 38, .18);
    }

    .nav-link-pill--danger:hover,
    .nav-link-pill--danger:focus {
      background: rgba(220, 38, 38, .12);
      color: #991b1b;
    }

    .catalog-thumb {
      width: 52px;
      height: 52px;
      border-radius: 12px;
      object-fit: cover;
      border: 1px solid rgba(15,23,42,.08);
      display: block;
      background: #fff;
    }
    .catalog-thumb-fallback {
      width: 52px;
      height: 52px;
      border-radius: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: .72rem;
      font-weight: 700;
      color: var(--accent);
      background: rgba(var(--accent-rgb), .08);
      border: 1px dashed rgba(var(--accent-rgb), .28);
    }

    .image-upload {
      display: grid;
      grid-template-columns: 86px 1fr;
      gap: 1rem;
      padding: .85rem;
      border-radius: 16px;
      background: rgba(255,255,255,.75);
      border: 1px dashed rgba(148,163,184,.5);
    }
    .image-preview {
      width: 86px;
      height: 86px;
      border-radius: 16px;
      border: 1px solid rgba(15,23,42,.1);
      background: rgba(15,23,42,.04);
      display: grid;
      place-items: center;
      overflow: hidden;
    }
    .image-preview img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .image-placeholder {
      font-size: .8rem;
      font-weight: 700;
      color: var(--accent);
      letter-spacing: .04em;
    }
    .image-actions {
      display: flex;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
    }
    .import-card {
      position: relative;
      overflow: hidden;
      background:
        radial-gradient(circle at top right, rgba(var(--accent-2-rgb), .18), transparent 32%),
        linear-gradient(145deg, rgba(255,255,255,.96), rgba(248,250,252,.92));
    }
    .import-card::after {
      content: '';
      position: absolute;
      inset: auto -10% -65% auto;
      width: 320px;
      height: 320px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(var(--accent-rgb), .12), transparent 68%);
      pointer-events: none;
    }
    .import-grid {
      position: relative;
      z-index: 1;
      display: grid;
      grid-template-columns: minmax(0, 1.5fr) minmax(280px, .9fr);
      gap: 1.25rem;
      align-items: stretch;
    }
    .import-dropzone {
      height: 100%;
      padding: 1.15rem;
      border-radius: 20px;
      border: 1px solid rgba(var(--accent-rgb), .14);
      background: linear-gradient(180deg, rgba(255,255,255,.96), rgba(244,247,251,.96));
      box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
      transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease, background .18s ease;
    }
    .import-dropzone.is-dragover {
      border-color: rgba(var(--accent-rgb), .42);
      background: linear-gradient(180deg, rgba(239,246,255,.98), rgba(255,255,255,.98));
      box-shadow: 0 0 0 .24rem rgba(var(--accent-rgb), .12), inset 0 1px 0 rgba(255,255,255,.92);
      transform: translateY(-1px);
    }
    .import-dropzone-head {
      display: flex;
      align-items: flex-start;
      gap: .9rem;
      margin-bottom: 1rem;
    }
    .import-icon {
      width: 52px;
      height: 52px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 16px;
      color: #fff;
      background: linear-gradient(135deg, var(--accent), var(--accent-dark));
      box-shadow: 0 14px 28px rgba(var(--accent-rgb), .26);
      flex: 0 0 auto;
    }
    .import-title {
      margin: 0;
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--ink);
    }
    .import-copy {
      margin: .25rem 0 0;
      color: rgba(17,24,39,.72);
      font-size: .95rem;
      line-height: 1.55;
    }
    .import-input-wrap {
      display: grid;
      gap: .75rem;
    }
    .import-pick-zone {
      display: grid;
      gap: .8rem;
      padding: 1rem;
      border-radius: 18px;
      border: 1px dashed rgba(var(--accent-rgb), .22);
      background: rgba(var(--accent-rgb), .03);
    }
    .import-input-wrap .form-control {
      min-height: 54px;
      border-radius: 16px;
      border: 1px solid rgba(148,163,184,.38);
      background: rgba(255,255,255,.96);
      box-shadow: 0 8px 22px rgba(15,23,42,.05);
    }
    .import-input-wrap .form-control:hover,
    .import-input-wrap .form-control:focus {
      border-color: rgba(var(--accent-rgb), .35);
      box-shadow: 0 0 0 .18rem rgba(var(--accent-rgb), .12), 0 10px 24px rgba(15,23,42,.08);
    }
    .import-chips {
      display: flex;
      flex-wrap: wrap;
      gap: .55rem;
    }
    .import-drop-hint {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      flex-wrap: wrap;
      color: rgba(17,24,39,.74);
      font-size: .92rem;
    }
    .import-drop-hint strong {
      color: var(--ink);
      font-size: .96rem;
    }
    .import-file-state {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      min-height: 40px;
      padding: .55rem .8rem;
      border-radius: 14px;
      border: 1px solid rgba(148,163,184,.26);
      background: rgba(255,255,255,.88);
      color: rgba(17,24,39,.7);
      font-size: .9rem;
    }
    .import-file-state.is-ready {
      color: var(--accent);
      border-color: rgba(var(--accent-rgb), .24);
      background: rgba(var(--accent-rgb), .06);
      font-weight: 600;
    }
    .import-chip {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      padding: .45rem .72rem;
      border-radius: 999px;
      border: 1px solid rgba(var(--accent-rgb), .14);
      background: rgba(var(--accent-rgb), .06);
      color: var(--accent);
      font-size: .82rem;
      font-weight: 700;
      letter-spacing: .01em;
    }
    .import-side {
      position: relative;
      z-index: 1;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 1rem;
      padding: 1.2rem;
      border-radius: 20px;
      color: #fff;
      background: linear-gradient(160deg, rgba(30,58,138,.98), rgba(37,99,235,.95));
      box-shadow: 0 22px 45px rgba(var(--accent-rgb), .24);
    }
    .import-side-label {
      margin: 0;
      font-size: .78rem;
      font-weight: 700;
      letter-spacing: .12em;
      text-transform: uppercase;
      color: rgba(255,255,255,.68);
    }
    .import-side-title {
      margin: .3rem 0 0;
      font-size: 1.2rem;
      font-weight: 700;
      line-height: 1.25;
    }
    .import-side-copy {
      margin: .55rem 0 0;
      color: rgba(255,255,255,.82);
      line-height: 1.55;
      font-size: .95rem;
    }
    .import-side .btn-primary {
      min-height: 52px;
      border-radius: 16px;
      background: linear-gradient(135deg, rgba(255,255,255,.98), rgba(241,245,249,.96));
      color: var(--accent);
      box-shadow: 0 14px 30px rgba(7, 24, 68, .22);
    }
    .import-side .btn-primary:hover,
    .import-side .btn-primary:focus {
      color: var(--accent-dark);
      transform: translateY(-1px);
    }
    .import-side .btn-primary[disabled] {
      opacity: .8;
      transform: none;
      cursor: wait;
    }
    .import-list {
      margin: 0;
      padding: 0;
      list-style: none;
      display: grid;
      gap: .55rem;
    }
    .import-list li {
      display: flex;
      align-items: center;
      gap: .55rem;
      color: rgba(255,255,255,.84);
      font-size: .92rem;
    }
    .import-list-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: rgba(255,255,255,.92);
      flex: 0 0 auto;
    }
    .catalog-action-bar {
      display: flex;
      flex-wrap: wrap;
      gap: .85rem;
      margin-bottom: 1.25rem;
    }
    .catalog-launch-btn {
      min-height: 52px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: .6rem;
      padding-inline: 1.15rem;
    }
    .catalog-modal .modal-content {
      border: 0;
      border-radius: 24px;
      overflow: hidden;
      box-shadow: 0 30px 80px rgba(15,23,42,.18);
    }
    .catalog-modal .modal-header {
      border-bottom: 1px solid rgba(15,23,42,.06);
      background: linear-gradient(180deg, rgba(255,255,255,.96), rgba(248,250,252,.9));
    }
    .catalog-modal .modal-body {
      background: linear-gradient(180deg, rgba(255,255,255,.98), rgba(248,250,252,.96));
    }
    .catalog-toast-wrap {
      position: fixed;
      top: 1rem;
      right: 1rem;
      z-index: 1080;
      max-width: min(92vw, 420px);
    }
    .catalog-toast {
      border: 0;
      border-radius: 18px;
      overflow: hidden;
      background: rgba(255,255,255,.96);
      box-shadow: 0 24px 60px rgba(15,23,42,.18);
      backdrop-filter: blur(10px);
    }
    .catalog-toast .toast-header {
      border-bottom: 1px solid rgba(15,23,42,.06);
      background: linear-gradient(135deg, rgba(var(--accent-rgb), .12), rgba(var(--accent-2-rgb), .06));
      color: var(--ink);
    }
    .catalog-toast-icon {
      width: 30px;
      height: 30px;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      background: linear-gradient(135deg, var(--accent), var(--accent-dark));
      box-shadow: 0 10px 24px rgba(var(--accent-rgb), .24);
      flex: 0 0 auto;
    }
    .catalog-toast .toast-body {
      color: rgba(17,24,39,.82);
      line-height: 1.55;
    }
    @media (max-width: 576px) {
      .image-upload { grid-template-columns: 70px 1fr; }
      .image-preview { width: 70px; height: 70px; border-radius: 14px; }
      .catalog-toast-wrap {
        top: .75rem;
        right: .75rem;
        left: .75rem;
        max-width: none;
      }
    }
    @media (max-width: 991.98px) {
      .import-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body class="has-leaves-bg">
<div class="catalog-toast-wrap" aria-live="polite" aria-atomic="true">
  <div id="catalogImportToast" class="toast catalog-toast" role="status" aria-live="polite" aria-atomic="true" data-bs-delay="4200">
    <div class="toast-header">
      <span class="catalog-toast-icon me-2" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 8.5 6.2 11.5 13 4.5" />
        </svg>
      </span>
      <strong class="me-auto">Importación completada</strong>
      <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Cerrar"></button>
    </div>
    <div class="toast-body" id="catalogImportToastBody">Los productos se importaron correctamente.</div>
  </div>
</div>
<div class="modal fade" id="catalogImportSuccessModal" tabindex="-1" aria-labelledby="catalogImportSuccessModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:22px; overflow:hidden;">
      <div class="modal-header" style="background:linear-gradient(135deg, rgba(30,58,138,.1), rgba(59,130,246,.08)); border-bottom:1px solid rgba(15,23,42,.06);">
        <div class="d-flex align-items-center gap-2">
          <span class="catalog-toast-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 8.5 6.2 11.5 13 4.5" />
            </svg>
          </span>
          <h5 class="modal-title mb-0" id="catalogImportSuccessModalLabel">Importación completada</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body px-4 py-4">
        <p class="mb-0" id="catalogImportSuccessModalBody">Los productos se importaron correctamente.</p>
      </div>
      <div class="modal-footer border-0 pt-0 px-4 pb-4">
        <button type="button" class="btn btn-primary action-btn" data-bs-dismiss="modal">Aceptar</button>
      </div>
    </div>
  </div>
</div>
<div class="bg-leaves" aria-hidden="true">
  <div class="bg-leaf leaf-1"></div>
  <div class="bg-leaf leaf-2"></div>
  <div class="bg-leaf leaf-3"></div>
  <div class="bg-leaf leaf-4"></div>
  <div class="bg-leaf leaf-5"></div>
  <div class="bg-leaf leaf-6"></div>
  <div class="bg-leaf leaf-7"></div>
  <div class="bg-leaf leaf-8"></div>
  <div class="bg-leaf leaf-9"></div>
  <div class="bg-leaf leaf-10"></div>
  <div class="bg-leaf leaf-11"></div>
  <div class="bg-leaf leaf-12"></div>
</div>
<nav class="navbar navbar-expand-lg navbar-glass sticky-top">
  <div class="container py-2">
    <a class="navbar-brand d-flex align-items-center gap-2 fw-bold text-dark mb-0 h4 text-decoration-none" href="/dashboard" aria-label="<?= e($appName) ?>">
      <img src="/logo.png" alt="Logo" class="navbar-logo">
    </a>
    <div class="ms-auto d-flex align-items-center gap-2 justify-content-end">
      <span class="pill d-none d-lg-inline-flex">Admin</span>

      <button class="btn btn-outline-primary btn-sm d-inline-flex align-items-center d-lg-none nav-toggle-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#appNavOffcanvas" aria-controls="appNavOffcanvas">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="me-1" aria-hidden="true">
          <path d="M2.5 4h11" />
          <path d="M2.5 8h11" />
          <path d="M2.5 12h11" />
        </svg>
        Menú
      </button>

      <div class="d-none d-lg-flex align-items-center gap-2 justify-content-end">
        <div class="nav-shell" role="navigation" aria-label="Secciones">
          <a class="nav-link-pill" href="/dashboard">Dashboard</a>
          <a class="nav-link-pill" href="/sales">Ventas</a>
          <a class="nav-link-pill" href="/customers">Clientes</a>
          <a class="nav-link-pill" href="/products">Productos</a>
          <a class="nav-link-pill is-active" href="/catalogo" aria-current="page">Catálogo</a>
          <a class="nav-link-pill" href="/pedidos">Pedidos<?php if ($newOrdersCount > 0): ?><span class="badge rounded-pill text-bg-danger ms-1"><?= e((string)$newOrdersCount) ?></span><?php endif; ?></a>
          <a class="nav-link-pill" href="/income">Ingresos</a>
          <a class="nav-link-pill" href="/expense">Egresos</a>
          <a class="nav-link-pill" href="/stock">Stock</a>
        </div>

        <form method="post" action="/logout.php" class="d-flex">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <button type="submit" class="nav-link-pill nav-link-pill--danger">Salir</button>
        </form>
      </div>
    </div>
  </div>
</nav>

<div class="offcanvas offcanvas-end" tabindex="-1" id="appNavOffcanvas" aria-labelledby="appNavOffcanvasLabel">
  <div class="offcanvas-header">
    <div class="d-flex align-items-center gap-2">
      <img src="/logo.png" alt="Logo" class="navbar-logo">
      <h5 class="offcanvas-title mb-0 visually-hidden" id="appNavOffcanvasLabel"><?= e($appName) ?></h5>
      <span class="pill ms-1">Admin</span>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
  </div>
  <div class="offcanvas-body">
    <div class="list-group offcanvas-nav">
      <a class="list-group-item list-group-item-action" href="/dashboard">Dashboard</a>
      <a class="list-group-item list-group-item-action" href="/sales">Ventas</a>
      <a class="list-group-item list-group-item-action" href="/customers">Clientes</a>
      <a class="list-group-item list-group-item-action" href="/products">Productos</a>
      <a class="list-group-item list-group-item-action" href="/catalogo">Catálogo</a>
      <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="/pedidos"><span>Pedidos</span><?php if ($newOrdersCount > 0): ?><span class="badge rounded-pill text-bg-danger"><?= e((string)$newOrdersCount) ?></span><?php endif; ?></a>
      <a class="list-group-item list-group-item-action" href="/income">Ingresos</a>
      <a class="list-group-item list-group-item-action" href="/expense">Egresos</a>
      <a class="list-group-item list-group-item-action" href="/stock">Stock</a>
    </div>

    <div class="mt-3">
      <form method="post" action="/logout.php" class="d-flex">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button type="submit" class="btn btn-outline-danger w-100">Salir</button>
      </form>
    </div>
  </div>
</div>

<main class="container page-shell">
  <div class="row justify-content-center">
    <div class="col-12 col-lg-10 col-xl-9">
      <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 gap-3">
        <div>
          <p class="muted-label mb-1">Productos</p>
          <h1 class="h3 mb-0">Catálogo</h1>
          <div class="text-muted mt-1">Lista de precios</div>
        </div>
      </div>

      <?php if ($flash !== ''): ?>
        <div class="alert alert-success" role="alert"><?= e($flash) ?></div>
      <?php endif; ?>
      <?php if ($flashError !== ''): ?>
        <div class="alert alert-warning" role="alert"><?= e($flashError) ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
      <?php endif; ?>
      <div id="catalogClientSuccess" class="alert alert-success d-none" role="alert"></div>
      <div id="catalogClientError" class="alert alert-danger d-none" role="alert"></div>

      <div class="catalog-action-bar">
        <button class="btn btn-primary action-btn catalog-launch-btn" type="button" data-bs-toggle="modal" data-bs-target="#catalogImportModal">
          Importación masiva
        </button>
        <button class="btn btn-outline-primary action-btn catalog-launch-btn" type="button" id="catalogOpenFormBtn" data-bs-toggle="modal" data-bs-target="#catalogProductModal">
          Agregar o editar producto
        </button>
      </div>

      <div class="modal fade catalog-modal" id="catalogImportModal" tabindex="-1" aria-labelledby="catalogImportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header px-4 py-3">
              <div>
                <p class="muted-label mb-1">Carga masiva</p>
                <h2 class="h5 mb-0" id="catalogImportModalLabel">Importar productos desde Excel</h2>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body px-4 py-4 import-card">
              <form method="post" action="/catalogo" enctype="multipart/form-data" class="import-grid" id="catalogImportForm">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="import_excel">

                <div class="import-dropzone" id="catalogImportDropzone" tabindex="0" role="button" aria-label="Arrastrar y soltar archivo Excel para importar">
                  <div class="import-dropzone-head">
                    <span class="import-icon" aria-hidden="true">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 3H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V9z"/>
                        <path d="M14 3v6h6"/>
                        <path d="M9 14h6"/>
                        <path d="M9 18h6"/>
                        <path d="M10 10h1"/>
                      </svg>
                    </span>
                    <div>
                      <h3 class="import-title">Subí tu lista y cargá todo de una vez</h3>
                      <p class="import-copy">Arrancá con un archivo Excel o CSV y el sistema crea o actualiza productos automáticamente según el nombre.</p>
                    </div>
                  </div>

                  <div class="import-input-wrap">
                    <div class="import-pick-zone">
                      <label class="form-label fw-semibold" for="catalog_file">Archivo Excel</label>
                      <input class="form-control" id="catalog_file" name="catalog_file" type="file" accept=".xlsx,.xls,.csv" required>
                      <div class="import-drop-hint">
                        <strong>Arrastrá y soltá tu archivo acá</strong>
                        <span>o hacé clic para seleccionarlo</span>
                      </div>
                      <div class="import-file-state" id="catalogImportFileState">Todavía no hay ningún archivo seleccionado</div>
                    </div>
                    <div class="import-chips" aria-label="Columnas esperadas">
                      <span class="import-chip">Producto</span>
                      <span class="import-chip">Precio</span>
                      <span class="import-chip">Unidad</span>
                      <span class="import-chip">Descripción</span>
                      <span class="import-chip">Moneda</span>
                      <span class="import-chip">Imagen</span>
                    </div>
                    <div class="form-text">La primera fila debe traer esos títulos. Imagen es opcional y puede ser una URL pública, un nombre de archivo ya subido o una imagen incrustada en la fila del Excel.</div>
                  </div>
                </div>

                <div class="import-side">
                  <div>
                    <p class="import-side-label">Importación inteligente</p>
                    <h3 class="import-side-title">Crea nuevos y actualiza los existentes</h3>
                    <p class="import-side-copy">Si el nombre del producto ya existe en tu catálogo, se actualizan precio, moneda, unidad y descripción con los datos del archivo.</p>
                  </div>

                  <button type="submit" class="btn btn-primary action-btn w-100">Subir e importar</button>

                  <ul class="import-list" aria-label="Reglas de importación">
                    <li><span class="import-list-dot" aria-hidden="true"></span>Compatible con XLSX, XLS y CSV</li>
                    <li><span class="import-list-dot" aria-hidden="true"></span>Omite filas totalmente vacías</li>
                    <li><span class="import-list-dot" aria-hidden="true"></span>Muestra aviso si alguna fila falla</li>
                  </ul>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <div class="modal fade catalog-modal" id="catalogProductModal" tabindex="-1" aria-labelledby="catalogFormModeTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header px-4 py-3">
              <div>
                <p class="muted-label mb-1" id="catalogFormModeLabel"><?= $edit ? 'Editar' : 'Nuevo' ?></p>
                <h2 class="h5 mb-0" id="catalogFormModeTitle"><?= $edit ? 'Modificar producto' : 'Agregar producto' ?></h2>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body px-4 py-4">
              <form method="post" action="/catalogo" class="row g-3" id="catalogForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="q" id="catalogFormQ" value="<?= e($q) ?>">
                <input type="hidden" name="action" id="catalogAction" value="<?= $edit ? 'update' : 'create' ?>">
                <input type="hidden" name="id" id="catalogId" value="<?= $edit ? e((string)$edit['id']) : '' ?>">

                <div class="col-12 col-md-6">
                  <label class="form-label" for="name">Producto</label>
                  <div class="input-group">
                    <input class="form-control" id="name" name="name" value="<?= e($defaultName) ?>" required>
                    <button class="btn btn-outline-secondary" type="button" id="catalogVoiceBtn">Voz</button>
                  </div>
                  <input class="d-none" type="file" id="catalogVoiceFile" accept="audio/*" capture>
                  <div class="form-text">Podés decir: “arroz integral, precio 1500 pesos”. Si tu navegador no soporta voz, usá el micrófono del teclado (dictado) con el cursor en el campo.</div>
                </div>
                <div class="col-12 col-md-3">
                  <label class="form-label" for="price">Precio</label>
                  <input class="form-control" id="price" name="price" inputmode="decimal" placeholder="0.00" value="<?= e($defaultPrice) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                  <label class="form-label" for="unit">Unidad</label>
                  <select class="form-select" id="unit" name="unit">
                    <?php foreach ([
                      'kg' => 'Por kilo (kg)',
                      'l' => 'Por litro (l)',
                      'un' => 'Por unidad (un)',
                    ] as $k => $label): ?>
                      <option value="<?= e($k) ?>" <?= (string)$defaultUnit === (string)$k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-text">Aclaración: el precio es por <strong>1 kg</strong>, <strong>1 litro</strong> o <strong>1 unidad</strong>.</div>
                </div>
                <div class="col-12 col-md-3">
                  <label class="form-label" for="currency">Moneda</label>
                  <select class="form-select" id="currency" name="currency">
                    <?php foreach (['ARS', 'USD', 'EUR'] as $cur): ?>
                      <option value="<?= e($cur) ?>" <?= strtoupper($defaultCurrency) === $cur ? 'selected' : '' ?>><?= e($cur) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-12 col-md-6">
                  <label class="form-label" for="image">Imagen del producto <span class="text-muted">(opcional)</span></label>
                  <div class="image-upload mt-2">
                    <div class="image-preview" id="imagePreviewWrap">
                      <img id="imagePreview" src="<?= e($defaultImageUrl) ?>" data-current-url="<?= e($defaultImageUrl) ?>" alt="Imagen" style="<?= $defaultImageUrl !== '' ? '' : 'display:none;' ?>">
                      <span id="imagePlaceholder" class="image-placeholder" style="<?= $defaultImageUrl !== '' ? 'display:none;' : '' ?>">SIN FOTO</span>
                    </div>
                    <div>
                      <input class="form-control" id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp">
                      <div class="form-text">Formatos: JPG, PNG o WebP. Máx. 4 MB.</div>
                      <div class="image-actions mt-2">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" id="imageRemove" name="image_remove" value="1">
                          <label class="form-check-label" for="imageRemove">Quitar imagen</label>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="col-12">
                  <label class="form-label" for="description">Descripción <span class="text-muted">(opcional)</span></label>
                  <textarea class="form-control" id="description" name="description" rows="2" maxlength="255" placeholder="Ej: integral, sin TACC, sabor vainilla…"><?= e($defaultDescription) ?></textarea>
                </div>

                <div class="col-12 d-flex flex-wrap gap-2 justify-content-end">
                  <a class="btn btn-outline-secondary action-btn <?= $edit ? '' : 'd-none' ?>" id="catalogCancel" href="/catalogo<?= $q !== '' ? ('?q=' . rawurlencode($q)) : '' ?>">Cancelar</a>
                  <button type="submit" class="btn btn-primary action-btn"><?= $edit ? 'Guardar cambios' : 'Agregar' ?></button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <div class="card card-lift">
        <div class="card-header card-header-clean bg-white px-4 py-3">
          <p class="muted-label mb-1">Lista</p>
          <h2 class="h5 mb-0">Productos y precios</h2>
        </div>
        <div class="card-body px-4 py-4">
          <form method="get" action="/catalogo" class="d-flex flex-column flex-md-row gap-2 align-items-md-center justify-content-between mb-3" id="catalogSearchForm">
            <div class="d-flex gap-2 flex-grow-1">
              <input class="form-control" id="catalogSearch" name="q" value="<?= e($q) ?>" placeholder="Buscar por producto" aria-label="Buscar" autocomplete="off">
            </div>
            <div class="d-flex flex-wrap gap-2">
              <a class="btn btn-outline-secondary btn-sm" href="/catalogo<?= $q !== '' ? ('?q=' . rawurlencode($q) . '&format=csv') : '?format=csv' ?>">CSV</a>
              <a class="btn btn-outline-secondary btn-sm" href="/catalogo<?= $q !== '' ? ('?q=' . rawurlencode($q) . '&format=xlsx') : '?format=xlsx' ?>">Excel</a>
              <button class="btn btn-outline-secondary btn-sm" type="submit">Buscar</button>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                    <th style="width:80px">PLU</th>
                  <th>Producto</th>
                  <th style="width:90px">Imagen</th>
                  <th>Descripción</th>
                  <th style="width:180px" class="text-end">Precio</th>
                  <th style="width:220px"></th>
                </tr>
              </thead>
              <tbody id="catalogTbody">
              <?php if (count($rows) === 0): ?>
                <tr><td colspan="6" class="text-muted">Sin resultados.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <?php $unit = trim((string)($r['unit'] ?? '')); ?>
                  <?php $imagePath = trim((string)($r['image_path'] ?? '')); ?>
                  <?php $imageUrl = $imagePath !== '' ? catalog_image_url($imagePath) : ''; ?>
                  <tr>
                    <td><?= e((string)$r['id']) ?></td>
                    <td><?= e(catalog_capitalize_first((string)$r['name'])) ?></td>
                    <td>
                      <?php if ($imageUrl !== ''): ?>
                        <span class="catalog-thumb-fallback" style="display:none;">IMG</span>
                        <img class="catalog-thumb" src="<?= e($imageUrl) ?>" alt="" onerror="this.style.display='none'; this.previousElementSibling.style.display='inline-flex';">
                      <?php else: ?>
                        <span class="catalog-thumb-fallback">IMG</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= e(trim((string)($r['description'] ?? '')) !== '' ? (string)$r['description'] : '—') ?></td>
                    <td class="text-end"><?= e(money_format_cents((int)$r['price_cents'], (string)$r['currency']) . ($unit !== '' ? (' / ' . $unit) : '')) ?></td>
                    <td class="text-end">
                      <div class="d-inline-flex gap-2">
                        <a
                          class="btn btn-outline-primary btn-sm js-edit"
                          href="/catalogo?edit=<?= e((string)$r['id']) ?><?= $q !== '' ? ('&q=' . rawurlencode($q)) : '' ?>"
                          data-id="<?= e((string)$r['id']) ?>"
                          data-name="<?= e((string)$r['name']) ?>"
                          data-description="<?= e((string)($r['description'] ?? '')) ?>"
                          data-image-url="<?= e($imageUrl) ?>"
                          data-unit="<?= e((string)($r['unit'] ?? '')) ?>"
                          data-price="<?= e(number_format(((int)$r['price_cents']) / 100, 2, '.', '')) ?>"
                          data-currency="<?= e((string)$r['currency']) ?>"
                        >Editar</a>
                        <button type="button" class="btn btn-outline-danger btn-sm js-delete" data-id="<?= e((string)$r['id']) ?>">Eliminar</button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</main>

<?php
$initialItems = [];
foreach ($rows as $r) {
  $unit = trim((string)($r['unit'] ?? ''));
  $imagePath = trim((string)($r['image_path'] ?? ''));
  $imageUrl = $imagePath !== '' ? catalog_image_url($imagePath) : '';
  $priceFormatted = money_format_cents((int)($r['price_cents'] ?? 0), (string)($r['currency'] ?? 'ARS'));
  $initialItems[] = [
    'id' => (int)($r['id'] ?? 0),
    'name' => (string)($r['name'] ?? ''),
    'description' => (string)($r['description'] ?? ''),
    'image_path' => $imagePath,
    'image_url' => $imageUrl,
    'unit' => $unit,
    'price_cents' => (int)($r['price_cents'] ?? 0),
    'currency' => (string)($r['currency'] ?? 'ARS'),
    'price_formatted' => $priceFormatted,
    'price_label' => $priceFormatted . ($unit !== '' ? (' / ' . $unit) : ''),
  ];
}
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

<script>
(() => {
  const buildAjaxEndpoint = () => {
    const action = (document.getElementById('catalogImportForm')?.getAttribute('action'))
      || (document.getElementById('catalogForm')?.getAttribute('action'))
      || window.location.href;
    const url = new URL(action, window.location.href);
    url.searchParams.set('ajax', '1');
    url.searchParams.delete('format');
    return url.toString();
  };

  const endpoint = buildAjaxEndpoint();
  const initialItems = <?= json_encode($initialItems, JSON_UNESCAPED_UNICODE) ?>;
  const initialImportFlash = <?= json_encode(stripos($flash, 'Importación completada') !== false ? $flash : '', JSON_UNESCAPED_UNICODE) ?>;
  const initialEditMode = <?= json_encode($edit !== null) ?>;
  let localItems = Array.isArray(initialItems) ? initialItems : [];
  let ajaxAvailable = true;

  const setLocalItems = (items) => {
    localItems = Array.isArray(items) ? items : [];
  };

  const clientSuccess = document.getElementById('catalogClientSuccess');
  const clientError = document.getElementById('catalogClientError');
  const tbody = document.getElementById('catalogTbody');
  const searchForm = document.getElementById('catalogSearchForm');
  const searchInput = document.getElementById('catalogSearch');
  const importForm = document.getElementById('catalogImportForm');
  const importDropzone = document.getElementById('catalogImportDropzone');
  const importFileInput = document.getElementById('catalog_file');
  const importFileState = document.getElementById('catalogImportFileState');
  const importSubmitBtn = importForm ? importForm.querySelector('button[type="submit"]') : null;
  const importModalEl = document.getElementById('catalogImportModal');
  const importToastEl = document.getElementById('catalogImportToast');
  const importToastBody = document.getElementById('catalogImportToastBody');
  const importSuccessModalEl = document.getElementById('catalogImportSuccessModal');
  const importSuccessModalBody = document.getElementById('catalogImportSuccessModalBody');
  const productModalEl = document.getElementById('catalogProductModal');
  const productModalTrigger = document.getElementById('catalogOpenFormBtn');
  const form = document.getElementById('catalogForm');
  const formQ = document.getElementById('catalogFormQ');
  const actionInput = document.getElementById('catalogAction');
  const idInput = document.getElementById('catalogId');
  const nameInput = document.getElementById('name');
  const descriptionInput = document.getElementById('description');
  const priceInput = document.getElementById('price');
  const unitInput = document.getElementById('unit');
  const currencyInput = document.getElementById('currency');
  const imageInput = document.getElementById('image');
  const imagePreviewWrap = document.getElementById('imagePreviewWrap');
  const imagePreview = document.getElementById('imagePreview');
  const imagePlaceholder = document.getElementById('imagePlaceholder');
  const imageRemove = document.getElementById('imageRemove');
  const cancelLink = document.getElementById('catalogCancel');
  const modeLabel = document.getElementById('catalogFormModeLabel');
  const modeTitle = document.getElementById('catalogFormModeTitle');
  const voiceBtn = document.getElementById('catalogVoiceBtn');
  const voiceFile = document.getElementById('catalogVoiceFile');

  const defaultVoiceLabel = voiceBtn ? (voiceBtn.textContent || 'Voz') : 'Voz';
  const defaultImportLabel = importSubmitBtn ? (importSubmitBtn.textContent || 'Subir e importar') : 'Subir e importar';

  const hideMsg = (el) => { if (!el) return; el.classList.add('d-none'); el.textContent = ''; };
  const showMsg = (el, msg) => { if (!el) return; el.textContent = msg; el.classList.remove('d-none'); };
  const clearMsgs = () => { hideMsg(clientSuccess); hideMsg(clientError); };
  let importToast = null;
  let importSuccessModal = null;
  let importModal = null;
  let productModal = null;
  let importSuccessModalTimer = null;
  let pendingImportSuccessMessage = '';

  const isNetworkFetchError = (error) => {
    const message = error && error.message ? String(error.message) : String(error || '');
    return error instanceof TypeError || /failed to fetch|networkerror|load failed/i.test(message);
  };

  const showImportToast = (message) => {
    if (!importToastEl || !importToastBody || !window.bootstrap || !window.bootstrap.Toast) {
      return;
    }
    importToastBody.textContent = String(message || 'Los productos se importaron correctamente.');
    if (!importToast) {
      importToast = new window.bootstrap.Toast(importToastEl);
    }
    importToast.show();
  };

  const showImportSuccessModal = (message) => {
    if (!importSuccessModalEl || !importSuccessModalBody || !window.bootstrap || !window.bootstrap.Modal) {
      return;
    }
    importSuccessModalBody.textContent = String(message || 'Los productos se importaron correctamente.');
    if (!importSuccessModal) {
      importSuccessModal = new window.bootstrap.Modal(importSuccessModalEl);
    }
    if (importSuccessModalTimer) {
      window.clearTimeout(importSuccessModalTimer);
      importSuccessModalTimer = null;
    }
    importSuccessModal.show();
    importSuccessModalTimer = window.setTimeout(() => {
      try {
        importSuccessModal.hide();
      } catch (_) {}
      importSuccessModalTimer = null;
    }, 2000);
  };

  const getImportModal = () => {
    if (!importModalEl || !window.bootstrap || !window.bootstrap.Modal) {
      return null;
    }
    if (!importModal) {
      importModal = new window.bootstrap.Modal(importModalEl);
    }
    return importModal;
  };

  const getProductModal = () => {
    if (!productModalEl || !window.bootstrap || !window.bootstrap.Modal) {
      return null;
    }
    if (!productModal) {
      productModal = new window.bootstrap.Modal(productModalEl);
    }
    return productModal;
  };

  const showProductModal = () => {
    const modal = getProductModal();
    if (modal) {
      modal.show();
    }
  };

  const hideProductModal = () => {
    const modal = getProductModal();
    if (modal) {
      modal.hide();
    }
  };

  const hideImportModal = () => {
    const modal = getImportModal();
    if (modal) {
      modal.hide();
    }
  };

  const showImportSuccessAfterImportModal = (message) => {
    const text = String(message || 'Importación completada.');
    const importModalInstance = getImportModal();
    if (!importModalEl || !importModalInstance || !importModalEl.classList.contains('show')) {
      showImportSuccessModal(text);
      return;
    }

    pendingImportSuccessMessage = text;
    hideImportModal();
  };

  const setImportFileState = (file) => {
    if (!importFileState) return;
    if (!file) {
      importFileState.textContent = 'Todavía no hay ningún archivo seleccionado';
      importFileState.classList.remove('is-ready');
      return;
    }
    const name = String(file.name || 'archivo');
    importFileState.textContent = 'Listo para importar: ' + name;
    importFileState.classList.add('is-ready');
  };

  const setImportBusy = (busy) => {
    if (importSubmitBtn) {
      importSubmitBtn.disabled = !!busy;
      importSubmitBtn.textContent = busy ? 'Importando...' : defaultImportLabel;
    }
    if (importFileInput) {
      importFileInput.disabled = !!busy;
    }
    if (importDropzone) {
      importDropzone.setAttribute('aria-busy', busy ? 'true' : 'false');
      importDropzone.classList.toggle('is-dragover', false);
    }
  };

  const importSelectedFile = async (file) => {
    if (!importForm) return;
    if (!file) {
      throw new Error('Seleccioná un archivo Excel antes de importar.');
    }

    clearMsgs();
    setImportBusy(true);

    try {
      const fd = new FormData(importForm);
      fd.set('csrf_token', csrfToken());
      fd.set('ajax', '1');
      fd.delete('catalog_file');
      fd.append('catalog_file', file, file.name || 'importacion.xlsx');

      const resp = await postFormAction(fd);
      if (importForm) importForm.reset();
      setImportFileState(null);
      showMsg(clientSuccess, resp.message || 'Importación completada.');
      showImportSuccessAfterImportModal(resp.message || 'Importación completada.');

      const rowErrors = resp && resp.result && Array.isArray(resp.result.errors)
        ? resp.result.errors
        : [];
      if (rowErrors.length > 0) {
        showMsg(clientError, rowErrors.slice(0, 6).join(' | '));
      }

      await refresh();
    } catch (error) {
      if (isNetworkFetchError(error) && importFileInput && importFileInput.files && importFileInput.files.length > 0) {
        showMsg(clientError, 'No se pudo conectar para importar en segundo plano. Se enviará el formulario normal.');
        importForm.submit();
        return;
      }
      throw error;
    } finally {
      setImportBusy(false);
    }
  };

  let imageObjectUrl = '';
  const clearImageObjectUrl = () => {
    if (imageObjectUrl) {
      try { URL.revokeObjectURL(imageObjectUrl); } catch (_) {}
    }
    imageObjectUrl = '';
  };

  const setImagePreview = (url) => {
    if (!imagePreviewWrap || !imagePreview) return;
    const cleanUrl = String(url || '').trim();
    imagePreview.dataset.currentUrl = cleanUrl;
    if (!cleanUrl) {
      imagePreview.removeAttribute('src');
      imagePreview.style.display = 'none';
      if (imagePlaceholder) imagePlaceholder.style.display = '';
      return;
    }
    imagePreview.src = cleanUrl;
    imagePreview.style.display = '';
    if (imagePlaceholder) imagePlaceholder.style.display = 'none';
  };

  const setCreateMode = () => {
    actionInput.value = 'create';
    idInput.value = '';
    modeLabel.textContent = 'Nuevo';
    modeTitle.textContent = 'Agregar producto';
    cancelLink.classList.add('d-none');
    nameInput.value = '';
    if (descriptionInput) descriptionInput.value = '';
    priceInput.value = '';
    if (unitInput) unitInput.value = '';
    currencyInput.value = 'ARS';
    if (imageInput) imageInput.value = '';
    if (imageRemove) imageRemove.checked = false;
    clearImageObjectUrl();
    setImagePreview('');
  };

  const setEditMode = (item) => {
    actionInput.value = 'update';
    idInput.value = String(item.id);
    modeLabel.textContent = 'Editar';
    modeTitle.textContent = 'Modificar producto';
    cancelLink.classList.remove('d-none');
    nameInput.value = item.name || '';
    if (descriptionInput) descriptionInput.value = item.description || '';
    priceInput.value = item.price || '';
    if (unitInput) unitInput.value = item.unit || '';
    currencyInput.value = item.currency || 'ARS';
    if (imageInput) imageInput.value = '';
    if (imageRemove) imageRemove.checked = false;
    clearImageObjectUrl();
    setImagePreview(item.image_url || '');
    showProductModal();
    nameInput.focus();
  };

  const csrfToken = () => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  };

  const renderRows = (items) => {
    tbody.innerHTML = '';
    if (!items || items.length === 0) {
      const tr = document.createElement('tr');
      tr.innerHTML = '<td colspan="6" class="text-muted">Sin resultados.</td>';
      tbody.appendChild(tr);
      return;
    }

    for (const it of items) {
      const tr = document.createElement('tr');
      const priceRaw = (typeof it.price_cents === 'number')
        ? (it.price_cents / 100).toFixed(2)
        : '';

      const descValue = String(it.description || '').trim();
      const descHtml = descValue ? escapeHtml(descValue) : '<span class="text-muted">—</span>';
      const unit = String(it.unit || '').trim();
      const priceLabel = it.price_label || ((it.price_formatted || '') + (unit ? (' / ' + unit) : ''));
      const imageUrl = String(it.image_url || '').trim();
      const imageHtml = imageUrl
        ? `<span class="catalog-thumb-fallback" style="display:none;">IMG</span><img class="catalog-thumb" src="${escapeAttr(imageUrl)}" alt="" onerror="this.style.display='none'; this.previousElementSibling.style.display='inline-flex';">`
        : '<span class="catalog-thumb-fallback">IMG</span>';
      const displayName = capitalizeFirst(it.name || '');
      tr.innerHTML = `
        <td>${escapeHtml(String(it.id ?? ''))}</td>
        <td>${escapeHtml(displayName)}</td>
        <td>${imageHtml}</td>
        <td class="text-muted">${descHtml}</td>
        <td class="text-end">${escapeHtml(priceLabel || '')}</td>
        <td class="text-end">
          <div class="d-inline-flex gap-2">
            <a
              class="btn btn-outline-primary btn-sm js-edit"
              href="/catalogo?edit=${encodeURIComponent(String(it.id))}${searchInput.value ? ('&q=' + encodeURIComponent(searchInput.value)) : ''}"
              data-id="${escapeAttr(String(it.id))}"
              data-name="${escapeAttr(it.name || '')}"
              data-description="${escapeAttr(it.description || '')}"
              data-image-url="${escapeAttr(imageUrl)}"
              data-unit="${escapeAttr(unit)}"
              data-price="${escapeAttr(priceRaw)}"
              data-currency="${escapeAttr(it.currency || 'ARS')}"
            >Editar</a>
            <button type="button" class="btn btn-outline-danger btn-sm js-delete" data-id="${escapeAttr(String(it.id))}">Eliminar</button>
          </div>
        </td>
      `.trim();
      tbody.appendChild(tr);
    }
  };

  const fetchList = async (q) => {
    if (!ajaxAvailable) return localItems;

    const url = new URL(endpoint, window.location.href);
    if (q) {
      url.searchParams.set('q', q);
    }
    let res;
    try {
      res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
    } catch (_) {
      ajaxAvailable = false;
      return localItems;
    }

    if (!res || !res.ok) {
      ajaxAvailable = false;
      return localItems;
    }

    let data = null;
    try {
      data = await res.json();
    } catch (_) {
      ajaxAvailable = false;
      return localItems;
    }

    if (!data || data.ok !== true) {
      ajaxAvailable = false;
      return localItems;
    }
    return data.items || [];
  };

  const refresh = async () => {
    clearMsgs();
    formQ.value = searchInput.value || '';
    const query = String(searchInput.value || '').trim();
    const items = await fetchList(query);
    if (query === '') {
      setLocalItems(items);
      renderRows(items);
      return;
    }
    renderRows(filterItems(items, query));
  };

  const postAction = async (payload) => {
    const res = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!data || data.ok !== true) {
      throw new Error((data && data.error) ? data.error : 'No se pudo procesar el catálogo.');
    }
    return data;
  };

  const postActionForm = async (formData) => {
    const res = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: formData,
    });
    const data = await res.json();
    if (!data || data.ok !== true) {
      throw new Error((data && data.error) ? data.error : 'No se pudo procesar el catálogo.');
    }
    return data;
  };

  const postFormAction = async (formData) => {
    const res = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: formData,
    });
    const data = await res.json();
    if (!data || data.ok !== true) {
      throw new Error((data && data.error) ? data.error : 'No se pudo procesar el catálogo.');
    }
    return data;
  };

  const escapeHtml = (s) => String(s)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
  const escapeAttr = escapeHtml;

  const normalizeText = (value) => {
    const s = String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase();
    return s.trim();
  };

  const filterItems = (items, query) => {
    const nq = normalizeText(query);
    if (!nq) return Array.isArray(items) ? items : [];
    return (items || []).filter((it) => {
      const name = normalizeText(it.name || '');
      const desc = normalizeText(it.description || '');
      return name.includes(nq) || desc.includes(nq);
    });
  };

  const capitalizeFirst = (value) => {
    const s = String(value || '').trim();
    if (!s) return '';
    return s.charAt(0).toUpperCase() + s.slice(1);
  };

  const applyTranscriptToForm = (raw) => {
    const text = String(raw || '').trim();
    if (!text) return;

    const lower = text.toLowerCase();

    const parseNumberToken = (token) => {
      let s = String(token || '').trim().replace(/\s+/g, '');
      if (!s) return null;

      const hasDot = s.includes('.');
      const hasComma = s.includes(',');

      if (hasDot && hasComma) {
        // Asumimos que el último separador es decimal y el otro miles.
        const lastDot = s.lastIndexOf('.');
        const lastComma = s.lastIndexOf(',');
        if (lastComma > lastDot) {
          // 13.000,50
          s = s.replaceAll('.', '').replace(',', '.');
        } else {
          // 13,000.50
          s = s.replaceAll(',', '');
        }
      } else if (hasComma && !hasDot) {
        const lastComma = s.lastIndexOf(',');
        const decimals = s.length - lastComma - 1;
        if (decimals > 0 && decimals <= 2) {
          s = s.replace(',', '.');
        } else {
          s = s.replaceAll(',', '');
        }
      } else if (hasDot && !hasComma) {
        const lastDot = s.lastIndexOf('.');
        const decimals = s.length - lastDot - 1;
        if (decimals > 0 && decimals <= 2) {
          // ok, decimal con punto
        } else {
          s = s.replaceAll('.', '');
        }
      }

      const n = Number.parseFloat(s);
      return Number.isFinite(n) ? n : null;
    };

    const parseSpokenPrice = (s) => {
      const numRe = '([0-9]{1,3}(?:[\.,\s][0-9]{3})+(?:[\.,][0-9]{1,2})?|[0-9]+(?:[\.,][0-9]{1,2})?)';

      let m = s.match(new RegExp('precios?\\s*[:\\-]?\\s*\\$?\\s*' + numRe + '(?:\\s*(mil|miles|k))?\\b', 'i'));
      if (!m) {
        m = s.match(new RegExp('\\b' + numRe + '(?:\\s*(mil|miles|k))?\\b', 'i'));
      }
      if (!m || !m[1]) return null;

      const base = parseNumberToken(m[1]);
      if (base === null) return null;

      const mult = (m[2] && String(m[2]).trim() !== '') ? 1000 : 1;
      return base * mult;
    };

    // Moneda
    let currency = 'ARS';
    if (/(\beuro\b|\beur\b)/i.test(lower)) currency = 'EUR';
    if (/(\bd[oó]lar\b|\busd\b)/i.test(lower)) currency = 'USD';
    if (/(\bars\b|\bpeso\b|\bpesos\b)/i.test(lower)) currency = 'ARS';

    // Precio
    let price = '';
    const parsedPrice = parseSpokenPrice(lower);
    if (parsedPrice !== null) {
      price = Number.isInteger(parsedPrice)
        ? String(parsedPrice)
        : String(parsedPrice.toFixed(2));
      price = price.replace(/\.00$/, '');
    }

    // Nombre
    let name = text
      .replace(/\bprecios?\b\s*[:\-]?\s*\$?\s*[0-9]+(?:[\.,][0-9]{1,2})?\s*(mil|miles|k)\b/ig, '')
      .replace(/\bprecios?\b\s*[:\-]?\s*\$?\s*[0-9]{1,3}(?:[\.,\s][0-9]{3})+(?:[\.,][0-9]{1,2})?/ig, '')
      .replace(/\bprecios?\b\s*[:\-]?\s*\$?\s*[0-9]+(?:[\.,][0-9]{1,2})?/ig, '')
      .replace(/\b(d[oó]lar|usd|euro|eur|ars|peso|pesos)\b/ig, '')
      .replace(/[\$€]/g, '')
      .replace(/[,]+/g, ' ')
      .replace(/\s{2,}/g, ' ')
      .trim();

    if (name) nameInput.value = name;
    if (price) priceInput.value = price;
    if (currency) currencyInput.value = currency;

    showMsg(clientSuccess, 'Voz detectada: ' + text);
    if (!name) nameInput.focus();
    else if (!price) priceInput.focus();
  };

  const transcribeAudioFile = async (file) => {
    const fd = new FormData();
    fd.append('csrf_token', csrfToken());
    fd.append('audio', file);

    const res = await fetch('/api_speech_to_text.php', {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: fd,
    });

    const data = await res.json().catch(() => null);
    if (!data || data.ok !== true) {
      throw new Error((data && data.error) ? data.error : 'No se pudo transcribir el audio.');
    }
    return String(data.text || '').trim();
  };

  let recorder = null;
  let recChunks = [];

  const SpeechRecognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition;
  let recognizer = null;
  let isRecognizing = false;

  const supportsSpeechRecognition = () => {
    return !!SpeechRecognitionCtor;
  };

  const startOrStopDictation = async () => {
    clearMsgs();

    if (!supportsSpeechRecognition()) {
      throw new Error('Este navegador no soporta dictado directo.');
    }

    const setUi = (busy, listening) => {
      if (!voiceBtn) return;
      voiceBtn.disabled = !!busy;
      voiceBtn.textContent = listening ? 'Detener' : defaultVoiceLabel;
    };

    if (!recognizer) {
      recognizer = new SpeechRecognitionCtor();
      recognizer.lang = 'es-AR';
      recognizer.continuous = false;
      recognizer.interimResults = false;
      try { recognizer.maxAlternatives = 1; } catch (_) {}

      recognizer.onstart = () => {
        isRecognizing = true;
        setUi(false, true);
        showMsg(clientSuccess, 'Escuchando… hablá ahora y esperá la transcripción.');
      };

      recognizer.onerror = (ev) => {
        isRecognizing = false;
        setUi(false, false);
        const code = ev && ev.error ? String(ev.error) : '';
        const msg = code === 'not-allowed'
          ? 'Permiso de micrófono denegado. Habilitalo e intentá de nuevo.'
          : 'No se pudo usar el dictado. Probá con grabación o el micrófono del teclado.';
        showMsg(clientError, msg);
      };

      recognizer.onresult = (ev) => {
        const t = ev && ev.results && ev.results[0] && ev.results[0][0] && ev.results[0][0].transcript
          ? String(ev.results[0][0].transcript)
          : '';
        if (t.trim() !== '') {
          applyTranscriptToForm(t);
        }
      };

      recognizer.onend = () => {
        isRecognizing = false;
        setUi(false, false);
      };
    }

    if (isRecognizing) {
      try { recognizer.stop(); } catch (_) {}
      return;
    }

    setUi(true, false);
    try {
      recognizer.start();
    } catch (err) {
      isRecognizing = false;
      setUi(false, false);
      throw err;
    }
  };

  const supportsMediaRecorder = () => {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
  };

  const startOrStopRecording = async () => {
    clearMsgs();

    // Si el navegador no soporta grabación directa (muy común en iOS), abrimos selector de audio con capture.
    if (!supportsMediaRecorder()) {
      if (voiceFile) {
        voiceFile.value = '';
        voiceFile.click();
        return;
      }
      nameInput.focus();
      showMsg(clientSuccess, 'Usá el micrófono del teclado para dictar en “Producto”.');
      return;
    }

    if (recorder && recorder.state === 'recording') {
      recorder.stop();
      return;
    }

    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    recChunks = [];
    recorder = new MediaRecorder(stream);
    const prevLabel = voiceBtn ? voiceBtn.textContent : '';

    const setBusy = (busy, isRecording) => {
      if (!voiceBtn) return;
      voiceBtn.disabled = !!busy;
      voiceBtn.textContent = isRecording ? 'Detener' : (prevLabel || 'Voz');
    };

    recorder.ondataavailable = (e) => {
      if (e.data && e.data.size > 0) recChunks.push(e.data);
    };
    recorder.onerror = () => {
      setBusy(false, false);
      try { stream.getTracks().forEach(t => t.stop()); } catch (_) {}
      showMsg(clientError, 'No se pudo grabar. Permití el micrófono e intentá de nuevo.');
    };
    recorder.onstart = () => {
      setBusy(false, true);
      showMsg(clientSuccess, 'Grabando… tocá “Detener” para transcribir.');
    };
    recorder.onstop = async () => {
      setBusy(true, false);
      try { stream.getTracks().forEach(t => t.stop()); } catch (_) {}

      try {
        const blob = new Blob(recChunks, { type: recorder.mimeType || 'audio/webm' });
        const file = new File([blob], 'voz.webm', { type: blob.type || 'audio/webm' });
        const text = await transcribeAudioFile(file);
        applyTranscriptToForm(text);
      } catch (err) {
        showMsg(clientError, err && err.message ? err.message : String(err));
      } finally {
        setBusy(false, false);
      }
    };

    recorder.start();
  };

  // Búsqueda dinámica
  if (searchForm) {
    searchForm.addEventListener('submit', (e) => {
      e.preventDefault();
      clearMsgs();
      refresh().catch((err) => {
        showMsg(clientError, err.message || String(err));
        try { searchForm.submit(); } catch (_) {}
      });
    });
  }

  if (importFileInput) {
    importFileInput.addEventListener('change', () => {
      const file = importFileInput.files && importFileInput.files[0] ? importFileInput.files[0] : null;
      setImportFileState(file);
    });
  }

  if (importForm) {
    importForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const file = importFileInput && importFileInput.files && importFileInput.files[0]
        ? importFileInput.files[0]
        : null;
      importSelectedFile(file)
        .catch((err) => showMsg(clientError, err && err.message ? err.message : String(err)));
    });
  }

  if (productModalTrigger) {
    productModalTrigger.addEventListener('click', () => {
      clearMsgs();
      setCreateMode();
    });
  }

  if (importModalEl) {
    importModalEl.addEventListener('hidden.bs.modal', () => {
      if (!pendingImportSuccessMessage) {
        return;
      }
      const message = pendingImportSuccessMessage;
      pendingImportSuccessMessage = '';
      window.setTimeout(() => {
        showImportSuccessModal(message);
      }, 120);
    });
  }

  if (importDropzone) {
    const stop = (e) => {
      e.preventDefault();
      e.stopPropagation();
    };

    ['dragenter', 'dragover'].forEach((eventName) => {
      importDropzone.addEventListener(eventName, (e) => {
        stop(e);
        importDropzone.classList.add('is-dragover');
      });
    });

    ['dragleave', 'dragend'].forEach((eventName) => {
      importDropzone.addEventListener(eventName, (e) => {
        stop(e);
        const related = e.relatedTarget;
        if (!related || !importDropzone.contains(related)) {
          importDropzone.classList.remove('is-dragover');
        }
      });
    });

    importDropzone.addEventListener('drop', (e) => {
      stop(e);
      importDropzone.classList.remove('is-dragover');
      const files = e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files : null;
      const file = files && files[0] ? files[0] : null;
      if (!file) return;

      try {
        if (importFileInput && typeof DataTransfer !== 'undefined') {
          const dt = new DataTransfer();
          dt.items.add(file);
          importFileInput.files = dt.files;
        }
      } catch (_) {}

      setImportFileState(file);
      importSelectedFile(file)
        .catch((err) => showMsg(clientError, err && err.message ? err.message : String(err)));
    });

    importDropzone.addEventListener('click', (e) => {
      if (!importFileInput) return;
      if (e.target === importFileInput) return;
      importFileInput.click();
    });

    importDropzone.addEventListener('keydown', (e) => {
      if (!importFileInput) return;
      if (e.key !== 'Enter' && e.key !== ' ') return;
      e.preventDefault();
      importFileInput.click();
    });
  }

  let t = null;
  if (searchInput) {
    const triggerSearch = () => {
      clearTimeout(t);
      t = setTimeout(() => {
        refresh().catch((err) => showMsg(clientError, err.message || String(err)));
      }, 150);
    };

    searchInput.addEventListener('input', triggerSearch);
    searchInput.addEventListener('keyup', triggerSearch);
    searchInput.addEventListener('change', triggerSearch);
    searchInput.addEventListener('search', triggerSearch);
  }

  // Crear / actualizar sin recargar
  if (form) {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      clearMsgs();

      formQ.value = searchInput.value || '';
      const fd = new FormData(form);
      fd.set('ajax', '1');
      fd.set('csrf_token', csrfToken());

      postFormAction(fd)
        .then((resp) => {
          showMsg(clientSuccess, resp.message || 'OK');
          setCreateMode();
          hideProductModal();
          return refresh();
        })
        .catch((err) => showMsg(clientError, err.message || String(err)));
    });
  }

  if (imageInput) {
    imageInput.addEventListener('change', () => {
      const file = imageInput.files && imageInput.files[0] ? imageInput.files[0] : null;
      clearImageObjectUrl();
      if (!file) {
        if (imageRemove && imageRemove.checked) {
          setImagePreview('');
        } else {
          const current = imagePreview ? (imagePreview.dataset.currentUrl || '') : '';
          setImagePreview(current);
        }
        return;
      }

      imageObjectUrl = URL.createObjectURL(file);
      if (imageRemove) imageRemove.checked = false;
      setImagePreview(imageObjectUrl);
    });
  }

  if (imageRemove) {
    imageRemove.addEventListener('change', () => {
      if (imageRemove.checked) {
        setImagePreview('');
        return;
      }
      const current = imagePreview ? (imagePreview.dataset.currentUrl || '') : '';
      setImagePreview(current);
    });
  }

  // Editar / eliminar desde la tabla
  document.addEventListener('click', (e) => {
    const edit = e.target && e.target.closest ? e.target.closest('.js-edit') : null;
    if (edit) {
      e.preventDefault();
      clearMsgs();
      setEditMode({
        id: edit.getAttribute('data-id') || '',
        name: edit.getAttribute('data-name') || '',
        description: edit.getAttribute('data-description') || '',
        image_url: edit.getAttribute('data-image-url') || '',
        unit: edit.getAttribute('data-unit') || '',
        price: edit.getAttribute('data-price') || '',
        currency: edit.getAttribute('data-currency') || 'ARS',
      });
      return;
    }

    const del = e.target && e.target.closest ? e.target.closest('.js-delete') : null;
    if (del) {
      e.preventDefault();
      clearMsgs();
      const id = del.getAttribute('data-id') || '';
      if (!id) return;
      if (!confirm('¿Eliminar este producto del catálogo?')) return;

      postAction({
        csrf_token: csrfToken(),
        action: 'delete',
        id,
        q: searchInput.value || '',
      })
        .then((resp) => {
          showMsg(clientSuccess, resp.message || 'Producto eliminado.');
          if (idInput.value === id) setCreateMode();
          return refresh();
        })
        .catch((err) => showMsg(clientError, err.message || String(err)));
    }
  });

  // Cancelar edición sin recargar
  if (cancelLink) {
    cancelLink.addEventListener('click', (e) => {
      e.preventDefault();
      clearMsgs();
      setCreateMode();
      hideProductModal();
    });
  }

  if (imageInput) {
    imageInput.addEventListener('change', () => {
      if (!imageInput.files || imageInput.files.length === 0) {
        clearImageObjectUrl();
        const current = imagePreview ? (imagePreview.dataset.currentUrl || '') : '';
        setImagePreview(current);
        return;
      }

      const file = imageInput.files[0];
      clearImageObjectUrl();
      imageObjectUrl = URL.createObjectURL(file);
      if (imageRemove) imageRemove.checked = false;
      setImagePreview(imageObjectUrl);
    });
  }

  if (imageRemove) {
    imageRemove.addEventListener('change', () => {
      if (!imageRemove.checked) {
        const current = imagePreview ? (imagePreview.dataset.currentUrl || '') : '';
        setImagePreview(current);
        return;
      }
      if (imageInput) imageInput.value = '';
      clearImageObjectUrl();
      setImagePreview('');
    });
  }

  // Primer carga dinámica (mantiene HTML como fallback)
  refresh().catch(() => {
    // Si falla, queda el render server-side.
  });

  if (initialImportFlash) {
    showImportSuccessModal(initialImportFlash);
  }

  if (initialEditMode) {
    showProductModal();
  }

  // Carga por voz
  if (voiceBtn) {
    voiceBtn.addEventListener('click', () => {
      (async () => {
        // Preferimos dictado directo (Web Speech API) cuando está disponible.
        // Si falla (permiso, error del navegador), caemos a grabación/subida.
        if (supportsSpeechRecognition()) {
          try {
            await startOrStopDictation();
            return;
          } catch (_) {
            // El error ya lo mostramos; intentamos el fallback.
          }
        }
        await startOrStopRecording();
      })().catch((err) => {
        showMsg(clientError, err && err.message ? err.message : String(err));
      });
    });
  }

  if (voiceFile) {
    voiceFile.addEventListener('change', () => {
      const f = voiceFile.files && voiceFile.files[0] ? voiceFile.files[0] : null;
      if (!f) return;
      clearMsgs();
      showMsg(clientSuccess, 'Transcribiendo audio…');
      transcribeAudioFile(f)
        .then((text) => applyTranscriptToForm(text))
        .catch((err) => showMsg(clientError, err && err.message ? err.message : String(err)));
    });
  }

  if (imagePreview) {
    imagePreview.addEventListener('error', () => {
      setImagePreview('');
    });
  }
})();
</script>
</body>
</html>

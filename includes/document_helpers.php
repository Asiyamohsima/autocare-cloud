<?php
/**
 * AutoCare Cloud - helpers for the document module.
 * Files are stored in Amazon S3. MySQL stores only the metadata.
 */
require_once __DIR__ . '/service_helpers.php';        // also loads vehicle_helpers.php
require_once dirname(__DIR__) . '/config/aws.php';     // S3 functions

const DOC_MAX_BYTES = 5 * 1024 * 1024;   // 5 MB
const DOC_SOON_DAYS = 30;                // "Expiring Soon" window in days

function document_types(): array
{
    return ['Insurance', 'Registration', 'Pollution Certificate', 'Driving License',
            'Warranty', 'Service Bill', 'Purchase Invoice', 'Vehicle Image', 'Other'];
}

/** The S3 prefix (folder name) for a document type. */
function document_type_folder(string $type): string
{
    $map = [
        'Insurance'             => 'insurance',
        'Registration'          => 'registration',
        'Pollution Certificate' => 'pollution',
        'Warranty'              => 'warranty',
        'Service Bill'          => 'service-bills',
        'Vehicle Image'         => 'images',
    ];
    return $map[$type] ?? 'other';
}

/** extension => [MIME type we store and serve, MIME types the server may detect]. */
function document_allowed_types(): array
{
    $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    return [
        'pdf'  => ['application/pdf', ['application/pdf']],
        'jpg'  => ['image/jpeg', ['image/jpeg']],
        'jpeg' => ['image/jpeg', ['image/jpeg']],
        'png'  => ['image/png', ['image/png']],
        'doc'  => ['application/msword',
                   ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office']],
        'docx' => [$docx, [$docx, 'application/zip', 'application/x-zip-compressed']],
    ];
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

/** Bootstrap icon classes for a file extension. */
function document_icon(string $ext): string
{
    if ($ext === 'pdf') {
        return 'bi-file-earmark-pdf text-danger';
    }
    if ($ext === 'jpg' || $ext === 'jpeg' || $ext === 'png') {
        return 'bi-file-earmark-image text-success';
    }
    return 'bi-file-earmark-word text-primary';
}

function document_extension(string $s3Key): string
{
    return strtolower(pathinfo($s3Key, PATHINFO_EXTENSION));
}

function document_defaults(): array
{
    return ['vehicle_id' => '', 'document_type' => 'Other', 'document_name' => '', 'expiry_date' => ''];
}

function read_document_post(): array
{
    $out = [];
    foreach (array_keys(document_defaults()) as $key) {
        $raw = $_POST[$key] ?? '';
        $out[$key] = is_string($raw) ? trim($raw) : '';
    }
    return $out;
}

/** Validates the text fields of the form. Returns [$errors, $data]. */
function validate_document_form(array $in, mysqli $db): array
{
    $errors = [];
    $data   = [];

    // --- Vehicle ---
    $vehicle = null;
    $vid     = valid_id($in['vehicle_id']);
    if ($vid === null) {
        $errors['vehicle_id'] = 'Please select a vehicle.';
    } else {
        $vehicle = find_vehicle($db, $vid);
        if ($vehicle === null) {
            $errors['vehicle_id'] = 'The selected vehicle does not exist.';
        }
    }
    $data['vehicle'] = $vehicle;

    // --- Document type ---
    if (!in_array($in['document_type'], document_types(), true)) {
        $errors['document_type'] = 'Choose a valid document type.';
    }
    $data['document_type'] = $in['document_type'];

    // --- Document name ---
    $name = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $in['document_name']));
    $len  = mb_strlen($name);
    if ($len < 2) {
        $errors['document_name'] = 'Enter a document name (at least 2 characters).';
    } elseif ($len > 150) {
        $errors['document_name'] = 'Document name must be 150 characters or fewer.';
    }
    $data['document_name'] = $name;

    // --- Expiry date (optional) ---
    $exp = $in['expiry_date'];
    if ($exp === '') {
        $data['expiry_date'] = null;
    } else {
        if (!is_valid_date($exp)) {
            $errors['expiry_date'] = 'Enter a valid date.';
        } elseif ($exp < '2000-01-01' || $exp > date('Y-m-d', strtotime('+30 years'))) {
            $errors['expiry_date'] = 'The expiry date looks wrong. Please check the year.';
        }
        $data['expiry_date'] = $exp;
    }

    return [$errors, $data];
}

/** Deeper check of the file's real content. */
function document_content_ok(string $ext, string $path): bool
{
    switch ($ext) {
        case 'pdf':
            $head = (string)file_get_contents($path, false, null, 0, 1024);
            return strpos($head, '%PDF-') !== false;

        case 'jpg':
        case 'jpeg':
            $info = @getimagesize($path);
            return $info !== false && $info[2] === IMAGETYPE_JPEG;

        case 'png':
            $info = @getimagesize($path);
            return $info !== false && $info[2] === IMAGETYPE_PNG;

        case 'doc':
            return file_get_contents($path, false, null, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

        case 'docx':
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return false;
            }
            $ok = $zip->locateName('[Content_Types].xml') !== false
               && $zip->locateName('word/document.xml') !== false;
            $zip->close();
            return $ok;
    }
    return false;
}

/**
 * Validates the uploaded file: upload error, size, extension, real MIME type, content.
 * Returns [$errorMessageOrNull, $info]. $info has: ext, mime, size, tmp.
 */
function validate_uploaded_file(?array $file): array
{
    $tooBig  = 'The file is too large. The maximum size is 5 MB.';
    $noFile  = 'Please choose a file to upload.';
    $generic = 'The file could not be uploaded. Please try again.';
    $badType = 'The file content does not match its type. Please upload a genuine PDF, JPG, PNG, DOC or DOCX file.';

    if ($file === null || !isset($file['error']) || is_array($file['error'])) {
        return [$noFile, []];
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return [$noFile, []];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return [$tooBig, []];
        default:
            error_log('[AutoCare] Upload error code ' . (int)$file['error']);
            return [$generic, []];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return [$generic, []];
    }

    // --- Size (checked on the real file, not the number sent by the browser) ---
    $size = filesize($tmp);
    if ($size === false) {
        return [$generic, []];
    }
    if ($size === 0) {
        return ['The file is empty.', []];
    }
    if ($size > DOC_MAX_BYTES) {
        return [$tooBig, []];
    }

    // --- Extension ---
    $ext     = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = document_allowed_types();
    if (!isset($allowed[$ext])) {
        return ['Only PDF, JPG, JPEG, PNG, DOC and DOCX files are allowed.', []];
    }

    // --- Real MIME type, detected from the file's bytes ---
    $detected = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!in_array($detected, $allowed[$ext][1], true)) {
        error_log('[AutoCare] Upload rejected: extension "' . $ext . '" but detected MIME "' . $detected . '"');
        return [$badType, []];
    }

    // --- Content check ---
    if (!document_content_ok($ext, $tmp)) {
        error_log('[AutoCare] Upload rejected: content check failed for extension "' . $ext . '"');
        return [$badType, []];
    }

    return [null, ['ext' => $ext, 'mime' => $allowed[$ext][0], 'size' => (int)$size, 'tmp' => $tmp]];
}

/**
 * Builds the S3 object key: vehicles/<REG>/<folder>/<random-name>.<ext>
 * The registration number only contains A-Z and 0-9, and the file name is random,
 * so nothing the user typed ends up in the key.
 */
function build_s3_key(string $registration, string $type, string $ext): string
{
    $reg = normalize_registration($registration);
    if ($reg === '') {
        throw new RuntimeException('Vehicle has no usable registration number.');
    }
    return 'vehicles/' . $reg . '/' . document_type_folder($type) . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
}

function insert_document(mysqli $db, array $d): int
{
    $stmt = $db->prepare(
        'INSERT INTO documents (vehicle_id, document_name, document_type, s3_key, file_size, file_type, expiry_date)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssiss',
        $d['vehicle_id'], $d['document_name'], $d['document_type'], $d['s3_key'],
        $d['file_size'], $d['file_type'], $d['expiry_date']
    );
    $stmt->execute();
    $newId = (int)$db->insert_id;
    $stmt->close();
    return $newId;
}

/**
 * Uploads the file to S3, then saves its metadata in MySQL.
 * If saving the metadata fails, the S3 object is removed again so no orphan is left.
 * Throws S3OperationException (safe message) or RuntimeException.
 */
function store_document(mysqli $db, array $form, array $file): int
{
    $key = build_s3_key($form['vehicle']['registration_number'], $form['document_type'], $file['ext']);

    s3_put_file($key, $file['tmp'], $file['mime']);

    try {
        return insert_document($db, [
            'vehicle_id'    => (int)$form['vehicle']['id'],
            'document_name' => $form['document_name'],
            'document_type' => $form['document_type'],
            's3_key'        => $key,
            'file_size'     => $file['size'],
            'file_type'     => $file['mime'],
            'expiry_date'   => $form['expiry_date'],
        ]);
    } catch (Throwable $e) {
        error_log('[AutoCare] Document metadata insert failed, removing the uploaded S3 object: ' . $e->getMessage());
        try {
            s3_delete_object($key);
        } catch (Throwable $cleanup) {
            error_log('[AutoCare] CLEANUP FAILED - orphan S3 object left behind: ' . $key);
        }
        throw new RuntimeException('Could not save the document record.', 0, $e);
    }
}

function find_document(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare(
        'SELECT d.*, v.registration_number, v.make, v.model
           FROM documents d JOIN vehicles v ON v.id = d.vehicle_id
          WHERE d.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** A safe file name for the browser's download, e.g. "Insurance Policy.pdf". */
function document_download_name(string $name, string $ext): string
{
    $base = trim((string)preg_replace('/[\x00-\x1F\x7F"\/\\\\:*?<>|]+/u', '', $name));
    $base = mb_substr($base, 0, 100);
    if ($base === '') {
        $base = 'document';
    }
    return $base . '.' . $ext;
}

<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$supabaseUrl = 'https://bgykitdaudcmcetqijkd.supabase.co';
$supabaseKey = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImJneWtpdGRhdWRjbWNldHFpamtkIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NTA0MzEyNjcsImV4cCI6MjA2NjAwNzI2N30.CZjOxc0_7cHEvPFUDr7zLzqaDDeIr_5tUcObBLKqg3Q';
$bucketName = 'kabox-uploads';
$maxFileSize = 20 * 1024 * 1024;

function send_json_error($message, $statusCode = 400) {
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function generate_random_name($length = 8) {
    return bin2hex(random_bytes($length / 2));
}

function upload_to_supabase($fileData, $fileName, $fileMime) {
    global $supabaseUrl, $supabaseKey, $bucketName;
    $uploadUrl = $supabaseUrl . '/storage/v1/object/' . $bucketName . '/' . $fileName;

    $ch = curl_init($uploadUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $supabaseKey,
        'Content-Type: ' . $fileMime,
        'x-upsert: false'
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'error' => 'cURL Error: ' . $curl_error];
    }

    if ($httpcode >= 200 && $httpcode < 300) {
        return ['success' => true, 'error' => null];
    } else {
        $errorData = json_decode($response, true);
        $errorMessage = $errorData['message'] ?? 'Terjadi error tidak dikenal pada server storage.';
        $detailedError = $errorMessage . " (HTTP Status: " . $httpcode . ")";
        return ['success' => false, 'error' => $detailedError];
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error('Metode tidak diizinkan. Gunakan POST.', 405);
}

$uploadedFilesUrls = [];
$errors = [];

if (isset($_POST['url']) && !empty($_POST['url'])) {
    $url = filter_var($_POST['url'], FILTER_VALIDATE_URL);
    if (!$url) send_json_error('URL tidak valid.');
    
    $fileContent = @file_get_contents($url);
    if ($fileContent === false) send_json_error('Gagal mengambil konten dari URL.');
    if (strlen($fileContent) > $maxFileSize) send_json_error('File dari URL melebihi batas 20MB.');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($fileContent) ?: 'application/octet-stream';
    $pathInfo = pathinfo(parse_url($url, PHP_URL_PATH));
    $extension = isset($pathInfo['extension']) ? '.' . strtolower($pathInfo['extension']) : '';
    $newFileName = generate_random_name() . $extension;

    $uploadResult = upload_to_supabase($fileContent, $newFileName, $mimeType);
    if ($uploadResult['success']) {
        $uploadedFilesUrls[] = ['url' => '/files/' . $newFileName];
    } else {
        $errors[] = "Gagal upload dari URL: " . $uploadResult['error'];
    }

} elseif (isset($_FILES['files'])) {
    $files = $_FILES['files'];
    if (count($files['name']) > 3) send_json_error('Maksimal 3 file sekali unggah.');

    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i] > $maxFileSize) {
            $errors[] = "File '" . htmlspecialchars($name) . "' melebihi batas 20MB.";
            continue;
        }

        $tmpName = $files['tmp_name'][$i];
        $fileExtension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $newFileName = generate_random_name(10) . '.' . $fileExtension;

        $uploadResult = upload_to_supabase(file_get_contents($tmpName), $newFileName, $files['type'][$i]);
        if ($uploadResult['success']) {
            $uploadedFilesUrls[] = ['url' => '/files/' . $newFileName];
        } else {
            $errors[] = "File '" . htmlspecialchars($name) . "': " . $uploadResult['error'];
        }
    }
} else {
    send_json_error('Tidak ada file atau URL yang dikirim.', 400);
}

if (!empty($uploadedFilesUrls)) {
    echo json_encode(['success' => true, 'files' => $uploadedFilesUrls, 'errors' => $errors]);
} else {
    $fullErrorMessage = empty($errors)
        ? 'Tidak ada file yang berhasil diunggah. Cek kembali file Anda.'
        : implode("\n", $errors);
    send_json_error($fullErrorMessage, 500);
}
?>

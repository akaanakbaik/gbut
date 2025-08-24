<?php
// Mengatur header untuk response JSON dan CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// --- KONFIGURASI SUPABASE ---
// Ambil dari environment variables jika ada (lebih aman), atau hardcode.
$supabaseUrl = getenv('SUPABASE_URL') ?: 'https://bgykitdaudcmcetqijkd.supabase.co';
$supabaseKey = getenv('SUPABASE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImJneWtpdGRhdWRjbWNldHFpamtkIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NTA0MzEyNjcsImV4cCI6MjA2NjAwNzI2N30.CZjOxc0_7cHEvPFUDr7zLzqaDDeIr_5tUcObBLKqg3Q';
$bucketName = getenv('BUCKET_NAME') ?: 'kabox-uploads';
$maxFileSize = 20 * 1024 * 1024; // 20 MB

// Fungsi untuk mengirim response error standar
function send_json_error($message, $statusCode = 400) {
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// Fungsi untuk menghasilkan nama file acak yang unik
function generate_random_name($length = 8) {
    return bin2hex(random_bytes($length / 2)); // Lebih aman dari str_shuffle
}

// Fungsi inti untuk mengunggah data ke Supabase Storage
function upload_to_supabase($fileData, $fileName, $fileMime) {
    global $supabaseUrl, $supabaseKey, $bucketName;

    $uploadUrl = $supabaseUrl . '/storage/v1/object/' . $bucketName . '/' . $fileName;

    $ch = curl_init($uploadUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $supabaseKey,
        'Content-Type: ' . $fileMime,
        'x-upsert: false' // Jangan timpa jika ada, untuk mencegah kolisi (meski kecil kemungkinannya)
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode >= 200 && $httpcode < 300) {
        return true;
    } else {
        $errorData = json_decode($response, true);
        $errorMessage = $errorData['message'] ?? 'Gagal mengunggah ke Supabase Storage.';
        // Jangan kirim error langsung, return false agar bisa ditangani di logic utama
        error_log("Supabase Upload Error: " . $errorMessage);
        return false;
    }
}

// --- LOGIKA UTAMA ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error('Metode tidak diizinkan. Gunakan POST.', 405);
}

$uploadedFilesUrls = [];

// Handle upload dari URL
if (isset($_POST['url']) && !empty($_POST['url'])) {
    if (!empty($_FILES)) {
        send_json_error('Unggah file atau URL, tidak bisa keduanya bersamaan.', 400);
    }

    $url = filter_var($_POST['url'], FILTER_VALIDATE_URL);
    if (!$url) {
        send_json_error('URL yang diberikan tidak valid.');
    }

    $context = stream_context_create(['http' => ['header' => "User-Agent: KaboxUploader/1.0\r\n"]]);
    $fileContent = @file_get_contents($url, false, $context);

    if ($fileContent === false) {
        send_json_error('Gagal mengambil konten dari URL. Pastikan URL dapat diakses secara publik.', 400);
    }
    
    if (strlen($fileContent) > $maxFileSize) {
        send_json_error('File dari URL melebihi batas ukuran 20MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($fileContent) ?: 'application/octet-stream';
    
    $pathInfo = pathinfo(parse_url($url, PHP_URL_PATH));
    $extension = isset($pathInfo['extension']) ? '.' . strtolower($pathInfo['extension']) : '';
    
    $newFileName = generate_random_name() . $extension;

    if (upload_to_supabase($fileContent, $newFileName, $mimeType)) {
        $uploadedFilesUrls[] = ['url' => '/files/' . $newFileName];
    } else {
        send_json_error('Gagal memproses file dari URL.', 500);
    }

// Handle upload file dari form
} elseif (isset($_FILES['files'])) {
    $files = $_FILES['files'];
    if (count($files['name']) > 5) {
        send_json_error('Maksimal 5 file yang bisa diunggah sekaligus.', 400);
    }

    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue; // Skip file yang error
        }
        if ($files['size'][$i] > $maxFileSize) {
            continue; // Skip file yang kebesaran
        }

        $tmpName = $files['tmp_name'][$i];
        $fileExtension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $newFileName = generate_random_name(10) . '.' . $fileExtension;

        if (upload_to_supabase(file_get_contents($tmpName), $newFileName, $files['type'][$i])) {
            $uploadedFilesUrls[] = ['url' => '/files/' . $newFileName];
        }
    }
} else {
    send_json_error('Tidak ada file atau URL yang dikirim untuk diunggah.', 400);
}

// Kirim response sukses jika ada file yang berhasil diunggah
if (!empty($uploadedFilesUrls)) {
    echo json_encode(['success' => true, 'files' => $uploadedFilesUrls]);
} else {
    send_json_error('Tidak ada file yang berhasil diunggah. Cek ukuran atau format file.', 500);
}
?>


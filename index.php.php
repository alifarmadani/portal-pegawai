<?php
require_once __DIR__ . '/config/db.php';
date_default_timezone_set('Asia/Jakarta');
header("User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)");
header("Connection: keep-alive");
echo "OK running<br>";

// ======================================
// 🔹 Fungsi Kirim Pesan WhatsApp via Fonnte
// ======================================
function sendWhatsapp($target, $message)
{
    $token = "bzAKGpqKAXWbWhBjsnQg"; // Ganti token Fonnte kamu

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.fonnte.com/send",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            "target" => $target,
            "message" => $message,
        ],
        CURLOPT_HTTPHEADER => [
            "Authorization: $token"
        ],
    ));

    $response = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);

    if ($error) {
        file_put_contents(__DIR__ . '/log_auto_send.txt', "[ERROR] $error\n", FILE_APPEND);
        return false;
    } else {
        file_put_contents(__DIR__ . '/log_auto_send.txt', "[SENT to $target] $response\n", FILE_APPEND);
        return true;
    }
}

file_put_contents(__DIR__ . '/log_auto_send.txt', "[" . date('Y-m-d H:i:s') . "] Script dijalankan\n", FILE_APPEND);
echo "[" . date('Y-m-d H:i:s') . "] Mengecek event...\n";

// ======================================
// 🔹 Ambil notifikasi aktif (belum semua dikirim)
// ======================================
$query = "
    SELECT id, title, body, event_start, target_type, department_id, sent_before_1d, sent_before_3h
    FROM notifications
    WHERE type = 'pengumuman'
    AND (sent_before_1d = 0 OR sent_before_3h = 0)
    AND event_start > NOW()
    AND event_start < DATE_ADD(NOW(), INTERVAL 1 DAY)
";

$result = mysqli_query($conn, $query);
if (!$result) {
    die('Query error: ' . mysqli_error($conn));
}

$now = time();

while ($row = mysqli_fetch_assoc($result)) {
    $id = $row['id'];
    $title = $row['title'];
    $body = $row['body'];
    $target_type = $row['target_type'];
    $department_id = $row['department_id'];
    $event_time = strtotime($row['event_start']);
    $tanggal = date('d F Y', $event_time);
    $jam = date('H:i', $event_time);

    $message = "🔔 *Pengumuman Penting*\n\n📌 {$title}\n\n{$body}\n\n🗓 *Tanggal:* {$tanggal}\n⏰ *Jam:* {$jam}";

    // Tentukan daftar penerima
    $recipients = [];

    if ($target_type == 'all') {
        $recipientsQuery = mysqli_query($conn, "
            SELECT phone_e164 FROM employees 
            WHERE phone_e164 IS NOT NULL AND phone_e164 != ''
        ");
    } elseif ($target_type == 'department' && !empty($department_id)) {
        $recipientsQuery = mysqli_query($conn, "
            SELECT phone_e164 FROM employees 
            WHERE department_id = {$department_id}
              AND phone_e164 IS NOT NULL 
              AND phone_e164 != ''
        ");
    } else { // target_type = 'employee'
        $recipientsQuery = mysqli_query($conn, "
            SELECT e.phone_e164
            FROM notification_recipients nr
            JOIN employees e ON nr.employee_id = e.id
            WHERE nr.notification_id = {$id}
              AND e.phone_e164 IS NOT NULL
              AND e.phone_e164 != ''
        ");
    }

    while ($r = mysqli_fetch_assoc($recipientsQuery)) {
        $nomor = preg_replace('/[^0-9]/', '', $r['phone_e164']);
        if ($nomor) $recipients[] = $nomor;
    }

    // Kirim Pesan 1 Hari Sebelum Event
    if ($row['sent_before_1d'] == 0 && $now >= $event_time - 86400) {
        echo "Mengirim pesan 1 hari sebelum event ID {$id} ke " . count($recipients) . " orang\n";
        foreach ($recipients as $nomor) {
            sendWhatsapp($nomor, $message);
        }
        $conn->query("UPDATE notifications SET sent_before_1d = 1 WHERE id = {$id}");
    }

    // Kirim Pesan 3 Jam Sebelum Event
    if ($row['sent_before_3h'] == 0 && $now >= $event_time - 10800) {
        echo "Mengirim pesan 3 jam sebelum event ID {$id} ke " . count($recipients) . " orang\n";
        foreach ($recipients as $nomor) {
            sendWhatsapp($nomor, $message);
        }
        $conn->query("UPDATE notifications SET sent_before_3h = 1 WHERE id = {$id}");
    }
    // Kirim Pesan 30 Menit Sebelum Event
    if ($row['sent_before_30m'] == 0 && $now >= $event_time - 1800) {
        echo "Mengirim pesan 30 menit sebelum event ID {$id} ke " . count($recipients) . " orang\n";
        foreach ($recipients as $nomor) {
            sendWhatsapp($nomor, $message);
        }
        $conn->query("UPDATE notifications SET sent_before_30m = 1 WHERE id = {$id}");
    }

    if (
        $row['sent_before_1d'] == 1 &&
        $row['sent_before_3h'] == 1 &&
        $row['sent_before_30m'] == 1
    ) {
        $conn->query("UPDATE notifications SET sent = 1 WHERE id = {$id}");
    }


}

$conn->close();

echo "\n✅ Proses pengiriman selesai.";
?>

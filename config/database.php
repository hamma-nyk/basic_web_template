<?php
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'database.php') {
    http_response_code(403);
    die("Forbidden");
}

$host   = '192.168.10.16'; 
$port   = '5432';
$dbname = 'ikoit_sispro';
$user   = 'postgres';
$pass   = 'postgres';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Koneksi Database PostgreSQL Gagal.");
}

// Koneksi ke SQL Server (Database d_master)
// Sesuaikan kredensial dan IP dengan server SQL Server yang digunakan
$ms_host   = '192.168.10.11'; // Ganti jika beda IP
$ms_dbname = 'D_Master';
$ms_user   = 'sa';
$ms_pass   = 'PASSWORDSETUPSRVNUSANTARASIDIANYA'; // Sesuaikan

try {
    // Bisa pakai odbc: atau sqlsrv: (di sini pakai sqlsrv sebagai standar PHP Windows, ubah ke odbc jika driver yang ada odbc)
    // $dsn_ms = "sqlsrv:Server=$ms_host;Database=$ms_dbname";
    // Jika pakai ODBC:
    $dsn_ms = "odbc:Driver={SQL Server};Server=$ms_host;Database=$ms_dbname;";
    
    $pdo_ms = new PDO($dsn_ms, $ms_user, $ms_pass);
    $pdo_ms->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_ms->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // Simpan pesan error asli ke global agar bisa ditampilkan di UI
    $GLOBALS['ms_error'] = $e->getMessage();
    error_log("Koneksi Database SQL Server Gagal: " . $e->getMessage());
}

try {
    $pdo->exec("CREATE SCHEMA IF NOT EXISTS sc_master");

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS sc_master.role (
            id SERIAL PRIMARY KEY,
            nama VARCHAR(50) UNIQUE NOT NULL,
            keterangan VARCHAR(255) DEFAULT NULL,
            is_superadmin BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS sc_master.module (
            id SERIAL PRIMARY KEY,
            kode VARCHAR(50) UNIQUE NOT NULL,
            nama VARCHAR(100) NOT NULL,
            icon TEXT DEFAULT NULL,
            url VARCHAR(100) DEFAULT NULL,
            grup VARCHAR(50) DEFAULT NULL,
            urutan INT NOT NULL DEFAULT 0,
            is_active BOOLEAN NOT NULL DEFAULT TRUE
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS sc_master.role_module (
            id SERIAL PRIMARY KEY,
            role_id INT NOT NULL REFERENCES sc_master.role(id) ON DELETE CASCADE,
            module_id INT NOT NULL REFERENCES sc_master.module(id) ON DELETE CASCADE,
            UNIQUE(role_id, module_id)
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS sc_master."user" (
            id SERIAL PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            nama_lengkap VARCHAR(100) NOT NULL,
            role_id INT REFERENCES sc_master.role(id) ON DELETE SET NULL,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $colCheck = $pdo->query("
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema='sc_master' AND table_name='user' AND column_name='role_id'
    ")->fetchColumn();
    if (!$colCheck) {
        $pdo->exec('ALTER TABLE sc_master."user" ADD COLUMN role_id INT REFERENCES sc_master.role(id) ON DELETE SET NULL');
        $pdo->exec('ALTER TABLE sc_master."user" DROP COLUMN IF EXISTS role');
    }

    $check = $pdo->query("SELECT COUNT(*) FROM sc_master.role")->fetchColumn();
    if ($check == 0) {
        $pdo->exec("INSERT INTO sc_master.role (nama, keterangan, is_superadmin) VALUES ('Superadmin', 'Akses penuh ke semua modul', TRUE)");
    }

    $check = $pdo->query('SELECT COUNT(*) FROM sc_master."user"')->fetchColumn();
    if ($check == 0) {
        $saRole = $pdo->query("SELECT id FROM sc_master.role WHERE is_superadmin = TRUE LIMIT 1")->fetchColumn();
        $pdo->prepare('INSERT INTO sc_master."user" (username, password, nama_lengkap, role_id) VALUES (?, ?, ?, ?)')
            ->execute(['superadmin', password_hash('superadmin', PASSWORD_BCRYPT), 'Super Administrator', $saRole]);
    }

    $check = $pdo->query("SELECT COUNT(*) FROM sc_master.module")->fetchColumn();
    if ($check == 0) {
        $pdo->exec("
            INSERT INTO sc_master.module (kode, nama, icon, url, grup, urutan) VALUES
            ('dashboard', 'Dashboard', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"7\" height=\"9\" x=\"3\" y=\"3\" rx=\"1\"/><rect width=\"7\" height=\"5\" x=\"14\" y=\"3\" rx=\"1\"/><rect width=\"7\" height=\"9\" x=\"14\" y=\"12\" rx=\"1\"/><rect width=\"7\" height=\"5\" x=\"3\" y=\"16\" rx=\"1\"/></svg>', 'index.php', NULL, 1),
            ('master_user', 'Data Pengguna', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2\"/><circle cx=\"9\" cy=\"7\" r=\"4\"/><path d=\"M22 21v-2a4 4 0 0 0-3-3.87\"/><path d=\"M16 3.13a4 4 0 0 1 0 7.75\"/></svg>', 'master_user.php', 'Master Data', 10),
            ('master_role', 'Data Role & Akses', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"m21 16-4 4-4-4\"/><path d=\"M17 20V4\"/><path d=\"m3 8 4-4 4 4\"/><path d=\"M7 4v16\"/></svg>', 'master_role.php', 'Master Data', 11),
            ('master_module', 'Data Modul', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z\"/><polyline points=\"9 22 9 12 15 12 15 22\"/></svg>', 'master_module.php', 'Master Data', 12),
            ('copy_module', 'Copy Module User', '<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect width=\"14\" height=\"14\" x=\"8\" y=\"8\" rx=\"2\" ry=\"2\"/><path d=\"M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2\"/></svg>', 'copy_module.php', 'Tools', 20)
        ");
    }
} catch(PDOException $e) {
    error_log("Migration gagal: " . $e->getMessage());
}
?>

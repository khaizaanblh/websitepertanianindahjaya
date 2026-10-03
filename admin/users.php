<?php

require_once "../includes/auth.php";

require_admin();

require_once "../config/database.php";

$title = "Pengguna";

$base_url = get_base_url();

$message = "";
$error = "";


/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function redirect_users($message = "", $error = "")
{
    $url = "/kasir_pertanian/admin/users.php";

    $params = [];

    if ($message !== "") {
        $params["message"] = $message;
    }

    if ($error !== "") {
        $params["error"] = $error;
    }

    if (!empty($params)) {
        $url .= "?" . http_build_query($params);
    }

    header("Location: " . $url);

    exit;
}


/*
|--------------------------------------------------------------------------
| Flash Message
|--------------------------------------------------------------------------
*/

if (isset($_GET["message"])) {
    $message = $_GET["message"];
}

if (isset($_GET["error"])) {
    $error = $_GET["error"];
}


/*
|--------------------------------------------------------------------------
| POST PROCESS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "add";


    /*
    |--------------------------------------------------------------------------
    | TAMBAH PENGGUNA
    |--------------------------------------------------------------------------
    */

    if ($action === "add") {

        $name = trim($_POST["name"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";
        $role = $_POST["role"] ?? "kasir";

        $branch_id = isset($_POST["branch_id"])
            ? (int) $_POST["branch_id"]
            : 0;


        /*
        | Validasi nama
        */

        if ($name === "") {
            redirect_users("", "Nama pengguna wajib diisi.");
        }

        if (mb_strlen($name) < 3) {
            redirect_users("", "Nama pengguna minimal 3 karakter.");
        }


        /*
        | Validasi username
        */

        if ($username === "") {
            redirect_users("", "Username wajib diisi.");
        }

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
            redirect_users(
                "",
                "Username hanya boleh menggunakan huruf, angka, titik, garis bawah, dan tanda minus."
            );
        }

        if (mb_strlen($username) < 4) {
            redirect_users("", "Username minimal 4 karakter.");
        }


        /*
        | Validasi password
        */

        if ($password === "") {
            redirect_users("", "Password wajib diisi.");
        }

        if (strlen($password) < 6) {
            redirect_users("", "Password minimal 6 karakter.");
        }


        /*
        | Validasi role
        */

        $allowed_roles = ["admin", "kasir"];

        if (!in_array($role, $allowed_roles, true)) {
            redirect_users("", "Role pengguna tidak valid.");
        }


        /*
        |--------------------------------------------------------------------------
        | Validasi Cabang
        |--------------------------------------------------------------------------
        */

        if ($role === "kasir") {

            if ($branch_id <= 0) {
                redirect_users(
                    "",
                    "Cabang wajib dipilih untuk pengguna dengan role Kasir."
                );
            }


            /*
            | Pastikan cabang aktif
            */

            $branch_check = $conn->prepare("
                SELECT id
                FROM branches
                WHERE id = ?
                  AND status = 'active'
                LIMIT 1
            ");

            if (!$branch_check) {
                redirect_users(
                    "",
                    "Terjadi kesalahan saat memeriksa cabang."
                );
            }

            $branch_check->bind_param(
                "i",
                $branch_id
            );

            $branch_check->execute();

            $branch_result = $branch_check->get_result();

            if ($branch_result->num_rows === 0) {

                $branch_check->close();

                redirect_users(
                    "",
                    "Cabang yang dipilih tidak tersedia atau sedang tidak aktif."
                );
            }

            $branch_check->close();

        } else {

            /*
            | Administrator tidak terikat cabang.
            */

            $branch_id = null;
        }


        /*
        |--------------------------------------------------------------------------
        | Cek Username
        |--------------------------------------------------------------------------
        */

        $check = $conn->prepare("
            SELECT id
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        if (!$check) {
            redirect_users(
                "",
                "Terjadi kesalahan saat memeriksa username."
            );
        }

        $check->bind_param(
            "s",
            $username
        );

        $check->execute();

        $result = $check->get_result();

        if ($result->num_rows > 0) {

            $check->close();

            redirect_users(
                "",
                "Username tersebut sudah digunakan."
            );
        }

        $check->close();


        /*
        |--------------------------------------------------------------------------
        | Hash Password
        |--------------------------------------------------------------------------
        */

        $hash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );


        /*
        |--------------------------------------------------------------------------
        | Insert User
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            INSERT INTO users
            (
                name,
                username,
                password,
                role,
                branch_id
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            redirect_users(
                "",
                "Pengguna gagal ditambahkan. Struktur database tidak sesuai."
            );
        }

        $stmt->bind_param(
            "ssssi",
            $name,
            $username,
            $hash,
            $role,
            $branch_id
        );

        if ($stmt->execute()) {

            $stmt->close();

            redirect_users(
                "Pengguna berhasil ditambahkan."
            );

        } else {

            $stmt->close();

            redirect_users(
                "",
                "Pengguna gagal ditambahkan. Silakan coba lagi."
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | EDIT PENGGUNA
    |--------------------------------------------------------------------------
    */

    if ($action === "edit") {

        $user_id = isset($_POST["user_id"])
            ? (int) $_POST["user_id"]
            : 0;

        $name = trim($_POST["edit_name"] ?? "");
        $username = trim($_POST["edit_username"] ?? "");
        $password = $_POST["edit_password"] ?? "";
        $role = $_POST["edit_role"] ?? "kasir";

        $branch_id = isset($_POST["edit_branch_id"])
            ? (int) $_POST["edit_branch_id"]
            : 0;


        /*
        | Validasi ID
        */

        if ($user_id <= 0) {
            redirect_users("", "Pengguna yang akan diedit tidak valid.");
        }


        /*
        | Validasi nama
        */

        if ($name === "") {
            redirect_users("", "Nama pengguna wajib diisi.");
        }

        if (mb_strlen($name) < 3) {
            redirect_users("", "Nama pengguna minimal 3 karakter.");
        }


        /*
        | Validasi username
        */

        if ($username === "") {
            redirect_users("", "Username wajib diisi.");
        }

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
            redirect_users(
                "",
                "Username hanya boleh menggunakan huruf, angka, titik, garis bawah, dan tanda minus."
            );
        }

        if (mb_strlen($username) < 4) {
            redirect_users("", "Username minimal 4 karakter.");
        }


        /*
        | Validasi role
        */

        $allowed_roles = ["admin", "kasir"];

        if (!in_array($role, $allowed_roles, true)) {
            redirect_users("", "Role pengguna tidak valid.");
        }


        /*
        |--------------------------------------------------------------------------
        | Cek User
        |--------------------------------------------------------------------------
        */

        $user_check = $conn->prepare("
            SELECT
                id,
                role,
                branch_id
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        if (!$user_check) {
            redirect_users(
                "",
                "Terjadi kesalahan saat memeriksa pengguna."
            );
        }

        $user_check->bind_param(
            "i",
            $user_id
        );

        $user_check->execute();

        $existing_user = $user_check
            ->get_result()
            ->fetch_assoc();

        $user_check->close();

        if (!$existing_user) {
            redirect_users(
                "",
                "Pengguna yang akan diedit tidak ditemukan."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Validasi Cabang
        |--------------------------------------------------------------------------
        */

        if ($role === "kasir") {

            if ($branch_id <= 0) {
                redirect_users(
                    "",
                    "Cabang wajib dipilih untuk pengguna dengan role Kasir."
                );
            }


            /*
            | Pastikan cabang aktif
            */

            $branch_check = $conn->prepare("
                SELECT id
                FROM branches
                WHERE id = ?
                  AND status = 'active'
                LIMIT 1
            ");

            if (!$branch_check) {
                redirect_users(
                    "",
                    "Terjadi kesalahan saat memeriksa cabang."
                );
            }

            $branch_check->bind_param(
                "i",
                $branch_id
            );

            $branch_check->execute();

            $branch_result = $branch_check->get_result();

            if ($branch_result->num_rows === 0) {

                $branch_check->close();

                redirect_users(
                    "",
                    "Cabang yang dipilih tidak tersedia atau sedang tidak aktif."
                );
            }

            $branch_check->close();

        } else {

            /*
            | Administrator tidak terikat cabang.
            */

            $branch_id = null;
        }


        /*
        |--------------------------------------------------------------------------
        | Cek Username
        |--------------------------------------------------------------------------
        | Username boleh tetap sama dengan milik user yang sedang diedit.
        |--------------------------------------------------------------------------
        */

        $username_check = $conn->prepare("
            SELECT id
            FROM users
            WHERE username = ?
              AND id <> ?
            LIMIT 1
        ");

        if (!$username_check) {
            redirect_users(
                "",
                "Terjadi kesalahan saat memeriksa username."
            );
        }

        $username_check->bind_param(
            "si",
            $username,
            $user_id
        );

        $username_check->execute();

        $username_result = $username_check->get_result();

        if ($username_result->num_rows > 0) {

            $username_check->close();

            redirect_users(
                "",
                "Username tersebut sudah digunakan oleh pengguna lain."
            );
        }

        $username_check->close();


        /*
        |--------------------------------------------------------------------------
        | UPDATE DENGAN PASSWORD
        |--------------------------------------------------------------------------
        */

        if ($password !== "") {

            if (strlen($password) < 6) {
                redirect_users(
                    "",
                    "Password baru minimal 6 karakter."
                );
            }

            $hash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );


            $stmt = $conn->prepare("
                UPDATE users
                SET
                    name = ?,
                    username = ?,
                    password = ?,
                    role = ?,
                    branch_id = ?
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                redirect_users(
                    "",
                    "Pengguna gagal diperbarui."
                );
            }

            $stmt->bind_param(
                "ssssii",
                $name,
                $username,
                $hash,
                $role,
                $branch_id,
                $user_id
            );

        } else {

            /*
            |--------------------------------------------------------------------------
            | UPDATE TANPA PASSWORD
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE users
                SET
                    name = ?,
                    username = ?,
                    role = ?,
                    branch_id = ?
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                redirect_users(
                    "",
                    "Pengguna gagal diperbarui."
                );
            }

            $stmt->bind_param(
                "sssii",
                $name,
                $username,
                $role,
                $branch_id,
                $user_id
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Jalankan Update
        |--------------------------------------------------------------------------
        */

        if ($stmt->execute()) {

            $stmt->close();

            redirect_users(
                "Data pengguna berhasil diperbarui."
            );

        } else {

            $stmt->close();

            redirect_users(
                "",
                "Data pengguna gagal diperbarui. Silakan coba lagi."
            );
        }
    }
}


/*
|--------------------------------------------------------------------------
| Statistik Pengguna
|--------------------------------------------------------------------------
*/

$total_users = 0;
$total_admin = 0;
$total_kasir = 0;

$stats = $conn->query("
    SELECT
        COUNT(*) AS total_users,
        SUM(role = 'admin') AS total_admin,
        SUM(role = 'kasir') AS total_kasir
    FROM users
");

if ($stats) {

    $stat = $stats->fetch_assoc();

    $total_users = (int) ($stat["total_users"] ?? 0);
    $total_admin = (int) ($stat["total_admin"] ?? 0);
    $total_kasir = (int) ($stat["total_kasir"] ?? 0);
}


/*
|--------------------------------------------------------------------------
| Ambil Cabang Aktif
|--------------------------------------------------------------------------
*/

$branches = [];

$branch_query = $conn->query("
    SELECT
        id,
        name
    FROM branches
    WHERE status = 'active'
    ORDER BY name ASC
");

if ($branch_query) {

    while ($branch = $branch_query->fetch_assoc()) {

        $branches[] = $branch;
    }
}


/*
|--------------------------------------------------------------------------
| Ambil Daftar Pengguna
|--------------------------------------------------------------------------
*/

$users = $conn->query("
    SELECT
        u.id,
        u.name,
        u.username,
        u.role,
        u.branch_id,
        u.created_at,
        b.name AS branch_name
    FROM users u
    LEFT JOIN branches b
        ON b.id = u.branch_id
    ORDER BY u.id DESC
");


require "../includes/header.php";

?>

<style>

/* =========================================================
   USER PAGE
========================================================= */

.user-page {
    display: grid;
    gap: 16px;
}


/* Header */

.user-hero {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
}

.user-hero-copy h2 {
    margin: 0;
    font-size: 21px;
    letter-spacing: -.035em;
}

.user-hero-copy p {
    margin: 7px 0 0;
    color: #77837b;
    font-size: 11px;
    line-height: 1.6;
}

.user-hero-actions {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
}


/* Stats */

.user-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}

.user-stat {
    position: relative;
    overflow: hidden;
    background: #fff;
    border: 1px solid #dfe7e0;
    border-radius: 16px;
    padding: 18px;
    box-shadow: 0 8px 25px #173c240b;
}

.user-stat::after {
    content: "";
    position: absolute;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    right: -25px;
    top: -25px;
    background: #176f3d0b;
}

.user-stat:nth-child(2)::after {
    background: #2394540b;
}

.user-stat:nth-child(3)::after {
    background: #ee7a190b;
}

.user-stat small {
    display: block;
    color: #77837b;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 850;
}

.user-stat strong {
    display: block;
    margin-top: 9px;
    font-size: 25px;
    letter-spacing: -.05em;
}

.user-stat span {
    display: block;
    margin-top: 4px;
    color: #77837b;
    font-size: 10px;
}


/* Alert */

.user-alert {
    padding: 12px 14px;
    border-radius: 11px;
    font-size: 11px;
    font-weight: 700;
}

.user-alert.success {
    background: #eaf6ed;
    border: 1px solid #cce5d1;
    color: #176f3d;
}

.user-alert.error {
    background: #ffeded;
    border: 1px solid #efc4c4;
    color: #b33b3b;
}


/* Form */

.user-form-layout {
    display: grid;
    grid-template-columns: 1.15fr .85fr;
    gap: 18px;
}

.user-form-main {
    min-width: 0;
}

.user-form-side {
    padding: 17px;
    border: 1px solid #dfe7e0;
    border-radius: 14px;
    background: #f8faf8;
}

.user-form-side h3 {
    margin: 0 0 8px;
    font-size: 12px;
}

.user-form-side p {
    margin: 0;
    color: #77837b;
    font-size: 10px;
    line-height: 1.65;
}

.user-role-info {
    margin-top: 12px;
    padding: 11px;
    background: #fff;
    border: 1px solid #dfe7e0;
    border-radius: 10px;
    font-size: 10px;
    line-height: 1.6;
}

.user-role-info strong {
    display: block;
    margin-bottom: 3px;
}

.user-branch-info {
    margin-top: 10px;
    padding: 10px 11px;
    border-radius: 9px;
    background: #eef7f1;
    border: 1px solid #d7eadc;
    color: #376247;
    font-size: 10px;
    line-height: 1.55;
}

.user-form-actions {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-top: 15px;
}


/* Branch */

.branch-field {
    transition: opacity .2s ease;
}

.branch-field.disabled {
    opacity: .55;
}

.branch-required {
    color: #c34b4b;
    font-weight: 900;
}

.branch-optional {
    color: #77837b;
    font-size: 9px;
    font-weight: 600;
}


/* Password */

.password-field {
    position: relative;
}

.password-field input {
    padding-right: 72px !important;
}

.password-toggle {
    position: absolute;
    right: 7px;
    top: 7px;
    border: 0;
    border-radius: 7px;
    padding: 6px 8px;
    background: #f3f6f2;
    color: #526058;
    font-size: 9px;
    font-weight: 800;
}

.password-toggle:hover {
    background: #e8eee9;
}


/* Table */

.user-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}

.user-search {
    flex: 1;
    min-width: 220px;
    padding: 11px 13px;
    border: 1px solid #dfe7e0;
    border-radius: 10px;
    outline: none;
    background: #fff;
    font-size: 11px;
}

.user-search:focus {
    border-color: #8fc5a3;
    box-shadow: 0 0 0 3px #176f3d10;
}

.user-count {
    color: #77837b;
    font-size: 10px;
    white-space: nowrap;
}

.user-table-wrap {
    overflow-x: auto;
}

.user-table {
    min-width: 900px;
}

.user-table th {
    white-space: nowrap;
}

.user-table td {
    vertical-align: middle;
}

.user-name {
    font-weight: 800;
}

.user-username {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11px;
    color: #526058;
}


/* Role badge */

.role-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 9px;
    font-weight: 850;
}

.role-badge::before {
    content: "";
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}

.role-admin {
    background: #eaf6ed;
    color: #176f3d;
}

.role-kasir {
    background: #fff3df;
    color: #ad6200;
}


/* Branch badge */

.branch-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 9px;
    border-radius: 20px;
    background: #eef4ef;
    color: #486052;
    font-size: 9px;
    font-weight: 800;
}

.branch-badge::before {
    content: "âŒ‚";
    font-size: 10px;
}

.branch-none {
    color: #9aa39d;
    font-size: 9px;
    font-style: italic;
}


/* Edit Button */

.user-edit-btn {
    border: 1px solid #d7e2d9;
    background: #f7faf7;
    color: #176f3d;
    padding: 6px 10px;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 800;
    cursor: pointer;
    transition: .15s ease;
}

.user-edit-btn:hover {
    background: #eaf6ed;
    border-color: #bcd8c4;
}


/* Empty */

.user-empty {
    text-align: center;
    padding: 35px 20px !important;
    color: #77837b;
}

.user-empty strong {
    display: block;
    color: #526058;
    margin-bottom: 5px;
}

.user-empty span {
    font-size: 10px;
}


/* =========================================================
   MODAL EDIT USER
========================================================= */

.user-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(16, 32, 22, .42);
    backdrop-filter: blur(3px);
}

.user-modal.show {
    display: flex;
}

.user-modal-card {
    width: min(560px, 100%);
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    background: #fff;
    border: 1px solid #dfe7e0;
    border-radius: 18px;
    box-shadow: 0 25px 70px rgba(23, 60, 36, .22);
}

.user-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    padding: 18px 20px;
    border-bottom: 1px solid #e5ebe6;
}

.user-modal-header h3 {
    margin: 0;
    font-size: 15px;
}

.user-modal-header p {
    margin: 5px 0 0;
    color: #77837b;
    font-size: 10px;
}

.user-modal-close {
    border: 0;
    background: #f2f5f2;
    color: #526058;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    font-size: 17px;
    cursor: pointer;
}

.user-modal-close:hover {
    background: #e8eee9;
}

.user-modal-body {
    padding: 20px;
}

.user-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    padding: 15px 20px;
    border-top: 1px solid #e5ebe6;
    background: #fafcfb;
}

.edit-password-note {
    margin-top: 5px;
    color: #77837b;
    font-size: 9px;
    line-height: 1.5;
}


/* Responsive */

@media (max-width: 900px) {

    .user-form-layout {
        grid-template-columns: 1fr;
    }

    .user-hero {
        flex-direction: column;
    }

}

@media (max-width: 700px) {

    .user-stats {
        grid-template-columns: 1fr;
    }

    .user-toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .user-search {
        min-width: 100%;
    }

    .user-modal {
        padding: 10px;
    }

    .user-modal-card {
        max-height: calc(100vh - 20px);
    }

}

</style>


<div class="user-page">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="user-hero">

        <div class="user-hero-copy">

            <h2>Manajemen Pengguna</h2>

            <p>
                Kelola akun pengguna, role, dan cabang
                pada sistem Pertanian Indah Jaya.
            </p>

        </div>

        <div class="user-hero-actions">

            <a
                href="#form-tambah"
                class="btn primary"
            >
                + Tambah Pengguna
            </a>

        </div>

    </div>


    <!-- =====================================================
         ALERT
    ====================================================== -->

    <?php if ($message): ?>

        <div class="user-alert success">

            âœ“
            <?= htmlspecialchars($message) ?>

        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="user-alert error">

            !
            <?= htmlspecialchars($error) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         STATISTICS
    ====================================================== -->

    <div class="user-stats">

        <div class="user-stat">

            <small>Total Pengguna</small>

            <strong>
                <?= number_format($total_users) ?>
            </strong>

            <span>
                akun terdaftar
            </span>

        </div>


        <div class="user-stat">

            <small>Administrator</small>

            <strong>
                <?= number_format($total_admin) ?>
            </strong>

            <span>
                akses pengelolaan sistem
            </span>

        </div>


        <div class="user-stat">

            <small>Kasir</small>

            <strong>
                <?= number_format($total_kasir) ?>
            </strong>

            <span>
                pengguna transaksi
            </span>

        </div>

    </div>


    <!-- =====================================================
         FORM TAMBAH
    ====================================================== -->

    <div
        class="panel"
        id="form-tambah"
    >

        <div class="user-form-layout">

            <div class="user-form-main">

                <h2>Tambah Pengguna</h2>

                <p class="mini">
                    Buat akun baru untuk administrator atau kasir.
                </p>


                <form
                    method="POST"
                    autocomplete="off"
                    onsubmit="return validateUserForm()"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="add"
                    >


                    <div class="field">

                        <label for="name">
                            Nama Lengkap
                        </label>

                        <input
                            id="name"
                            type="text"
                            name="name"
                            maxlength="100"
                            placeholder="Contoh: Budi Santoso"
                            required
                        >

                    </div>


                    <div class="field">

                        <label for="username">
                            Username
                        </label>

                        <input
                            id="username"
                            type="text"
                            name="username"
                            maxlength="50"
                            placeholder="Contoh: budi"
                            pattern="[A-Za-z0-9._-]+"
                            title="Username hanya boleh menggunakan huruf, angka, titik, garis bawah, dan tanda minus."
                            required
                        >

                        <div class="mini">
                            Gunakan minimal 4 karakter.
                        </div>

                    </div>


                    <div class="field">

                        <label for="password">
                            Password
                        </label>

                        <div class="password-field">

                            <input
                                id="password"
                                type="password"
                                name="password"
                                minlength="6"
                                placeholder="Minimal 6 karakter"
                                required
                            >

                            <button
                                type="button"
                                class="password-toggle"
                                onclick="togglePassword()"
                            >
                                Lihat
                            </button>

                        </div>

                    </div>


                    <div class="field">

                        <label for="role">
                            Role / Hak Akses
                        </label>

                        <select
                            id="role"
                            name="role"
                            onchange="updateRoleInfo()"
                        >

                            <option value="kasir">
                                Kasir
                            </option>

                            <option value="admin">
                                Administrator
                            </option>

                        </select>

                    </div>


                    <!-- CABANG -->

                    <div
                        class="field branch-field"
                        id="branchField"
                    >

                        <label for="branch_id">

                            Cabang

                            <span
                                id="branchRequired"
                                class="branch-required"
                            >
                                *
                            </span>

                            <span
                                id="branchOptional"
                                class="branch-optional"
                                style="display:none;"
                            >
                                (opsional)
                            </span>

                        </label>

                        <select
                            id="branch_id"
                            name="branch_id"
                        >

                            <option value="">
                                -- Pilih Cabang --
                            </option>

                            <?php foreach ($branches as $branch): ?>

                                <option
                                    value="<?= (int) $branch["id"] ?>"
                                >
                                    <?= htmlspecialchars(
                                        $branch["name"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                        <div
                            class="mini"
                            id="branchHelp"
                        >
                            Kasir wajib ditempatkan pada salah satu
                            cabang aktif.
                        </div>

                    </div>


                    <div class="user-form-actions">

                        <button
                            type="submit"
                            class="btn primary"
                        >
                            Simpan Pengguna
                        </button>

                        <button
                            type="reset"
                            class="btn"
                            onclick="resetRoleInfo()"
                        >
                            Reset
                        </button>

                    </div>

                </form>

            </div>


            <div class="user-form-side">

                <h3>Hak Akses Pengguna</h3>

                <p>
                    Pilih role sesuai tanggung jawab pengguna
                    dalam sistem.
                </p>


                <div class="user-role-info">

                    <strong id="roleTitle">
                        Kasir
                    </strong>

                    <span id="roleDescription">
                        Mengelola transaksi penjualan,
                        riwayat transaksi, serta shift dan rekap
                        sesuai cabangnya.
                    </span>

                </div>


                <div class="user-role-info">

                    <strong>Administrator</strong>

                    <span>
                        Mengelola produk, stok, pengguna,
                        cabang, manajemen kas, dan laporan.
                    </span>

                </div>


                <div class="user-branch-info">

                    <strong>Hubungan Cabang</strong><br>

                    Setiap kasir terhubung dengan satu cabang.
                    Transaksi, kas, dan shift kasir akan mengikuti
                    cabang yang ditentukan pada akun pengguna.

                </div>

            </div>

        </div>

    </div>


    <!-- =====================================================
         DAFTAR PENGGUNA
    ====================================================== -->

    <div class="panel">

        <div class="user-toolbar">

            <input
                type="text"
                id="userSearch"
                class="user-search"
                placeholder="Cari nama, username, role, atau cabang..."
                oninput="filterUsers()"
            >

            <span class="user-count">

                <?= number_format($total_users) ?>

                akun terdaftar

            </span>

        </div>


        <div class="user-table-wrap">

            <table
                class="table user-table"
                id="userTable"
            >

                <thead>

                    <tr>

                        <th>
                            Pengguna
                        </th>

                        <th>
                            Username
                        </th>

                        <th>
                            Role
                        </th>

                        <th>
                            Cabang
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Dibuat
                        </th>

                        <th>
                            Aksi
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if ($users && $users->num_rows > 0): ?>

                        <?php while ($u = $users->fetch_assoc()): ?>

                            <tr>

                                <td>

                                    <div class="user-name">

                                        <?= htmlspecialchars(
                                            $u["name"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </div>

                                </td>


                                <td>

                                    <div class="user-username">

                                        <?= htmlspecialchars(
                                            $u["username"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </div>

                                </td>


                                <td>

                                    <?php if ($u["role"] === "admin"): ?>

                                        <span class="role-badge role-admin">
                                            Administrator
                                        </span>

                                    <?php else: ?>

                                        <span class="role-badge role-kasir">
                                            Kasir
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php if (!empty($u["branch_name"])): ?>

                                        <span class="branch-badge">

                                            <?= htmlspecialchars(
                                                $u["branch_name"],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>

                                        </span>

                                    <?php else: ?>

                                        <span class="branch-none">
                                            Tidak terikat cabang
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <span class="badge">
                                        Aktif
                                    </span>

                                </td>


                                <td>

                                    <?= date(
                                        "d M Y, H:i",
                                        strtotime($u["created_at"])
                                    ) ?>

                                </td>


                                <td>

                                    <button
                                        type="button"
                                        class="user-edit-btn"
                                        onclick='openEditUser(<?= json_encode([
                                            "id" => (int) $u["id"],
                                            "name" => $u["name"],
                                            "username" => $u["username"],
                                            "role" => $u["role"],
                                            "branch_id" => $u["branch_id"]
                                                ? (int) $u["branch_id"]
                                                : null
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                    >
                                        Edit
                                    </button>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td
                                colspan="7"
                                class="user-empty"
                            >

                                <strong>
                                    Belum ada pengguna
                                </strong>

                                <span>
                                    Tambahkan pengguna pertama melalui
                                    form di atas.
                                </span>

                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- =====================================================
         INFORMATION
    ====================================================== -->

    <div class="panel">

        <h2>Informasi Hak Akses</h2>

        <div class="receipt">

            <b>Administrator</b><br>

            Mengelola produk & stok, pengguna,
            cabang, manajemen kas, dan laporan penjualan.

            <br><br>

            <b>Kasir</b><br>

            Menangani transaksi penjualan,
            riwayat transaksi, serta pembukaan dan
            penutupan shift berdasarkan cabangnya.

        </div>

    </div>

</div>


<!-- =========================================================
     MODAL EDIT PENGGUNA
========================================================= -->

<div
    class="user-modal"
    id="editUserModal"
    onclick="closeEditModalOutside(event)"
>

    <div
        class="user-modal-card"
        onclick="event.stopPropagation()"
    >

        <div class="user-modal-header">

            <div>

                <h3>Edit Pengguna</h3>

                <p>
                    Ubah nama, username, role, cabang, atau password pengguna.
                </p>

            </div>

            <button
                type="button"
                class="user-modal-close"
                onclick="closeEditModal()"
            >
                Ã—
            </button>

        </div>


        <form
            method="POST"
            autocomplete="off"
            onsubmit="return validateEditUserForm()"
        >

            <input
                type="hidden"
                name="action"
                value="edit"
            >

            <input
                type="hidden"
                name="user_id"
                id="edit_user_id"
            >


            <div class="user-modal-body">


                <div class="field">

                    <label for="edit_name">
                        Nama Lengkap
                    </label>

                    <input
                        id="edit_name"
                        type="text"
                        name="edit_name"
                        maxlength="100"
                        required
                    >

                </div>


                <div class="field">

                    <label for="edit_username">
                        Username
                    </label>

                    <input
                        id="edit_username"
                        type="text"
                        name="edit_username"
                        maxlength="50"
                        pattern="[A-Za-z0-9._-]+"
                        title="Username hanya boleh menggunakan huruf, angka, titik, garis bawah, dan tanda minus."
                        required
                    >

                </div>


                <div class="field">

                    <label for="edit_role">
                        Role / Hak Akses
                    </label>

                    <select
                        id="edit_role"
                        name="edit_role"
                        onchange="updateEditRoleInfo()"
                    >

                        <option value="kasir">
                            Kasir
                        </option>

                        <option value="admin">
                            Administrator
                        </option>

                    </select>

                </div>


                <div
                    class="field branch-field"
                    id="editBranchField"
                >

                    <label for="edit_branch_id">

                        Cabang

                        <span
                            id="editBranchRequired"
                            class="branch-required"
                        >
                            *
                        </span>

                        <span
                            id="editBranchOptional"
                            class="branch-optional"
                            style="display:none;"
                        >
                            (opsional)
                        </span>

                    </label>

                    <select
                        id="edit_branch_id"
                        name="edit_branch_id"
                    >

                        <option value="">
                            -- Pilih Cabang --
                        </option>

                        <?php foreach ($branches as $branch): ?>

                            <option
                                value="<?= (int) $branch["id"] ?>"
                            >
                                <?= htmlspecialchars(
                                    $branch["name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <div
                        class="mini"
                        id="editBranchHelp"
                    >
                        Kasir wajib memiliki cabang aktif.
                    </div>

                </div>


                <div class="field">

                    <label for="edit_password">
                        Password Baru
                    </label>

                    <div class="password-field">

                        <input
                            id="edit_password"
                            type="password"
                            name="edit_password"
                            minlength="6"
                            placeholder="Kosongkan jika tidak ingin mengganti"
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            onclick="toggleEditPassword()"
                        >
                            Lihat
                        </button>

                    </div>

                    <div class="edit-password-note">
                        Kosongkan password jika password lama ingin tetap digunakan.
                    </div>

                </div>


                <div
                    class="user-branch-info"
                    id="editRoleInfo"
                >
                    Kasir terhubung dengan satu cabang dan transaksi akan
                    mengikuti cabang tersebut.
                </div>

            </div>


            <div class="user-modal-footer">

                <button
                    type="button"
                    class="btn"
                    onclick="closeEditModal()"
                >
                    Batal
                </button>

                <button
                    type="submit"
                    class="btn primary"
                >
                    Simpan Perubahan
                </button>

            </div>

        </form>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| Toggle Password Tambah
|--------------------------------------------------------------------------
*/

function togglePassword()
{
    const input = document.getElementById("password");
    const button = document.querySelector(
        "#form-tambah .password-toggle"
    );

    if (!input || !button) {
        return;
    }

    if (input.type === "password") {

        input.type = "text";
        button.textContent = "Sembunyikan";

    } else {

        input.type = "password";
        button.textContent = "Lihat";

    }
}


/*
|--------------------------------------------------------------------------
| Toggle Password Edit
|--------------------------------------------------------------------------
*/

function toggleEditPassword()
{
    const input = document.getElementById("edit_password");
    const button = document.querySelector(
        "#editUserModal .password-toggle"
    );

    if (!input || !button) {
        return;
    }

    if (input.type === "password") {

        input.type = "text";
        button.textContent = "Sembunyikan";

    } else {

        input.type = "password";
        button.textContent = "Lihat";

    }
}


/*
|--------------------------------------------------------------------------
| Role Information Tambah
|--------------------------------------------------------------------------
*/

function updateRoleInfo()
{
    const role = document.getElementById("role").value;

    const title = document.getElementById("roleTitle");
    const description = document.getElementById("roleDescription");

    const branchField = document.getElementById("branchField");
    const branchSelect = document.getElementById("branch_id");
    const branchRequired = document.getElementById("branchRequired");
    const branchOptional = document.getElementById("branchOptional");
    const branchHelp = document.getElementById("branchHelp");

    if (role === "admin") {

        title.textContent = "Administrator";

        description.textContent =
            "Mengelola produk, stok, pengguna, " +
            "cabang, manajemen kas, dan laporan.";

        branchField.classList.add("disabled");

        branchSelect.value = "";
        branchSelect.required = false;

        branchRequired.style.display = "none";
        branchOptional.style.display = "inline";

        branchHelp.textContent =
            "Administrator dapat mengelola seluruh cabang " +
            "dan tidak wajib terikat pada satu cabang.";

    } else {

        title.textContent = "Kasir";

        description.textContent =
            "Mengelola transaksi penjualan, " +
            "riwayat transaksi, serta shift dan rekap " +
            "sesuai cabangnya.";

        branchField.classList.remove("disabled");

        branchSelect.required = true;

        branchRequired.style.display = "inline";
        branchOptional.style.display = "none";

        branchHelp.textContent =
            "Kasir wajib ditempatkan pada salah satu cabang aktif.";
    }
}


/*
|--------------------------------------------------------------------------
| Role Information Edit
|--------------------------------------------------------------------------
*/

function updateEditRoleInfo()
{
    const role = document.getElementById("edit_role").value;

    const branchField =
        document.getElementById("editBranchField");

    const branchSelect =
        document.getElementById("edit_branch_id");

    const branchRequired =
        document.getElementById("editBranchRequired");

    const branchOptional =
        document.getElementById("editBranchOptional");

    const branchHelp =
        document.getElementById("editBranchHelp");

    const roleInfo =
        document.getElementById("editRoleInfo");


    if (role === "admin") {

        branchField.classList.add("disabled");

        branchSelect.value = "";
        branchSelect.required = false;

        branchRequired.style.display = "none";
        branchOptional.style.display = "inline";

        branchHelp.textContent =
            "Administrator tidak wajib terikat pada cabang.";

        roleInfo.textContent =
            "Administrator dapat mengelola seluruh cabang, " +
            "produk, pengguna, stok, kas, dan laporan.";

    } else {

        branchField.classList.remove("disabled");

        branchSelect.required = true;

        branchRequired.style.display = "inline";
        branchOptional.style.display = "none";

        branchHelp.textContent =
            "Kasir wajib memiliki satu cabang aktif.";

        roleInfo.textContent =
            "Kasir terhubung dengan satu cabang dan transaksi, " +
            "shift, serta riwayat akan mengikuti cabang tersebut.";
    }
}


/*
|--------------------------------------------------------------------------
| Validasi Form Tambah
|--------------------------------------------------------------------------
*/

function validateUserForm()
{
    const role =
        document.getElementById("role").value;

    const branch =
        document.getElementById("branch_id").value;


    if (role === "kasir" && branch === "") {

        alert(
            "Silakan pilih cabang untuk pengguna Kasir."
        );

        document
            .getElementById("branch_id")
            .focus();

        return false;
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| Validasi Form Edit
|--------------------------------------------------------------------------
*/

function validateEditUserForm()
{
    const role =
        document.getElementById("edit_role").value;

    const branch =
        document.getElementById("edit_branch_id").value;

    const name =
        document.getElementById("edit_name").value.trim();

    const username =
        document.getElementById("edit_username").value.trim();

    const password =
        document.getElementById("edit_password").value;


    if (name.length < 3) {

        alert(
            "Nama pengguna minimal 3 karakter."
        );

        document
            .getElementById("edit_name")
            .focus();

        return false;
    }


    if (username.length < 4) {

        alert(
            "Username minimal 4 karakter."
        );

        document
            .getElementById("edit_username")
            .focus();

        return false;
    }


    if (role === "kasir" && branch === "") {

        alert(
            "Silakan pilih cabang untuk pengguna Kasir."
        );

        document
            .getElementById("edit_branch_id")
            .focus();

        return false;
    }


    if (
        password !== "" &&
        password.length < 6
    ) {

        alert(
            "Password baru minimal 6 karakter."
        );

        document
            .getElementById("edit_password")
            .focus();

        return false;
    }


    return true;
}


/*
|--------------------------------------------------------------------------
| Reset Role Information
|--------------------------------------------------------------------------
*/

function resetRoleInfo()
{
    setTimeout(function () {

        updateRoleInfo();

    }, 50);
}


/*
|--------------------------------------------------------------------------
| Buka Modal Edit
|--------------------------------------------------------------------------
*/

function openEditUser(user)
{
    const modal =
        document.getElementById("editUserModal");

    if (!modal) {
        return;
    }


    document.getElementById("edit_user_id").value =
        user.id || "";

    document.getElementById("edit_name").value =
        user.name || "";

    document.getElementById("edit_username").value =
        user.username || "";

    document.getElementById("edit_role").value =
        user.role || "kasir";

    document.getElementById("edit_branch_id").value =
        user.branch_id
            ? String(user.branch_id)
            : "";

    document.getElementById("edit_password").value =
        "";

    document.getElementById("edit_password").type =
        "password";


    const passwordButton =
        document.querySelector(
            "#editUserModal .password-toggle"
        );

    if (passwordButton) {
        passwordButton.textContent = "Lihat";
    }


    updateEditRoleInfo();


    /*
    | Jika Admin, branch otomatis dikosongkan.
    | Jika Kasir, branch user tetap dipilih.
    */

    if (user.role === "admin") {

        document.getElementById(
            "edit_branch_id"
        ).value = "";

    } else if (user.branch_id) {

        document.getElementById(
            "edit_branch_id"
        ).value = String(user.branch_id);
    }


    modal.classList.add("show");

    document.body.style.overflow = "hidden";


    setTimeout(function () {

        document
            .getElementById("edit_name")
            .focus();

    }, 50);
}


/*
|--------------------------------------------------------------------------
| Tutup Modal Edit
|--------------------------------------------------------------------------
*/

function closeEditModal()
{
    const modal =
        document.getElementById("editUserModal");

    if (!modal) {
        return;
    }

    modal.classList.remove("show");

    document.body.style.overflow = "";
}


/*
|--------------------------------------------------------------------------
| Tutup Modal Klik Background
|--------------------------------------------------------------------------
*/

function closeEditModalOutside(event)
{
    if (
        event.target &&
        event.target.id === "editUserModal"
    ) {

        closeEditModal();
    }
}


/*
|--------------------------------------------------------------------------
| ESC untuk Tutup Modal
|--------------------------------------------------------------------------
*/

document.addEventListener(
    "keydown",
    function (event) {

        if (event.key === "Escape") {

            closeEditModal();
        }

    }
);


/*
|--------------------------------------------------------------------------
| Search User
|--------------------------------------------------------------------------
*/

function filterUsers()
{
    const input =
        document.getElementById("userSearch");

    const table =
        document.getElementById("userTable");

    if (!input || !table) {
        return;
    }


    const query =
        input.value
            .toLowerCase()
            .trim();


    const rows =
        table.querySelectorAll(
            "tbody tr"
        );


    rows.forEach(function (row) {

        const text =
            row.textContent.toLowerCase();

        row.style.display =
            text.includes(query)
                ? ""
                : "none";

    });
}


/*
|--------------------------------------------------------------------------
| Initial Role State
|--------------------------------------------------------------------------
*/

document.addEventListener(
    "DOMContentLoaded",
    function () {

        updateRoleInfo();

    }
);

</script>


<?php require "../includes/footer.php"; ?>

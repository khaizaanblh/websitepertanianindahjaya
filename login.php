<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/config/database.php";

$base_url = get_base_url();

$error = "";
$username = "";
$selected_branch_id = 0;


/*
|--------------------------------------------------------------------------
| FUNGSI ESCAPE
|--------------------------------------------------------------------------
*/
function esc($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}


/*
|--------------------------------------------------------------------------
| AMBIL DAFTAR CABANG AKTIF
|--------------------------------------------------------------------------
*/
$branches = [];

$stmtBranches = $conn->prepare("
    SELECT
        id,
        name,
        address,
        phone,
        status
    FROM branches
    WHERE status = 'active'
    ORDER BY id ASC
");

if ($stmtBranches) {

    $stmtBranches->execute();

    $resultBranches = $stmtBranches->get_result();

    while ($branch = $resultBranches->fetch_assoc()) {
        $branches[] = $branch;
    }

    $stmtBranches->close();
}


/*
|--------------------------------------------------------------------------
| PROSES LOGIN
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | AMBIL DATA FORM
    |--------------------------------------------------------------------------
    */

    $username = trim(
        $_POST["username"] ?? ""
    );

    $password = $_POST["password"] ?? "";

    $selected_branch_id = (int) (
        $_POST["branch_id"] ?? 0
    );


    /*
    |--------------------------------------------------------------------------
    | BERSIHKAN SESSION LOGIN LAMA
    |--------------------------------------------------------------------------
    */

    unset($_SESSION["user"]);
    unset($_SESSION["branch"]);
    unset($_SESSION["branch_id"]);
    unset($_SESSION["active_branch_id"]);
    unset($_SESSION["selected_branch_id"]);
    unset($_SESSION["login_branch_id"]);


    /*
    |--------------------------------------------------------------------------
    | VALIDASI CABANG
    |--------------------------------------------------------------------------
    */

    if ($selected_branch_id <= 0) {

        $error =
            "Silakan pilih cabang terlebih dahulu.";

    } elseif (empty($branches)) {

        $error =
            "Belum ada cabang aktif yang tersedia. " .
            "Silakan hubungi administrator.";

    } elseif ($username === "" || $password === "") {

        $error =
            "Username dan password wajib diisi.";

    } else {


        /*
        |--------------------------------------------------------------------------
        | CARI USER
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT
                u.id,
                u.name,
                u.username,
                u.password,
                u.role,
                u.branch_id,

                b.name AS branch_name,
                b.address AS branch_address,
                b.phone AS branch_phone,
                b.status AS branch_status

            FROM users u

            LEFT JOIN branches b
                ON b.id = u.branch_id

            WHERE u.username = ?

            LIMIT 1
        ");


        if (!$stmt) {

            $error =
                "Terjadi kesalahan sistem. " .
                "Silakan coba lagi.";

        } else {

            $stmt->bind_param(
                "s",
                $username
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $user = $result->fetch_assoc();


            /*
            |--------------------------------------------------------------------------
            | VERIFIKASI USERNAME DAN PASSWORD
            |--------------------------------------------------------------------------
            */

            if (
                !$user ||
                !password_verify(
                    $password,
                    $user["password"]
                )
            ) {

                $error =
                    "Username atau password salah.";

            } else {


                /*
                |--------------------------------------------------------------------------
                | CARI CABANG YANG DIPILIH USER
                |--------------------------------------------------------------------------
                */

                $selected_branch = null;

                foreach ($branches as $branch) {

                    if (
                        (int) $branch["id"] ===
                        $selected_branch_id
                    ) {

                        $selected_branch = $branch;

                        break;
                    }
                }


                /*
                |--------------------------------------------------------------------------
                | VALIDASI CABANG
                |--------------------------------------------------------------------------
                */

                if (!$selected_branch) {

                    $error =
                        "Cabang yang dipilih tidak tersedia " .
                        "atau sudah tidak aktif.";

                } elseif (
                    ($selected_branch["status"] ?? "") !==
                    "active"
                ) {

                    $error =
                        "Cabang yang dipilih sedang tidak aktif.";

                } else {


                    /*
                    |--------------------------------------------------------------------------
                    | NORMALISASI ROLE
                    |--------------------------------------------------------------------------
                    */

                    $user_role = strtolower(
                        trim(
                            (string) (
                                $user["role"] ?? ""
                            )
                        )
                    );


                    /*
                    |--------------------------------------------------------------------------
                    | VALIDASI KHUSUS KASIR
                    |--------------------------------------------------------------------------
                    */

                    if ($user_role === "kasir") {

                        $user_branch_id =
                            (int) (
                                $user["branch_id"] ?? 0
                            );


                        if ($user_branch_id <= 0) {

                            $error =
                                "Akun kasir belum memiliki cabang. " .
                                "Silakan hubungi administrator.";

                        } elseif (
                            $user_branch_id !==
                            $selected_branch_id
                        ) {

                            $error =
                                "Akun kasir ini terdaftar pada " .
                                "cabang " .
                                (
                                    $user["branch_name"]
                                    ?: "yang berbeda"
                                ) .
                                ". Silakan pilih cabang yang sesuai.";

                        } elseif (
                            ($user["branch_status"] ?? "") !==
                            "active"
                        ) {

                            $error =
                                "Cabang akun kasir sedang tidak aktif. " .
                                "Silakan hubungi administrator.";
                        }
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | LOGIN BERHASIL
                    |--------------------------------------------------------------------------
                    */

                    if ($error === "") {

                        session_regenerate_id(true);


                        /*
                        |--------------------------------------------------------------------------
                        | DATA CABANG AKTIF
                        |--------------------------------------------------------------------------
                        */

                        $active_branch_id =
                            (int) $selected_branch["id"];

                        $active_branch_name =
                            $selected_branch["name"];

                        $active_branch_address =
                            $selected_branch["address"] ?? "";

                        $active_branch_phone =
                            $selected_branch["phone"] ?? "";

                        $active_branch_status =
                            $selected_branch["status"];


                        /*
                        |--------------------------------------------------------------------------
                        | SESSION USER
                        |--------------------------------------------------------------------------
                        */

                        $_SESSION["user"] = [

                            "id" =>
                                (int) $user["id"],

                            "name" =>
                                $user["name"],

                            "username" =>
                                $user["username"],

                            "role" =>
                                $user["role"],

                            "branch_id" =>
                                $active_branch_id,

                            "branch_name" =>
                                $active_branch_name

                        ];


                        /*
                        |--------------------------------------------------------------------------
                        | SESSION BRANCH
                        |--------------------------------------------------------------------------
                        */

                        $_SESSION["branch"] = [

                            "id" =>
                                $active_branch_id,

                            "name" =>
                                $active_branch_name,

                            "address" =>
                                $active_branch_address,

                            "phone" =>
                                $active_branch_phone,

                            "status" =>
                                $active_branch_status

                        ];


                        /*
                        |--------------------------------------------------------------------------
                        | SESSION ID CABANG AKTIF
                        |--------------------------------------------------------------------------
                        */

                        $_SESSION["branch_id"] =
                            $active_branch_id;

                        $_SESSION["active_branch_id"] =
                            $active_branch_id;

                        $_SESSION["selected_branch_id"] =
                            $active_branch_id;

                        $_SESSION["login_branch_id"] =
                            $active_branch_id;


                        /*
                        |--------------------------------------------------------------------------
                        | REDIRECT
                        |--------------------------------------------------------------------------
                        */

                        header(
                            "Location: " .
                            $base_url .
                            "/index.php"
                        );

                        exit;
                    }
                }
            }

            $stmt->close();
        }
    }
}


/*
|--------------------------------------------------------------------------
| JIKA BUKAN POST
|--------------------------------------------------------------------------
*/

elseif ($_SERVER["REQUEST_METHOD"] !== "POST") {

    if (isset($_SESSION["user"])) {

        $hasUserSession =
            isset($_SESSION["user"]["id"]) &&
            (int) $_SESSION["user"]["id"] > 0;

        $hasBranchSession =
            isset($_SESSION["branch"]) &&
            isset($_SESSION["branch"]["id"]) &&
            (int) $_SESSION["branch"]["id"] > 0;


        if (
            $hasUserSession &&
            $hasBranchSession
        ) {

            header(
                "Location: " .
                $base_url .
                "/index.php"
            );

            exit;
        }


        unset($_SESSION["user"]);
        unset($_SESSION["branch"]);
        unset($_SESSION["branch_id"]);
        unset($_SESSION["active_branch_id"]);
        unset($_SESSION["selected_branch_id"]);
        unset($_SESSION["login_branch_id"]);
    }
}

?>


<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Login - Pertanian Indah Jaya
    </title>


    <style>

        :root {

            --green-950: #092416;
            --green-900: #0e321f;
            --green-800: #124728;
            --green-700: #176b3b;
            --green-600: #21864b;
            --green-500: #2b9a58;

            --lime: #91c83e;

            --bg: #f4f7f4;
            --card: #ffffff;

            --text: #17231b;
            --muted: #77837b;

            --border: #dfe7e1;

            --danger: #c43e3e;
            --danger-bg: #fff1f1;

            --shadow:
                0 25px 70px rgba(18, 52, 31, .10);

        }


        * {
            box-sizing: border-box;
        }


        html,
        body {
            min-height: 100%;
        }


        body {

            margin: 0;

            min-height: 100vh;

            font-family:
                Inter,
                ui-sans-serif,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

            color: var(--text);

            background:
                radial-gradient(
                    circle at 8% 8%,
                    #dcefd2 0,
                    transparent 25%
                ),
                radial-gradient(
                    circle at 94% 90%,
                    #f4e5d3 0,
                    transparent 25%
                ),
                #f5f8f5;
        }


        /* ============================================================
           MAIN WRAPPER
        ============================================================ */

        .login-wrapper {

            min-height: 100vh;

            display: grid;

            grid-template-columns:
                minmax(0, 1fr)
                minmax(440px, 530px);

        }


        /* ============================================================
           LEFT BRAND
        ============================================================ */

        .login-brand {

            position: relative;

            display: flex;

            align-items: center;

            padding: 70px;

            overflow: hidden;

            color: #fff;

            background:
                linear-gradient(
                    145deg,
                    #081f13 0%,
                    #0e321f 48%,
                    #176b3b 100%
                );
        }


        .login-brand::before {

            content: "";

            position: absolute;

            width: 580px;
            height: 580px;

            right: -230px;
            top: -240px;

            border-radius: 50%;

            border:
                1px solid rgba(255,255,255,.08);

            background:
                rgba(145,200,62,.07);
        }


        .login-brand::after {

            content: "";

            position: absolute;

            width: 330px;
            height: 330px;

            left: -170px;
            bottom: -170px;

            border-radius: 50%;

            border:
                1px solid rgba(255,255,255,.06);

            background:
                rgba(35,148,84,.14);
        }


        .brand-content {

            position: relative;

            z-index: 2;

            width: 100%;

            max-width: 590px;
        }


        /* ============================================================
           LOGO
        ============================================================ */

        .brand-logo-large {

            width: 108px;
            height: 108px;

            display: flex;

            align-items: center;
            justify-content: center;

            margin-bottom: 28px;

            padding: 8px;

            border-radius: 25px;

            background: #fff;

            box-shadow:
                0 20px 45px rgba(0,0,0,.25);

            overflow: hidden;
        }


        .brand-logo-large img {

            display: block;

            width: 100%;
            height: 100%;

            object-fit: contain;
        }


        /* ============================================================
           KICKER
        ============================================================ */

        .brand-kicker {

            display: inline-flex;

            align-items: center;

            gap: 9px;

            margin-bottom: 13px;

            color: #c3d8c9;

            font-size: 10px;

            font-weight: 900;

            text-transform: uppercase;

            letter-spacing: .15em;
        }


        .brand-kicker::before {

            content: "";

            width: 7px;
            height: 7px;

            border-radius: 50%;

            background: var(--lime);

            box-shadow:
                0 0 0 5px rgba(145,200,62,.15);
        }


        .brand-content h1 {

            margin: 0;

            font-size:
                clamp(40px, 4.4vw, 60px);

            line-height: 1.02;

            letter-spacing: -.055em;

            font-weight: 850;
        }


        .brand-description {

            max-width: 510px;

            margin: 20px 0 0;

            color: #c6d9cc;

            font-size: 13px;

            line-height: 1.75;
        }


        /* ============================================================
           FEATURE CARDS
        ============================================================ */

        .brand-features {

            display: grid;

            grid-template-columns:
                repeat(3, minmax(0, 1fr));

            gap: 11px;

            max-width: 530px;

            margin-top: 32px;
        }


        .brand-feature {

            min-width: 0;

            padding: 15px;

            border:
                1px solid rgba(255,255,255,.10);

            border-radius: 14px;

            background:
                rgba(255,255,255,.055);

            backdrop-filter:
                blur(10px);
        }


        .brand-feature strong {

            display: block;

            font-size: 11px;

            font-weight: 850;
        }


        .brand-feature span {

            display: block;

            margin-top: 5px;

            color: #a9c3b1;

            font-size: 9px;

            line-height: 1.45;
        }


        /* ============================================================
           RIGHT LOGIN AREA
        ============================================================ */

        .login-area {

            min-width: 0;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 35px;

            background:
                rgba(248,250,248,.92);
        }


        /* ============================================================
           LOGIN CARD
        ============================================================ */

        .login-card {

            width: 100%;

            max-width: 440px;

            padding: 34px;

            border:
                1px solid var(--border);

            border-radius: 24px;

            background: var(--card);

            box-shadow: var(--shadow);
        }


        .login-card-header {

            margin-bottom: 25px;
        }


        .login-card-header h2 {

            margin: 0;

            font-size: 25px;

            line-height: 1.2;

            letter-spacing: -.045em;
        }


        .login-card-header p {

            margin: 8px 0 0;

            color: var(--muted);

            font-size: 10px;

            line-height: 1.65;
        }


        /* ============================================================
           ERROR
        ============================================================ */

        .login-error {

            display: flex;

            align-items: flex-start;

            gap: 10px;

            margin-bottom: 18px;

            padding: 12px 13px;

            border:
                1px solid #efc2c2;

            border-radius: 11px;

            background: var(--danger-bg);

            color: var(--danger);

            font-size: 10px;

            line-height: 1.5;
        }


        .login-error-icon {

            flex: 0 0 auto;

            width: 20px;
            height: 20px;

            display: grid;

            place-items: center;

            border-radius: 50%;

            background: var(--danger);

            color: #fff;

            font-size: 10px;

            font-weight: 900;
        }


        /* ============================================================
           FORM
        ============================================================ */

        .login-form {

            display: grid;

            gap: 17px;
        }


        .login-field {

            min-width: 0;
        }


        .login-field label {

            display: block;

            margin-bottom: 8px;

            color: #435148;

            font-size: 9px;

            font-weight: 850;

            letter-spacing: .06em;

            text-transform: uppercase;
        }


        /* ============================================================
           CABANG SECTION
        ============================================================ */

        .branch-selector {

            position: relative;

            width: 100%;
        }


        .branch-selector-head {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 10px;

            margin-bottom: 9px;
        }


        .branch-selector-title {

            display: flex;

            align-items: center;

            gap: 7px;

            color: #3f4e44;

            font-size: 9px;

            font-weight: 800;

            text-transform: uppercase;

            letter-spacing: .06em;
        }


        .branch-selector-title-icon {

            width: 22px;
            height: 22px;

            display: grid;

            place-items: center;

            border-radius: 7px;

            background: #eaf5ed;

            color: var(--green-700);

            font-size: 11px;
        }


        .branch-status {

            display: inline-flex;

            align-items: center;

            gap: 5px;

            padding: 5px 8px;

            border-radius: 999px;

            background: #edf8ef;

            color: #267344;

            font-size: 8px;

            font-weight: 800;
        }


        .branch-status::before {

            content: "";

            width: 5px;
            height: 5px;

            border-radius: 50%;

            background: #39a95d;

            box-shadow:
                0 0 0 3px rgba(57,169,93,.12);
        }


        /* ============================================================
           CUSTOM BRANCH SELECT
        ============================================================ */

        .branch-select-wrap {

            position: relative;

            width: 100%;
        }


        .branch-select {

            position: relative;

            width: 100%;

            min-height: 68px;

            padding:
                0 44px 0 15px;

            border:
                1px solid var(--border);

            border-radius: 14px;

            outline: none;

            background:
                linear-gradient(
                    180deg,
                    #ffffff,
                    #fbfdfb
                );

            color: var(--text);

            font-size: 11px;

            font-weight: 700;

            cursor: pointer;

            appearance: none;

            -webkit-appearance: none;

            transition:
                border-color .18s ease,
                box-shadow .18s ease,
                transform .18s ease;
        }


        .branch-select:hover {

            border-color: #b8cfbd;

            background: #fff;
        }


        .branch-select:focus {

            border-color: #7eb393;

            box-shadow:
                0 0 0 4px rgba(23,111,61,.08);
        }


        .branch-select-arrow {

            position: absolute;

            right: 15px;

            top: 50%;

            width: 24px;
            height: 24px;

            transform:
                translateY(-50%);

            display: grid;

            place-items: center;

            border-radius: 7px;

            background: #eef5ef;

            color: var(--green-700);

            font-size: 11px;

            pointer-events: none;
        }


        /* ============================================================
           BRANCH INFO
        ============================================================ */

        .branch-info {

            display: flex;

            align-items: center;

            gap: 9px;

            margin-top: 8px;

            padding:
                9px 11px;

            border:
                1px solid #edf1ed;

            border-radius: 10px;

            background: #fafcfa;

            color: #7b877f;

            font-size: 8px;

            line-height: 1.45;
        }


        .branch-info-icon {

            flex: 0 0 auto;

            width: 25px;
            height: 25px;

            display: grid;

            place-items: center;

            border-radius: 8px;

            background: #edf6ef;

            color: var(--green-700);

            font-size: 11px;
        }


        .branch-info strong {

            display: block;

            margin-bottom: 2px;

            color: #526057;

            font-size: 8px;

            font-weight: 850;
        }


        .branch-info span {

            display: block;

            color: #8b968f;

            font-size: 8px;
        }


        /* ============================================================
           EMPTY BRANCH
        ============================================================ */

        .branch-empty {

            padding: 14px;

            border:
                1px solid #efd1d1;

            border-radius: 12px;

            background: #fff6f6;

            color: #a54b4b;

            font-size: 9px;

            line-height: 1.5;
        }


        /* ============================================================
           INPUT
        ============================================================ */

        .login-input-wrap {

            position: relative;
        }


        .login-input {

            width: 100%;

            height: 48px;

            padding:
                0 14px;

            border:
                1px solid var(--border);

            border-radius: 11px;

            outline: none;

            background: #fff;

            color: var(--text);

            font-size: 11px;

            transition:
                border-color .18s ease,
                box-shadow .18s ease;
        }


        .login-input::placeholder {

            color: #a2ada5;
        }


        .login-input:hover {

            border-color: #c4d4c8;
        }


        .login-input:focus {

            border-color: #7eb393;

            box-shadow:
                0 0 0 4px rgba(23,111,61,.08);
        }


        /* ============================================================
           PASSWORD
        ============================================================ */

        .password-input {

            padding-right: 90px;
        }


        .password-toggle {

            position: absolute;

            right: 6px;
            top: 6px;

            height: 36px;

            padding:
                0 10px;

            border: 0;

            border-radius: 8px;

            background: #f0f5f1;

            color: #5d6b62;

            font-size: 8px;

            font-weight: 850;

            cursor: pointer;

            transition: .15s;
        }


        .password-toggle:hover {

            background: #e4eee6;

            color: var(--green-700);
        }


        /* ============================================================
           SUBMIT
        ============================================================ */

        .login-submit {

            width: 100%;

            height: 49px;

            margin-top: 3px;

            border: 0;

            border-radius: 11px;

            background:
                linear-gradient(
                    135deg,
                    var(--green-700),
                    var(--green-500)
                );

            color: #fff;

            font-size: 11px;

            font-weight: 850;

            cursor: pointer;

            box-shadow:
                0 12px 25px rgba(23,111,61,.20);

            transition:
                transform .18s ease,
                box-shadow .18s ease,
                opacity .18s ease;
        }


        .login-submit:hover {

            transform:
                translateY(-1px);

            box-shadow:
                0 15px 30px rgba(23,111,61,.25);
        }


        .login-submit:active {

            transform:
                translateY(0);
        }


        .login-submit:disabled {

            cursor: not-allowed;

            opacity: .65;

            transform: none;

            box-shadow: none;
        }


        /* ============================================================
           FOOTER
        ============================================================ */

        .login-footer {

            margin-top: 22px;

            padding-top: 17px;

            border-top:
                1px solid var(--border);

            text-align: center;

            color: #89948d;

            font-size: 8px;

            line-height: 1.65;
        }


        .login-footer strong {

            color: var(--green-700);

            font-weight: 850;
        }


        /* ============================================================
           RESPONSIVE
        ============================================================ */

        @media (max-width: 1000px) {

            .login-wrapper {

                grid-template-columns:
                    minmax(0, 1fr)
                    minmax(390px, 450px);
            }


            .login-brand {

                padding: 50px;
            }

        }


        @media (max-width: 850px) {

            .login-wrapper {

                grid-template-columns: 1fr;
            }


            .login-brand {

                min-height: 310px;

                padding:
                    45px 35px;

                align-items: center;
            }


            .brand-features {

                max-width: 500px;
            }


            .login-area {

                min-height:
                    calc(100vh - 310px);

                padding:
                    30px 20px;
            }

        }


        @media (max-width: 560px) {

            .login-brand {

                min-height: 260px;

                padding:
                    30px 22px;
            }


            .brand-logo-large {

                width: 78px;
                height: 78px;

                margin-bottom: 18px;

                padding: 5px;

                border-radius: 18px;
            }


            .brand-content h1 {

                font-size: 30px;
            }


            .brand-description {

                margin-top: 12px;

                font-size: 10px;
            }


            .brand-features {

                display: none;
            }


            .login-area {

                padding:
                    20px 14px;
            }


            .login-card {

                padding:
                    25px 19px;

                border-radius: 19px;
            }


            .login-card-header h2 {

                font-size: 21px;
            }


            .branch-select {

                min-height: 62px;

                font-size: 10px;
            }

        }

    </style>

</head>


<body>


<div class="login-wrapper">


    <!-- =========================================================
         BRANDING
    ========================================================== -->

    <section class="login-brand">

        <div class="brand-content">


            <div class="brand-logo-large">

                <img
                    src="<?= $base_url ?>/assets/logo-pij.jpeg"
                    alt="Logo Toko Pertanian Indah Jaya"
                >

            </div>


            <div class="brand-kicker">

                Sistem Manajemen Toko

            </div>


            <h1>

                Pertanian<br>
                Indah Jaya

            </h1>


            <p class="brand-description">

                Sistem kasir dan manajemen toko pertanian
                untuk membantu pengelolaan transaksi,
                produk, stok, kas, pengguna, dan laporan
                secara lebih teratur.

            </p>


            <div class="brand-features">


                <div class="brand-feature">

                    <strong>
                        Kasir
                    </strong>

                    <span>
                        Transaksi penjualan
                    </span>

                </div>


                <div class="brand-feature">

                    <strong>
                        Stok
                    </strong>

                    <span>
                        Produk & persediaan
                    </span>

                </div>


                <div class="brand-feature">

                    <strong>
                        Laporan
                    </strong>

                    <span>
                        Rekap transaksi
                    </span>

                </div>


            </div>


        </div>

    </section>


    <!-- =========================================================
         LOGIN
    ========================================================== -->

    <main class="login-area">


        <div class="login-card">


            <div class="login-card-header">

                <h2>
                    Selamat Datang
                </h2>

                <p>
                    Pilih cabang operasional terlebih dahulu,
                    kemudian masuk menggunakan akun Anda.
                </p>

            </div>


            <?php if ($error !== ""): ?>

                <div
                    class="login-error"
                    role="alert"
                    aria-live="assertive"
                >

                    <span class="login-error-icon">
                        !
                    </span>

                    <span>
                        <?= esc($error) ?>
                    </span>

                </div>

            <?php endif; ?>


            <form
                method="POST"
                class="login-form"
                autocomplete="on"
                id="loginForm"
            >


                <!-- =================================================
                     CABANG
                ================================================== -->

                <div class="login-field">

                    <div class="branch-selector">


                        <div class="branch-selector-head">

                            <div class="branch-selector-title">

                                <span class="branch-selector-title-icon">
                                    â–¦
                                </span>

                                <span>
                                    Pilih Cabang
                                </span>

                            </div>


                            <?php if (!empty($branches)): ?>

                                <span class="branch-status">
                                    Aktif
                                </span>

                            <?php endif; ?>

                        </div>


                        <?php if (!empty($branches)): ?>


                            <div class="branch-select-wrap">

                                <select
                                    id="branch_id"
                                    name="branch_id"
                                    class="branch-select"
                                    required
                                    aria-label="Pilih cabang"
                                >

                                    <option value="">
                                        Pilih lokasi cabang Anda
                                    </option>


                                    <?php foreach ($branches as $branch): ?>

                                        <option
                                            value="<?= (int) $branch["id"] ?>"
                                            <?= $selected_branch_id === (int) $branch["id"]
                                                ? "selected"
                                                : "" ?>
                                        >
                                            <?= esc($branch["name"]) ?>
                                            <?php if (!empty($branch["address"])): ?>
                                                â€” <?= esc($branch["address"]) ?>
                                            <?php endif; ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>


                                <span
                                    class="branch-select-arrow"
                                    aria-hidden="true"
                                >
                                    â–¼
                                </span>

                            </div>


                            <div
                                class="branch-info"
                                id="branchInfo"
                            >

                                <span
                                    class="branch-info-icon"
                                    id="branchInfoIcon"
                                >
                                    âŒ‚
                                </span>


                                <div>

                                    <strong id="branchInfoName">
                                        Belum ada cabang dipilih
                                    </strong>

                                    <span id="branchInfoDetail">
                                        Pilih salah satu cabang untuk melanjutkan.
                                    </span>

                                </div>

                            </div>


                        <?php else: ?>


                            <div class="branch-empty">

                                Belum ada cabang aktif yang tersedia.
                                Silakan hubungi administrator untuk
                                mengaktifkan cabang terlebih dahulu.

                            </div>


                            <select
                                id="branch_id"
                                name="branch_id"
                                style="display:none"
                            >

                                <option value="">
                                    Tidak tersedia
                                </option>

                            </select>

                        <?php endif; ?>


                    </div>

                </div>


                <!-- =================================================
                     USERNAME
                ================================================== -->

                <div class="login-field">

                    <label for="username">
                        Username
                    </label>

                    <div class="login-input-wrap">

                        <input
                            id="username"
                            type="text"
                            name="username"
                            class="login-input"
                            value="<?= esc($username) ?>"
                            placeholder="Masukkan username"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false"
                            required
                        >

                    </div>

                </div>


                <!-- =================================================
                     PASSWORD
                ================================================== -->

                <div class="login-field">

                    <label for="password">
                        Password
                    </label>

                    <div class="login-input-wrap">

                        <input
                            id="password"
                            type="password"
                            name="password"
                            class="login-input password-input"
                            placeholder="Masukkan password"
                            autocomplete="current-password"
                            required
                        >


                        <button
                            type="button"
                            class="password-toggle"
                            id="passwordToggle"
                            onclick="togglePassword()"
                        >
                            Lihat
                        </button>

                    </div>

                </div>


                <!-- =================================================
                     SUBMIT
                ================================================== -->

                <button
                    type="submit"
                    class="login-submit"
                    id="loginSubmit"
                    <?= empty($branches) ? "disabled" : "" ?>
                >
                    Masuk ke Sistem
                </button>


            </form>


            <div class="login-footer">

                Sistem Kasir & Manajemen Toko<br>

                <strong>
                    Pertanian Indah Jaya
                </strong>

            </div>


        </div>


    </main>


</div>


<script>


/*
|--------------------------------------------------------------------------
| DATA CABANG DARI PHP
|--------------------------------------------------------------------------
|
| Dipakai hanya untuk menampilkan informasi cabang
| ketika user memilih cabang.
|
*/

const branchData = <?= json_encode(
    array_map(
        function ($branch) {

            return [

                "id" =>
                    (int) $branch["id"],

                "name" =>
                    (string) ($branch["name"] ?? ""),

                "address" =>
                    (string) ($branch["address"] ?? ""),

                "phone" =>
                    (string) ($branch["phone"] ?? ""),

                "status" =>
                    (string) ($branch["status"] ?? "")

            ];

        },
        $branches
    ),
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;


/*
|--------------------------------------------------------------------------
| TOGGLE PASSWORD
|--------------------------------------------------------------------------
*/

function togglePassword()
{

    const password =
        document.getElementById("password");

    const button =
        document.getElementById("passwordToggle");


    if (!password || !button) {
        return;
    }


    if (password.type === "password") {

        password.type = "text";

        button.textContent =
            "Sembunyikan";

    } else {

        password.type = "password";

        button.textContent =
            "Lihat";

    }

}


/*
|--------------------------------------------------------------------------
| TAMPILKAN INFORMASI CABANG
|--------------------------------------------------------------------------
*/

function updateBranchInfo()
{

    const select =
        document.getElementById("branch_id");

    const nameElement =
        document.getElementById("branchInfoName");

    const detailElement =
        document.getElementById("branchInfoDetail");

    const iconElement =
        document.getElementById("branchInfoIcon");


    if (
        !select ||
        !nameElement ||
        !detailElement
    ) {
        return;
    }


    const selectedId =
        Number(select.value);


    const branch =
        branchData.find(
            function (item) {

                return Number(item.id) ===
                    selectedId;

            }
        );


    if (!branch) {

        nameElement.textContent =
            "Belum ada cabang dipilih";

        detailElement.textContent =
            "Pilih salah satu cabang untuk melanjutkan.";

        if (iconElement) {
            iconElement.textContent = "âŒ‚";
        }

        return;
    }


    nameElement.textContent =
        branch.name ||
        "Cabang";


    let detail = "";


    if (branch.address) {

        detail =
            branch.address;

    }


    if (branch.phone) {

        if (detail !== "") {
            detail += " â€¢ ";
        }

        detail +=
            branch.phone;
    }


    if (detail === "") {

        detail =
            "Cabang aktif dan siap digunakan.";
    }


    detailElement.textContent =
        detail;


    if (iconElement) {
        iconElement.textContent = "âœ“";
    }

}


/*
|--------------------------------------------------------------------------
| DOM READY
|--------------------------------------------------------------------------
*/

document.addEventListener(
    "DOMContentLoaded",
    function ()
    {

        const form =
            document.getElementById("loginForm");

        const submitButton =
            document.getElementById("loginSubmit");

        const branchSelect =
            document.getElementById("branch_id");


        /*
        |--------------------------------------------------------------------------
        | CABANG
        |--------------------------------------------------------------------------
        */

        if (branchSelect) {

            branchSelect.addEventListener(
                "change",
                updateBranchInfo
            );


            /*
             * Tampilkan cabang yang sudah dipilih
             * jika halaman kembali karena error login.
             */

            updateBranchInfo();
        }


        /*
        |--------------------------------------------------------------------------
        | SUBMIT LOGIN
        |--------------------------------------------------------------------------
        */

        if (!form || !submitButton) {
            return;
        }


        form.addEventListener(
            "submit",
            function ()
            {

                const branch =
                    document.getElementById("branch_id");


                if (
                    !branch ||
                    !branch.value ||
                    Number(branch.value) <= 0
                ) {

                    return;
                }


                submitButton.disabled = true;

                submitButton.textContent =
                    "Memproses...";

            }
        );

    }
);

</script>


</body>

</html>

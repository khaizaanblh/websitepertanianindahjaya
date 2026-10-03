<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$base_url = "/kasir_pertanian";

$current_page = basename($_SERVER['PHP_SELF']);

$role = $_SESSION['user']['role'] ?? '';
$user_name = $_SESSION['user']['name'] ?? 'User';

/*
|--------------------------------------------------------------------------
| CABANG AKTIF
|--------------------------------------------------------------------------
*/
$branch_name = $_SESSION['branch']['name'] ?? 'Cabang Utama';

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
        Pertanian Indah Jaya
    </title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= $base_url ?>/assets/style.css"
    >

    <style>

        /* =====================================================
           SIDEBAR BRAND
        ===================================================== */

        .brand {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 4px 2px 22px;
            position: relative;
        }

        .brand-logo {
            width: 58px;
            height: 58px;
            min-width: 58px;
            border-radius: 16px;
            background: #ffffff;
            border: 1px solid rgba(255,255,255,.22);
            box-shadow: 0 8px 22px rgba(0,0,0,.16);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .brand-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .brand-text {
            min-width: 0;
        }

        .brand-text strong {
            display: block;
            color: #ffffff;
            font-size: 15px;
            line-height: 1.25;
            font-weight: 800;
            letter-spacing: -.02em;
        }

        .brand-text small {
            display: block;
            margin-top: 5px;
            color: rgba(255,255,255,.62);
            font-size: 10px;
            line-height: 1.3;
            font-weight: 500;
        }


        /* =====================================================
           SIDEBAR TOGGLE
        ===================================================== */

        .sidebar-toggle {
            position: absolute;
            top: 18px;
            right: -14px;
            width: 30px;
            height: 30px;
            padding: 0;
            border: 1px solid #dfe8e2;
            border-radius: 50%;
            background: #ffffff;
            color: #176f3d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 800;
            line-height: 1;
            cursor: pointer;
            z-index: 100;
            box-shadow: 0 5px 16px rgba(23,60,36,.16);
            transition:
                background .2s ease,
                color .2s ease,
                transform .2s ease,
                box-shadow .2s ease;
        }

        .sidebar-toggle:hover {
            background: #176f3d;
            color: #ffffff;
            transform: scale(1.06);
            box-shadow: 0 7px 20px rgba(23,60,36,.22);
        }


        /* =====================================================
           PROFILE USER
        ===================================================== */

        .profile {
            margin-top: 4px;
            margin-bottom: 20px;
            padding: 16px;
            border-radius: 16px;

            background:
                linear-gradient(
                    135deg,
                    rgba(255,255,255,.09),
                    rgba(255,255,255,.04)
                );

            border: 1px solid rgba(255,255,255,.08);

            box-shadow:
                inset 0 1px 0 rgba(255,255,255,.04);
        }

        .profile-name {
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .profile-role {
            display: inline-flex;
            margin-top: 8px;
            padding: 5px 9px;
            border-radius: 20px;
            background: rgba(145,200,62,.16);
            color: #b9dc88;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .08em;
        }

        /* CABANG */

        .profile-branch {
            display: block;
            margin-top: 10px;
            color: rgba(255,255,255,.72);
            font-size: 10px;
            line-height: 1.4;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .profile-branch strong {
            color: #ffffff;
            font-weight: 700;
        }


        /* =====================================================
           SIDEBAR MENU
        ===================================================== */

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 48px;
            padding: 0 15px;
            border-radius: 13px;
            color: rgba(255,255,255,.72);
            text-decoration: none;
            font-size: 12px;
            font-weight: 650;

            transition:
                background .18s ease,
                color .18s ease,
                transform .18s ease;
        }

        .sidebar-menu a span {
            width: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 22px;
            font-size: 15px;
            color: rgba(255,255,255,.62);
        }

        .sidebar-menu a:hover {
            color: #ffffff;
            background: rgba(255,255,255,.07);
            transform: translateX(2px);
        }

        .sidebar-menu a:hover span {
            color: #ffffff;
        }

        .sidebar-menu a.active {
            color: #173c24;
            background: #ffffff;
            box-shadow: 0 8px 22px rgba(0,0,0,.12);
        }

        .sidebar-menu a.active span {
            color: #176f3d;
        }


        /* =====================================================
           DIVIDER
        ===================================================== */

        .menu-divider {
            height: 1px;
            margin: 15px 10px;
            background: rgba(255,255,255,.10);
        }


        /* =====================================================
           LOGOUT
        ===================================================== */

        .sidebar-menu .logout-link {
            color: #f1aaa4;
        }

        .sidebar-menu .logout-link span {
            color: #f1aaa4;
        }

        .sidebar-menu .logout-link:hover {
            color: #ffffff;
            background: rgba(220,70,60,.12);
        }


        /* =====================================================
           CABANG BADGE TOPBAR
        ===================================================== */

        .branch-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            padding: 6px 10px;
            border-radius: 20px;
            background: #edf7ef;
            border: 1px solid #d8e9dc;
            color: #176f3d;
            font-size: 10px;
            font-weight: 800;
        }

        .branch-badge span {
            font-size: 12px;
        }


        /* =====================================================
           SIDEBAR COLLAPSED
        ===================================================== */

        .app.sidebar-collapsed .sidebar {
            width: 86px;
            min-width: 86px;
        }

        .app.sidebar-collapsed .brand {
            justify-content: center;
            padding-left: 0;
            padding-right: 0;
        }

        .app.sidebar-collapsed .brand-text {
            display: none;
        }

        .app.sidebar-collapsed .profile {
            padding: 10px 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border-color: transparent;
            box-shadow: none;
        }

        .app.sidebar-collapsed .profile-name,
        .app.sidebar-collapsed .profile-role,
        .app.sidebar-collapsed .profile-branch {
            display: none;
        }

        .app.sidebar-collapsed .sidebar-menu {
            align-items: center;
        }

        .app.sidebar-collapsed .sidebar-menu a {
            width: 48px;
            min-height: 48px;
            padding: 0;
            justify-content: center;
            gap: 0;
            font-size: 0;
        }

        .app.sidebar-collapsed .sidebar-menu a:hover {
            transform: none;
        }

        .app.sidebar-collapsed .sidebar-menu a span {
            width: 22px;
            min-width: 22px;
            font-size: 16px;
        }

        .app.sidebar-collapsed .menu-divider {
            width: 48px;
            margin-left: 0;
            margin-right: 0;
        }

        .app.sidebar-collapsed .sidebar-toggle {
            right: -14px;
        }


        /* =====================================================
           MAIN AREA
        ===================================================== */

        .main {
            min-width: 0;
            transition:
                margin-left .25s ease,
                width .25s ease;
        }

        .app.sidebar-collapsed {
            grid-template-columns: 86px minmax(0, 1fr) !important;
        }


        /* =====================================================
           TOPBAR
        ===================================================== */

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .topbar h1 {
            margin: 0;
            color: #17241b;
            font-size: 25px;
            font-weight: 800;
            letter-spacing: -.04em;
        }

        .topbar p {
            margin: 6px 0 0;
            color: #718078;
            font-size: 12px;
        }


        /* =====================================================
           TOP USER
        ===================================================== */

        .top-user {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 10px;
            border: 1px solid #e0e8e2;
            border-radius: 13px;
            background: #ffffff;
            box-shadow: 0 5px 18px rgba(23,60,36,.05);
        }

        .top-avatar {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;

            background:
                linear-gradient(
                    135deg,
                    #176f3d,
                    #2b9557
                );

            color: #ffffff;
            font-size: 14px;
            font-weight: 800;
        }

        .top-user strong {
            display: block;
            color: #17241b;
            font-size: 11px;
            font-weight: 800;
        }

        .top-user small {
            display: block;
            margin-top: 2px;
            color: #7b8981;
            font-size: 9px;
        }


        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 900px) {

            .topbar {
                align-items: flex-start;
            }

            .top-user {
                display: none;
            }

        }

        @media (max-width: 620px) {

            .brand-logo {
                width: 50px;
                height: 50px;
                min-width: 50px;
            }

            .brand-text strong {
                font-size: 13px;
            }

            .brand-text small {
                font-size: 9px;
            }

            .topbar h1 {
                font-size: 21px;
            }

            .app.sidebar-collapsed .sidebar {
                width: 76px;
                min-width: 76px;
            }

            .app.sidebar-collapsed {
                grid-template-columns: 76px minmax(0, 1fr) !important;
            }

        }

    </style>

</head>

<body>

<div class="app" id="app">


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside class="sidebar" id="sidebar">


        <!-- =================================================
             TOMBOL KECILKAN SIDEBAR
        ================================================== -->

        <button
            type="button"
            class="sidebar-toggle"
            id="sidebarToggle"
            aria-label="Kecilkan menu"
            aria-expanded="true"
            title="Kecilkan menu"
        >
            ‹
        </button>


        <!-- =================================================
             BRAND / LOGO TOKO
        ================================================== -->

        <div class="brand">

            <div class="brand-logo">

                <img
                    src="<?= $base_url ?>/assets/logo-pij.jpeg"
                    alt="Logo Pertanian Indah Jaya"
                    onerror="this.style.display='none';"
                >

            </div>

            <div class="brand-text">

                <strong>
                    Pertanian Indah Jaya
                </strong>

                <small>
                    Sistem Kasir Toko
                </small>

            </div>

        </div>


        <!-- =================================================
             PROFILE USER
        ================================================== -->

        <div class="profile">

            <div class="profile-name">
                <?= htmlspecialchars($user_name) ?>
            </div>

            <div class="profile-role">
                <?= strtoupper(htmlspecialchars($role)) ?>
            </div>

            <div class="profile-branch">
                Cabang:
                <strong>
                    <?= htmlspecialchars($branch_name) ?>
                </strong>
            </div>

        </div>


        <!-- =================================================
             MENU
        ================================================== -->

        <nav class="sidebar-menu">


            <!-- =================================================
                 DASHBOARD
            ================================================== -->

            <a
                href="<?= $base_url ?>/index.php"
                class="<?= $current_page === 'index.php' ? 'active' : '' ?>"
                title="Dashboard"
            >

                <span>▦</span>

                Dashboard

            </a>


            <?php if ($role === 'admin'): ?>

    <!-- PRODUK -->

    <a
        href="<?= $base_url ?>/admin/products.php"
        class="<?= $current_page === 'products.php' ? 'active' : '' ?>"
    >

        <span>▣</span>

        Produk & Stok

    </a>


    <!-- PENGGUNA -->

    <a
        href="<?= $base_url ?>/admin/users.php"
        class="<?= $current_page === 'users.php' ? 'active' : '' ?>"
    >

        <span>♙</span>

        Pengguna

    </a>


    <!-- CABANG -->

    <a
        href="<?= $base_url ?>/admin/branches.php"
        class="<?= $current_page === 'branches.php' ? 'active' : '' ?>"
    >

        <span>⌂</span>

        Manajemen Cabang

    </a>


    <!-- KAS -->

    <a
        href="<?= $base_url ?>/admin/cash.php"
        class="<?= $current_page === 'cash.php' ? 'active' : '' ?>"
    >

        <span>Rp</span>

        Manajemen Kas

    </a>


    <!-- LAPORAN -->

    <a
        href="<?= $base_url ?>/admin/reports.php"
        class="<?= $current_page === 'reports.php' ? 'active' : '' ?>"
    >

        <span>▤</span>

        Laporan & Transaksi

    </a>

<?php endif; ?>


            <?php if ($role === 'kasir'): ?>


                <!-- =================================================
                     KASIR
                ================================================== -->

                <a
                    href="<?= $base_url ?>/kasir/pos.php"
                    class="<?= $current_page === 'pos.php' ? 'active' : '' ?>"
                    title="Kasir"
                >

                    <span>🛒</span>

                    Kasir

                </a>


                <!-- =================================================
                     MANAJEMEN KAS
                ================================================== -->

                <a
                    href="<?= $base_url ?>/admin/cash.php"
                    class="<?= $current_page === 'cash.php' ? 'active' : '' ?>"
                    title="Manajemen Kas"
                >

                    <span>Rp</span>

                    Manajemen Kas

                </a>


                <!-- =================================================
                     RIWAYAT
                ================================================== -->

                <a
                    href="<?= $base_url ?>/kasir/history.php"
                    class="<?= $current_page === 'history.php' ? 'active' : '' ?>"
                    title="Riwayat Transaksi"
                >

                    <span>↺</span>

                    Riwayat Transaksi

                </a>


                <!-- =================================================
                     SHIFT
                ================================================== -->

                <a
                    href="<?= $base_url ?>/admin/shifts.php"
                    class="<?= $current_page === 'shifts.php' ? 'active' : '' ?>"
                    title="Shift & Rekap"
                >

                    <span>◷</span>

                    Shift & Rekap

                </a>


            <?php endif; ?>


            <!-- =================================================
                 DIVIDER
            ================================================== -->

            <div class="menu-divider"></div>


            <!-- =================================================
                 LOGOUT
            ================================================== -->

            <a
                href="<?= $base_url ?>/logout.php"
                class="logout-link"
                title="Logout"
            >

                <span>↪</span>

                Logout

            </a>


        </nav>

    </aside>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main">


        <!-- =================================================
             TOPBAR
        ================================================== -->

        <header class="topbar">

            <div>

                <h1>
                    Pertanian Indah Jaya
                </h1>

                <p>
                    Sistem Manajemen Toko Pertanian
                </p>

                <div class="branch-badge">

                    <span>⌂</span>

                    Cabang Aktif:
                    <?= htmlspecialchars($branch_name) ?>

                </div>

            </div>


            <!-- =================================================
                 USER TOP RIGHT
            ================================================== -->

            <div class="top-user">

                <div class="top-avatar">

                    <?= strtoupper(
                        htmlspecialchars(
                            substr($user_name, 0, 1)
                        )
                    ) ?>

                </div>

                <div>

                    <strong>
                        <?= htmlspecialchars($user_name) ?>
                    </strong>

                    <small>
                        <?= ucfirst(htmlspecialchars($role)) ?>
                        •
                        <?= htmlspecialchars($branch_name) ?>
                    </small>

                </div>

            </div>

        </header>


        <!-- =================================================
             PAGE CONTENT
        ================================================== -->

        <div class="content">


<script>

(function () {

    const app =
        document.querySelector('.app');

    const button =
        document.getElementById('sidebarToggle');

    if (!app || !button) {
        return;
    }


    /* =====================================================
       STORAGE
    ===================================================== */

    const storageKey =
        'pij_sidebar_collapsed';


    /* =====================================================
       UPDATE BUTTON
    ===================================================== */

    function updateButton(collapsed)
    {

        button.textContent =
            collapsed ? '›' : '‹';

        button.setAttribute(
            'aria-expanded',
            collapsed ? 'false' : 'true'
        );

        button.setAttribute(
            'aria-label',
            collapsed
                ? 'Buka menu'
                : 'Kecilkan menu'
        );

        button.setAttribute(
            'title',
            collapsed
                ? 'Buka menu'
                : 'Kecilkan menu'
        );

    }


    /* =====================================================
       APPLY STATE
    ===================================================== */

    function applySidebarState(collapsed)
    {

        app.classList.toggle(
            'sidebar-collapsed',
            collapsed
        );

        updateButton(collapsed);

    }


    /* =====================================================
       LOAD STATE
    ===================================================== */

    let savedState = false;

    try {

        savedState =
            localStorage.getItem(
                storageKey
            ) === '1';

    } catch (error) {

        savedState = false;

    }


    applySidebarState(savedState);


    /* =====================================================
       TOGGLE
    ===================================================== */

    button.addEventListener(
        'click',
        function () {

            const collapsed =
                !app.classList.contains(
                    'sidebar-collapsed'
                );

            applySidebarState(
                collapsed
            );


            try {

                localStorage.setItem(
                    storageKey,
                    collapsed ? '1' : '0'
                );

            } catch (error) {

                // localStorage tidak tersedia.
                // Sidebar tetap dapat digunakan.

            }

        }
    );

})();

</script>

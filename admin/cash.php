<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

/*
|--------------------------------------------------------------------------
| AKSES
|--------------------------------------------------------------------------
| Admin dan Kasir dapat mengakses Manajemen Kas.
*/
require_kasir();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| DATA USER LOGIN
|--------------------------------------------------------------------------
*/

$user_id = (int) ($_SESSION['user']['id'] ?? 0);
$user_role = $_SESSION['user']['role'] ?? 'kasir';

/*
|--------------------------------------------------------------------------
| FUNGSI
|--------------------------------------------------------------------------
*/

function rupiah($number)
{
    return 'Rp ' . number_format((float) $number, 0, ',', '.');
}

function redirect_cash($status, $message, $branch_id = null)
{
    $url = "/kasir_pertanian/admin/cash.php";

    $params = [
        $status => $message
    ];

    if ($branch_id !== null && (int) $branch_id > 0) {
        $params['branch_id'] = (int) $branch_id;
    }

    header($url . '?' . http_build_query($params));
    exit;
}

/*
|--------------------------------------------------------------------------
| VALIDASI USER LOGIN
|--------------------------------------------------------------------------
*/

if ($user_id <= 0) {
    header("Location: /kasir_pertanian/");
    exit;
}

/*
|--------------------------------------------------------------------------
| AMBIL CABANG USER
|--------------------------------------------------------------------------
| Jangan hanya percaya $_SESSION['user']['branch_id'].
| Ambil ulang dari database agar lebih aman.
|--------------------------------------------------------------------------
*/

$user_branch_id = 0;
$user_branch_name = '';

$stmt_user = $conn->prepare("
    SELECT
        u.branch_id,
        b.name AS branch_name
    FROM users u
    LEFT JOIN branches b
        ON b.id = u.branch_id
    WHERE u.id = ?
    LIMIT 1
");

if ($stmt_user) {

    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();

    $result_user = $stmt_user->get_result();

    if ($result_user && $result_user->num_rows > 0) {

        $user_data = $result_user->fetch_assoc();

        $user_branch_id = (int) ($user_data['branch_id'] ?? 0);
        $user_branch_name = $user_data['branch_name'] ?? '';

    }

    $stmt_user->close();
}

/*
|--------------------------------------------------------------------------
| USER WAJIB MEMILIKI CABANG
|--------------------------------------------------------------------------
*/

if ($user_branch_id <= 0) {

    require_once __DIR__ . "/../includes/header.php";

    ?>

    <div style="
        max-width:700px;
        margin:40px auto;
        background:#fff;
        border:1px solid #e2e8e3;
        border-radius:16px;
        padding:25px;
        box-shadow:0 8px 24px rgba(20,50,30,.06);
    ">

        <h2 style="margin:0 0 10px;color:#173522;">
            Cabang Belum Ditentukan
        </h2>

        <p style="font-size:13px;color:#66736b;line-height:1.7;">
            Akun Anda belum memiliki cabang.
            Silakan minta Administrator menentukan cabang untuk akun ini
            sebelum menggunakan Manajemen Kas.
        </p>

    </div>

    <?php

    require_once __DIR__ . "/../includes/footer.php";
    exit;
}

/*
|--------------------------------------------------------------------------
| CABANG AKTIF
|--------------------------------------------------------------------------
|
| Kasir:
|   selalu menggunakan cabang miliknya.
|
| Admin:
|   dapat memilih cabang melalui ?branch_id=
|
*/

$active_branch_id = $user_branch_id;

/*
|--------------------------------------------------------------------------
| AMBIL DAFTAR CABANG
|--------------------------------------------------------------------------
*/

$branches = [];

$branch_result = $conn->query("
    SELECT
        id,
        name,
        address,
        phone
    FROM branches
    WHERE status = 'active'
    ORDER BY name ASC
");

if ($branch_result) {

    while ($branch = $branch_result->fetch_assoc()) {
        $branches[] = $branch;
    }

    $branch_result->free();
}

/*
|--------------------------------------------------------------------------
| ADMIN BOLEH MEMILIH CABANG
|--------------------------------------------------------------------------
*/

if ($user_role === 'admin') {

    $requested_branch_id = (int) ($_GET['branch_id'] ?? 0);

    if ($requested_branch_id > 0) {

        foreach ($branches as $branch) {

            if ((int) $branch['id'] === $requested_branch_id) {

                $active_branch_id = $requested_branch_id;

                break;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| CARI NAMA CABANG AKTIF
|--------------------------------------------------------------------------
*/

$active_branch_name = 'Cabang';

foreach ($branches as $branch) {

    if ((int) $branch['id'] === $active_branch_id) {

        $active_branch_name = $branch['name'];

        break;
    }
}

/*
|--------------------------------------------------------------------------
| PESAN
|--------------------------------------------------------------------------
*/

$message = $_GET['success'] ?? '';
$error   = $_GET['error'] ?? '';

/*
|--------------------------------------------------------------------------
| KATEGORI YANG DIIZINKAN
|--------------------------------------------------------------------------
*/

$allowed_categories = [
    'Operasional Toko',
    'ATK',
    'Transportasi',
    'Kebersihan',
    'Konsumsi',
    'Perawatan',
    'Lainnya'
];

/*
|--------------------------------------------------------------------------
| PROSES SIMPAN TRANSAKSI KAS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $type        = trim($_POST['type'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $amount      = (float) ($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | CABANG TRANSAKSI
    |--------------------------------------------------------------------------
    |
    | Kasir tidak boleh memilih cabang lain.
    |
    | Admin boleh memilih cabang aktif.
    |
    */

    $transaction_branch_id = $active_branch_id;

    if ($user_role === 'admin') {

        $posted_branch_id = (int) ($_POST['branch_id'] ?? 0);

        if ($posted_branch_id > 0) {

            $branch_exists = false;

            foreach ($branches as $branch) {

                if ((int) $branch['id'] === $posted_branch_id) {

                    $branch_exists = true;

                    break;
                }
            }

            if ($branch_exists) {
                $transaction_branch_id = $posted_branch_id;
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDASI
    |--------------------------------------------------------------------------
    */

    if (!in_array($type, ['in', 'out'], true)) {
        redirect_cash(
            'error',
            'Jenis transaksi kas tidak valid.',
            $active_branch_id
        );
    }

    if (!in_array($category, $allowed_categories, true)) {
        redirect_cash(
            'error',
            'Kategori transaksi tidak valid.',
            $active_branch_id
        );
    }

    if ($amount <= 0) {
        redirect_cash(
            'error',
            'Nominal harus lebih dari Rp 0.',
            $transaction_branch_id
        );
    }

    if ($description === '') {
        redirect_cash(
            'error',
            'Keterangan wajib diisi.',
            $transaction_branch_id
        );
    }

    if (strlen($description) > 255) {
        redirect_cash(
            'error',
            'Keterangan maksimal 255 karakter.',
            $transaction_branch_id
        );
    }

    if ($transaction_branch_id <= 0) {
        redirect_cash(
            'error',
            'Cabang transaksi tidak valid.',
            $active_branch_id
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SIMPAN TRANSAKSI
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        INSERT INTO cash_transactions
        (
            type,
            category,
            amount,
            description,
            user_id,
            branch_id
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {

        redirect_cash(
            'error',
            'Gagal menyiapkan transaksi kas.',
            $transaction_branch_id
        );
    }

    $stmt->bind_param(
        "ssdsii",
        $type,
        $category,
        $amount,
        $description,
        $user_id,
        $transaction_branch_id
    );

    if ($stmt->execute()) {

        $stmt->close();

        redirect_cash(
            'success',
            'Transaksi kas berhasil disimpan untuk ' . $active_branch_name . '.',
            $transaction_branch_id
        );
    }

    $stmt->close();

    redirect_cash(
        'error',
        'Gagal menyimpan transaksi kas.',
        $transaction_branch_id
    );
}

/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/

$filter_type = $_GET['type'] ?? '';
$filter_category = $_GET['category'] ?? '';

$where = [];
$params = [];
$types = '';

/*
|--------------------------------------------------------------------------
| WAJIB CABANG AKTIF
|--------------------------------------------------------------------------
*/

$where[] = "ct.branch_id = ?";
$params[] = $active_branch_id;
$types .= 'i';

/*
|--------------------------------------------------------------------------
| HANYA HARI INI
|--------------------------------------------------------------------------
*/

$where[] = "DATE(ct.created_at) = CURDATE()";

/*
|--------------------------------------------------------------------------
| FILTER JENIS
|--------------------------------------------------------------------------
*/

if (in_array($filter_type, ['in', 'out'], true)) {

    $where[] = "ct.type = ?";
    $params[] = $filter_type;
    $types .= 's';
}

/*
|--------------------------------------------------------------------------
| FILTER KATEGORI
|--------------------------------------------------------------------------
*/

if (in_array($filter_category, $allowed_categories, true)) {

    $where[] = "ct.category = ?";
    $params[] = $filter_category;
    $types .= 's';
}

$where_sql = implode(' AND ', $where);

/*
|--------------------------------------------------------------------------
| REKAP PENJUALAN TUNAI HARI INI
|--------------------------------------------------------------------------
| PENTING:
| Penjualan hanya dihitung dari cabang aktif.
|--------------------------------------------------------------------------
*/

$cash_sales = 0;

$stmt_cash_sales = $conn->prepare("
    SELECT
        COALESCE(SUM(total), 0) AS total
    FROM sales
    WHERE branch_id = ?
      AND payment_method = 'cash'
      AND DATE(created_at) = CURDATE()
");

if ($stmt_cash_sales) {

    $stmt_cash_sales->bind_param(
        "i",
        $active_branch_id
    );

    $stmt_cash_sales->execute();

    $result = $stmt_cash_sales->get_result();

    if ($result) {

        $row = $result->fetch_assoc();

        $cash_sales = (float) ($row['total'] ?? 0);
    }

    $stmt_cash_sales->close();
}

/*
|--------------------------------------------------------------------------
| REKAP KAS MASUK HARI INI
|--------------------------------------------------------------------------
*/

$cash_in = 0;

$stmt_cash_in = $conn->prepare("
    SELECT
        COALESCE(SUM(amount), 0) AS total
    FROM cash_transactions
    WHERE branch_id = ?
      AND type = 'in'
      AND DATE(created_at) = CURDATE()
");

if ($stmt_cash_in) {

    $stmt_cash_in->bind_param(
        "i",
        $active_branch_id
    );

    $stmt_cash_in->execute();

    $result = $stmt_cash_in->get_result();

    if ($result) {

        $row = $result->fetch_assoc();

        $cash_in = (float) ($row['total'] ?? 0);
    }

    $stmt_cash_in->close();
}

/*
|--------------------------------------------------------------------------
| REKAP KAS KELUAR HARI INI
|--------------------------------------------------------------------------
*/

$cash_out = 0;

$stmt_cash_out = $conn->prepare("
    SELECT
        COALESCE(SUM(amount), 0) AS total
    FROM cash_transactions
    WHERE branch_id = ?
      AND type = 'out'
      AND DATE(created_at) = CURDATE()
");

if ($stmt_cash_out) {

    $stmt_cash_out->bind_param(
        "i",
        $active_branch_id
    );

    $stmt_cash_out->execute();

    $result = $stmt_cash_out->get_result();

    if ($result) {

        $row = $result->fetch_assoc();

        $cash_out = (float) ($row['total'] ?? 0);
    }

    $stmt_cash_out->close();
}

/*
|--------------------------------------------------------------------------
| SALDO KAS
|--------------------------------------------------------------------------
|
| Penjualan Tunai + Kas Masuk - Kas Keluar
|
*/

$cash_balance = $cash_sales + $cash_in - $cash_out;

/*
|--------------------------------------------------------------------------
| REKAP KAS KELUAR PER KATEGORI
|--------------------------------------------------------------------------
*/

$category_summary = [];

$stmt_category = $conn->prepare("
    SELECT
        category,
        COALESCE(SUM(amount), 0) AS total
    FROM cash_transactions
    WHERE branch_id = ?
      AND type = 'out'
      AND DATE(created_at) = CURDATE()
    GROUP BY category
    ORDER BY total DESC
");

if ($stmt_category) {

    $stmt_category->bind_param(
        "i",
        $active_branch_id
    );

    $stmt_category->execute();

    $category_result = $stmt_category->get_result();

    if ($category_result) {

        while ($row = $category_result->fetch_assoc()) {

            $category_summary[] = $row;
        }
    }

    $stmt_category->close();
}

/*
|--------------------------------------------------------------------------
| RIWAYAT TRANSAKSI KAS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        ct.id,
        ct.type,
        ct.category,
        ct.amount,
        ct.description,
        ct.created_at,
        u.name AS user_name
    FROM cash_transactions ct
    INNER JOIN users u
        ON u.id = ct.user_id
    WHERE {$where_sql}
    ORDER BY ct.created_at DESC
";

$stmt_history = $conn->prepare($sql);

if ($stmt_history) {

    if (!empty($params)) {

        $stmt_history->bind_param(
            $types,
            ...$params
        );
    }

    $stmt_history->execute();

    $history = $stmt_history->get_result();

} else {

    $history = false;
}

/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/../includes/header.php";

?>

<style>

.cash-page {
    max-width: 1400px;
}

.cash-summary {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}

.cash-card {
    background: #fff;
    border: 1px solid #e2e8e3;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 8px 24px rgba(20, 50, 30, .06);
}

.cash-card .label {
    color: #718078;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 10px;
}

.cash-card .value {
    font-size: 25px;
    font-weight: 800;
    color: #173522;
}

.cash-card .desc {
    margin-top: 7px;
    font-size: 11px;
    color: #87928b;
}

.cash-card.balance {
    background: linear-gradient(135deg, #123b23, #1d7040);
    color: #fff;
    border-color: #123b23;
}

.cash-card.balance .label,
.cash-card.balance .desc {
    color: #c5dacb;
}

.cash-card.balance .value {
    color: #fff;
}

.cash-layout {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 18px;
    align-items: start;
}

.cash-panel {
    background: #fff;
    border: 1px solid #e2e8e3;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 8px 24px rgba(20, 50, 30, .05);
}

.cash-panel h2 {
    margin: 0 0 5px;
    font-size: 16px;
    color: #17221b;
}

.cash-panel .subtitle {
    color: #7c8880;
    font-size: 11px;
    margin-bottom: 18px;
}

.cash-form-group {
    margin-bottom: 14px;
}

.cash-form-group label {
    display: block;
    font-size: 11px;
    font-weight: 750;
    color: #425048;
    margin-bottom: 6px;
}

.cash-form-group input,
.cash-form-group select {
    width: 100%;
    border: 1px solid #dce5de;
    border-radius: 10px;
    padding: 11px 12px;
    font-size: 12px;
    background: #fff;
    outline: none;
}

.cash-form-group input:focus,
.cash-form-group select:focus {
    border-color: #68a67b;
    box-shadow: 0 0 0 3px rgba(31, 111, 61, .08);
}

.cash-info {
    background: #f5faf6;
    border: 1px solid #dcebdd;
    border-radius: 11px;
    padding: 12px;
    font-size: 11px;
    line-height: 1.6;
    color: #526158;
    margin-bottom: 15px;
}

.cash-info strong {
    color: #176f3d;
}

.cash-button {
    width: 100%;
    border: 0;
    border-radius: 10px;
    padding: 12px;
    background: linear-gradient(135deg, #176f3d, #239454);
    color: #fff;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
}

.cash-button:hover {
    opacity: .94;
}

.cash-filter {
    display: grid;
    grid-template-columns: 180px 1fr auto auto;
    gap: 10px;
    margin-bottom: 15px;
}

.cash-filter select {
    border: 1px solid #dce5de;
    border-radius: 9px;
    padding: 10px;
    background: #fff;
    font-size: 11px;
}

.cash-filter button,
.cash-filter a {
    border: 1px solid #dce5de;
    border-radius: 9px;
    padding: 10px 13px;
    background: #fff;
    color: #344239;
    text-decoration: none;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}

.cash-filter button:hover,
.cash-filter a:hover {
    background: #f5f8f5;
}

.cash-table {
    width: 100%;
    border-collapse: collapse;
}

.cash-table th,
.cash-table td {
    padding: 12px 10px;
    border-bottom: 1px solid #edf1ee;
    text-align: left;
    font-size: 11px;
}

.cash-table th {
    background: #f7f9f7;
    color: #78837c;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: .06em;
}

.cash-type {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 9px;
    font-weight: 800;
}

.cash-type.in {
    background: #e8f6ec;
    color: #176f3d;
}

.cash-type.out {
    background: #fff0e2;
    color: #a85c00;
}

.cash-category {
    display: inline-block;
    padding: 4px 8px;
    border: 1px solid #e2e8e3;
    border-radius: 7px;
    background: #fafcfb;
    font-size: 10px;
    color: #536158;
}

.amount-in {
    color: #176f3d;
    font-weight: 800;
}

.amount-out {
    color: #b65e00;
    font-weight: 800;
}

.category-box {
    margin-top: 18px;
}

.category-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 10px 0;
    border-bottom: 1px solid #edf1ee;
    font-size: 11px;
}

.category-row:last-child {
    border-bottom: 0;
}

.category-row strong {
    color: #a85c00;
}

.alert-success {
    background: #eaf7ed;
    border: 1px solid #cce7d2;
    color: #176f3d;
    border-radius: 10px;
    padding: 11px 13px;
    margin-bottom: 16px;
    font-size: 12px;
    font-weight: 650;
}

.alert-error {
    background: #fff0f0;
    border: 1px solid #f0caca;
    color: #b53535;
    border-radius: 10px;
    padding: 11px 13px;
    margin-bottom: 16px;
    font-size: 12px;
    font-weight: 650;
}

/*
|--------------------------------------------------------------------------
| CABANG
|--------------------------------------------------------------------------
*/

.branch-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 18px;
    padding: 13px 15px;
    background: #f5faf6;
    border: 1px solid #dcebdd;
    border-radius: 12px;
}

.branch-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.branch-icon {
    width: 36px;
    height: 36px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    background: #e3f2e7;
    color: #176f3d;
    font-size: 16px;
}

.branch-info small {
    display: block;
    color: #7b887f;
    font-size: 9px;
    margin-bottom: 3px;
}

.branch-info strong {
    display: block;
    color: #173522;
    font-size: 12px;
}

.branch-selector {
    display: flex;
    align-items: center;
    gap: 8px;
}

.branch-selector label {
    font-size: 10px;
    font-weight: 800;
    color: #526158;
}

.branch-selector select {
    min-width: 190px;
    border: 1px solid #cfded2;
    border-radius: 9px;
    padding: 9px 10px;
    background: #fff;
    color: #344239;
    font-size: 11px;
    outline: none;
}

.branch-locked {
    padding: 8px 10px;
    background: #fff;
    border: 1px solid #dce5de;
    border-radius: 8px;
    color: #526158;
    font-size: 10px;
    font-weight: 700;
}

@media (max-width: 1100px) {

    .cash-summary {
        grid-template-columns: repeat(2, 1fr);
    }

    .cash-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {

    .cash-summary {
        grid-template-columns: 1fr;
    }

    .cash-filter {
        grid-template-columns: 1fr;
    }

    .cash-table {
        min-width: 850px;
    }

    .cash-table-wrap {
        overflow-x: auto;
    }

    .branch-bar {
        align-items: flex-start;
        flex-direction: column;
    }

    .branch-selector {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
    }

    .branch-selector select {
        width: 100%;
    }
}

</style>

<div class="cash-page">

    <div class="top">

        <div>

            <h1>Manajemen Kas</h1>

            <div style="font-size:11px;color:#7b867f;margin-top:5px;">
                Pengelolaan uang tunai dan pengeluaran operasional toko
            </div>

        </div>

        <span class="muted">
            <?= date('d M Y') ?>
        </span>

    </div>


    <?php if ($message): ?>

        <div class="alert-success">
            âœ“ <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="alert-error">
            âš  <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <!-- =========================================================
         CABANG AKTIF
    ========================================================== -->

    <div class="branch-bar">

        <div class="branch-info">

            <div class="branch-icon">
                ðŸª
            </div>

            <div>

                <small>
                    CABANG AKTIF
                </small>

                <strong>
                    <?= htmlspecialchars($active_branch_name) ?>
                </strong>

            </div>

        </div>


        <?php if ($user_role === 'admin'): ?>

            <form method="GET" class="branch-selector">

                <label for="branch_id">
                    Pilih Cabang
                </label>

                <select
                    name="branch_id"
                    id="branch_id"
                    onchange="this.form.submit()"
                >

                    <?php foreach ($branches as $branch): ?>

                        <option
                            value="<?= (int) $branch['id'] ?>"
                            <?= (int) $branch['id'] === $active_branch_id ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($branch['name']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <?php if ($filter_type): ?>
                    <input
                        type="hidden"
                        name="type"
                        value="<?= htmlspecialchars($filter_type) ?>"
                    >
                <?php endif; ?>

                <?php if ($filter_category): ?>
                    <input
                        type="hidden"
                        name="category"
                        value="<?= htmlspecialchars($filter_category) ?>"
                    >
                <?php endif; ?>

            </form>

        <?php else: ?>

            <div class="branch-locked">
                ðŸ”’ Cabang akun kasir
            </div>

        <?php endif; ?>

    </div>


    <!-- =========================================================
         RINGKASAN KAS
    ========================================================== -->

    <div class="cash-summary">

        <div class="cash-card">

            <div class="label">
                Penjualan Tunai
            </div>

            <div class="value">
                <?= rupiah($cash_sales) ?>
            </div>

            <div class="desc">
                Penjualan tunai cabang ini hari ini
            </div>

        </div>


        <div class="cash-card">

            <div class="label">
                Kas Masuk
            </div>

            <div class="value">
                <?= rupiah($cash_in) ?>
            </div>

            <div class="desc">
                Pemasukan kas selain penjualan
            </div>

        </div>


        <div class="cash-card">

            <div class="label">
                Kas Keluar
            </div>

            <div class="value">
                <?= rupiah($cash_out) ?>
            </div>

            <div class="desc">
                Pengeluaran operasional cabang
            </div>

        </div>


        <div class="cash-card balance">

            <div class="label">
                Saldo Kas Hari Ini
            </div>

            <div class="value">
                <?= rupiah($cash_balance) ?>
            </div>

            <div class="desc">
                Penjualan tunai + kas masuk âˆ’ kas keluar
            </div>

        </div>

    </div>


    <!-- =========================================================
         LAYOUT
    ========================================================== -->

    <div class="cash-layout">


        <!-- =====================================================
             FORM TRANSAKSI
        ====================================================== -->

        <div>

            <div class="cash-panel">

                <h2>
                    Catat Transaksi Kas
                </h2>

                <div class="subtitle">
                    Transaksi akan tercatat pada
                    <strong><?= htmlspecialchars($active_branch_name) ?></strong>.
                </div>


                <form method="POST">

                    <?php if ($user_role === 'admin'): ?>

                        <input
                            type="hidden"
                            name="branch_id"
                            value="<?= $active_branch_id ?>"
                        >

                    <?php endif; ?>


                    <div class="cash-form-group">

                        <label>
                            Cabang
                        </label>

                        <input
                            type="text"
                            value="<?= htmlspecialchars($active_branch_name) ?>"
                            readonly
                            style="background:#f7f9f7;color:#68746d;"
                        >

                    </div>


                    <div class="cash-form-group">

                        <label>
                            Jenis Transaksi
                        </label>

                        <select name="type" id="cashType" required>

                            <option value="out">
                                Kas Keluar / Pengeluaran
                            </option>

                            <option value="in">
                                Kas Masuk / Pemasukan
                            </option>

                        </select>

                    </div>


                    <div class="cash-form-group">

                        <label>
                            Kategori
                        </label>

                        <select name="category" required>

                            <?php foreach ($allowed_categories as $category): ?>

                                <option value="<?= htmlspecialchars($category) ?>">
                                    <?= htmlspecialchars($category) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="cash-form-group">

                        <label>
                            Nominal
                        </label>

                        <input
                            type="number"
                            name="amount"
                            min="1"
                            step="1"
                            placeholder="Contoh: 50000"
                            required
                        >

                    </div>


                    <div class="cash-form-group">

                        <label>
                            Keterangan
                        </label>

                        <input
                            type="text"
                            name="description"
                            maxlength="255"
                            placeholder="Contoh: Beli plastik packing"
                            required
                        >

                    </div>


                    <div class="cash-info">

                        <strong>
                            Catatan Kas Keluar
                        </strong>

                        <br>

                        Kas keluar digunakan untuk pengeluaran operasional
                        cabang seperti ATK, transportasi, kebersihan,
                        konsumsi, dan perawatan.

                        <br>
                        <br>

                        <strong>
                            Pembelian stok barang jangan dicatat sebagai
                            kas keluar operasional.
                        </strong>

                        Pembelian stok sebaiknya diproses melalui pencatatan
                        pembelian/stok agar laporan stok dan keuangan tetap
                        terpisah.

                    </div>


                    <button
                        type="submit"
                        class="cash-button"
                    >
                        Simpan Transaksi Kas
                    </button>

                </form>

            </div>


            <!-- =================================================
                 REKAP KAS KELUAR PER KATEGORI
            ================================================== -->

            <div class="cash-panel category-box">

                <h2>
                    Kas Keluar Berdasarkan Kategori
                </h2>

                <div class="subtitle">
                    Rincian pengeluaran operasional
                    <?= htmlspecialchars($active_branch_name) ?>
                    hari ini.
                </div>


                <?php if (empty($category_summary)): ?>

                    <div class="cash-info">
                        Belum ada kas keluar hari ini
                        pada <?= htmlspecialchars($active_branch_name) ?>.
                    </div>

                <?php else: ?>

                    <?php foreach ($category_summary as $item): ?>

                        <div class="category-row">

                            <span>
                                <?= htmlspecialchars($item['category']) ?>
                            </span>

                            <strong>
                                <?= rupiah($item['total']) ?>
                            </strong>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </div>


        <!-- =====================================================
             RIWAYAT
        ====================================================== -->

        <div class="cash-panel">

            <h2>
                Riwayat Transaksi Kas
            </h2>

            <div class="subtitle">
                Semua transaksi kas hari ini untuk
                <strong><?= htmlspecialchars($active_branch_name) ?></strong>.
            </div>


            <!-- FILTER -->

            <form method="GET" class="cash-filter">

                <?php if ($user_role === 'admin'): ?>

                    <input
                        type="hidden"
                        name="branch_id"
                        value="<?= $active_branch_id ?>"
                    >

                <?php endif; ?>


                <select name="type">

                    <option value="">
                        Semua Jenis
                    </option>

                    <option
                        value="in"
                        <?= $filter_type === 'in' ? 'selected' : '' ?>
                    >
                        Kas Masuk
                    </option>

                    <option
                        value="out"
                        <?= $filter_type === 'out' ? 'selected' : '' ?>
                    >
                        Kas Keluar
                    </option>

                </select>


                <select name="category">

                    <option value="">
                        Semua Kategori
                    </option>

                    <?php foreach ($allowed_categories as $category): ?>

                        <option
                            value="<?= htmlspecialchars($category) ?>"
                            <?= $filter_category === $category ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($category) ?>
                        </option>

                    <?php endforeach; ?>

                </select>


                <button type="submit">
                    Filter
                </button>


                <a
                    href="/kasir_pertanian/admin/cash.php<?= $user_role === 'admin' ? '?branch_id=' . $active_branch_id : '' ?>"
                >
                    Reset
                </a>

            </form>


            <div class="cash-table-wrap">

                <table class="cash-table">

                    <thead>

                        <tr>

                            <th>
                                Waktu
                            </th>

                            <th>
                                Jenis
                            </th>

                            <th>
                                Kategori
                            </th>

                            <th>
                                Keterangan
                            </th>

                            <th>
                                Petugas
                            </th>

                            <th>
                                Nominal
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php if (!$history || $history->num_rows === 0): ?>

                        <tr>

                            <td
                                colspan="6"
                                style="text-align:center;padding:35px;color:#89948d;"
                            >
                                Belum ada transaksi kas hari ini
                                pada <?= htmlspecialchars($active_branch_name) ?>.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php while ($row = $history->fetch_assoc()): ?>

                            <tr>

                                <td>
                                    <?= date('H:i', strtotime($row['created_at'])) ?>
                                </td>


                                <td>

                                    <?php if ($row['type'] === 'out'): ?>

                                        <span class="cash-type out">
                                            Kas Keluar
                                        </span>

                                    <?php else: ?>

                                        <span class="cash-type in">
                                            Kas Masuk
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <span class="cash-category">
                                        <?= htmlspecialchars($row['category']) ?>
                                    </span>

                                </td>


                                <td>
                                    <?= htmlspecialchars($row['description']) ?>
                                </td>


                                <td>
                                    <?= htmlspecialchars($row['user_name']) ?>
                                </td>


                                <td>

                                    <?php if ($row['type'] === 'out'): ?>

                                        <span class="amount-out">
                                            âˆ’ <?= rupiah($row['amount']) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="amount-in">
                                            + <?= rupiah($row['amount']) ?>
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>


<?php

if ($stmt_history) {
    $stmt_history->close();
}

require_once __DIR__ . "/../includes/footer.php";

?>

<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

require_kasir();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = (int) ($_SESSION["user"]["id"] ?? 0);

$message = "";
$error = "";


/* =========================================================
   HELPER
========================================================= */

function rupiah($number)
{
    return "Rp " . number_format(
        (float) $number,
        0,
        ",",
        "."
    );
}

function redirect_shift($status, $message)
{
    header(
        "Location: /kasir_pertanian/admin/shifts.php?"
        . $status
        . "="
        . urlencode($message)
    );

    exit;
}


/* =========================================================
   PESAN REDIRECT
========================================================= */

if (isset($_GET["success"])) {
    $message = $_GET["success"];
}

if (isset($_GET["error"])) {
    $error = $_GET["error"];
}


/* =========================================================
   AMBIL CABANG USER
========================================================= */

$user_branch = null;

$stmt = $conn->prepare("
    SELECT
        u.branch_id,
        b.name AS branch_name,
        b.status AS branch_status
    FROM users u
    LEFT JOIN branches b
        ON b.id = u.branch_id
    WHERE u.id = ?
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        "i",
        $user_id
    );

    $stmt->execute();

    $user_branch = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();
}

$branch_id = (int) (
    $user_branch["branch_id"] ?? 0
);

$branch_name = trim(
    $user_branch["branch_name"] ?? ""
);

$branch_status = $user_branch["branch_status"] ?? "";


/* =========================================================
   VALIDASI USER DAN CABANG
========================================================= */

if ($user_id <= 0) {

    require_once __DIR__ . "/../includes/header.php";
    ?>

    <div class="shift-page">

        <div class="page-title">
            <div>
                <h2>Shift & Rekap</h2>
                <p>
                    Kelola pembukaan, pemantauan, dan penutupan shift kasir.
                </p>
            </div>
        </div>

        <div class="alert alert-danger">
            Sesi pengguna tidak ditemukan.
            Silakan login kembali.
        </div>

    </div>

    <?php
    require_once __DIR__ . "/../includes/footer.php";
    exit;
}


if (
    $branch_id <= 0
    || $branch_status !== "active"
) {

    require_once __DIR__ . "/../includes/header.php";
    ?>

    <div class="shift-page">

        <div class="page-title">
            <div>
                <h2>Shift & Rekap</h2>
                <p>
                    Kelola pembukaan, pemantauan, dan penutupan shift kasir.
                </p>
            </div>
        </div>

        <div class="alert alert-danger">
            Akun kasir belum memiliki cabang aktif.
            Silakan hubungi administrator untuk menetapkan cabang
            sebelum membuka shift.
        </div>

    </div>

    <?php
    require_once __DIR__ . "/../includes/footer.php";
    exit;
}


/* =========================================================
   AMBIL SHIFT AKTIF
========================================================= */
/*
 * PENTING:
 * Tabel shifts tidak memiliki branch_id.
 * Cabang shift mengikuti branch_id milik user.
 */

$active_shift = null;

$stmt = $conn->prepare("
    SELECT *
    FROM shifts
    WHERE user_id = ?
      AND status = 'open'
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        "i",
        $user_id
    );

    $stmt->execute();

    $active_shift = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();
}


/* =========================================================
   BUKA SHIFT
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["action"] ?? "") === "open_shift"
) {

    if ($active_shift) {

        redirect_shift(
            "error",
            "Anda masih memiliki shift aktif."
        );
    }

    $opening_cash = (float) (
        $_POST["opening_cash"] ?? 0
    );

    if ($opening_cash < 0) {

        redirect_shift(
            "error",
            "Kas awal tidak boleh kurang dari 0."
        );
    }

    $start_time = date(
        "Y-m-d H:i:s"
    );


    /*
     * Tabel shifts hanya menyimpan user_id.
     * Cabang berasal dari users.branch_id.
     */

    $stmt = $conn->prepare("
        INSERT INTO shifts
        (
            user_id,
            opening_cash,
            start_time,
            status
        )
        VALUES (?, ?, ?, 'open')
    ");

    if (!$stmt) {

        redirect_shift(
            "error",
            "Gagal menyiapkan pembukaan shift."
        );
    }

    $stmt->bind_param(
        "ids",
        $user_id,
        $opening_cash,
        $start_time
    );

    if ($stmt->execute()) {

        $stmt->close();

        redirect_shift(
            "success",
            "Shift berhasil dibuka untuk cabang "
            . $branch_name
            . "."
        );
    }

    $stmt->close();

    redirect_shift(
        "error",
        "Gagal membuka shift."
    );
}


/* =========================================================
   TUTUP SHIFT
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["action"] ?? "") === "close_shift"
) {

    $shift_id = (int) (
        $_POST["shift_id"] ?? 0
    );

    $closing_cash = (float) (
        $_POST["closing_cash"] ?? 0
    );


    if (!$active_shift) {

        redirect_shift(
            "error",
            "Tidak ada shift aktif yang dapat ditutup."
        );
    }


    if (
        (int) $active_shift["id"]
        !== $shift_id
    ) {

        redirect_shift(
            "error",
            "Shift yang dipilih tidak valid."
        );
    }


    if ($closing_cash < 0) {

        redirect_shift(
            "error",
            "Kas fisik tidak boleh kurang dari 0."
        );
    }


    $shift_start = $active_shift["start_time"];


    /* =====================================================
       PENJUALAN SELAMA SHIFT
    ===================================================== */

    $cash_sales = 0;
    $total_sales = 0;
    $total_transactions = 0;

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_transactions,
            COALESCE(SUM(total), 0) AS total_sales,
            COALESCE(
                SUM(
                    CASE
                        WHEN payment_method = 'cash'
                        THEN total
                        ELSE 0
                    END
                ),
                0
            ) AS cash_sales
        FROM sales
        WHERE cashier_id = ?
          AND branch_id = ?
          AND created_at >= ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            "iis",
            $user_id,
            $branch_id,
            $shift_start
        );

        $stmt->execute();

        $sales_data = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        $total_transactions = (int) (
            $sales_data["total_transactions"] ?? 0
        );

        $total_sales = (float) (
            $sales_data["total_sales"] ?? 0
        );

        $cash_sales = (float) (
            $sales_data["cash_sales"] ?? 0
        );
    }


    /* =====================================================
       KAS MASUK
    ===================================================== */

    $cash_in = 0;

    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(amount), 0) AS total_in
        FROM cash_transactions
        WHERE user_id = ?
          AND branch_id = ?
          AND type = 'in'
          AND created_at >= ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            "iis",
            $user_id,
            $branch_id,
            $shift_start
        );

        $stmt->execute();

        $cash_data = $stmt
            ->get_result()
            ->fetch_assoc();

        $cash_in = (float) (
            $cash_data["total_in"] ?? 0
        );

        $stmt->close();
    }


    /* =====================================================
       KAS KELUAR
    ===================================================== */

    $cash_out = 0;

    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(amount), 0) AS total_out
        FROM cash_transactions
        WHERE user_id = ?
          AND branch_id = ?
          AND type = 'out'
          AND created_at >= ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            "iis",
            $user_id,
            $branch_id,
            $shift_start
        );

        $stmt->execute();

        $cash_data = $stmt
            ->get_result()
            ->fetch_assoc();

        $cash_out = (float) (
            $cash_data["total_out"] ?? 0
        );

        $stmt->close();
    }


    /* =====================================================
       HITUNG KAS SEHARUSNYA
    ===================================================== */

    $expected_cash =
        (float) $active_shift["opening_cash"]
        + $cash_sales
        + $cash_in
        - $cash_out;


    /* =====================================================
       HITUNG SELISIH
    ===================================================== */

    $difference_cash =
        $closing_cash
        - $expected_cash;

    $end_time = date(
        "Y-m-d H:i:s"
    );


    /* =====================================================
       UPDATE SHIFT
    ===================================================== */

    $stmt = $conn->prepare("
        UPDATE shifts
        SET
            closing_cash = ?,
            expected_cash = ?,
            difference_cash = ?,
            end_time = ?,
            status = 'closed'
        WHERE id = ?
          AND user_id = ?
          AND status = 'open'
    ");

    if (!$stmt) {

        redirect_shift(
            "error",
            "Gagal menyiapkan proses penutupan shift."
        );
    }

    $stmt->bind_param(
        "dddsii",
        $closing_cash,
        $expected_cash,
        $difference_cash,
        $end_time,
        $shift_id,
        $user_id
    );

    if ($stmt->execute()) {

        $stmt->close();

        redirect_shift(
            "success",
            "Shift berhasil ditutup dan rekap telah disimpan."
        );
    }

    $stmt->close();

    redirect_shift(
        "error",
        "Gagal menutup shift."
    );
}


/* =========================================================
   REFRESH SHIFT AKTIF
========================================================= */

$stmt = $conn->prepare("
    SELECT *
    FROM shifts
    WHERE user_id = ?
      AND status = 'open'
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        "i",
        $user_id
    );

    $stmt->execute();

    $active_shift = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();
}


/* =========================================================
   DATA REKAP SHIFT
========================================================= */

$total_transactions = 0;
$total_sales = 0;
$cash_sales = 0;
$cash_in = 0;
$cash_out = 0;
$non_cash_sales = 0;
$expected_cash = 0;


if ($active_shift) {

    $shift_start =
        $active_shift["start_time"];


    /* =====================================================
       PENJUALAN
    ===================================================== */

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_transactions,

            COALESCE(
                SUM(total),
                0
            ) AS total_sales,

            COALESCE(
                SUM(
                    CASE
                        WHEN payment_method = 'cash'
                        THEN total
                        ELSE 0
                    END
                ),
                0
            ) AS cash_sales,

            COALESCE(
                SUM(
                    CASE
                        WHEN payment_method IN ('debit', 'transfer')
                        THEN total
                        ELSE 0
                    END
                ),
                0
            ) AS non_cash_sales

        FROM sales

        WHERE cashier_id = ?
          AND branch_id = ?
          AND created_at >= ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            "iis",
            $user_id,
            $branch_id,
            $shift_start
        );

        $stmt->execute();

        $sales_data = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        $total_transactions = (int) (
            $sales_data["total_transactions"] ?? 0
        );

        $total_sales = (float) (
            $sales_data["total_sales"] ?? 0
        );

        $cash_sales = (float) (
            $sales_data["cash_sales"] ?? 0
        );

        $non_cash_sales = (float) (
            $sales_data["non_cash_sales"] ?? 0
        );
    }


    /* =====================================================
       KAS MASUK
    ===================================================== */

    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(amount), 0) AS total
        FROM cash_transactions
        WHERE user_id = ?
          AND branch_id = ?
          AND type = 'in'
          AND created_at >= ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            "iis",
            $user_id,
            $branch_id,
            $shift_start
        );

        $stmt->execute();

        $cash_data = $stmt
            ->get_result()
            ->fetch_assoc();

        $cash_in = (float) (
            $cash_data["total"] ?? 0
        );

        $stmt->close();
    }


    /* =====================================================
       KAS KELUAR
    ===================================================== */

    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(amount), 0) AS total
        FROM cash_transactions
        WHERE user_id = ?
          AND branch_id = ?
          AND type = 'out'
          AND created_at >= ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            "iis",
            $user_id,
            $branch_id,
            $shift_start
        );

        $stmt->execute();

        $cash_data = $stmt
            ->get_result()
            ->fetch_assoc();

        $cash_out = (float) (
            $cash_data["total"] ?? 0
        );

        $stmt->close();
    }


    /* =====================================================
       KAS SEHARUSNYA
    ===================================================== */

    $expected_cash =
        (float) $active_shift["opening_cash"]
        + $cash_sales
        + $cash_in
        - $cash_out;
}


/* =========================================================
   RIWAYAT SHIFT
========================================================= */
/*
 * Tidak menggunakan s.branch_id karena kolom tersebut
 * memang tidak ada di tabel shifts.
 *
 * Cabang ditampilkan dari users.branch_id.
 */

$stmt = $conn->prepare("
    SELECT
        s.*,
        u.name AS user_name,
        b.name AS branch_name
    FROM shifts s
    LEFT JOIN users u
        ON u.id = s.user_id
    LEFT JOIN branches b
        ON b.id = u.branch_id
    WHERE s.user_id = ?
    ORDER BY s.id DESC
    LIMIT 20
");

if (!$stmt) {

    die(
        "Gagal mengambil riwayat shift: "
        . htmlspecialchars($conn->error)
    );
}

$stmt->bind_param(
    "i",
    $user_id
);

$stmt->execute();

$shift_history = $stmt->get_result();

$stmt->close();


/* =========================================================
   HEADER
========================================================= */

require_once __DIR__ . "/../includes/header.php";

?>

<style>

.shift-page {
    width: 100%;
}

.shift-hero {
    display: grid;
    grid-template-columns: 1.15fr .85fr;
    gap: 18px;
    margin-bottom: 18px;
}

.shift-status {
    position: relative;
    overflow: hidden;
    min-height: 220px;
    padding: 27px;
    border: 0;
    color: #fff;
    background:
        radial-gradient(
            circle at 90% 10%,
            rgba(145,200,62,.25),
            transparent 28%
        ),
        linear-gradient(
            135deg,
            #0b2b18 0%,
            #176f3d 58%,
            #239454 100%
        );
    box-shadow: 0 15px 40px rgba(23,111,61,.16);
}

.shift-status::before {
    content: "";
    position: absolute;
    width: 250px;
    height: 250px;
    right: -100px;
    top: -135px;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,.10);
}

.shift-kicker {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: #c5e1ce;
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .13em;
    text-transform: uppercase;
}

.shift-status h2 {
    position: relative;
    z-index: 1;
    margin: 13px 0 7px;
    font-size: 28px;
    letter-spacing: -.05em;
}

.shift-status p {
    position: relative;
    z-index: 1;
    max-width: 480px;
    margin: 0;
    color: #d4e4d8;
    font-size: 11px;
    line-height: 1.65;
}

.shift-live {
    position: relative;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 22px;
    padding: 8px 13px;
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 20px;
    background: rgba(255,255,255,.08);
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .07em;
}

.shift-live i {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #91c83e;
    box-shadow: 0 0 0 4px rgba(145,200,62,.15);
}

.shift-branch {
    position: relative;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-top: 12px;
    padding: 7px 11px;
    border: 1px solid rgba(255,255,255,.14);
    border-radius: 20px;
    background: rgba(255,255,255,.08);
    color: #e0eee4;
    font-size: 9px;
    font-weight: 850;
}

.shift-summary {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.shift-mini {
    position: relative;
    overflow: hidden;
    padding: 18px;
    border: 1px solid #dfe7e0;
    border-radius: 15px;
    background: #fff;
    box-shadow: 0 8px 25px rgba(23,60,36,.05);
}

.shift-mini::after {
    content: "";
    position: absolute;
    width: 70px;
    height: 70px;
    right: -28px;
    top: -28px;
    border-radius: 50%;
    background: rgba(23,111,61,.05);
}

.shift-mini small {
    display: block;
    color: #77837b;
    font-size: 9px;
    font-weight: 850;
    letter-spacing: .07em;
    text-transform: uppercase;
}

.shift-mini strong {
    position: relative;
    z-index: 1;
    display: block;
    margin-top: 9px;
    font-size: 20px;
    letter-spacing: -.045em;
}

.shift-mini span {
    display: block;
    margin-top: 5px;
    color: #77837b;
    font-size: 10px;
    line-height: 1.4;
}

.shift-grid {
    display: grid;
    grid-template-columns: 1.1fr .9fr;
    gap: 18px;
    margin-bottom: 18px;
}

.shift-grid .panel {
    margin-top: 0;
}

.shift-breakdown {
    margin-top: 5px;
}

.shift-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 14px 0;
    border-bottom: 1px solid #dfe7e0;
    font-size: 12px;
}

.shift-line:last-child {
    border-bottom: 0;
}

.shift-line span {
    color: #526058;
}

.shift-line strong {
    font-size: 12px;
    white-space: nowrap;
}

.shift-total {
    margin-top: 7px;
    padding: 16px 14px !important;
    border: 1px solid #dce9de !important;
    border-radius: 12px;
    background: #f7faf7;
}

.shift-total span {
    color: #17221b;
    font-weight: 850;
}

.shift-total strong {
    color: #176f3d;
    font-size: 19px;
}

.shift-close .field {
    margin-top: 18px;
}

.shift-close .field input {
    padding: 13px 12px;
    font-size: 15px;
    background: #fbfdfb;
}

.shift-note {
    margin-top: 11px;
    padding: 12px 13px;
    border: 1px solid #dfe7e0;
    border-radius: 11px;
    background: #f7faf7;
    color: #77837b;
    font-size: 10px;
    line-height: 1.65;
}

.open-shift-box {
    max-width: 760px;
}

.open-shift-icon {
    display: grid;
    place-items: center;
    width: 54px;
    height: 54px;
    margin-bottom: 14px;
    border-radius: 15px;
    background: #eaf6ed;
    color: #176f3d;
    font-size: 23px;
}

.open-shift-box h3 {
    margin: 0 0 7px;
    font-size: 18px;
}

.open-shift-box p {
    max-width: 650px;
    margin: 0 0 22px;
    color: #77837b;
    font-size: 11px;
    line-height: 1.65;
}

.open-info {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-bottom: 20px;
}

.open-info-item {
    padding: 13px;
    border: 1px solid #dfe7e0;
    border-radius: 11px;
    background: #f8faf8;
}

.open-info-item strong {
    display: block;
    color: #176f3d;
    font-size: 12px;
}

.open-info-item span {
    display: block;
    margin-top: 4px;
    color: #77837b;
    font-size: 9px;
}

.shift-history {
    margin-top: 18px;
}

.shift-history .table-responsive {
    overflow-x: auto;
}

.shift-history table {
    min-width: 900px;
}

.shift-history th,
.shift-history td {
    white-space: nowrap;
}

.shift-empty {
    padding: 35px !important;
    text-align: center !important;
    color: #77837b;
}

.status-open {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 9px;
    border-radius: 20px;
    background: #eaf6ed;
    color: #176f3d;
    font-size: 9px;
    font-weight: 850;
}

.status-open i {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #239454;
}

.status-closed {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #f0f3f0;
    color: #647168;
    font-size: 9px;
    font-weight: 850;
}

.diff-positive {
    color: #176f3d;
    font-weight: 850;
}

.diff-negative {
    color: #d94a4a;
    font-weight: 850;
}

.diff-zero {
    color: #526058;
    font-weight: 850;
}

@media (max-width: 950px) {

    .shift-hero,
    .shift-grid {
        grid-template-columns: 1fr;
    }

    .shift-status {
        min-height: auto;
    }
}

@media (max-width: 650px) {

    .shift-summary {
        grid-template-columns: 1fr 1fr;
    }

    .open-info {
        grid-template-columns: 1fr;
    }

    .shift-status h2 {
        font-size: 23px;
    }
}

@media (max-width: 480px) {

    .shift-summary {
        grid-template-columns: 1fr;
    }

    .shift-status {
        padding: 21px;
    }

    .shift-line {
        align-items: flex-start;
        flex-direction: column;
        gap: 5px;
    }
}

</style>


<div class="shift-page">

    <div class="page-title">

        <div>

            <h2>Shift & Rekap</h2>

            <p>
                Kelola pembukaan, pemantauan, dan penutupan shift kasir.
            </p>

        </div>

    </div>


    <?php if ($message): ?>

        <div class="alert alert-success">
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <?php if (!$active_shift): ?>

        <div class="panel open-shift-box">

            <div class="open-shift-icon">
                ðŸ’¼
            </div>

            <h3>Buka Shift Kasir</h3>

            <p>
                Sebelum melakukan transaksi, buka shift terlebih dahulu
                dan masukkan jumlah uang tunai yang tersedia di laci kasir.
                Nominal ini akan menjadi dasar perhitungan kas pada akhir shift.
            </p>

            <div class="open-info">

                <div class="open-info-item">
                    <strong>Cabang</strong>

                    <span>
                        <?= htmlspecialchars($branch_name) ?>
                    </span>
                </div>

                <div class="open-info-item">
                    <strong>Kas Awal</strong>

                    <span>
                        Uang fisik sebelum transaksi
                    </span>
                </div>

                <div class="open-info-item">
                    <strong>Kas Keluar</strong>

                    <span>
                        Akan mengurangi kas fisik
                    </span>
                </div>

            </div>


            <form method="POST">

                <input
                    type="hidden"
                    name="action"
                    value="open_shift"
                >

                <div class="form-group">

                    <label for="opening_cash">
                        Kas Awal
                    </label>

                    <input
                        type="number"
                        name="opening_cash"
                        id="opening_cash"
                        min="0"
                        step="1"
                        value="0"
                        placeholder="Contoh: 300000"
                        required
                    >

                    <small>
                        Masukkan jumlah uang fisik yang tersedia
                        di laci kasir saat mulai bekerja.
                    </small>

                </div>

                <button
                    type="submit"
                    class="btn btn-primary"
                    style="margin-top:15px;"
                >
                    Buka Shift
                </button>

            </form>

        </div>


    <?php else: ?>

        <div class="shift-hero">

            <div class="panel shift-status">

                <span class="shift-kicker">
                    Operasional Kasir
                </span>

                <h2>
                    Shift Sedang Berjalan
                </h2>

                <p>
                    Shift dimulai pada
                    <?= date(
                        "d/m/Y H:i",
                        strtotime($active_shift["start_time"])
                    ) ?>.

                    Seluruh transaksi dan pergerakan kas
                    <strong>
                        <?= htmlspecialchars($branch_name) ?>
                    </strong>
                    selama shift akan masuk ke dalam rekap.
                </p>

                <div class="shift-live">
                    <i></i>
                    SHIFT AKTIF
                </div>

                <div class="shift-branch">
                    ðŸ¢ <?= htmlspecialchars($branch_name) ?>
                </div>

            </div>


            <div class="shift-summary">

                <div class="shift-mini">

                    <small>
                        Total Transaksi
                    </small>

                    <strong>
                        <?= number_format($total_transactions) ?>
                    </strong>

                    <span>
                        transaksi selama shift
                    </span>

                </div>


                <div class="shift-mini">

                    <small>
                        Total Penjualan
                    </small>

                    <strong>
                        <?= rupiah($total_sales) ?>
                    </strong>

                    <span>
                        semua metode pembayaran
                    </span>

                </div>


                <div class="shift-mini">

                    <small>
                        Penjualan Tunai
                    </small>

                    <strong>
                        <?= rupiah($cash_sales) ?>
                    </strong>

                    <span>
                        masuk ke laci kas
                    </span>

                </div>


                <div class="shift-mini">

                    <small>
                        Kas Seharusnya
                    </small>

                    <strong>
                        <?= rupiah($expected_cash) ?>
                    </strong>

                    <span>
                        saldo fisik yang seharusnya tersedia
                    </span>

                </div>

            </div>

        </div>


        <div class="shift-grid">

            <div class="panel">

                <div class="panel-header">

                    <div>

                        <h3>
                            Rekap Pergerakan Kas
                        </h3>

                        <p>
                            Perhitungan kas fisik berdasarkan aktivitas
                            selama shift pada cabang ini.
                        </p>

                    </div>

                </div>


                <div class="shift-breakdown">

                    <div class="shift-line">

                        <span>
                            Kas Awal
                        </span>

                        <strong>
                            <?= rupiah(
                                $active_shift["opening_cash"]
                            ) ?>
                        </strong>

                    </div>


                    <div class="shift-line">

                        <span>
                            Penjualan Tunai
                        </span>

                        <strong style="color:#176f3d;">
                            + <?= rupiah($cash_sales) ?>
                        </strong>

                    </div>


                    <div class="shift-line">

                        <span>
                            Kas Masuk
                        </span>

                        <strong style="color:#176f3d;">
                            + <?= rupiah($cash_in) ?>
                        </strong>

                    </div>


                    <div class="shift-line">

                        <span>
                            Kas Keluar
                        </span>

                        <strong style="color:#d94a4a;">
                            âˆ’ <?= rupiah($cash_out) ?>
                        </strong>

                    </div>


                    <div class="shift-line">

                        <span>
                            Penjualan Debit / Transfer
                        </span>

                        <strong>
                            <?= rupiah($non_cash_sales) ?>
                        </strong>

                    </div>


                    <div class="shift-line shift-total">

                        <span>
                            Kas Seharusnya
                        </span>

                        <strong>
                            <?= rupiah($expected_cash) ?>
                        </strong>

                    </div>

                </div>

            </div>


            <div class="panel shift-close">

                <div class="panel-header">

                    <div>

                        <h3>
                            Tutup Shift
                        </h3>

                        <p>
                            Hitung uang fisik di laci kasir,
                            kemudian masukkan jumlah aktualnya.
                        </p>

                    </div>

                </div>


                <form
                    method="POST"
                    onsubmit="return confirm('Yakin ingin menutup shift ini? Pastikan kas fisik sudah dihitung dengan benar.');"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="close_shift"
                    >

                    <input
                        type="hidden"
                        name="shift_id"
                        value="<?= (int) $active_shift["id"] ?>"
                    >


                    <div class="form-group">

                        <label for="closing_cash">
                            Kas Fisik Saat Tutup
                        </label>

                        <input
                            type="number"
                            name="closing_cash"
                            id="closing_cash"
                            min="0"
                            step="1"
                            placeholder="Contoh: <?= number_format($expected_cash, 0, ",", ".") ?>"
                            required
                        >

                        <small>
                            Masukkan jumlah uang tunai yang benar-benar
                            tersedia di laci kasir.
                        </small>

                    </div>


                    <div class="shift-note">

                        <strong>
                            Cabang:
                        </strong>

                        <?= htmlspecialchars($branch_name) ?>

                        <br><br>

                        <strong>
                            Perhatian
                        </strong>

                        <br>

                        Kas fisik harus dihitung secara langsung.
                        Sistem akan membandingkannya dengan
                        <strong>
                            <?= rupiah($expected_cash) ?>
                        </strong>
                        untuk mendapatkan nilai selisih kas.

                    </div>


                    <button
                        type="submit"
                        class="btn btn-primary"
                        style="width:100%;margin-top:14px;"
                    >
                        Tutup Shift & Simpan Rekap
                    </button>

                </form>

            </div>

        </div>

    <?php endif; ?>


    <div class="panel shift-history">

        <div class="panel-header">

            <div>

                <h3>
                    Riwayat Shift
                </h3>

                <p>
                    Menampilkan maksimal 20 shift terakhir
                    untuk akun kasir ini.
                    Cabang mengikuti cabang akun yang sedang login.
                </p>

            </div>

        </div>


        <div class="table-responsive">

            <table class="data-table">

                <thead>

                    <tr>

                        <th>
                            Mulai
                        </th>

                        <th>
                            Selesai
                        </th>

                        <th>
                            Kas Awal
                        </th>

                        <th>
                            Kas Seharusnya
                        </th>

                        <th>
                            Kas Fisik
                        </th>

                        <th>
                            Selisih
                        </th>

                        <th>
                            Status
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php if ($shift_history->num_rows === 0): ?>

                    <tr>

                        <td
                            colspan="7"
                            class="shift-empty"
                        >
                            Belum ada riwayat shift.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php while ($row = $shift_history->fetch_assoc()): ?>

                        <tr>

                            <td>

                                <strong>

                                    <?= date(
                                        "d/m/Y",
                                        strtotime($row["start_time"])
                                    ) ?>

                                </strong>

                                <br>

                                <span class="mini">

                                    <?= date(
                                        "H:i",
                                        strtotime($row["start_time"])
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <?php if (!empty($row["end_time"])): ?>

                                    <strong>

                                        <?= date(
                                            "d/m/Y",
                                            strtotime($row["end_time"])
                                        ) ?>

                                    </strong>

                                    <br>

                                    <span class="mini">

                                        <?= date(
                                            "H:i",
                                            strtotime($row["end_time"])
                                        ) ?>

                                    </span>

                                <?php else: ?>

                                    <span class="mini">
                                        Belum selesai
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <?= rupiah(
                                    $row["opening_cash"]
                                ) ?>

                            </td>


                            <td>

                                <?php if ($row["status"] === "closed"): ?>

                                    <?= rupiah(
                                        $row["expected_cash"]
                                    ) ?>

                                <?php else: ?>

                                    <span class="mini">
                                        Sedang berjalan
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <?php if ($row["status"] === "closed"): ?>

                                    <?= rupiah(
                                        $row["closing_cash"]
                                    ) ?>

                                <?php else: ?>

                                    -

                                <?php endif; ?>

                            </td>


                            <td>

                                <?php if ($row["status"] === "open"): ?>

                                    -

                                <?php else: ?>

                                    <?php

                                    $difference =
                                        (float) (
                                            $row["difference_cash"] ?? 0
                                        );

                                    ?>

                                    <?php if ($difference > 0): ?>

                                        <span class="diff-positive">
                                            + <?= rupiah($difference) ?>
                                        </span>

                                    <?php elseif ($difference < 0): ?>

                                        <span class="diff-negative">
                                            âˆ’ <?= rupiah(abs($difference)) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="diff-zero">
                                            <?= rupiah(0) ?>
                                        </span>

                                    <?php endif; ?>

                                <?php endif; ?>

                            </td>


                            <td>

                                <?php if ($row["status"] === "open"): ?>

                                    <span class="status-open">

                                        <i></i>

                                        Aktif

                                    </span>

                                <?php else: ?>

                                    <span class="status-closed">
                                        Ditutup
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


<?php

$shift_history->free();

require_once __DIR__ . "/../includes/footer.php";

?>

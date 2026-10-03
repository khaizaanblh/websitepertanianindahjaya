<?php

require_once __DIR__ . "/../includes/auth.php";

require_kasir();

require_once __DIR__ . "/../config/database.php";

$title = "Riwayat Transaksi";

$base_url = get_base_url();

$message = $_GET["message"] ?? "";
$error   = $_GET["error"] ?? "";

$cashier_id = (int) ($_SESSION["user"]["id"] ?? 0);


/*
|--------------------------------------------------------------------------
| CABANG AKTIF
|--------------------------------------------------------------------------
|
| Prioritas:
| 1. branch_id dari session branch
| 2. branch_id dari session user
| 3. Ambil langsung dari tabel users berdasarkan kasir yang login
|
|--------------------------------------------------------------------------
*/

$branch_id = (int) (
    $_SESSION["branch"]["id"]
    ?? $_SESSION["user"]["branch_id"]
    ?? 0
);

$branch_name = trim(
    $_SESSION["branch"]["name"]
    ?? $_SESSION["user"]["branch_name"]
    ?? ""
);


/*
|--------------------------------------------------------------------------
| FALLBACK CABANG DARI USER LOGIN
|--------------------------------------------------------------------------
*/

if ($branch_id <= 0 && $cashier_id > 0) {

    $user_branch_stmt = $conn->prepare("
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

    if ($user_branch_stmt) {

        $user_branch_stmt->bind_param(
            "i",
            $cashier_id
        );

        $user_branch_stmt->execute();

        $user_branch =
            $user_branch_stmt
                ->get_result()
                ->fetch_assoc();

        $user_branch_stmt->close();

        if ($user_branch) {

            $branch_id =
                (int) ($user_branch["branch_id"] ?? 0);

            $branch_name =
                trim(
                    $user_branch["branch_name"] ?? ""
                );
        }
    }
}


/*
|--------------------------------------------------------------------------
| VALIDASI CABANG
|--------------------------------------------------------------------------
*/

if ($branch_id <= 0) {

    die(
        "Cabang aktif tidak ditemukan. Silakan login kembali."
    );
}


/*
|--------------------------------------------------------------------------
| VALIDASI CABANG AKTIF
|--------------------------------------------------------------------------
*/

$branch_stmt = $conn->prepare("
    SELECT
        id,
        name,
        status
    FROM branches
    WHERE id = ?
    LIMIT 1
");

if (!$branch_stmt) {

    die(
        "Gagal memeriksa cabang: " .
        htmlspecialchars(
            $conn->error,
            ENT_QUOTES,
            "UTF-8"
        )
    );
}

$branch_stmt->bind_param(
    "i",
    $branch_id
);

$branch_stmt->execute();

$active_branch =
    $branch_stmt
        ->get_result()
        ->fetch_assoc();

$branch_stmt->close();


if (
    !$active_branch ||
    ($active_branch["status"] ?? "") !== "active"
) {

    die(
        "Cabang yang sedang digunakan tidak aktif. Silakan login kembali."
    );
}


$branch_name =
    trim(
        $active_branch["name"] ?? $branch_name
    );


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function rupiah($amount)
{
    return "Rp " . number_format(
        (float) $amount,
        0,
        ",",
        "."
    );
}


function payment_label($method)
{
    return match ($method) {
        "cash"     => "Tunai",
        "debit"    => "Debit",
        "transfer" => "Transfer",
        default    => ucfirst((string) $method)
    };
}


function payment_class($method)
{
    return match ($method) {
        "cash"     => "payment-cash",
        "debit"    => "payment-debit",
        "transfer" => "payment-transfer",
        default    => ""
    };
}


function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}


/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/

$search = trim(
    $_GET["search"] ?? ""
);

$payment_method =
    $_GET["payment_method"] ?? "";

$date_from =
    trim($_GET["date_from"] ?? "");

$date_to =
    trim($_GET["date_to"] ?? "");


/*
|--------------------------------------------------------------------------
| VALIDASI FILTER PEMBAYARAN
|--------------------------------------------------------------------------
*/

$allowed_payment = [
    "",
    "cash",
    "debit",
    "transfer"
];

if (
    !in_array(
        $payment_method,
        $allowed_payment,
        true
    )
) {
    $payment_method = "";
}


/*
|--------------------------------------------------------------------------
| VALIDASI TANGGAL
|--------------------------------------------------------------------------
*/

function valid_date($date)
{
    if (
        !preg_match(
            "/^\d{4}-\d{2}-\d{2}$/",
            $date
        )
    ) {
        return false;
    }

    $parts = explode("-", $date);

    return checkdate(
        (int) $parts[1],
        (int) $parts[2],
        (int) $parts[0]
    );
}


if (
    $date_from !== "" &&
    !valid_date($date_from)
) {
    $date_from = "";
}


if (
    $date_to !== "" &&
    !valid_date($date_to)
) {
    $date_to = "";
}


/*
|--------------------------------------------------------------------------
| VALIDASI RENTANG TANGGAL
|--------------------------------------------------------------------------
*/

if (
    $date_from !== "" &&
    $date_to !== "" &&
    $date_from > $date_to
) {
    $temp = $date_from;
    $date_from = $date_to;
    $date_to = $temp;
}


/*
|--------------------------------------------------------------------------
| DETAIL TRANSAKSI
|--------------------------------------------------------------------------
|
| history.php?detail=INV-xxxx
|
| Detail hanya dapat dibuka oleh:
| - kasir yang sedang login
| - DAN cabang yang sedang aktif
|
|--------------------------------------------------------------------------
*/

$detail_invoice = trim(
    $_GET["detail"] ?? ""
);

$detail_sale  = null;
$detail_items = [];


if ($detail_invoice !== "") {

    /*
    |--------------------------------------------------------------------------
    | AMBIL TRANSAKSI
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.invoice,
            s.total,
            s.payment_method,
            s.paid,
            s.change_amount,
            s.receipt,
            s.created_at,
            s.branch_id,
            COALESCE(u.name, 'Kasir') AS cashier
        FROM sales s
        LEFT JOIN users u
            ON u.id = s.cashier_id
        WHERE s.invoice = ?
          AND s.cashier_id = ?
          AND s.branch_id = ?
        LIMIT 1
    ");

    if ($stmt) {

        $stmt->bind_param(
            "sii",
            $detail_invoice,
            $cashier_id,
            $branch_id
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $detail_sale = $result->fetch_assoc();

        $stmt->close();
    }


    /*
    |--------------------------------------------------------------------------
    | AMBIL ITEM TRANSAKSI
    |--------------------------------------------------------------------------
    */

    if ($detail_sale) {

        $stmt = $conn->prepare("
            SELECT
                si.id,
                si.product_id,
                si.qty,
                si.price,

                COALESCE(
                    p.name,
                    'Produk tidak tersedia'
                ) AS product_name,

                COALESCE(
                    p.category,
                    '-'
                ) AS category,

                COALESCE(
                    p.barcode,
                    ''
                ) AS barcode

            FROM sale_items si

            LEFT JOIN products p
                ON p.id = si.product_id

            WHERE si.sale_id = ?

            ORDER BY si.id ASC
        ");

        if ($stmt) {

            $sale_id = (int) $detail_sale["id"];

            $stmt->bind_param(
                "i",
                $sale_id
            );

            $stmt->execute();

            $result = $stmt->get_result();

            while (
                $item = $result->fetch_assoc()
            ) {

                $item["qty"] =
                    (int) $item["qty"];

                $item["price"] =
                    (float) $item["price"];

                $item["subtotal"] =
                    $item["qty"] *
                    $item["price"];

                $detail_items[] =
                    $item;
            }

            $stmt->close();
        }
    }
}


/*
|--------------------------------------------------------------------------
| QUERY TRANSAKSI
|--------------------------------------------------------------------------
|
| PENTING:
| Riwayat dibatasi dengan branch_id.
| cashier_id tetap dipakai agar kasir hanya
| melihat transaksi milik akunnya sendiri.
|
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        s.id,
        s.invoice,
        s.total,
        s.payment_method,
        s.paid,
        s.change_amount,
        s.receipt,
        s.created_at,
        s.branch_id,
        COALESCE(u.name, 'Kasir') AS cashier

    FROM sales s

    LEFT JOIN users u
        ON u.id = s.cashier_id

    WHERE s.branch_id = ?
      AND s.cashier_id = ?
";


$params = [
    $branch_id,
    $cashier_id
];

$types = "ii";


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $sql .= "
        AND (
            s.invoice LIKE ?
            OR CAST(s.total AS CHAR) LIKE ?
        )
    ";

    $keyword =
        "%" . $search . "%";

    $params[] = $keyword;
    $params[] = $keyword;

    $types .= "ss";
}


/*
|--------------------------------------------------------------------------
| FILTER PEMBAYARAN
|--------------------------------------------------------------------------
*/

if ($payment_method !== "") {

    $sql .= "
        AND s.payment_method = ?
    ";

    $params[] =
        $payment_method;

    $types .= "s";
}


/*
|--------------------------------------------------------------------------
| FILTER TANGGAL DARI
|--------------------------------------------------------------------------
*/

if ($date_from !== "") {

    $sql .= "
        AND s.created_at >= ?
    ";

    $params[] =
        $date_from . " 00:00:00";

    $types .= "s";
}


/*
|--------------------------------------------------------------------------
| FILTER TANGGAL SAMPAI
|--------------------------------------------------------------------------
*/

if ($date_to !== "") {

    $sql .= "
        AND s.created_at < DATE_ADD(
            ?,
            INTERVAL 1 DAY
        )
    ";

    $params[] =
        $date_to . " 00:00:00";

    $types .= "s";
}


/*
|--------------------------------------------------------------------------
| ORDER
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY s.id DESC
";


/*
|--------------------------------------------------------------------------
| EKSEKUSI QUERY
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare($sql);

if (!$stmt) {

    die(
        "Gagal menyiapkan query transaksi: " .
        e($conn->error)
    );
}


/*
|--------------------------------------------------------------------------
| BIND PARAMETER
|--------------------------------------------------------------------------
|
| Dibuat menggunakan reference supaya aman
| untuk berbagai versi PHP.
|
|--------------------------------------------------------------------------
*/

$bind_values = [];

$bind_values[] = &$types;

foreach ($params as $key => $value) {
    $bind_values[] = &$params[$key];
}

call_user_func_array(
    [$stmt, "bind_param"],
    $bind_values
);


$stmt->execute();

$sales = $stmt->get_result();


/*
|--------------------------------------------------------------------------
| RINGKASAN
|--------------------------------------------------------------------------
*/

$total_transactions = 0;

$total_sales = 0;

$total_cash = 0;

$total_non_cash = 0;

$transaction_rows = [];


while (
    $row = $sales->fetch_assoc()
) {

    $total_transactions++;


    $row["total"] =
        (float) $row["total"];

    $row["paid"] =
        (float) $row["paid"];

    $row["change_amount"] =
        (float) $row["change_amount"];


    $total_sales +=
        $row["total"];


    if (
        $row["payment_method"] === "cash"
    ) {

        $total_cash +=
            $row["total"];

    } else {

        $total_non_cash +=
            $row["total"];
    }


    $transaction_rows[] =
        $row;
}


$stmt->close();


require __DIR__ . "/../includes/header.php";

?>

<style>

/* =========================================================
   HISTORY PAGE
========================================================= */

.history-page {
    width: 100%;
}


/* =========================================================
   ALERT
========================================================= */

.history-alert {
    display: flex;
    align-items: center;
    gap: 10px;

    margin-bottom: 16px;

    padding: 12px 15px;

    border-radius: 12px;

    font-size: 10px;
    font-weight: 700;
}

.history-alert.success {
    background: #eaf6ed;
    color: #176f3d;
    border: 1px solid #cce6d2;
}

.history-alert.error {
    background: #fff0f0;
    color: #b63f3f;
    border: 1px solid #f0cccc;
}


/* =========================================================
   HERO
========================================================= */

.history-hero {
    display: grid;

    grid-template-columns:
        minmax(0, 1.25fr)
        minmax(320px, .75fr);

    gap: 18px;

    margin-bottom: 18px;
}

.history-hero-main {
    position: relative;
    overflow: hidden;

    padding: 24px;

    border-radius: 18px;

    background:
        linear-gradient(
            135deg,
            #10351f,
            #176f3d 65%,
            #239454
        );

    color: #fff;

    min-height: 180px;

    box-shadow:
        0 15px 40px #173c2420;
}

.history-hero-main::before {
    content: "";

    position: absolute;

    width: 230px;
    height: 230px;

    right: -80px;
    top: -120px;

    border-radius: 50%;

    background: #ffffff0d;

    border: 1px solid #ffffff12;
}

.history-hero-main::after {
    content: "";

    position: absolute;

    width: 120px;
    height: 120px;

    right: 100px;
    bottom: -80px;

    border-radius: 50%;

    background: #91c83e12;
}

.history-kicker {
    position: relative;
    z-index: 1;

    display: inline-flex;

    align-items: center;

    gap: 7px;

    color: #c5e1ce;

    font-size: 9px;
    font-weight: 900;

    letter-spacing: .11em;

    text-transform: uppercase;
}

.history-kicker::before {
    content: "";

    width: 7px;
    height: 7px;

    border-radius: 50%;

    background: #91c83e;

    box-shadow:
        0 0 0 4px #91c83e22;
}

.history-hero-main h2 {
    position: relative;
    z-index: 1;

    margin: 12px 0 7px;

    font-size: 26px;

    letter-spacing: -.045em;
}

.history-hero-main p {
    position: relative;
    z-index: 1;

    max-width: 470px;

    margin: 0;

    color: #d1e2d6;

    font-size: 10px;

    line-height: 1.65;
}


/* =========================================================
   HERO STATS
========================================================= */

.history-hero-stats {
    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 12px;
}

.history-stat {
    position: relative;
    overflow: hidden;

    padding: 16px;

    background: #fff;

    border: 1px solid var(--border);

    border-radius: 15px;

    box-shadow:
        0 8px 25px #173c240b;
}

.history-stat::after {
    content: "";

    position: absolute;

    width: 65px;
    height: 65px;

    right: -25px;
    top: -25px;

    border-radius: 50%;

    background: #176f3d0c;
}

.history-stat small {
    display: block;

    color: var(--muted);

    font-size: 9px;
    font-weight: 850;

    text-transform: uppercase;

    letter-spacing: .06em;
}

.history-stat strong {
    display: block;

    margin-top: 8px;

    font-size: 19px;

    letter-spacing: -.04em;
}

.history-stat span {
    display: block;

    margin-top: 4px;

    color: var(--muted);

    font-size: 9px;
}


/* =========================================================
   FILTER
========================================================= */

.history-filter {
    margin-top: 0;

    padding: 18px;

    background: #fff;

    border: 1px solid var(--border);

    border-radius: 16px;

    box-shadow:
        0 8px 25px #173c240b;
}

.history-filter-head {
    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 12px;

    margin-bottom: 13px;
}

.history-filter-head h2 {
    margin: 0;

    font-size: 14px;

    letter-spacing: -.025em;
}

.history-filter-head span {
    color: var(--muted);

    font-size: 9px;
}

.history-filter-grid {
    display: grid;

    grid-template-columns:
        minmax(180px, 1.5fr)
        minmax(130px, .8fr)
        minmax(130px, .8fr)
        minmax(150px, .9fr)
        auto;

    gap: 9px;

    align-items: end;
}

.history-field label {
    display: block;

    margin-bottom: 5px;

    color: #526058;

    font-size: 9px;
    font-weight: 800;
}

.history-field input,
.history-field select {
    width: 100%;

    padding: 10px 11px;

    border:
        1px solid var(--border);

    border-radius: 9px;

    background: #fff;

    color: var(--text);

    outline: none;

    font-size: 10px;

    box-sizing: border-box;
}

.history-field input:focus,
.history-field select:focus {
    border-color: #8fc5a3;

    box-shadow:
        0 0 0 3px #176f3d10;
}

.history-filter-actions {
    display: flex;

    gap: 7px;
}

.history-filter-actions .btn {
    white-space: nowrap;
}


/* =========================================================
   TABLE PANEL
========================================================= */

.history-panel {
    margin-top: 16px;

    background: #fff;

    border: 1px solid var(--border);

    border-radius: 16px;

    overflow: hidden;

    box-shadow:
        0 8px 25px #173c240b;
}

.history-panel-head {
    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 12px;

    padding: 18px 20px;

    border-bottom:
        1px solid var(--border);
}

.history-panel-head h2 {
    margin: 0;

    font-size: 14px;

    letter-spacing: -.025em;
}

.history-panel-head p {
    margin: 4px 0 0;

    color: var(--muted);

    font-size: 9px;
}

.history-count {
    padding: 6px 10px;

    border-radius: 20px;

    background: #f1f7f2;

    color: var(--green);

    font-size: 9px;

    font-weight: 850;

    white-space: nowrap;
}

.history-table-wrap {
    width: 100%;

    overflow-x: auto;
}

.history-table {
    width: 100%;

    min-width: 820px;

    border-collapse: collapse;
}

.history-table th {
    padding: 12px 14px;

    background: #f7faf7;

    border-bottom:
        1px solid var(--border);

    color: var(--muted);

    text-align: left;

    font-size: 8px;

    font-weight: 850;

    text-transform: uppercase;

    letter-spacing: .07em;

    white-space: nowrap;
}

.history-table td {
    padding: 13px 14px;

    border-bottom:
        1px solid var(--border);

    color: #344239;

    font-size: 10px;

    vertical-align: middle;
}

.history-table tbody tr {
    transition: .15s;
}

.history-table tbody tr:hover {
    background: #fbfdfb;
}

.history-table tbody tr:last-child td {
    border-bottom: 0;
}


/* =========================================================
   INVOICE
========================================================= */

.invoice-number {
    display: block;

    color: var(--text);

    font-weight: 900;

    font-size: 10px;
}

.invoice-date {
    display: block;

    margin-top: 4px;

    color: var(--muted);

    font-size: 8px;
}


/* =========================================================
   PAYMENT
========================================================= */

.payment-badge {
    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 5px 9px;

    border-radius: 20px;

    font-size: 8px;

    font-weight: 850;
}

.payment-badge::before {
    content: "";

    width: 5px;
    height: 5px;

    border-radius: 50%;

    background: currentColor;
}

.payment-cash {
    background: #eaf6ed;

    color: var(--green);
}

.payment-debit {
    background: #eef3ff;

    color: #4869a8;
}

.payment-transfer {
    background: #fff3df;

    color: #ad6200;
}


/* =========================================================
   TOTAL
========================================================= */

.history-total {
    font-weight: 900;

    color: var(--text);

    white-space: nowrap;
}

.history-total.cash {
    color: var(--green);
}


/* =========================================================
   ACTION
========================================================= */

.history-action {
    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 5px;

    padding: 7px 11px;

    border:
        1px solid var(--border);

    border-radius: 8px;

    background: #fff;

    color: #435048;

    text-decoration: none;

    font-size: 8px;

    font-weight: 850;

    transition: .15s;

    cursor: pointer;
}

.history-action:hover {
    border-color: #9bc8a8;

    color: var(--green);

    background: #f7faf7;
}

.history-action.primary {
    background: #176f3d;

    color: #fff;

    border-color: #176f3d;
}

.history-action.primary:hover {
    background: #125b31;

    color: #fff;
}


/* =========================================================
   EMPTY
========================================================= */

.history-empty {
    padding: 55px 25px;

    text-align: center;
}

.history-empty-icon {
    width: 52px;
    height: 52px;

    display: grid;

    place-items: center;

    margin: 0 auto 12px;

    border-radius: 15px;

    background: #f0f6f1;

    font-size: 21px;
}

.history-empty h3 {
    margin: 0;

    font-size: 13px;
}

.history-empty p {
    max-width: 380px;

    margin: 7px auto 0;

    color: var(--muted);

    font-size: 9px;

    line-height: 1.6;
}


/* =========================================================
   INFO
========================================================= */

.history-info {
    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 12px;

    margin-top: 16px;
}

.history-info-card {
    padding: 14px;

    background: #fff;

    border:
        1px solid var(--border);

    border-radius: 13px;
}

.history-info-card strong {
    display: block;

    font-size: 10px;

    margin-bottom: 5px;
}

.history-info-card span {
    display: block;

    color: var(--muted);

    font-size: 8px;

    line-height: 1.55;
}


/* =========================================================
   DETAIL MODAL
========================================================= */

.transaction-modal {
    position: fixed;

    inset: 0;

    z-index: 9999;

    display: flex;

    align-items: center;

    justify-content: center;

    padding: 22px;

    background: #10231980;

    backdrop-filter: blur(5px);
}

.transaction-modal-box {
    width: min(680px, 100%);

    max-height:
        calc(100vh - 44px);

    overflow-y: auto;

    background: #fff;

    border:
        1px solid #dce7de;

    border-radius: 20px;

    box-shadow:
        0 30px 80px #0d24184d;
}

.transaction-modal-head {
    display: flex;

    justify-content: space-between;

    align-items: flex-start;

    gap: 15px;

    padding: 20px 22px;

    border-bottom:
        1px solid var(--border);
}

.transaction-modal-title {
    display: flex;

    gap: 12px;

    align-items: flex-start;
}

.transaction-modal-icon {
    width: 42px;
    height: 42px;

    display: grid;

    place-items: center;

    flex: 0 0 42px;

    border-radius: 12px;

    background: #eaf6ed;

    color: var(--green);

    font-size: 18px;
}

.transaction-modal-head h2 {
    margin: 0;

    font-size: 17px;

    letter-spacing: -.035em;
}

.transaction-modal-head p {
    margin: 4px 0 0;

    color: var(--muted);

    font-size: 9px;
}

.modal-close {
    width: 32px;
    height: 32px;

    display: grid;

    place-items: center;

    border:
        1px solid var(--border);

    border-radius: 9px;

    background: #fff;

    color: #526058;

    cursor: pointer;

    font-size: 15px;

    transition: .15s;
}

.modal-close:hover {
    background: #f5f8f5;

    color: var(--green);

    border-color: #a9cbb3;
}

.transaction-modal-body {
    padding: 20px 22px;
}


/* =========================================================
   TRANSACTION META
========================================================= */

.transaction-meta {
    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 10px;

    margin-bottom: 18px;
}

.transaction-meta-card {
    padding: 12px;

    border:
        1px solid var(--border);

    border-radius: 11px;

    background: #f9fbf9;
}

.transaction-meta-card small {
    display: block;

    color: var(--muted);

    font-size: 8px;

    font-weight: 800;

    text-transform: uppercase;

    letter-spacing: .05em;
}

.transaction-meta-card strong {
    display: block;

    margin-top: 5px;

    font-size: 10px;

    color: var(--text);

    word-break: break-word;
}


/* =========================================================
   RECEIPT ITEMS
========================================================= */

.receipt-items {
    width: 100%;

    border-collapse: collapse;

    margin-top: 5px;
}

.receipt-items th {
    padding: 10px 8px;

    border-bottom:
        1px solid var(--border);

    color: var(--muted);

    text-align: left;

    font-size: 8px;

    text-transform: uppercase;

    letter-spacing: .05em;
}

.receipt-items td {
    padding: 12px 8px;

    border-bottom:
        1px solid var(--border);

    font-size: 10px;

    vertical-align: top;
}

.receipt-items tr:last-child td {
    border-bottom: 0;
}

.item-name {
    font-weight: 800;

    color: var(--text);
}

.item-meta {
    display: block;

    margin-top: 3px;

    color: var(--muted);

    font-size: 8px;
}

.item-price {
    white-space: nowrap;

    color: #526058;
}

.item-qty {
    text-align: center;

    white-space: nowrap;
}

.item-subtotal {
    text-align: right;

    white-space: nowrap;

    font-weight: 850;
}


/* =========================================================
   TRANSACTION SUMMARY
========================================================= */

.transaction-summary {
    margin-top: 15px;

    padding: 15px;

    border-radius: 13px;

    background: #f7faf7;

    border:
        1px solid #dfe9e1;
}

.transaction-summary-row {
    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    padding: 6px 0;

    color: #526058;

    font-size: 10px;
}

.transaction-summary-row.total {
    margin-top: 7px;

    padding-top: 12px;

    border-top:
        1px dashed #bfcfc3;

    color: var(--text);

    font-size: 13px;

    font-weight: 900;
}

.transaction-summary-row.total strong {
    color: var(--green);
}

.transaction-summary-row.change strong {
    color: var(--green);
}

.transaction-payment {
    display: flex;

    align-items: center;

    gap: 8px;
}


/* =========================================================
   MODAL ACTION
========================================================= */

.transaction-modal-actions {
    display: flex;

    justify-content: flex-end;

    gap: 8px;

    padding: 16px 22px;

    border-top:
        1px solid var(--border);

    background: #fbfcfb;
}

.transaction-modal-actions .btn {
    min-width: 110px;
}


/* =========================================================
   MODAL ERROR
========================================================= */

.detail-error {
    margin: 20px;

    padding: 16px;

    border-radius: 12px;

    background: #fff1f1;

    border:
        1px solid #efcccc;

    color: #a83d3d;

    font-size: 10px;

    line-height: 1.6;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1050px) {

    .history-hero {
        grid-template-columns: 1fr;
    }

    .history-filter-grid {
        grid-template-columns:
            repeat(2, 1fr);
    }

    .history-filter-actions {
        grid-column: span 2;
    }
}


@media (max-width: 700px) {

    .history-hero-stats {
        grid-template-columns:
            repeat(2, 1fr);
    }

    .history-filter-grid {
        grid-template-columns: 1fr;
    }

    .history-filter-actions {
        grid-column: auto;
    }

    .history-info {
        grid-template-columns: 1fr;
    }

    .history-panel-head {
        align-items: flex-start;

        flex-direction: column;
    }

    .transaction-meta {
        grid-template-columns: 1fr;
    }

    .transaction-modal {
        padding: 10px;
    }

    .transaction-modal-box {
        max-height:
            calc(100vh - 20px);

        border-radius: 16px;
    }

    .transaction-modal-head,
    .transaction-modal-body,
    .transaction-modal-actions {
        padding-left: 16px;
        padding-right: 16px;
    }
}


@media (max-width: 500px) {

    .history-hero-main {
        padding: 19px;
    }

    .history-hero-main h2 {
        font-size: 22px;
    }

    .history-hero-stats {
        grid-template-columns:
            1fr 1fr;
    }

    .history-stat {
        padding: 13px;
    }

    .history-stat strong {
        font-size: 16px;
    }

    .history-filter {
        padding: 14px;
    }

    .history-filter-actions {
        flex-direction: column;
    }

    .history-filter-actions .btn {
        width: 100%;
        text-align: center;
    }

    .transaction-modal-actions {
        flex-direction: column;
    }

    .transaction-modal-actions .btn {
        width: 100%;
    }
}


/* =========================================================
   PRINT STRUK 80MM
========================================================= */

@media print {

    @page {
        size: 80mm auto;
        margin: 0;
    }

    html,
    body {
        width: 80mm !important;

        margin: 0 !important;
        padding: 0 !important;

        background: #fff !important;
    }

    body * {
        visibility: hidden !important;
    }

    .transaction-modal,
    .transaction-modal * {
        visibility: visible !important;
    }

    .transaction-modal {
        position: static !important;

        display: block !important;

        width: 80mm !important;

        min-height: 0 !important;

        padding: 0 !important;

        background: #fff !important;

        box-shadow: none !important;
    }

    .transaction-modal-box {
        width: 80mm !important;

        max-height: none !important;

        overflow: visible !important;

        border: 0 !important;

        border-radius: 0 !important;

        box-shadow: none !important;

        background: #fff !important;
    }

    .transaction-modal-head {
        display: block !important;

        padding:
            4mm 5mm 2mm !important;

        border: 0 !important;

        text-align: center !important;
    }

    .transaction-modal-title {
        display: block !important;
    }

    .transaction-modal-icon,
    .modal-close {
        display: none !important;
    }

    .transaction-modal-head h2 {
        font-size: 14px !important;

        color: #000 !important;
    }

    .transaction-modal-head p {
        font-size: 8px !important;

        color: #000 !important;
    }

    .transaction-modal-body {
        padding:
            2mm 5mm 4mm !important;
    }

    .transaction-meta {
        display: block !important;

        margin-bottom: 3mm !important;
    }

    .transaction-meta-card {
        display: flex !important;

        justify-content: space-between !important;

        gap: 5px !important;

        padding: 1.5mm 0 !important;

        border: 0 !important;

        background: #fff !important;

        border-radius: 0 !important;
    }

    .transaction-meta-card small,
    .transaction-meta-card strong {
        color: #000 !important;

        font-size: 8px !important;

        margin: 0 !important;
    }

    .receipt-items {
        font-size: 8px !important;
    }

    .receipt-items th {
        padding:
            1.5mm 1mm !important;

        font-size: 7px !important;

        color: #000 !important;

        border-bottom:
            1px dashed #000 !important;
    }

    .receipt-items td {
        padding:
            1.5mm 1mm !important;

        font-size: 8px !important;

        color: #000 !important;

        border-bottom: 0 !important;
    }

    .item-meta {
        color: #000 !important;

        font-size: 7px !important;
    }

    .transaction-summary {
        margin-top: 3mm !important;

        padding: 2mm 0 !important;

        border: 0 !important;

        border-top:
            1px dashed #000 !important;

        border-radius: 0 !important;

        background: #fff !important;
    }

    .transaction-summary-row {
        padding: 1mm 0 !important;

        font-size: 8px !important;

        color: #000 !important;
    }

    .transaction-summary-row.total {
        padding-top: 2mm !important;

        border-top:
            1px dashed #000 !important;

        font-size: 10px !important;
    }

    .transaction-summary-row.total strong,
    .transaction-summary-row.change strong {
        color: #000 !important;
    }

    .transaction-modal-actions {
        display: none !important;
    }
}

</style>

<div class="history-page">

```
<?php if ($message): ?>

    <div class="history-alert success">

        âœ“

        <?= e($message) ?>

    </div>

<?php endif; ?>


<?php if ($error): ?>

    <div class="history-alert error">

        !

        <?= e($error) ?>

    </div>

<?php endif; ?>


<!-- =====================================================
     HERO
====================================================== -->

<div class="history-hero">

    <div class="history-hero-main">

        <span class="history-kicker">
            Aktivitas Kasir
        </span>

        <h2>
            Riwayat Transaksi
        </h2>

        <p>
            Pantau transaksi yang diproses menggunakan
            akun kasir ini pada cabang
            <strong><?= e($branch_name) ?></strong>.
            Buka detail transaksi untuk
            melihat barang, pembayaran, kembalian,
            serta mencetak struk.
        </p>

    </div>


    <div class="history-hero-stats">

        <div class="history-stat">

            <small>
                Total Transaksi
            </small>

            <strong>
                <?= number_format(
                    $total_transactions,
                    0,
                    ",",
                    "."
                ) ?>
            </strong>

            <span>
                transaksi ditemukan
            </span>

        </div>


        <div class="history-stat">

            <small>
                Total Penjualan
            </small>

            <strong>
                <?= rupiah($total_sales) ?>
            </strong>

            <span>
                seluruh pembayaran
            </span>

        </div>


        <div class="history-stat">

            <small>
                Penjualan Tunai
            </small>

            <strong>
                <?= rupiah($total_cash) ?>
            </strong>

            <span>
                masuk kas fisik
            </span>

        </div>


        <div class="history-stat">

            <small>
                Non-Tunai
            </small>

            <strong>
                <?= rupiah($total_non_cash) ?>
            </strong>

            <span>
                debit & transfer
            </span>

        </div>

    </div>

</div>


<!-- =====================================================
     FILTER
====================================================== -->

<form
    method="GET"
    class="history-filter"
>

    <div class="history-filter-head">

        <div>

            <h2>
                Filter Transaksi
            </h2>

            <span>
                Cari berdasarkan invoice,
                metode pembayaran, atau periode.
            </span>

        </div>

    </div>


    <div class="history-filter-grid">

        <div class="history-field">

            <label>
                PENCARIAN
            </label>

            <input
                type="search"
                name="search"
                value="<?= e($search) ?>"
                placeholder="Cari nomor invoice..."
                autocomplete="off"
            >

        </div>


        <div class="history-field">

            <label>
                DARI TANGGAL
            </label>

            <input
                type="date"
                name="date_from"
                value="<?= e($date_from) ?>"
            >

        </div>


        <div class="history-field">

            <label>
                SAMPAI TANGGAL
            </label>

            <input
                type="date"
                name="date_to"
                value="<?= e($date_to) ?>"
            >

        </div>


        <div class="history-field">

            <label>
                METODE PEMBAYARAN
            </label>

            <select name="payment_method">

                <option value="">
                    Semua metode
                </option>

                <option
                    value="cash"
                    <?= $payment_method === "cash"
                        ? "selected"
                        : "" ?>
                >
                    Tunai
                </option>

                <option
                    value="debit"
                    <?= $payment_method === "debit"
                        ? "selected"
                        : "" ?>
                >
                    Debit
                </option>

                <option
                    value="transfer"
                    <?= $payment_method === "transfer"
                        ? "selected"
                        : "" ?>
                >
                    Transfer
                </option>

            </select>

        </div>


        <div class="history-filter-actions">

            <button
                type="submit"
                class="btn primary"
            >
                Terapkan
            </button>


            <a
                href="<?= $base_url ?>/kasir/history.php"
                class="btn"
                style="text-decoration:none;"
            >
                Reset
            </a>

        </div>

    </div>

</form>


<!-- =====================================================
     TRANSACTIONS
====================================================== -->

<div class="history-panel">

    <div class="history-panel-head">

        <div>

            <h2>
                Daftar Transaksi
            </h2>

            <p>
                Menampilkan transaksi akun kasir
                pada cabang
                <strong><?= e($branch_name) ?></strong>.
            </p>

        </div>


        <span class="history-count">

            <?= number_format(
                $total_transactions,
                0,
                ",",
                "."
            ) ?>

            transaksi

        </span>

    </div>


    <?php if (empty($transaction_rows)): ?>

        <div class="history-empty">

            <div class="history-empty-icon">
                ðŸ§¾
            </div>

            <h3>
                Belum ada transaksi
            </h3>

            <p>
                Tidak ada transaksi yang sesuai
                dengan filter yang dipilih
                pada cabang ini.
                Silakan ubah pencarian atau mulai
                transaksi baru melalui halaman kasir.
            </p>

            <a
                href="<?= $base_url ?>/kasir/pos.php"
                class="btn primary"
                style="
                    display:inline-block;
                    margin-top:15px;
                    text-decoration:none;
                "
            >
                + Transaksi Baru
            </a>

        </div>

    <?php else: ?>

        <div class="history-table-wrap">

            <table class="history-table">

                <thead>

                    <tr>

                        <th>
                            Invoice
                        </th>

                        <th>
                            Total
                        </th>

                        <th>
                            Pembayaran
                        </th>

                        <th>
                            Dibayar
                        </th>

                        <th>
                            Kembalian
                        </th>

                        <th>
                            Waktu
                        </th>

                        <th>
                            Aksi
                        </th>

                    </tr>

                </thead>


                <tbody>

                    <?php foreach (
                        $transaction_rows
                        as $s
                    ): ?>

                        <tr>

                            <td>

                                <span class="invoice-number">
                                    <?= e($s["invoice"]) ?>
                                </span>

                                <span class="invoice-date">
                                    #<?= (int) $s["id"] ?>
                                </span>

                            </td>


                            <td>

                                <span class="history-total">

                                    <?= rupiah(
                                        $s["total"]
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <span
                                    class="payment-badge <?= e(
                                        payment_class(
                                            $s["payment_method"]
                                        )
                                    ) ?>"
                                >

                                    <?= e(
                                        payment_label(
                                            $s["payment_method"]
                                        )
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <?= rupiah(
                                    $s["paid"]
                                ) ?>

                            </td>


                            <td>

                                <?php if (
                                    $s["payment_method"] ===
                                    "cash"
                                ): ?>

                                    <span
                                        style="
                                            color:var(--green);
                                            font-weight:800;
                                        "
                                    >

                                        <?= rupiah(
                                            $s["change_amount"]
                                        ) ?>

                                    </span>

                                <?php else: ?>

                                    <span
                                        style="
                                            color:var(--muted);
                                        "
                                    >
                                        â€”
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <?php

                                $created_timestamp =
                                    strtotime(
                                        $s["created_at"]
                                    );

                                ?>

                                <?php if (
                                    $created_timestamp !== false
                                ): ?>

                                    <?= date(
                                        "d M Y",
                                        $created_timestamp
                                    ) ?>

                                    <span
                                        style="
                                            display:block;
                                            margin-top:3px;
                                            color:var(--muted);
                                            font-size:8px;
                                        "
                                    >

                                        <?= date(
                                            "H:i",
                                            $created_timestamp
                                        ) ?>

                                        WIB

                                    </span>

                                <?php else: ?>

                                    â€”

                                <?php endif; ?>

                            </td>


                            <td>

                                <a
                                    href="<?= $base_url ?>/kasir/history.php?detail=<?= urlencode(
                                        $s["invoice"]
                                    ) ?>&search=<?= urlencode(
                                        $search
                                    ) ?>&payment_method=<?= urlencode(
                                        $payment_method
                                    ) ?>&date_from=<?= urlencode(
                                        $date_from
                                    ) ?>&date_to=<?= urlencode(
                                        $date_to
                                    ) ?>"
                                    class="history-action primary"
                                >
                                    Detail
                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</div>


<!-- =====================================================
     INFO
====================================================== -->

<div class="history-info">

    <div class="history-info-card">

        <strong>
            ðŸ’µ Pembayaran Tunai
        </strong>

        <span>
            Transaksi tunai masuk ke perhitungan
            kas fisik dan digunakan dalam rekap shift.
        </span>

    </div>


    <div class="history-info-card">

        <strong>
            ðŸ’³ Debit
        </strong>

        <span>
            Pembayaran debit tercatat sebagai
            transaksi non-tunai dan tidak masuk
            ke uang fisik laci kasir.
        </span>

    </div>


    <div class="history-info-card">

        <strong>
            ðŸ¦ Transfer
        </strong>

        <span>
            Pembayaran transfer dicatat sebagai
            transaksi non-tunai dan dapat dilihat
            kembali melalui detail transaksi.
        </span>

    </div>

</div>
```

</div>

<?php if ($detail_invoice !== ""): ?>

```
<!-- =====================================================
     DETAIL TRANSACTION MODAL
====================================================== -->

<div
    class="transaction-modal"
    id="transactionModal"
    onclick="closeDetailOutside(event)"
>

    <div
        class="transaction-modal-box"
        onclick="event.stopPropagation()"
    >

        <?php if (!$detail_sale): ?>

            <div class="transaction-modal-head">

                <div class="transaction-modal-title">

                    <div class="transaction-modal-icon">
                        !
                    </div>

                    <div>

                        <h2>
                            Transaksi Tidak Ditemukan
                        </h2>

                        <p>
                            Detail transaksi tidak tersedia,
                            bukan milik akun kasir ini,
                            atau berada di cabang lain.
                        </p>

                    </div>

                </div>


                <button
                    type="button"
                    class="modal-close"
                    onclick="closeDetail()"
                    aria-label="Tutup"
                >
                    Ã—
                </button>

            </div>


            <div class="detail-error">

                Transaksi

                <strong>
                    <?= e($detail_invoice) ?>
                </strong>

                tidak ditemukan pada cabang

                <strong>
                    <?= e($branch_name) ?>
                </strong>.

                <br><br>

                Silakan kembali ke daftar transaksi
                dan pilih transaksi yang tersedia.

            </div>


            <div class="transaction-modal-actions">

                <button
                    type="button"
                    class="btn"
                    onclick="closeDetail()"
                >
                    Tutup
                </button>

            </div>


        <?php else: ?>

            <div class="transaction-modal-head">

                <div class="transaction-modal-title">

                    <div class="transaction-modal-icon">
                        ðŸ§¾
                    </div>

                    <div>

                        <h2>
                            Detail Transaksi
                        </h2>

                        <p>
                            <?= e(
                                $detail_sale["invoice"]
                            ) ?>
                        </p>

                    </div>

                </div>


                <button
                    type="button"
                    class="modal-close"
                    onclick="closeDetail()"
                    aria-label="Tutup"
                >
                    Ã—
                </button>

            </div>


            <div class="transaction-modal-body">

                <!-- =================================================
                     TRANSACTION META
                ================================================== -->

                <div class="transaction-meta">

                    <div class="transaction-meta-card">

                        <small>
                            Invoice
                        </small>

                        <strong>
                            <?= e(
                                $detail_sale["invoice"]
                            ) ?>
                        </strong>

                    </div>


                    <div class="transaction-meta-card">

                        <small>
                            Cabang
                        </small>

                        <strong>
                            <?= e($branch_name) ?>
                        </strong>

                    </div>


                    <div class="transaction-meta-card">

                        <small>
                            Tanggal & Waktu
                        </small>

                        <strong>

                            <?php

                            $detail_timestamp =
                                strtotime(
                                    $detail_sale["created_at"]
                                );

                            ?>

                            <?php if (
                                $detail_timestamp !== false
                            ): ?>

                                <?= date(
                                    "d M Y, H:i",
                                    $detail_timestamp
                                ) ?>

                                WIB

                            <?php else: ?>

                                â€”

                            <?php endif; ?>

                        </strong>

                    </div>


                    <div class="transaction-meta-card">

                        <small>
                            Kasir
                        </small>

                        <strong>
                            <?= e(
                                $detail_sale["cashier"]
                            ) ?>
                        </strong>

                    </div>

                </div>


                <!-- =================================================
                     ITEMS
                ================================================== -->

                <table class="receipt-items">

                    <thead>

                        <tr>

                            <th>
                                Produk
                            </th>

                            <th>
                                Harga
                            </th>

                            <th style="text-align:center;">
                                Qty
                            </th>

                            <th style="text-align:right;">
                                Subtotal
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (
                            empty($detail_items)
                        ): ?>

                            <tr>

                                <td
                                    colspan="4"
                                    style="
                                        text-align:center;
                                        color:var(--muted);
                                    "
                                >
                                    Tidak ada detail barang
                                    pada transaksi ini.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach (
                                $detail_items
                                as $item
                            ): ?>

                                <tr>

                                    <td>

                                        <span class="item-name">

                                            <?= e(
                                                $item["product_name"]
                                            ) ?>

                                        </span>

                                        <span class="item-meta">

                                            <?= e(
                                                $item["category"]
                                            ) ?>

                                            <?php if (
                                                !empty(
                                                    $item["barcode"]
                                                )
                                            ): ?>

                                                â€¢
                                                <?= e(
                                                    $item["barcode"]
                                                ) ?>

                                            <?php endif; ?>

                                        </span>

                                    </td>


                                    <td class="item-price">

                                        <?= rupiah(
                                            $item["price"]
                                        ) ?>

                                    </td>


                                    <td class="item-qty">

                                        <?= number_format(
                                            (int) $item["qty"],
                                            0,
                                            ",",
                                            "."
                                        ) ?>

                                    </td>


                                    <td class="item-subtotal">

                                        <?= rupiah(
                                            $item["subtotal"]
                                        ) ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>


                <!-- =================================================
                     PAYMENT SUMMARY
                ================================================== -->

                <?php

                $total_item_qty = 0;

                foreach (
                    $detail_items
                    as $item
                ) {

                    $total_item_qty +=
                        (int) $item["qty"];
                }

                ?>


                <div class="transaction-summary">

                    <div class="transaction-summary-row">

                        <span>
                            Jumlah Item
                        </span>

                        <strong>

                            <?= number_format(
                                $total_item_qty,
                                0,
                                ",",
                                "."
                            ) ?>

                            item

                        </strong>

                    </div>


                    <div class="transaction-summary-row">

                        <span>
                            Pembayaran
                        </span>

                        <strong
                            class="transaction-payment"
                        >

                            <span
                                class="payment-badge <?= e(
                                    payment_class(
                                        $detail_sale[
                                            "payment_method"
                                        ]
                                    )
                                ) ?>"
                            >

                                <?= e(
                                    payment_label(
                                        $detail_sale[
                                            "payment_method"
                                        ]
                                    )
                                ) ?>

                            </span>

                        </strong>

                    </div>


                    <div class="transaction-summary-row total">

                        <span>
                            Total Transaksi
                        </span>

                        <strong>
                            <?= rupiah(
                                $detail_sale["total"]
                            ) ?>
                        </strong>

                    </div>


                    <div class="transaction-summary-row">

                        <span>
                            Dibayar
                        </span>

                        <strong>
                            <?= rupiah(
                                $detail_sale["paid"]
                            ) ?>
                        </strong>

                    </div>


                    <?php if (
                        $detail_sale[
                            "payment_method"
                        ] === "cash"
                    ): ?>

                        <div
                            class="
                                transaction-summary-row
                                change
                            "
                        >

                            <span>
                                Kembalian
                            </span>

                            <strong>
                                <?= rupiah(
                                    $detail_sale[
                                        "change_amount"
                                    ]
                                ) ?>
                            </strong>

                        </div>

                    <?php else: ?>

                        <div
                            class="
                                transaction-summary-row
                                change
                            "
                        >

                            <span>
                                Status Pembayaran
                            </span>

                            <strong>
                                Lunas
                            </strong>

                        </div>

                    <?php endif; ?>

                </div>

            </div>


            <!-- =====================================================
                 MODAL ACTION
            ====================================================== -->

            <div class="transaction-modal-actions">

                <button
                    type="button"
                    class="btn"
                    onclick="closeDetail()"
                >
                    Tutup
                </button>


                <?php if (
                    (int) $detail_sale["receipt"] === 1
                ): ?>

                    <button
                        type="button"
                        class="btn primary"
                        onclick="printTransaction()"
                    >
                        ðŸ–¨ Cetak Struk
                    </button>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

</div>
```

<?php endif; ?>

<script>

/*
|--------------------------------------------------------------------------
| DETAIL MODAL
|--------------------------------------------------------------------------
*/

function closeDetail()
{
    const url =
        new URL(
            window.location.href
        );

    url.searchParams.delete(
        "detail"
    );

    window.location.href =
        url.toString();
}


/*
|--------------------------------------------------------------------------
| CLOSE MODAL KLIK AREA LUAR
|--------------------------------------------------------------------------
*/

function closeDetailOutside(event)
{
    if (
        event.target.id ===
        "transactionModal"
    ) {

        closeDetail();

    }
}


/*
|--------------------------------------------------------------------------
| ESC UNTUK MENUTUP DETAIL
|--------------------------------------------------------------------------
*/

document.addEventListener(
    "keydown",
    function(event)
    {

        if (
            event.key === "Escape" &&
            document.getElementById(
                "transactionModal"
            )
        ) {

            closeDetail();

        }

    }
);


/*
|--------------------------------------------------------------------------
| CETAK STRUK
|--------------------------------------------------------------------------
*/

function printTransaction()
{
    window.print();
}


/*
|--------------------------------------------------------------------------
| CEGAH SCROLL BODY SAAT MODAL AKTIF
|--------------------------------------------------------------------------
*/

document.addEventListener(
    "DOMContentLoaded",
    function()
    {

        const modal =
            document.getElementById(
                "transactionModal"
            );

        if (modal) {

            document.body.style.overflow =
                "hidden";

        }

    }
);

</script>

<?php

require __DIR__ . "/../includes/footer.php";

?>


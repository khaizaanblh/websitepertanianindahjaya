<?php

require_once "../includes/auth.php";
require_admin();

require_once "../config/database.php";

$title = "Laporan Penjualan";

$base_url = get_base_url();


/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function rupiah($number)
{
    return "Rp " . number_format(
        (float) $number,
        0,
        ",",
        "."
    );
}


function esc($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}


function payment_label($method)
{
    switch ($method) {

        case "cash":
            return "Tunai";

        case "debit":
            return "Debit";

        case "transfer":
            return "Transfer";

        default:
            return "-";
    }
}


function payment_class($method)
{
    switch ($method) {

        case "cash":
            return "payment-cash";

        case "debit":
            return "payment-debit";

        case "transfer":
            return "payment-transfer";

        default:
            return "";
    }
}


function valid_date($date)
{
    if ($date === "") {
        return true;
    }

    $check = DateTime::createFromFormat(
        "Y-m-d",
        $date
    );

    return $check &&
        $check->format("Y-m-d") === $date;
}


/*
|--------------------------------------------------------------------------
| Filter
|--------------------------------------------------------------------------
*/

$date_from = trim(
    $_GET["date_from"] ?? ""
);

$date_to = trim(
    $_GET["date_to"] ?? ""
);

$branch_id = (int) (
    $_GET["branch_id"] ?? 0
);

$cashier_id = (int) (
    $_GET["cashier_id"] ?? 0
);

$payment_method = trim(
    $_GET["payment_method"] ?? ""
);

$search = trim(
    $_GET["search"] ?? ""
);


/*
|--------------------------------------------------------------------------
| Mode halaman
|--------------------------------------------------------------------------
|
| 0 = halaman ringkasan
| >0 = membuka riwayat cabang tertentu
|
*/

$view_branch_history = (int) (
    $_GET["view_branch_history"] ?? 0
);


/*
|--------------------------------------------------------------------------
| Validasi tanggal
|--------------------------------------------------------------------------
*/

$date_error = "";

if (!valid_date($date_from)) {
    $date_from = "";
}

if (!valid_date($date_to)) {
    $date_to = "";
}


if (
    $date_from !== "" &&
    $date_to !== "" &&
    $date_from > $date_to
) {

    $date_error =
        "Tanggal mulai tidak boleh lebih besar dari tanggal akhir.";

    $date_from = "";
    $date_to = "";
}


/*
|--------------------------------------------------------------------------
| Validasi metode pembayaran
|--------------------------------------------------------------------------
*/

$allowed_payment_methods = [
    "cash",
    "debit",
    "transfer"
];


if (
    $payment_method !== "" &&
    !in_array(
        $payment_method,
        $allowed_payment_methods,
        true
    )
) {

    $payment_method = "";
}


/*
|--------------------------------------------------------------------------
| Ambil daftar cabang
|--------------------------------------------------------------------------
*/

$branches = [];

$branch_query = $conn->query("
    SELECT
        id,
        name,
        status
    FROM branches
    ORDER BY
        status DESC,
        name ASC
");


if ($branch_query) {

    while (
        $branch = $branch_query->fetch_assoc()
    ) {

        $branches[] = $branch;
    }
}


/*
|--------------------------------------------------------------------------
| Validasi branch_id
|--------------------------------------------------------------------------
*/

$valid_branch_ids = [];

foreach ($branches as $branch) {

    $valid_branch_ids[] =
        (int) $branch["id"];
}


if (
    $branch_id > 0 &&
    !in_array(
        $branch_id,
        $valid_branch_ids,
        true
    )
) {

    $branch_id = 0;
}


/*
|--------------------------------------------------------------------------
| Validasi branch yang dibuka
|--------------------------------------------------------------------------
*/

if (
    $view_branch_history > 0 &&
    !in_array(
        $view_branch_history,
        $valid_branch_ids,
        true
    )
) {

    $view_branch_history = 0;
}


/*
|--------------------------------------------------------------------------
| Jika membuka riwayat cabang,
| otomatis gunakan cabang tersebut.
|--------------------------------------------------------------------------
*/

if ($view_branch_history > 0) {

    $branch_id =
        $view_branch_history;
}


/*
|--------------------------------------------------------------------------
| Nama cabang aktif
|--------------------------------------------------------------------------
*/

$selected_branch_name = "Semua Cabang";


if ($branch_id > 0) {

    foreach ($branches as $branch) {

        if (
            (int) $branch["id"] ===
            $branch_id
        ) {

            $selected_branch_name =
                $branch["name"];

            break;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Ambil daftar kasir
|--------------------------------------------------------------------------
*/

$cashiers = [];


if ($branch_id > 0) {

    $stmt_cashiers = $conn->prepare("
        SELECT
            id,
            name,
            branch_id
        FROM users
        WHERE
            role = 'kasir'
            AND branch_id = ?
        ORDER BY
            name ASC
    ");

    if ($stmt_cashiers) {

        $stmt_cashiers->bind_param(
            "i",
            $branch_id
        );

        $stmt_cashiers->execute();

        $cashier_result =
            $stmt_cashiers->get_result();

        while (
            $cashier =
            $cashier_result->fetch_assoc()
        ) {

            $cashiers[] =
                $cashier;
        }

        $stmt_cashiers->close();
    }

} else {

    $cashier_query = $conn->query("
        SELECT
            u.id,
            u.name,
            u.branch_id,
            b.name AS branch_name
        FROM users u
        LEFT JOIN branches b
            ON b.id = u.branch_id
        WHERE
            u.role = 'kasir'
        ORDER BY
            u.name ASC
    ");

    if ($cashier_query) {

        while (
            $cashier =
            $cashier_query->fetch_assoc()
        ) {

            $cashiers[] =
                $cashier;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Validasi kasir
|--------------------------------------------------------------------------
*/

if ($cashier_id > 0) {

    $cashier_valid = false;

    foreach ($cashiers as $cashier) {

        if (
            (int) $cashier["id"] ===
            $cashier_id
        ) {

            $cashier_valid = true;

            break;
        }
    }


    if (!$cashier_valid) {

        $cashier_id = 0;
    }
}


/*
|--------------------------------------------------------------------------
| WHERE utama
|--------------------------------------------------------------------------
*/

$where = [];

$params = [];

$types = "";


/*
|--------------------------------------------------------------------------
| Filter cabang
|--------------------------------------------------------------------------
*/

if ($branch_id > 0) {

    $where[] =
        "s.branch_id = ?";

    $params[] =
        $branch_id;

    $types .= "i";
}


/*
|--------------------------------------------------------------------------
| Filter tanggal mulai
|--------------------------------------------------------------------------
*/

if ($date_from !== "") {

    $where[] =
        "s.created_at >= ?";

    $params[] =
        $date_from . " 00:00:00";

    $types .= "s";
}


/*
|--------------------------------------------------------------------------
| Filter tanggal akhir
|--------------------------------------------------------------------------
*/

if ($date_to !== "") {

    $date_to_exclusive =
        date(
            "Y-m-d",
            strtotime(
                $date_to . " +1 day"
            )
        );

    $where[] =
        "s.created_at < ?";

    $params[] =
        $date_to_exclusive . " 00:00:00";

    $types .= "s";
}


/*
|--------------------------------------------------------------------------
| Filter kasir
|--------------------------------------------------------------------------
*/

if ($cashier_id > 0) {

    $where[] =
        "s.cashier_id = ?";

    $params[] =
        $cashier_id;

    $types .= "i";
}


/*
|--------------------------------------------------------------------------
| Filter pembayaran
|--------------------------------------------------------------------------
*/

if ($payment_method !== "") {

    $where[] =
        "s.payment_method = ?";

    $params[] =
        $payment_method;

    $types .= "s";
}


/*
|--------------------------------------------------------------------------
| Search invoice
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $where[] =
        "s.invoice LIKE ?";

    $params[] =
        "%" . $search . "%";

    $types .= "s";
}


/*
|--------------------------------------------------------------------------
| Build WHERE
|--------------------------------------------------------------------------
*/

$where_sql = "";

if (!empty($where)) {

    $where_sql =
        "WHERE " .
        implode(
            " AND ",
            $where
        );
}


/*
|--------------------------------------------------------------------------
| Summary utama
|--------------------------------------------------------------------------
*/

$summary_sql = "

    SELECT

        COUNT(*) AS total_transaction,

        COALESCE(
            SUM(s.total),
            0
        ) AS total_sales,

        COALESCE(
            AVG(s.total),
            0
        ) AS average_sales,

        COALESCE(
            SUM(
                CASE
                    WHEN s.payment_method = 'cash'
                    THEN s.total
                    ELSE 0
                END
            ),
            0
        ) AS total_cash,

        COALESCE(
            SUM(
                CASE
                    WHEN s.payment_method = 'debit'
                    THEN s.total
                    ELSE 0
                END
            ),
            0
        ) AS total_debit,

        COALESCE(
            SUM(
                CASE
                    WHEN s.payment_method = 'transfer'
                    THEN s.total
                    ELSE 0
                END
            ),
            0
        ) AS total_transfer

    FROM sales s

    $where_sql

";


$stmt_summary =
    $conn->prepare(
        $summary_sql
    );


if (!$stmt_summary) {

    die(
        "Terjadi kesalahan saat memproses laporan."
    );
}


if (!empty($params)) {

    $stmt_summary->bind_param(
        $types,
        ...$params
    );
}


$stmt_summary->execute();


$summary =
    $stmt_summary
        ->get_result()
        ->fetch_assoc();


$stmt_summary->close();


$total_transaction =
    (int) (
        $summary["total_transaction"]
        ?? 0
    );


$total_sales =
    (float) (
        $summary["total_sales"]
        ?? 0
    );


$average_sales =
    (float) (
        $summary["average_sales"]
        ?? 0
    );


$total_cash =
    (float) (
        $summary["total_cash"]
        ?? 0
    );


$total_debit =
    (float) (
        $summary["total_debit"]
        ?? 0
    );


$total_transfer =
    (float) (
        $summary["total_transfer"]
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| Ringkasan penjualan per cabang
|--------------------------------------------------------------------------
|
| Bagian ini hanya menampilkan ringkasan.
| Riwayat transaksi TIDAK ditampilkan di sini.
|
*/

$branch_summary = [];


$branch_summary_sql = "

    SELECT

        b.id,

        b.name AS branch_name,

        COUNT(s.id) AS total_transaction,

        COALESCE(
            SUM(s.total),
            0
        ) AS total_sales,

        COALESCE(
            SUM(
                CASE
                    WHEN s.payment_method = 'cash'
                    THEN s.total
                    ELSE 0
                END
            ),
            0
        ) AS total_cash,

        COALESCE(
            SUM(
                CASE
                    WHEN s.payment_method = 'debit'
                    THEN s.total
                    ELSE 0
                END
            ),
            0
        ) AS total_debit,

        COALESCE(
            SUM(
                CASE
                    WHEN s.payment_method = 'transfer'
                    THEN s.total
                    ELSE 0
                END
            ),
            0
        ) AS total_transfer

    FROM branches b

    LEFT JOIN sales s
        ON s.branch_id = b.id

";


$branch_where = [];

$branch_params = [];

$branch_types = "";


/*
|--------------------------------------------------------------------------
| Filter tanggal
|--------------------------------------------------------------------------
*/

if ($date_from !== "") {

    $branch_where[] =
        "s.created_at >= ?";

    $branch_params[] =
        $date_from . " 00:00:00";

    $branch_types .= "s";
}


if ($date_to !== "") {

    $date_to_exclusive =
        date(
            "Y-m-d",
            strtotime(
                $date_to . " +1 day"
            )
        );

    $branch_where[] =
        "s.created_at < ?";

    $branch_params[] =
        $date_to_exclusive . " 00:00:00";

    $branch_types .= "s";
}


/*
|--------------------------------------------------------------------------
| Filter kasir
|--------------------------------------------------------------------------
*/

if ($cashier_id > 0) {

    $branch_where[] =
        "s.cashier_id = ?";

    $branch_params[] =
        $cashier_id;

    $branch_types .= "i";
}


/*
|--------------------------------------------------------------------------
| Filter pembayaran
|--------------------------------------------------------------------------
*/

if ($payment_method !== "") {

    $branch_where[] =
        "s.payment_method = ?";

    $branch_params[] =
        $payment_method;

    $branch_types .= "s";
}


/*
|--------------------------------------------------------------------------
| Filter invoice
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $branch_where[] =
        "s.invoice LIKE ?";

    $branch_params[] =
        "%" . $search . "%";

    $branch_types .= "s";
}


/*
|--------------------------------------------------------------------------
| Jika memilih cabang tertentu
|--------------------------------------------------------------------------
*/

if ($branch_id > 0) {

    $branch_where[] =
        "b.id = ?";

    $branch_params[] =
        $branch_id;

    $branch_types .= "i";
}


if (!empty($branch_where)) {

    $branch_summary_sql .=
        " WHERE " .
        implode(
            " AND ",
            $branch_where
        );
}


$branch_summary_sql .= "

    GROUP BY
        b.id,
        b.name

    ORDER BY
        total_sales DESC,
        b.name ASC

";


$stmt_branch_summary =
    $conn->prepare(
        $branch_summary_sql
    );


if ($stmt_branch_summary) {

    if (!empty($branch_params)) {

        $stmt_branch_summary->bind_param(
            $branch_types,
            ...$branch_params
        );
    }

    $stmt_branch_summary->execute();

    $branch_summary_result =
        $stmt_branch_summary
            ->get_result();

    while (
        $row =
        $branch_summary_result->fetch_assoc()
    ) {

        $branch_summary[] =
            $row;
    }

    $stmt_branch_summary->close();
}


/*
|--------------------------------------------------------------------------
| Filter aktif
|--------------------------------------------------------------------------
*/

$active_filters = 0;


if ($branch_id > 0) {
    $active_filters++;
}

if ($date_from !== "") {
    $active_filters++;
}

if ($date_to !== "") {
    $active_filters++;
}

if ($cashier_id > 0) {
    $active_filters++;
}

if ($payment_method !== "") {
    $active_filters++;
}

if ($search !== "") {
    $active_filters++;
}


/*
|--------------------------------------------------------------------------
| Jika sedang melihat riwayat cabang
|--------------------------------------------------------------------------
*/

$history_sales = [];

$history_count = 0;

$history_total = 0;


if ($view_branch_history > 0) {

    /*
    |--------------------------------------------------------------------------
    | WHERE khusus riwayat
    |--------------------------------------------------------------------------
    */

    $history_where = [
        "s.branch_id = ?"
    ];

    $history_params = [
        $view_branch_history
    ];

    $history_types = "i";


    if ($date_from !== "") {

        $history_where[] =
            "s.created_at >= ?";

        $history_params[] =
            $date_from . " 00:00:00";

        $history_types .= "s";
    }


    if ($date_to !== "") {

        $date_to_exclusive =
            date(
                "Y-m-d",
                strtotime(
                    $date_to . " +1 day"
                )
            );

        $history_where[] =
            "s.created_at < ?";

        $history_params[] =
            $date_to_exclusive . " 00:00:00";

        $history_types .= "s";
    }


    if ($cashier_id > 0) {

        $history_where[] =
            "s.cashier_id = ?";

        $history_params[] =
            $cashier_id;

        $history_types .= "i";
    }


    if ($payment_method !== "") {

        $history_where[] =
            "s.payment_method = ?";

        $history_params[] =
            $payment_method;

        $history_types .= "s";
    }


    if ($search !== "") {

        $history_where[] =
            "s.invoice LIKE ?";

        $history_params[] =
            "%" . $search . "%";

        $history_types .= "s";
    }


    $history_sql = "

        SELECT

            s.id,
            s.invoice,
            s.total,
            s.payment_method,
            s.paid,
            s.change_amount,
            s.created_at,

            u.name AS cashier,

            b.name AS branch_name

        FROM sales s

        INNER JOIN users u
            ON u.id = s.cashier_id

        LEFT JOIN branches b
            ON b.id = s.branch_id

        WHERE
            " .
        implode(
            " AND ",
            $history_where
        ) . "

        ORDER BY
            s.created_at DESC,
            s.id DESC

    ";


    $stmt_history =
        $conn->prepare(
            $history_sql
        );


    if ($stmt_history) {

        $stmt_history->bind_param(
            $history_types,
            ...$history_params
        );

        $stmt_history->execute();

        $history_result =
            $stmt_history->get_result();


        while (
            $row =
            $history_result->fetch_assoc()
        ) {

            $history_sales[] =
                $row;

            $history_total +=
                (float) $row["total"];
        }


        $history_count =
            count($history_sales);


        $stmt_history->close();
    }
}


/*
|--------------------------------------------------------------------------
| Nama cabang untuk riwayat
|--------------------------------------------------------------------------
*/

$history_branch_name =
    "Cabang";


if ($view_branch_history > 0) {

    foreach ($branches as $branch) {

        if (
            (int) $branch["id"] ===
            $view_branch_history
        ) {

            $history_branch_name =
                $branch["name"];

            break;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/

require "../includes/header.php";

?>

<style>

/* =========================================================
   REPORT PAGE
========================================================= */

.report-page {
    display: grid;
    gap: 16px;
}


/* =========================================================
   HERO
========================================================= */

.report-hero {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
}

.report-title h2 {
    margin: 0;
    font-size: 22px;
    letter-spacing: -.04em;
}

.report-title p {
    margin: 7px 0 0;
    color: #77837b;
    font-size: 11px;
    line-height: 1.6;
}

.report-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.branch-hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-top: 10px;
    padding: 6px 10px;
    border-radius: 20px;
    background: #edf7ef;
    color: #176f3d;
    border: 1px solid #dcecdf;
    font-size: 9px;
    font-weight: 850;
}


/* =========================================================
   SUMMARY
========================================================= */

.report-summary {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
}

.report-stat {
    position: relative;
    overflow: hidden;
    background: #fff;
    border: 1px solid #dfe7e0;
    border-radius: 16px;
    padding: 18px;
    box-shadow: 0 8px 25px #173c240b;
}

.report-stat::after {
    content: "";
    position: absolute;
    width: 82px;
    height: 82px;
    border-radius: 50%;
    right: -28px;
    top: -28px;
    background: #176f3d0b;
}

.report-stat:nth-child(2)::after {
    background: #91c83e12;
}

.report-stat:nth-child(3)::after {
    background: #ee7a1912;
}

.report-stat:nth-child(4)::after {
    background: #2394540b;
}

.report-stat small {
    display: block;
    color: #77837b;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 850;
}

.report-stat strong {
    display: block;
    margin-top: 10px;
    font-size: 21px;
    letter-spacing: -.045em;
    position: relative;
    z-index: 1;
}

.report-stat span {
    display: block;
    margin-top: 5px;
    color: #77837b;
    font-size: 10px;
}


/* =========================================================
   FILTER
========================================================= */

.report-filter {
    padding: 18px;
}

.filter-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 15px;
}

.filter-head h2 {
    margin: 0;
    font-size: 14px;
}

.filter-head span {
    color: #77837b;
    font-size: 10px;
}

.filter-grid {
    display: grid;
    grid-template-columns:
        1.25fr
        1fr
        1fr
        1fr
        1fr
        1fr;
    gap: 10px;
    align-items: end;
}

.filter-field label {
    display: block;
    margin-bottom: 6px;
    color: #526058;
    font-size: 9px;
    font-weight: 800;
}

.filter-field input,
.filter-field select {
    width: 100%;
    height: 39px;
    padding: 0 11px;
    border: 1px solid #dfe7e0;
    border-radius: 9px;
    background: #fff;
    color: #17221b;
    font-size: 10px;
    outline: none;
    box-sizing: border-box;
}

.filter-field input:focus,
.filter-field select:focus {
    border-color: #8fc5a3;
    box-shadow: 0 0 0 3px #176f3d10;
}

.filter-buttons {
    display: flex;
    gap: 7px;
}


/* =========================================================
   FILTER STATUS
========================================================= */

.filter-status {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid #edf1ed;
}

.filter-status-left {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}

.filter-count {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 20px;
    background: #edf7ef;
    color: #176f3d;
    font-size: 9px;
    font-weight: 850;
}

.filter-active {
    color: #77837b;
    font-size: 9px;
}


/* =========================================================
   ERROR
========================================================= */

.report-error {
    padding: 12px 14px;
    border: 1px solid #f0caca;
    border-radius: 11px;
    background: #fff5f5;
    color: #a33a3a;
    font-size: 10px;
    line-height: 1.5;
}


/* =========================================================
   BRANCH SUMMARY
========================================================= */

.branch-summary-grid {
    display: grid;
    grid-template-columns:
        repeat(
            auto-fit,
            minmax(240px, 1fr)
        );
    gap: 12px;
}

.branch-summary-card {
    position: relative;
    overflow: hidden;
    padding: 16px;
    border: 1px solid #dfe7e0;
    border-radius: 13px;
    background: #fff;
    box-shadow: 0 8px 25px #173c2408;
}

.branch-summary-card::after {
    content: "";
    position: absolute;
    width: 65px;
    height: 65px;
    right: -25px;
    top: -25px;
    border-radius: 50%;
    background: #176f3d0b;
}

.branch-summary-card small {
    display: block;
    color: #77837b;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: .06em;
    font-weight: 850;
}

.branch-summary-card strong {
    display: block;
    margin-top: 8px;
    font-size: 17px;
    position: relative;
    z-index: 1;
}

.branch-summary-card span {
    display: block;
    margin-top: 4px;
    color: #77837b;
    font-size: 9px;
}

.branch-summary-meta {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px solid #edf1ed;
    font-size: 9px;
    color: #526058;
}

.branch-summary-action {
    display: block;
    width: 100%;
    margin-top: 13px;
    padding: 10px 12px;
    border-radius: 9px;
    text-align: center;
    text-decoration: none;
    background: #176f3d;
    color: #fff;
    font-size: 10px;
    font-weight: 850;
    box-sizing: border-box;
    transition: .15s ease;
}

.branch-summary-action:hover {
    background: #125b31;
    transform: translateY(-1px);
}


/* =========================================================
   PAYMENT SUMMARY
========================================================= */

.payment-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.payment-box {
    padding: 15px;
    border: 1px solid #dfe7e0;
    border-radius: 13px;
    background: #fff;
}

.payment-box small {
    display: block;
    color: #77837b;
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .06em;
}

.payment-box strong {
    display: block;
    margin-top: 8px;
    font-size: 16px;
}

.payment-box span {
    display: block;
    margin-top: 4px;
    color: #77837b;
    font-size: 9px;
}


/* =========================================================
   TABLE
========================================================= */

.report-table-wrap {
    overflow-x: auto;
}

.report-table {
    min-width: 980px;
}

.report-table th {
    white-space: nowrap;
}

.report-table td {
    vertical-align: middle;
}

.invoice {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        monospace;
    font-size: 10px;
    font-weight: 800;
    color: #176f3d;
    text-decoration: none;
}

.invoice:hover {
    text-decoration: underline;
}

.cashier-name {
    font-weight: 700;
}

.amount {
    font-weight: 850;
    white-space: nowrap;
}

.date-cell {
    white-space: nowrap;
    color: #526058;
    font-size: 10px;
}


/* =========================================================
   PAYMENT BADGE
========================================================= */

.payment-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 9px;
    font-weight: 850;
}

.payment-cash {
    background: #eaf6ed;
    color: #176f3d;
}

.payment-debit {
    background: #eef3ff;
    color: #496aa8;
}

.payment-transfer {
    background: #f5edff;
    color: #7650a8;
}


/* =========================================================
   DETAIL
========================================================= */

.detail-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 30px;
    padding: 0 9px;
    border: 1px solid #dfe7e0;
    border-radius: 8px;
    background: #fff;
    color: #526058;
    font-size: 9px;
    font-weight: 800;
    text-decoration: none;
    transition: .15s ease;
}

.detail-link:hover {
    border-color: #8fc5a3;
    background: #f5faf5;
    color: #176f3d;
}


/* =========================================================
   EMPTY
========================================================= */

.report-empty {
    text-align: center;
    padding: 45px 20px !important;
}

.report-empty-icon {
    width: 42px;
    height: 42px;
    margin: 0 auto 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: #f2f6f3;
    color: #77837b;
    font-size: 18px;
}

.report-empty strong {
    display: block;
    margin-bottom: 5px;
    color: #526058;
}

.report-empty span {
    color: #77837b;
    font-size: 10px;
}


/* =========================================================
   NOTE
========================================================= */

.report-note {
    padding: 13px 15px;
    border-radius: 11px;
    background: #f5faf5;
    border: 1px solid #dfe7e0;
    color: #526058;
    font-size: 10px;
    line-height: 1.65;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1200px) {

    .filter-grid {
        grid-template-columns:
            repeat(3, 1fr);
    }

    .filter-buttons {
        grid-column: 1 / -1;
    }
}


@media (max-width: 1100px) {

    .report-summary {
        grid-template-columns:
            repeat(2, 1fr);
    }

    .filter-grid {
        grid-template-columns:
            repeat(2, 1fr);
    }

    .filter-buttons {
        grid-column: 1 / -1;
    }
}


@media (max-width: 800px) {

    .report-hero {
        flex-direction: column;
    }

    .payment-summary {
        grid-template-columns: 1fr;
    }

    .filter-status {
        align-items: flex-start;
        flex-direction: column;
    }
}


@media (max-width: 600px) {

    .report-summary {
        grid-template-columns: 1fr;
    }

    .filter-grid {
        grid-template-columns: 1fr;
    }

    .filter-buttons {
        grid-column: auto;
    }

    .filter-buttons .btn {
        flex: 1;
    }

    .report-title h2 {
        font-size: 20px;
    }

}

</style>


<div class="report-page">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="report-hero">

        <div class="report-title">

            <h2>
                Laporan Penjualan
            </h2>

            <p>
                Pantau omzet dan ringkasan penjualan
                berdasarkan cabang.
            </p>

            <div class="branch-hero-badge">

                Cabang:
                <?= esc($selected_branch_name) ?>

            </div>

        </div>


        <div class="report-actions">

            <?php if ($view_branch_history > 0): ?>

                <a
                    href="<?= $base_url ?>/admin/reports.php"
                    class="btn"
                >
                    Kembali ke Ringkasan Cabang
                </a>

            <?php endif; ?>

        </div>

    </div>


    <?php if ($date_error !== ""): ?>

        <div class="report-error">
            <?= esc($date_error) ?>
        </div>

    <?php endif; ?>


    <!-- =====================================================
         SUMMARY
    ====================================================== -->

    <div class="report-summary">


        <div class="report-stat">

            <small>
                Total Transaksi
            </small>

            <strong>
                <?= number_format(
                    $total_transaction,
                    0,
                    ",",
                    "."
                ) ?>
            </strong>

            <span>
                transaksi tercatat
            </span>

        </div>


        <div class="report-stat">

            <small>
                Total Omzet
            </small>

            <strong>
                <?= rupiah($total_sales) ?>
            </strong>

            <span>
                total penjualan
            </span>

        </div>


        <div class="report-stat">

            <small>
                Rata-rata Transaksi
            </small>

            <strong>
                <?= rupiah($average_sales) ?>
            </strong>

            <span>
                nilai rata-rata per transaksi
            </span>

        </div>


        <div class="report-stat">

            <small>
                <?= $view_branch_history > 0
                    ? "Transaksi Cabang"
                    : "Cabang"
                ?>
            </small>

            <strong>

                <?php if ($view_branch_history > 0): ?>

                    <?= number_format(
                        $history_count,
                        0,
                        ",",
                        "."
                    ) ?>

                <?php else: ?>

                    <?= count($branches) ?>

                <?php endif; ?>

            </strong>

            <span>

                <?php if ($view_branch_history > 0): ?>

                    transaksi ditemukan

                <?php else: ?>

                    cabang terdaftar

                <?php endif; ?>

            </span>

        </div>


    </div>


    <!-- =====================================================
         FILTER
    ====================================================== -->

    <div class="panel report-filter">


        <div class="filter-head">

            <h2>
                Filter Laporan
            </h2>

            <span>

                <?= $active_filters > 0
                    ? $active_filters . " filter aktif"
                    : "Tidak ada filter aktif"
                ?>

            </span>

        </div>


        <form
            method="GET"
            action="<?= $base_url ?>/admin/reports.php"
        >

            <div class="filter-grid">


                <!-- Search -->

                <div class="filter-field">

                    <label>
                        Cari Invoice
                    </label>

                    <input
                        type="text"
                        name="search"
                        value="<?= esc($search) ?>"
                        placeholder="Contoh: INV-..."
                        autocomplete="off"
                    >

                </div>


                <!-- Branch -->

                <div class="filter-field">

                    <label>
                        Cabang
                    </label>

                    <select name="branch_id">

                        <option value="0">
                            Semua Cabang
                        </option>

                        <?php foreach (
                            $branches
                            as $branch
                        ): ?>

                            <option
                                value="<?= (int) $branch["id"] ?>"
                                <?= $branch_id ===
                                    (int) $branch["id"]
                                    ? "selected"
                                    : ""
                                ?>
                            >

                                <?= esc(
                                    $branch["name"]
                                ) ?>

                                <?php if (
                                    ($branch["status"] ?? "")
                                    !== "active"
                                ): ?>

                                    â€” Nonaktif

                                <?php endif; ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- Date From -->

                <div class="filter-field">

                    <label>
                        Dari Tanggal
                    </label>

                    <input
                        type="date"
                        name="date_from"
                        value="<?= esc($date_from) ?>"
                    >

                </div>


                <!-- Date To -->

                <div class="filter-field">

                    <label>
                        Sampai Tanggal
                    </label>

                    <input
                        type="date"
                        name="date_to"
                        value="<?= esc($date_to) ?>"
                    >

                </div>


                <!-- Cashier -->

                <div class="filter-field">

                    <label>
                        Kasir
                    </label>

                    <select name="cashier_id">

                        <option value="0">
                            Semua Kasir
                        </option>

                        <?php foreach (
                            $cashiers
                            as $cashier
                        ): ?>

                            <option
                                value="<?= (int) $cashier["id"] ?>"
                                <?= $cashier_id ===
                                    (int) $cashier["id"]
                                    ? "selected"
                                    : ""
                                ?>
                            >

                                <?= esc(
                                    $cashier["name"]
                                ) ?>

                                <?php if (
                                    $branch_id === 0 &&
                                    !empty(
                                        $cashier["branch_name"]
                                    )
                                ): ?>

                                    â€”
                                    <?= esc(
                                        $cashier["branch_name"]
                                    ) ?>

                                <?php endif; ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- Payment -->

                <div class="filter-field">

                    <label>
                        Pembayaran
                    </label>

                    <select name="payment_method">

                        <option value="">
                            Semua Metode
                        </option>

                        <option
                            value="cash"
                            <?= $payment_method === "cash"
                                ? "selected"
                                : ""
                            ?>
                        >
                            Tunai
                        </option>

                        <option
                            value="debit"
                            <?= $payment_method === "debit"
                                ? "selected"
                                : ""
                            ?>
                        >
                            Debit
                        </option>

                        <option
                            value="transfer"
                            <?= $payment_method === "transfer"
                                ? "selected"
                                : ""
                            ?>
                        >
                            Transfer
                        </option>

                    </select>

                </div>


                <!-- Buttons -->

                <div class="filter-buttons">

                    <button
                        type="submit"
                        class="btn primary"
                    >
                        Terapkan Filter
                    </button>

                    <a
                        href="<?= $base_url ?>/admin/reports.php"
                        class="btn"
                    >
                        Reset
                    </a>

                </div>


            </div>


            <div class="filter-status">

                <div class="filter-status-left">

                    <span class="filter-count">

                        <?= number_format(
                            $total_transaction,
                            0,
                            ",",
                            "."
                        ) ?>

                        transaksi

                    </span>


                    <?php if ($active_filters > 0): ?>

                        <span class="filter-active">

                            Filter sedang diterapkan
                            pada laporan.

                        </span>

                    <?php else: ?>

                        <span class="filter-active">

                            Menampilkan ringkasan
                            seluruh cabang.

                        </span>

                    <?php endif; ?>

                </div>

            </div>

        </form>

    </div>


    <!-- =====================================================
         MODE RIWAYAT CABANG
    ====================================================== -->

    <?php if ($view_branch_history > 0): ?>


        <!-- =================================================
             HEADER RIWAYAT
        ================================================== -->

        <div class="panel">

            <div class="filter-head">

                <div>

                    <h2>
                        Riwayat Penjualan
                    </h2>

                    <span
                        style="
                            display:block;
                            margin-top:5px;
                        "
                    >
                        <?= esc(
                            $history_branch_name
                        ) ?>

                        Â·

                        <?= number_format(
                            $history_count,
                            0,
                            ",",
                            "."
                        ) ?>

                        transaksi

                    </span>

                </div>


                <div
                    style="
                        display:flex;
                        gap:7px;
                        flex-wrap:wrap;
                    "
                >

                    <span class="filter-count">

                        Total:

                        <?= rupiah(
                            $history_total
                        ) ?>

                    </span>


                    <a
                        href="<?= $base_url ?>/admin/reports.php"
                        class="btn"
                    >
                        Kembali
                    </a>

                </div>

            </div>


            <div class="report-table-wrap">

                <table class="table report-table">

                    <thead>

                        <tr>

                            <th>
                                Invoice
                            </th>

                            <th>
                                Kasir
                            </th>

                            <th>
                                Metode
                            </th>

                            <th>
                                Total
                            </th>

                            <th>
                                Dibayar
                            </th>

                            <th>
                                Kembalian
                            </th>

                            <th>
                                Tanggal
                            </th>

                            <th>
                                Aksi
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                        <?php if (
                            !empty($history_sales)
                        ): ?>


                            <?php foreach (
                                $history_sales
                                as $s
                            ): ?>


                                <tr>


                                    <td>

                                        <a
                                            href="<?= $base_url ?>/admin/transaction_detail.php?id=<?= (int) $s["id"] ?>"
                                            class="invoice"
                                            title="Lihat detail transaksi"
                                        >

                                            <?= esc(
                                                $s["invoice"]
                                            ) ?>

                                        </a>

                                    </td>


                                    <td>

                                        <span
                                            class="cashier-name"
                                        >

                                            <?= esc(
                                                $s["cashier"]
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <span
                                            class="
                                                payment-badge
                                                <?= esc(
                                                    payment_class(
                                                        $s["payment_method"]
                                                    )
                                                )
                                                ?>
                                            "
                                        >

                                            <?= esc(
                                                payment_label(
                                                    $s["payment_method"]
                                                )
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <span
                                            class="amount"
                                        >

                                            <?= rupiah(
                                                $s["total"]
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <?php if (
                                            (float) $s["paid"] > 0
                                        ): ?>

                                            <?= rupiah(
                                                $s["paid"]
                                            ) ?>

                                        <?php else: ?>

                                            <span class="mini">
                                                -
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <?php if (
                                            (float) $s["change_amount"] > 0
                                        ): ?>

                                            <?= rupiah(
                                                $s["change_amount"]
                                            ) ?>

                                        <?php else: ?>

                                            <span class="mini">
                                                -
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <span
                                            class="date-cell"
                                        >

                                            <?= date(
                                                "d M Y, H:i",
                                                strtotime(
                                                    $s["created_at"]
                                                )
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <a
                                            href="<?= $base_url ?>/admin/transaction_detail.php?id=<?= (int) $s["id"] ?>"
                                            class="detail-link"
                                            title="Lihat detail transaksi"
                                        >
                                            Detail
                                        </a>

                                    </td>


                                </tr>


                            <?php endforeach; ?>


                        <?php else: ?>


                            <tr>

                                <td
                                    colspan="8"
                                    class="report-empty"
                                >

                                    <div
                                        class="report-empty-icon"
                                    >
                                        â€”
                                    </div>

                                    <strong>
                                        Tidak ada transaksi
                                    </strong>

                                    <span>
                                        Belum ada riwayat penjualan
                                        untuk cabang ini sesuai
                                        filter yang dipilih.
                                    </span>

                                </td>

                            </tr>


                        <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </div>


    <?php else: ?>


        <!-- =================================================
             RINGKASAN PER CABANG
        ================================================== -->

        <div class="panel">

            <div class="filter-head">

                <div>

                    <h2>
                        Ringkasan Penjualan per Cabang
                    </h2>

                    <span
                        style="
                            display:block;
                            margin-top:5px;
                        "
                    >
                        Klik tombol pada cabang untuk
                        membuka riwayat penjualannya.
                    </span>

                </div>

                <span>
                    <?= $branch_id > 0
                        ? "Cabang terpilih"
                        : "Seluruh cabang"
                    ?>
                </span>

            </div>


            <?php if (
                !empty($branch_summary)
            ): ?>


                <div class="branch-summary-grid">


                    <?php foreach (
                        $branch_summary
                        as $branch
                    ): ?>


                        <div
                            class="branch-summary-card"
                        >

                            <small>

                                <?= esc(
                                    $branch["branch_name"]
                                ) ?>

                            </small>


                            <strong>

                                <?= rupiah(
                                    $branch["total_sales"]
                                ) ?>

                            </strong>


                            <span>

                                Total omzet penjualan

                            </span>


                            <div
                                class="
                                    branch-summary-meta
                                "
                            >

                                <span>

                                    <?= number_format(
                                        (int) $branch[
                                            "total_transaction"
                                        ],
                                        0,
                                        ",",
                                        "."
                                    ) ?>

                                    transaksi

                                </span>


                                <span>

                                    Tunai:

                                    <?= rupiah(
                                        $branch[
                                            "total_cash"
                                        ]
                                    ) ?>

                                </span>

                            </div>


                            <a
                                href="<?= $base_url ?>/admin/reports.php?branch_id=<?= (int) $branch["id"] ?>&view_branch_history=<?= (int) $branch["id"] ?><?=
                                    $date_from !== ""
                                        ? "&date_from=" .
                                            urlencode(
                                                $date_from
                                            )
                                        : ""
                                ?><?=
                                    $date_to !== ""
                                        ? "&date_to=" .
                                            urlencode(
                                                $date_to
                                            )
                                        : ""
                                ?><?=
                                    $cashier_id > 0
                                        ? "&cashier_id=" .
                                            (int) $cashier_id
                                        : ""
                                ?><?=
                                    $payment_method !== ""
                                        ? "&payment_method=" .
                                            urlencode(
                                                $payment_method
                                            )
                                        : ""
                                ?><?=
                                    $search !== ""
                                        ? "&search=" .
                                            urlencode(
                                                $search
                                            )
                                        : ""
                                ?>"
                                class="branch-summary-action"
                            >

                                Lihat Riwayat Penjualan

                            </a>


                        </div>


                    <?php endforeach; ?>


                </div>


            <?php else: ?>


                <div class="report-empty">

                    <div
                        class="report-empty-icon"
                    >
                        â€”
                    </div>

                    <strong>
                        Belum ada data penjualan
                    </strong>

                    <span>
                        Tidak terdapat data penjualan
                        pada periode atau filter yang dipilih.
                    </span>

                </div>


            <?php endif; ?>

        </div>


        <!-- =================================================
             PAYMENT SUMMARY
        ================================================== -->

        <div class="panel">

            <div class="filter-head">

                <h2>
                    Ringkasan Pembayaran
                </h2>

                <span>
                    <?= esc(
                        $selected_branch_name
                    ) ?>
                </span>

            </div>


            <div class="payment-summary">


                <div class="payment-box">

                    <small>
                        Tunai
                    </small>

                    <strong>
                        <?= rupiah(
                            $total_cash
                        ) ?>
                    </strong>

                    <span>
                        Penjualan tunai
                    </span>

                </div>


                <div class="payment-box">

                    <small>
                        Debit
                    </small>

                    <strong>
                        <?= rupiah(
                            $total_debit
                        ) ?>
                    </strong>

                    <span>
                        Penjualan debit
                    </span>

                </div>


                <div class="payment-box">

                    <small>
                        Transfer
                    </small>

                    <strong>
                        <?= rupiah(
                            $total_transfer
                        ) ?>
                    </strong>

                    <span>
                        Penjualan transfer
                    </span>

                </div>


            </div>

        </div>


    <?php endif; ?>


    <!-- =====================================================
         INFORMATION
    ====================================================== -->

    <div class="report-note">

        <strong>Catatan:</strong>

        Riwayat penjualan sekarang dibuka melalui
        tombol <strong>Lihat Riwayat Penjualan</strong>
        pada masing-masing cabang.

        Data transaksi menggunakan
        <code>sales.branch_id</code>, sehingga transaksi
        tetap terpisah berdasarkan cabang.

    </div>


</div>


<?php

require "../includes/footer.php";

?>

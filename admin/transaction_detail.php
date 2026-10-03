<?php

require_once "../includes/auth.php";
require_admin();

require_once "../config/database.php";

$title = "Detail Transaksi";
$base_url = get_base_url();


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


/*
|--------------------------------------------------------------------------
| AMBIL ID TRANSAKSI
|--------------------------------------------------------------------------
*/

$sale_id = (int) ($_GET["id"] ?? 0);

if ($sale_id <= 0) {

    header(
        "Location: " .
        $base_url .
        "/admin/reports.php"
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| AMBIL DATA TRANSAKSI
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        s.id,
        s.invoice,
        s.total,
        s.cashier_id,
        s.payment_method,
        s.paid,
        s.change_amount,
        s.created_at,
        u.name AS cashier
    FROM sales s
    LEFT JOIN users u
        ON u.id = s.cashier_id
    WHERE s.id = ?
    LIMIT 1
");

if (!$stmt) {

    die(
        "Gagal menyiapkan data transaksi."
    );
}

$stmt->bind_param(
    "i",
    $sale_id
);

$stmt->execute();

$sale = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();


/*
|--------------------------------------------------------------------------
| TRANSAKSI TIDAK DITEMUKAN
|--------------------------------------------------------------------------
*/

if (!$sale) {

    header(
        "Location: " .
        $base_url .
        "/admin/reports.php"
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| AMBIL DETAIL ITEM
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        si.product_id,
        si.qty,
        si.price,
        p.name,
        p.barcode,
        p.category
    FROM sale_items si
    LEFT JOIN products p
        ON p.id = si.product_id
    WHERE si.sale_id = ?
    ORDER BY si.product_id ASC
");

if (!$stmt) {

    die(
        "Gagal menyiapkan detail transaksi."
    );
}

$stmt->bind_param(
    "i",
    $sale_id
);

$stmt->execute();

$items = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();


/*
|--------------------------------------------------------------------------
| HITUNG DETAIL
|--------------------------------------------------------------------------
*/

$total_qty = 0;

$total_items = count($items);

foreach ($items as &$item) {

    $item["qty"] =
        (int) $item["qty"];

    $item["price"] =
        (float) $item["price"];

    $item["subtotal"] =
        $item["qty"] *
        $item["price"];

    $total_qty +=
        $item["qty"];
}

unset($item);


/*
|--------------------------------------------------------------------------
| FORMAT TANGGAL
|--------------------------------------------------------------------------
*/

$transaction_date = "-";

if (!empty($sale["created_at"])) {

    $timestamp =
        strtotime(
            $sale["created_at"]
        );

    if ($timestamp !== false) {

        $transaction_date =
            date(
                "d/m/Y H:i",
                $timestamp
            );
    }
}


/*
|--------------------------------------------------------------------------
| DATA PEMBAYARAN
|--------------------------------------------------------------------------
*/

$payment_method =
    $sale["payment_method"];

$payment_label =
    payment_label(
        $payment_method
    );

$total =
    (float) $sale["total"];

$paid =
    (float) $sale["paid"];

$change =
    (float) $sale["change_amount"];


require "../includes/header.php";

?>

<style>

/* =========================================================
   DETAIL PAGE
========================================================= */

.detail-page {
    max-width: 1180px;
    margin: 0 auto;
}


/* =========================================================
   HEADER
========================================================= */

.detail-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 20px;
}

.detail-title h1 {
    margin: 0;
    font-size: 22px;
    letter-spacing: -.04em;
}

.detail-title p {
    margin: 6px 0 0;
    color: var(--muted);
    font-size: 10px;
    line-height: 1.5;
}

.detail-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.detail-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 13px;
    border-radius: 9px;
    text-decoration: none;
    border: 1px solid var(--border);
    background: #fff;
    color: #4d5b52;
    font-size: 9px;
    font-weight: 850;
    cursor: pointer;
}

.detail-btn:hover {
    background: #f5f8f5;
}

.detail-btn.primary {
    background: var(--green);
    border-color: var(--green);
    color: #fff;
}

.detail-btn.primary:hover {
    opacity: .92;
}


/* =========================================================
   INVOICE HERO
========================================================= */

.invoice-card {
    background:
        linear-gradient(
            135deg,
            #f1f8f2,
            #ffffff
        );
    border: 1px solid #d7e8da;
    border-radius: 18px;
    padding: 22px;
    margin-bottom: 18px;
}

.invoice-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}

.invoice-label {
    color: var(--muted);
    font-size: 9px;
    font-weight: 750;
    text-transform: uppercase;
    letter-spacing: .08em;
}

.invoice-number {
    margin-top: 5px;
    font-size: 21px;
    font-weight: 950;
    letter-spacing: -.035em;
    color: var(--green);
    word-break: break-all;
}

.invoice-total {
    text-align: right;
}

.invoice-total span {
    display: block;
    color: var(--muted);
    font-size: 9px;
}

.invoice-total strong {
    display: block;
    margin-top: 3px;
    color: var(--green);
    font-size: 22px;
    letter-spacing: -.04em;
}


/* =========================================================
   INFO GRID
========================================================= */

.info-grid {
    display: grid;
    grid-template-columns:
        repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 18px;
}

.info-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 14px;
}

.info-card-label {
    color: var(--muted);
    font-size: 8px;
    font-weight: 750;
    text-transform: uppercase;
    letter-spacing: .06em;
}

.info-card-value {
    margin-top: 6px;
    font-size: 11px;
    font-weight: 900;
    color: var(--text);
}

.payment-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 20px;
    background: #edf7ef;
    color: var(--green);
    font-size: 8px;
    font-weight: 900;
}


/* =========================================================
   PANEL
========================================================= */

.detail-panel {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 17px;
    overflow: hidden;
    margin-bottom: 18px;
    box-shadow: 0 8px 28px #173c2408;
}

.detail-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 17px 19px;
    border-bottom: 1px solid var(--border);
}

.detail-panel-head h2 {
    margin: 0;
    font-size: 14px;
    letter-spacing: -.025em;
}

.detail-panel-head span {
    color: var(--muted);
    font-size: 9px;
}


/* =========================================================
   TABLE
========================================================= */

.detail-table-wrap {
    overflow-x: auto;
}

.detail-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 720px;
}

.detail-table th {
    padding: 11px 16px;
    background: #f8faf8;
    border-bottom: 1px solid var(--border);
    color: #68766e;
    font-size: 8px;
    text-align: left;
    text-transform: uppercase;
    letter-spacing: .05em;
    white-space: nowrap;
}

.detail-table td {
    padding: 13px 16px;
    border-bottom: 1px solid #edf1ed;
    font-size: 10px;
    vertical-align: middle;
}

.detail-table tbody tr:last-child td {
    border-bottom: 0;
}

.detail-table tbody tr:hover {
    background: #fbfdfb;
}

.product-name {
    font-weight: 850;
    color: var(--text);
}

.product-meta {
    margin-top: 4px;
    color: var(--muted);
    font-size: 8px;
}

.text-right {
    text-align: right !important;
}

.text-center {
    text-align: center !important;
}

.price {
    white-space: nowrap;
}

.subtotal {
    font-weight: 900;
    white-space: nowrap;
}


/* =========================================================
   SUMMARY
========================================================= */

.summary-grid {
    display: grid;
    grid-template-columns:
        minmax(0, 1fr)
        minmax(300px, .7fr);
    gap: 18px;
}

.payment-card,
.total-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 17px;
    padding: 19px;
}

.summary-title {
    margin: 0 0 14px;
    font-size: 12px;
    font-weight: 900;
}

.payment-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 10px 0;
    border-bottom: 1px solid #edf1ed;
    font-size: 10px;
}

.payment-row:last-child {
    border-bottom: 0;
}

.payment-row span {
    color: var(--muted);
}

.payment-row strong {
    white-space: nowrap;
}

.total-card {
    background:
        linear-gradient(
            145deg,
            #f1f8f2,
            #ffffff
        );
    border-color: #d7e8da;
}

.grand-total {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 15px;
    padding-bottom: 14px;
    border-bottom: 1px solid #dbe9de;
}

.grand-total span {
    color: var(--muted);
    font-size: 9px;
}

.grand-total strong {
    color: var(--green);
    font-size: 23px;
    letter-spacing: -.04em;
    white-space: nowrap;
}

.total-detail {
    padding-top: 10px;
}

.total-detail-row {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    padding: 5px 0;
    font-size: 9px;
}

.total-detail-row span {
    color: var(--muted);
}

.total-detail-row strong {
    white-space: nowrap;
}


/* =========================================================
   EMPTY
========================================================= */

.detail-empty {
    padding: 35px 20px;
    text-align: center;
    color: var(--muted);
    font-size: 10px;
}


/* =========================================================
   FOOTER NOTE
========================================================= */

.detail-note {
    padding: 12px 14px;
    border-radius: 10px;
    background: #f7faf7;
    border: 1px solid var(--border);
    color: var(--muted);
    font-size: 9px;
    line-height: 1.55;
}


/* =========================================================
   PRINT
========================================================= */

@media print {

    @page {
        margin: 12mm;
    }

    body {
        background: #fff !important;
    }

    .detail-actions,
    header,
    nav,
    footer {
        display: none !important;
    }

    .detail-page {
        max-width: none;
    }

    .detail-panel,
    .info-card,
    .payment-card,
    .total-card,
    .invoice-card {
        box-shadow: none !important;
    }

    .invoice-card {
        border: 1px solid #ddd;
    }

    .detail-table th {
        background: #f5f5f5 !important;
    }

}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 900px) {

    .info-grid {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }
}


@media (max-width: 600px) {

    .detail-header {
        flex-direction: column;
    }

    .detail-actions {
        width: 100%;
    }

    .detail-btn {
        flex: 1;
    }

    .invoice-top {
        align-items: flex-start;
        flex-direction: column;
    }

    .invoice-total {
        text-align: left;
    }

    .info-grid {
        grid-template-columns: 1fr;
    }

    .invoice-card {
        padding: 17px;
    }

    .invoice-number {
        font-size: 17px;
    }

}

</style>


<div class="detail-page">

    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="detail-header">

        <div class="detail-title">

            <h1>
                Detail Transaksi
            </h1>

            <p>
                Informasi lengkap transaksi penjualan
                dan rincian barang.
            </p>

        </div>


        <div class="detail-actions">

            <a
                href="<?= $base_url ?>/admin/reports.php"
                class="detail-btn"
            >
                â† Kembali
            </a>


            <button
                type="button"
                class="detail-btn primary"
                onclick="window.print()"
            >
                ðŸ–¨ Cetak
            </button>

        </div>

    </div>


    <!-- =====================================================
         INVOICE
    ====================================================== -->

    <section class="invoice-card">

        <div class="invoice-top">

            <div>

                <div class="invoice-label">
                    Nomor Invoice
                </div>

                <div class="invoice-number">
                    <?= esc($sale["invoice"]) ?>
                </div>

            </div>


            <div class="invoice-total">

                <span>
                    Total Transaksi
                </span>

                <strong>
                    <?= rupiah($total) ?>
                </strong>

            </div>

        </div>

    </section>


    <!-- =====================================================
         INFO
    ====================================================== -->

    <section class="info-grid">

        <div class="info-card">

            <div class="info-card-label">
                Kasir
            </div>

            <div class="info-card-value">
                <?= esc($sale["cashier"] ?? "-") ?>
            </div>

        </div>


        <div class="info-card">

            <div class="info-card-label">
                Tanggal Transaksi
            </div>

            <div class="info-card-value">
                <?= esc($transaction_date) ?>
            </div>

        </div>


        <div class="info-card">

            <div class="info-card-label">
                Metode Pembayaran
            </div>

            <div class="info-card-value">

                <span class="payment-badge">
                    <?= esc($payment_label) ?>
                </span>

            </div>

        </div>


        <div class="info-card">

            <div class="info-card-label">
                Jumlah Barang
            </div>

            <div class="info-card-value">
                <?= $total_qty ?> item
                Â·
                <?= $total_items ?> produk
            </div>

        </div>

    </section>


    <!-- =====================================================
         DETAIL PRODUK
    ====================================================== -->

    <section class="detail-panel">

        <div class="detail-panel-head">

            <h2>
                Rincian Produk
            </h2>

            <span>
                <?= $total_items ?> jenis produk
            </span>

        </div>


        <?php if (empty($items)): ?>

            <div class="detail-empty">

                Tidak ada detail produk
                untuk transaksi ini.

            </div>

        <?php else: ?>

            <div class="detail-table-wrap">

                <table class="detail-table">

                    <thead>

                        <tr>

                            <th width="50">
                                #
                            </th>

                            <th>
                                Produk
                            </th>

                            <th>
                                Barcode
                            </th>

                            <th class="text-center">
                                Qty
                            </th>

                            <th class="text-right">
                                Harga
                            </th>

                            <th class="text-right">
                                Subtotal
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach (
                            $items
                            as $index => $item
                        ): ?>

                            <tr>

                                <td>
                                    <?= $index + 1 ?>
                                </td>


                                <td>

                                    <div class="product-name">

                                        <?= esc(
                                            $item["name"]
                                            ?? "Produk dihapus"
                                        ) ?>

                                    </div>


                                    <?php if (
                                        !empty(
                                            $item["category"]
                                        )
                                    ): ?>

                                        <div class="product-meta">

                                            <?= esc(
                                                $item["category"]
                                            ) ?>

                                        </div>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php if (
                                        !empty(
                                            $item["barcode"]
                                        )
                                    ): ?>

                                        <span
                                            class="product-meta"
                                        >
                                            <?= esc(
                                                $item["barcode"]
                                            ) ?>
                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="product-meta"
                                        >
                                            -

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td class="text-center">

                                    <?= (int) $item["qty"] ?>

                                </td>


                                <td class="text-right price">

                                    <?= rupiah(
                                        $item["price"]
                                    ) ?>

                                </td>


                                <td class="text-right subtotal">

                                    <?= rupiah(
                                        $item["subtotal"]
                                    ) ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>


    <!-- =====================================================
         PEMBAYARAN
    ====================================================== -->

    <section class="summary-grid">

        <div class="payment-card">

            <h2 class="summary-title">
                Informasi Pembayaran
            </h2>


            <div class="payment-row">

                <span>
                    Metode pembayaran
                </span>

                <strong>
                    <?= esc($payment_label) ?>
                </strong>

            </div>


            <div class="payment-row">

                <span>
                    Total transaksi
                </span>

                <strong>
                    <?= rupiah($total) ?>
                </strong>

            </div>


            <div class="payment-row">

                <span>
                    Dibayar
                </span>

                <strong>
                    <?= rupiah($paid) ?>
                </strong>

            </div>


            <div class="payment-row">

                <span>
                    Kembalian
                </span>

                <strong>
                    <?= rupiah($change) ?>
                </strong>

            </div>

        </div>


        <div class="total-card">

            <div class="grand-total">

                <span>
                    TOTAL PEMBAYARAN
                </span>

                <strong>
                    <?= rupiah($total) ?>
                </strong>

            </div>


            <div class="total-detail">

                <div class="total-detail-row">

                    <span>
                        Jumlah produk
                    </span>

                    <strong>
                        <?= $total_items ?> jenis
                    </strong>

                </div>


                <div class="total-detail-row">

                    <span>
                        Total qty
                    </span>

                    <strong>
                        <?= $total_qty ?> item
                    </strong>

                </div>


                <div class="total-detail-row">

                    <span>
                        Pembayaran
                    </span>

                    <strong>
                        <?= esc($payment_label) ?>
                    </strong>

                </div>

            </div>

        </div>

    </section>


    <div class="detail-note">

        <strong>
            Informasi:
        </strong>

        Data transaksi ditampilkan berdasarkan
        catatan penjualan yang tersimpan di database.
        Harga dan total pada halaman ini berasal
        dari transaksi yang telah tersimpan.

    </div>

</div>


<?php

require "../includes/footer.php";

?>

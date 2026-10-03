<?php

require_once __DIR__ . "/../includes/auth.php";
require_kasir();

require_once __DIR__ . "/../config/database.php";

$title = "Kasir";
$base_url = "/kasir_pertanian";

$message = $_GET["message"] ?? "";
$error = $_GET["error"] ?? "";
$last_invoice = $_GET["invoice"] ?? "";


/* =========================================================
   CABANG AKTIF

   Cabang selalu diambil dari session login.
   Tidak ada branch_id dari POST/GET yang dipercaya.
========================================================= */

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
 * Jika session login belum menyimpan branch_id, ambil cabang
 * milik user yang sedang login dari tabel users.
 * Tetap tidak menerima branch_id dari POST/GET.
 */
if ($branch_id <= 0) {

    $session_user_id = (int) (
        $_SESSION["user"]["id"] ?? 0
    );

    if ($session_user_id > 0) {

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
                $session_user_id
            );

            $user_branch_stmt->execute();

            $user_branch =
                $user_branch_stmt
                    ->get_result()
                    ->fetch_assoc();

            $user_branch_stmt->close();

            if (
                $user_branch &&
                (int) ($user_branch["branch_id"] ?? 0) > 0
            ) {
                $branch_id =
                    (int) $user_branch["branch_id"];

                $branch_name =
                    trim($user_branch["branch_name"] ?? "");
            }
        }
    }
}


if ($branch_id > 0) {

    $branch_stmt = $conn->prepare("
        SELECT
            id,
            name,
            status
        FROM branches
        WHERE id = ?
        LIMIT 1
    ");


    if ($branch_stmt) {

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

        if (!$active_branch) {
            $branch_id = 0;
            $branch_name = "";
        } elseif (($active_branch["status"] ?? "") !== "active") {
            $branch_id = 0;
            $branch_name = "";
        } else {
            $branch_name = $active_branch["name"];
        }
    } else {
        $branch_id = 0;
        $branch_name = "";
    }
}


/* =========================================================
   HELPER
========================================================= */

function rupiah($amount)
{
    return "Rp " . number_format(
        (float) $amount,
        0,
        ",",
        "."
    );
}


function redirect_pos(
    $message = "",
    $error = "",
    $invoice = ""
) {
    $params = [];

    if ($message !== "") {
        $params["message"] = $message;
    }

    if ($error !== "") {
        $params["error"] = $error;
    }

    if ($invoice !== "") {
        $params["invoice"] = $invoice;
    }

    $url = "pos.php";

    if (!empty($params)) {
        $url .= "?" . http_build_query($params);
    }

    header("Location: " . $url);
    exit;
}


/* =========================================================
   STRUK TERAKHIR
========================================================= */

$last_sale = $_SESSION["last_sale"] ?? null;


/* =========================================================
   PROSES TRANSAKSI
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if ($branch_id <= 0) {

        redirect_pos(
            "",
            "Cabang aktif belum tersedia. Silakan login kembali."
        );
    }


    $cart = json_decode(
        $_POST["cart"] ?? "[]",
        true
    );

    $payment_method =
        $_POST["payment_method"] ?? "cash";

    $paid =
        (float) ($_POST["paid"] ?? 0);

    $receipt =
        isset($_POST["receipt"]) ? 1 : 0;


    $allowed_payment = [
        "cash",
        "debit",
        "transfer"
    ];


    /* =====================================================
       VALIDASI CART
    ===================================================== */

    if (!is_array($cart) || empty($cart)) {

        redirect_pos(
            "",
            "Keranjang masih kosong."
        );
    }


    if (
        !in_array(
            $payment_method,
            $allowed_payment,
            true
        )
    ) {

        redirect_pos(
            "",
            "Metode pembayaran tidak valid."
        );
    }


    /* =====================================================
       NORMALISASI CART
    ===================================================== */

    $normalized_cart = [];

    foreach ($cart as $item) {

        if (!is_array($item)) {
            continue;
        }

        $product_id =
            (int) ($item["id"] ?? 0);

        $qty =
            (int) ($item["qty"] ?? 0);


        if (
            $product_id <= 0 ||
            $qty <= 0
        ) {
            continue;
        }


        if (
            isset(
                $normalized_cart[$product_id]
            )
        ) {

            $normalized_cart[$product_id] += $qty;

        } else {

            $normalized_cart[$product_id] = $qty;
        }
    }


    if (empty($normalized_cart)) {

        redirect_pos(
            "",
            "Data keranjang tidak valid."
        );
    }


    /* =====================================================
       NON TUNAI
    ===================================================== */

    if ($payment_method !== "cash") {
        $paid = 0;
    }


    /* =====================================================
       ID KASIR
    ===================================================== */

    $cashier_id =
        (int) ($_SESSION["user"]["id"] ?? 0);


    if ($cashier_id <= 0) {

        redirect_pos(
            "",
            "Sesi kasir tidak valid. Silakan login kembali."
        );
    }


    /* =====================================================
       DATABASE TRANSACTION
    ===================================================== */

    $transaction_started = false;


    try {

        $conn->begin_transaction();

        $transaction_started = true;

        $total = 0;

        $items = [];


        /* =================================================
           AMBIL PRODUK + LOCK STOK
        ================================================= */

        foreach (
            $normalized_cart
            as $product_id => $qty
        ) {

            $stmt = $conn->prepare("
                SELECT
                    id,
                    name,
                    price,
                    stock,
                    branch_id
                FROM products
                WHERE id = ?
                  AND branch_id = ?
                FOR UPDATE
            ");


            if (!$stmt) {

                throw new Exception(
                    "Gagal memproses data produk."
                );
            }


            $stmt->bind_param(
                "ii",
                $product_id,
                $branch_id
            );


            if (!$stmt->execute()) {

                $stmt->close();

                throw new Exception(
                    "Gagal mengambil data produk."
                );
            }


            $result =
                $stmt->get_result();


            $product =
                $result->fetch_assoc();


            $stmt->close();


            if (!$product) {

                throw new Exception(
                    "Produk tidak ditemukan."
                );
            }


            $stock =
                (int) $product["stock"];


            if ($stock <= 0) {

                throw new Exception(
                    "Stok " .
                    $product["name"] .
                    " sedang habis."
                );
            }


            if ($stock < $qty) {

                throw new Exception(
                    "Stok " .
                    $product["name"] .
                    " tidak mencukupi. Tersedia " .
                    $stock .
                    "."
                );
            }


            $price =
                (float) $product["price"];


            $subtotal =
                $price * $qty;


            $total +=
                $subtotal;


            $items[] = [

                "id" =>
                    (int) $product["id"],

                "name" =>
                    $product["name"],

                "qty" =>
                    $qty,

                "price" =>
                    $price,

                "subtotal" =>
                    $subtotal
            ];
        }


        /* =================================================
           VALIDASI TOTAL
        ================================================= */

        if ($total <= 0) {

            throw new Exception(
                "Total transaksi tidak valid."
            );
        }


        /* =================================================
           VALIDASI PEMBAYARAN TUNAI
        ================================================= */

        if ($payment_method === "cash") {

            if ($paid <= 0) {

                throw new Exception(
                    "Nominal uang pembeli belum diisi."
                );
            }


            if ($paid < $total) {

                throw new Exception(
                    "Uang pembeli kurang " .
                    rupiah($total - $paid) .
                    "."
                );
            }
        }


        /* =================================================
           KEMBALIAN
        ================================================= */

        $change_amount = 0;


        if ($payment_method === "cash") {

            $change_amount =
                $paid - $total;
        }


        /* =================================================
           GENERATE INVOICE
        ================================================= */

        $invoice = "";


        for (
            $attempt = 0;
            $attempt < 10;
            $attempt++
        ) {

            $invoice =
                "TRX" .
                date("YmdHis") .
                random_int(1000, 9999);


            $check = $conn->prepare("
                SELECT id
                FROM sales
                WHERE invoice = ?
                LIMIT 1
            ");


            if (!$check) {

                throw new Exception(
                    "Gagal membuat nomor invoice."
                );
            }


            $check->bind_param(
                "s",
                $invoice
            );


            if (!$check->execute()) {

                $check->close();

                throw new Exception(
                    "Gagal memeriksa nomor invoice."
                );
            }


            $exists =
                $check
                    ->get_result()
                    ->num_rows > 0;


            $check->close();


            if (!$exists) {
                break;
            }


            if ($attempt === 9) {

                throw new Exception(
                    "Gagal menghasilkan nomor invoice unik."
                );
            }
        }


        /* =================================================
           INSERT SALES
        ================================================= */

        $stmt = $conn->prepare("
            INSERT INTO sales
            (
                invoice,
                total,
                cashier_id,
                branch_id,
                payment_method,
                paid,
                change_amount,
                receipt
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");


        if (!$stmt) {

            throw new Exception(
                "Gagal menyiapkan transaksi."
            );
        }


        $stmt->bind_param(
            "sdiisddi",
            $invoice,
            $total,
            $cashier_id,
            $branch_id,
            $payment_method,
            $paid,
            $change_amount,
            $receipt
        );


        if (!$stmt->execute()) {

            $stmt->close();

            throw new Exception(
                "Gagal menyimpan transaksi."
            );
        }


        $sale_id =
            $conn->insert_id;


        $stmt->close();


        if ($sale_id <= 0) {

            throw new Exception(
                "ID transaksi tidak berhasil dibuat."
            );
        }


        /* =================================================
           INSERT SALE ITEMS + KURANGI STOK
        ================================================= */

        foreach ($items as $item) {

            $product_id =
                (int) $item["id"];

            $qty =
                (int) $item["qty"];

            $price =
                (float) $item["price"];


            /* ---------------------------------------------
               SALE ITEMS
            --------------------------------------------- */

            $stmt = $conn->prepare("
                INSERT INTO sale_items
                (
                    sale_id,
                    product_id,
                    qty,
                    price
                )
                VALUES (?, ?, ?, ?)
            ");


            if (!$stmt) {

                throw new Exception(
                    "Gagal menyimpan detail transaksi."
                );
            }


            $stmt->bind_param(
                "iiid",
                $sale_id,
                $product_id,
                $qty,
                $price
            );


            if (!$stmt->execute()) {

                $stmt->close();

                throw new Exception(
                    "Gagal menyimpan detail produk."
                );
            }


            $stmt->close();


            /* ---------------------------------------------
               KURANGI STOK
            --------------------------------------------- */

            $stmt = $conn->prepare("
                UPDATE products
                SET stock = stock - ?
                WHERE id = ?
                  AND branch_id = ?
                  AND stock >= ?
            ");


            if (!$stmt) {

                throw new Exception(
                    "Gagal memperbarui stok."
                );
            }


            $stmt->bind_param(
                "iiii",
                $qty,
                $product_id,
                $branch_id,
                $qty
            );


            if (!$stmt->execute()) {

                $stmt->close();

                throw new Exception(
                    "Gagal mengurangi stok."
                );
            }


            if ($stmt->affected_rows !== 1) {

                $stmt->close();

                throw new Exception(
                    "Stok produk berubah. Silakan ulangi transaksi."
                );
            }


            $stmt->close();
        }


        /* =================================================
           COMMIT
        ================================================= */

        $conn->commit();

        $transaction_started = false;


        /* =================================================
           SIMPAN DATA STRUK
        ================================================= */

        if ($receipt) {

            $_SESSION["last_sale"] = [

                "sale_id" =>
                    $sale_id,

                "invoice" =>
                    $invoice,

                "cashier" =>
                    $_SESSION["user"]["name"] ?? "Kasir",

                "created_at" =>
                    date("Y-m-d H:i:s"),

                "items" =>
                    $items,

                "subtotal" =>
                    $total,

                "discount" =>
                    0,

                "total" =>
                    $total,

                "payment_method" =>
                    $payment_method,

                "paid" =>
                    $paid,

                "change_amount" =>
                    $change_amount,

                "receipt" =>
                    $receipt
            ];

        } else {

            unset(
                $_SESSION["last_sale"]
            );
        }


        /* =================================================
           REDIRECT
        ================================================= */

        redirect_pos(
            "Transaksi berhasil disimpan.",
            "",
            $invoice
        );


    } catch (Throwable $e) {

        if ($transaction_started) {

            try {

                $conn->rollback();

            } catch (Throwable $rollback_error) {

                // Abaikan error rollback.
            }
        }


        $safe_error =
            $e->getMessage();


        $known_errors = [

            "Keranjang masih kosong.",

            "Data keranjang tidak valid.",

            "Metode pembayaran tidak valid.",

            "Produk tidak ditemukan.",

            "Total transaksi tidak valid.",

            "Nominal uang pembeli belum diisi.",

            "Sesi kasir tidak valid. Silakan login kembali.",

            "Cabang aktif belum tersedia. Silakan login kembali."
        ];


        $is_known =
            in_array(
                $safe_error,
                $known_errors,
                true
            );


        $is_stock_error =
            stripos(
                $safe_error,
                "Stok "
            ) === 0;


        $is_payment_error =
            stripos(
                $safe_error,
                "Uang pembeli kurang"
            ) === 0;


        if (
            !$is_known &&
            !$is_stock_error &&
            !$is_payment_error
        ) {

            error_log(
                "POS TRANSACTION ERROR: " .
                $e->getMessage()
            );


            $safe_error =
                "Transaksi gagal diproses. Silakan coba lagi.";
        }


        redirect_pos(
            "",
            $safe_error
        );
    }
}


/* =========================================================
   DATA PRODUK
========================================================= */

$products = false;

if ($branch_id > 0) {

    $product_list_stmt = $conn->prepare("
        SELECT
            id,
            name,
            image,
            barcode,
            category,
            price,
            stock
        FROM products
        WHERE branch_id = ?
        ORDER BY name ASC
    ");

    if ($product_list_stmt) {
        $product_list_stmt->bind_param(
            "i",
            $branch_id
        );
        $product_list_stmt->execute();
        $products = $product_list_stmt->get_result();
        $product_list_stmt->close();
    }
}


if (!$products) {

    error_log(
        "POS PRODUCT QUERY ERROR: " .
        $conn->error
    );


    die(
        "Gagal mengambil data produk."
    );
}


require __DIR__ . "/../includes/header.php";

?>


<style>

/* =========================================================
   POS LAYOUT
========================================================= */

.pos-page {
    display: grid;
    grid-template-columns:
        minmax(0, 1.45fr)
        minmax(360px, .85fr);
    gap: 18px;
    align-items: start;
}

.pos-panel {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 18px;
    box-shadow: 0 8px 28px #173c240b;
}

.pos-panel-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}

.pos-panel-head h2 {
    margin: 0;
    font-size: 16px;
    letter-spacing: -.03em;
}

.pos-panel-head p {
    margin: 4px 0 0;
    color: var(--muted);
    font-size: 9px;
    line-height: 1.45;
}

.pos-count {
    padding: 5px 9px;
    border-radius: 20px;
    background: #f1f7f2;
    color: var(--green);
    font-size: 8px;
    font-weight: 850;
    white-space: nowrap;
}


/* =========================================================
   TOAST
========================================================= */

.pos-toast-container {
    position: fixed;
    top: 22px;
    right: 22px;
    z-index: 10000;
    width: min(390px, calc(100vw - 30px));
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
}

.pos-toast {
    position: relative;
    display: grid;
    grid-template-columns: 38px minmax(0, 1fr) 25px;
    gap: 10px;
    align-items: start;
    padding: 13px;
    border: 1px solid #dfe8e2;
    border-radius: 13px;
    background: rgba(255,255,255,.98);
    box-shadow:
        0 14px 35px rgba(23,60,36,.16),
        0 2px 6px rgba(23,60,36,.05);
    overflow: hidden;
    pointer-events: auto;
    animation: toastIn .35s ease both;
}

.pos-toast.hide {
    animation: toastOut .28s ease both;
}

@keyframes toastIn {
    from {
        opacity: 0;
        transform: translateX(35px) scale(.97);
    }

    to {
        opacity: 1;
        transform: translateX(0) scale(1);
    }
}

@keyframes toastOut {
    from {
        opacity: 1;
        transform: translateX(0) scale(1);
    }

    to {
        opacity: 0;
        transform: translateX(35px) scale(.97);
    }
}

.pos-toast-icon {
    width: 38px;
    height: 38px;
    border-radius: 11px;
    display: grid;
    place-items: center;
    font-size: 17px;
    font-weight: 900;
}

.pos-toast.success .pos-toast-icon {
    background: #e7f6eb;
    color: #176f3d;
}

.pos-toast.error .pos-toast-icon {
    background: #fff0f0;
    color: #c63d3d;
}

.pos-toast.warning .pos-toast-icon {
    background: #fff7e5;
    color: #a86b00;
}

.pos-toast-content {
    min-width: 0;
}

.pos-toast-title {
    font-size: 10px;
    font-weight: 900;
    color: #1e2922;
    line-height: 1.3;
}

.pos-toast-message {
    margin-top: 3px;
    color: #6b776f;
    font-size: 8px;
    line-height: 1.45;
}

.pos-toast-invoice {
    margin-top: 5px;
    color: #176f3d;
    font-size: 8px;
    font-weight: 850;
    letter-spacing: .01em;
}

.pos-toast-close {
    width: 25px;
    height: 25px;
    border: 0;
    border-radius: 7px;
    background: #f2f5f3;
    color: #647169;
    cursor: pointer;
    font-size: 15px;
    line-height: 1;
}

.pos-toast-close:hover {
    background: #e7ece8;
}

.pos-toast-action {
    grid-column: 2 / 4;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding-top: 9px;
    border-top: 1px solid #edf1ee;
}

.pos-toast-total {
    font-size: 12px;
    font-weight: 900;
    color: #176f3d;
}

.pos-toast-receipt {
    border: 0;
    border-radius: 7px;
    padding: 7px 10px;
    background: #176f3d;
    color: #fff;
    font-size: 8px;
    font-weight: 850;
    cursor: pointer;
}

.pos-toast-progress {
    position: absolute;
    left: 0;
    bottom: 0;
    height: 2px;
    width: 100%;
    background: #176f3d;
    transform-origin: left;
    animation: toastProgress 6s linear forwards;
}

.pos-toast.error .pos-toast-progress {
    background: #c63d3d;
}

@keyframes toastProgress {

    from {
        transform: scaleX(1);
    }

    to {
        transform: scaleX(0);
    }
}


/* =========================================================
   KONFIRMASI TRANSAKSI
========================================================= */

.checkout-confirm-modal {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(10, 25, 16, .48);
    backdrop-filter: blur(5px);
}

.checkout-confirm-modal.show {
    display: flex;
}

.checkout-confirm-box {
    width: min(420px, 100%);
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 24px 70px rgba(0,0,0,.22);
    overflow: hidden;
    animation: confirmModalIn .18s ease-out;
}

@keyframes confirmModalIn {

    from {
        opacity: 0;
        transform: translateY(10px) scale(.98);
    }

    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.checkout-confirm-head {
    padding: 20px 22px 12px;
}

.checkout-confirm-icon {
    width: 44px;
    height: 44px;
    border-radius: 13px;
    background: #eaf6ed;
    color: #176f3d;
    display: grid;
    place-items: center;
    font-size: 21px;
    font-weight: 900;
    margin-bottom: 13px;
}

.checkout-confirm-head h3 {
    margin: 0;
    color: #18231d;
    font-size: 18px;
    font-weight: 850;
}

.checkout-confirm-body {
    padding: 0 22px 20px;
}

.checkout-confirm-question {
    margin: 0;
    color: #526058;
    font-size: 13px;
    line-height: 1.6;
}

.checkout-confirm-info {
    margin-top: 14px;
    padding: 13px 14px;
    background: #f7faf7;
    border: 1px solid #dfe9e1;
    border-radius: 12px;
}

.checkout-confirm-row {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    padding: 5px 0;
    font-size: 12px;
}

.checkout-confirm-row span {
    color: #68736d;
}

.checkout-confirm-row strong {
    color: #18231d;
    text-align: right;
}

.checkout-confirm-total {
    margin-top: 7px;
    padding-top: 9px;
    border-top: 1px solid #dfe9e1;
}

.checkout-confirm-total strong {
    color: #176f3d;
    font-size: 16px;
}

.checkout-confirm-actions {
    display: flex;
    gap: 10px;
    padding: 16px 22px 20px;
    border-top: 1px solid #edf1ed;
    background: #fbfcfb;
}

.checkout-confirm-btn {
    flex: 1;
    min-height: 44px;
    border-radius: 10px;
    border: 1px solid #d9e2db;
    cursor: pointer;
    font-size: 12px;
    font-weight: 800;
    transition: .15s;
}

.checkout-confirm-btn.cancel {
    background: #fff;
    color: #526058;
}

.checkout-confirm-btn.cancel:hover {
    background: #f3f6f3;
}

.checkout-confirm-btn.confirm {
    background: #176f3d;
    border-color: #176f3d;
    color: #fff;
    box-shadow: 0 7px 18px rgba(23,111,61,.20);
}

.checkout-confirm-btn.confirm:hover {
    background: #125b31;
}


/* =========================================================
   SEARCH
========================================================= */

.pos-search-wrap {
    position: relative;
    margin-bottom: 9px;
}

.pos-search {
    width: 100%;
    padding: 12px 42px 12px 38px;
    border: 1px solid var(--border);
    border-radius: 10px;
    outline: none;
    background: #fbfdfb;
    color: var(--text);
    font-size: 10px;
    box-sizing: border-box;
}

.pos-search::-webkit-search-cancel-button,
.pos-search::-webkit-search-decoration {
    -webkit-appearance: none;
    appearance: none;
}

.pos-search:focus {
    border-color: #8fc5a3;
    box-shadow: 0 0 0 3px #176f3d10;
}

.pos-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    font-size: 14px;
}

.pos-search-clear {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 25px;
    height: 25px;
    border: 0;
    border-radius: 6px;
    background: #edf3ee;
    color: #5f7065;
    font-weight: 800;
    cursor: pointer;
}


/* =========================================================
   PRODUCT GRID
========================================================= */

.pos-products {
    display: grid;
    grid-template-columns:
        repeat(3, minmax(0, 1fr));
    gap: 10px;
    max-height: calc(100vh - 315px);
    min-height: 220px;
    overflow-y: auto;
    padding: 2px 3px 3px 1px;
}

.pos-product {
    border: 1px solid var(--border);
    background: #fff;
    border-radius: 12px;
    padding: 10px;
    text-align: left;
    color: var(--text);
    transition: .18s;
    min-width: 0;
    cursor: pointer;
}

.pos-product:hover {
    border-color: #8fc5a3;
    box-shadow: 0 8px 20px #176f3d12;
    transform: translateY(-2px);
}

.pos-product.out-of-stock {
    opacity: .55;
    background: #fafafa;
}

.pos-product.out-of-stock:hover {
    transform: none;
    border-color: var(--border);
    box-shadow: none;
}

.pos-product-image {
    height: 105px;
    border-radius: 9px;
    overflow: hidden;
    background: linear-gradient(
        135deg,
        #eef5ea,
        #faf0e5
    );
    display: grid;
    place-items: center;
    margin-bottom: 9px;
}

.pos-product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.pos-product-image .no-image {
    font-size: 25px;
    color: #78917e;
}

.pos-product-name {
    display: block;
    font-size: 10px;
    font-weight: 850;
    line-height: 1.35;
    min-height: 27px;
}

.pos-product-category {
    display: block;
    margin-top: 4px;
    font-size: 8px;
    color: var(--muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.pos-product-price {
    display: block;
    margin-top: 7px;
    font-size: 11px;
    font-weight: 900;
    color: var(--green);
}

.pos-product-stock {
    display: inline-flex;
    margin-top: 6px;
    padding: 4px 6px;
    border-radius: 20px;
    background: #f1f7f2;
    color: var(--green);
    font-size: 7px;
    font-weight: 850;
}

.pos-product-stock.empty {
    background: #fff0f0;
    color: #bd3e3e;
}

.pos-product-code {
    display: block;
    margin-top: 4px;
    color: #87928a;
    font-size: 7px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}


/* =========================================================
   EMPTY
========================================================= */

.pos-empty {
    min-height: 160px;
    display: grid;
    place-items: center;
    text-align: center;
    padding: 25px;
    border: 1px dashed var(--border);
    border-radius: 11px;
    background: #fbfdfb;
    color: var(--muted);
    font-size: 9px;
    line-height: 1.5;
}

.pos-empty strong {
    display: block;
    color: var(--text);
    font-size: 11px;
    margin-bottom: 4px;
}


/* =========================================================
   CART
========================================================= */

.pos-cart {
    min-height: 180px;
    max-height: 390px;
    overflow-y: auto;
    padding-right: 3px;
}

.pos-cart-item {
    display: grid;
    grid-template-columns:
        minmax(0, 1fr)
        auto;
    gap: 8px;
    padding: 11px 0;
    border-bottom: 1px solid var(--border);
}

.pos-cart-item:last-child {
    border-bottom: 0;
}

.pos-cart-name {
    font-size: 10px;
    font-weight: 850;
    line-height: 1.4;
}

.pos-cart-price {
    display: block;
    color: var(--muted);
    font-size: 8px;
    margin-top: 3px;
}

.pos-cart-subtotal {
    text-align: right;
    font-size: 10px;
    font-weight: 900;
    white-space: nowrap;
}

.pos-cart-controls {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 7px;
}

.pos-qty-btn {
    width: 25px;
    height: 25px;
    border: 1px solid var(--border);
    background: #fff;
    border-radius: 6px;
    color: var(--text);
    font-weight: 900;
    cursor: pointer;
}

.pos-qty {
    min-width: 22px;
    text-align: center;
    font-size: 9px;
    font-weight: 850;
}

.pos-remove {
    margin-left: 4px;
    border: 0;
    background: #fff0f0;
    color: #bd3e3e;
    border-radius: 6px;
    height: 25px;
    padding: 0 7px;
    font-size: 8px;
    font-weight: 850;
    cursor: pointer;
}


/* =========================================================
   TOTAL
========================================================= */

.pos-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 15px 0;
    margin-top: 3px;
    border-top: 1px solid var(--border);
}

.pos-total span {
    color: var(--muted);
    font-size: 10px;
    font-weight: 750;
}

.pos-total strong {
    font-size: 21px;
    color: var(--green);
    letter-spacing: -.04em;
}


/* =========================================================
   PAYMENT
========================================================= */

.payment-box {
    margin-top: 13px;
    padding-top: 13px;
    border-top: 1px solid var(--border);
}

.payment-title {
    font-size: 9px;
    font-weight: 850;
    color: #48564d;
    margin-bottom: 8px;
}

.payment-methods {
    display: grid;
    grid-template-columns:
        repeat(3, 1fr);
    gap: 6px;
}

.payment-method {
    border: 1px solid var(--border);
    background: #fff;
    border-radius: 9px;
    padding: 9px 6px;
    text-align: center;
    font-size: 8px;
    font-weight: 850;
    color: #526058;
    cursor: pointer;
}

.payment-method.active {
    background: #eaf6ed;
    border-color: #9bc8a8;
    color: var(--green);
}

.payment-icon {
    display: block;
    font-size: 15px;
    margin-bottom: 3px;
}


/* =========================================================
   CASH
========================================================= */

.cash-detail {
    margin-top: 10px;
}

.cash-detail label {
    display: block;
    font-size: 8px;
    font-weight: 800;
    color: #526058;
    margin-bottom: 4px;
}

.cash-input-wrap {
    position: relative;
}

.cash-input-prefix {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 9px;
    font-weight: 850;
    color: var(--muted);
}

.cash-detail input {
    width: 100%;
    padding: 10px 11px 10px 35px;
    border: 1px solid var(--border);
    border-radius: 9px;
    outline: none;
    font-size: 11px;
    font-weight: 750;
    box-sizing: border-box;
}

.cash-detail input:focus {
    border-color: #8fc5a3;
    box-shadow: 0 0 0 3px #176f3d10;
}

.change-box {
    margin-top: 8px;
    padding: 10px 11px;
    border-radius: 9px;
    background: #f5faf5;
    border: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 9px;
}

.change-box strong {
    color: var(--green);
    font-size: 12px;
}

.change-box.insufficient {
    background: #fff0f0;
    border-color: #f0c5c5;
}

.change-box.insufficient strong {
    color: var(--red);
}


/* =========================================================
   RECEIPT OPTION
========================================================= */

.receipt-option {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 9px;
    padding: 9px;
    border: 1px solid var(--border);
    background: #fbfdfb;
    border-radius: 9px;
    font-size: 8px;
    color: #526058;
    font-weight: 750;
    cursor: pointer;
}

.receipt-option input {
    accent-color: var(--green);
}


/* =========================================================
   CHECKOUT BUTTON
========================================================= */

.checkout-btn {
    width: 100%;
    margin-top: 10px;
    padding: 13px 14px;
    border: 0;
    border-radius: 10px;

    /* WARNA UTAMA TOMBOL */
    background: linear-gradient(
        135deg,
        #176f3d,
        #21864d
    );

    color: #fff;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .01em;

    box-shadow:
        0 8px 20px rgba(23,111,61,.20);

    cursor: pointer;

    transition:
        transform .15s ease,
        box-shadow .15s ease,
        background .15s ease,
        opacity .15s ease;
}


/* SAAT TOMBOL BISA DIGUNAKAN */
.checkout-btn:not(:disabled) {
    background: linear-gradient(
        135deg,
        #176f3d,
        #21864d
    );

    color: #fff;
}


/* HOVER */
.checkout-btn:hover:not(:disabled) {
    transform: translateY(-1px);

    background: linear-gradient(
        135deg,
        #125b31,
        #176f3d
    );

    box-shadow:
        0 10px 24px rgba(23,111,61,.28);
}


/* SAAT DITEKAN */
.checkout-btn:active:not(:disabled) {
    transform: translateY(0);

    box-shadow:
        0 5px 12px rgba(23,111,61,.20);
}


/* SAAT BELUM BISA TRANSAKSI */
.checkout-btn:disabled {
    opacity: 1;
    cursor: not-allowed;

    background: #9da9a1;

    color: #fff;

    box-shadow: none;
}


/* SAAT TRANSAKSI SEDANG DIPROSES */
.checkout-btn.processing {
    pointer-events: none;
    cursor: wait;

    opacity: .75;

    background: linear-gradient(
        135deg,
        #176f3d,
        #21864d
    );

    color: #fff;

    box-shadow:
        0 8px 20px rgba(23,111,61,.18);
}


/* =========================================================
   NOTE
========================================================= */

.pos-note {
    margin-top: 8px;
    padding: 9px 10px;
    border-radius: 8px;
    background: #f7faf7;
    border: 1px solid var(--border);
    color: var(--muted);
    font-size: 8px;
    line-height: 1.5;
}


/* =========================================================
   RECEIPT MODAL
========================================================= */

.receipt-modal {
    position: fixed;
    inset: 0;
    z-index: 9998;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(17, 29, 21, .58);
    backdrop-filter: blur(4px);
}

.receipt-modal.show {
    display: flex;
}

.receipt-modal-box {
    width: min(420px, 100%);
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    background: #f3f5f3;
    border-radius: 15px;
    box-shadow:
        0 25px 80px rgba(0,0,0,.28);
    animation: receiptIn .3s ease both;
}

@keyframes receiptIn {

    from {
        opacity: 0;
        transform: translateY(15px) scale(.97);
    }

    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.receipt-modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 14px 16px;
    background: #fff;
    border-bottom: 1px solid #e3e8e4;
    border-radius: 15px 15px 0 0;
}

.receipt-modal-head-left {
    display: flex;
    align-items: center;
    gap: 9px;
}

.receipt-success-icon {
    width: 29px;
    height: 29px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: #e5f5e9;
    color: #176f3d;
    font-weight: 900;
    font-size: 14px;
}

.receipt-modal-head strong {
    display: block;
    font-size: 11px;
    color: #202a24;
}

.receipt-modal-head span {
    display: block;
    margin-top: 2px;
    font-size: 7px;
    color: #7a857d;
}

.receipt-close {
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 7px;
    background: #f0f4f1;
    color: #536158;
    font-size: 18px;
    font-weight: 700;
    cursor: pointer;
}


/* =========================================================
   RECEIPT PAPER
========================================================= */

.receipt-paper {
    width: 80mm;
    max-width: calc(100% - 30px);
    margin: 16px auto;
    padding: 22px 17px;
    box-sizing: border-box;
    color: #202520;
    background: #fff;
    font-family:
        Arial,
        Helvetica,
        sans-serif;
    box-shadow:
        0 5px 16px rgba(0,0,0,.08);
}

.receipt-shop {
    text-align: center;
    padding-bottom: 13px;
    border-bottom: 1px dashed #aeb7b0;
}

/* LOGO PIJ DIHAPUS */

.receipt-shop h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 900;
    letter-spacing: .02em;
}

.receipt-shop h3 {
    margin: 3px 0 0;
    font-size: 8px;
    font-weight: 700;
    color: #5f6a62;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.receipt-shop p {
    margin: 5px 0 0;
    font-size: 7px;
    color: #7a827d;
}


/* =========================================================
   RECEIPT META
========================================================= */

.receipt-meta {
    padding: 11px 0;
    border-bottom: 1px dashed #aeb7b0;
}

.receipt-meta-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 5px;
    font-size: 8px;
}

.receipt-meta-row:last-child {
    margin-bottom: 0;
}

.receipt-meta-row span:first-child {
    color: #737d76;
}

.receipt-meta-row span:last-child {
    text-align: right;
    font-weight: 750;
    color: #303830;
    max-width: 62%;
    overflow-wrap: anywhere;
}


/* =========================================================
   RECEIPT ITEMS
========================================================= */

.receipt-items {
    padding: 11px 0;
    border-bottom: 1px dashed #aeb7b0;
}

.receipt-item {
    margin-bottom: 9px;
}

.receipt-item:last-child {
    margin-bottom: 0;
}

.receipt-item-name {
    font-size: 8px;
    font-weight: 800;
    line-height: 1.4;
}

.receipt-item-detail {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-top: 3px;
    font-size: 7.5px;
    color: #69736c;
}

.receipt-item-detail span:last-child {
    color: #303830;
    font-weight: 700;
    white-space: nowrap;
}


/* =========================================================
   RECEIPT SUMMARY
========================================================= */

.receipt-summary {
    padding: 11px 0;
}

.receipt-summary-row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 6px;
    font-size: 8px;
}

.receipt-summary-row span:last-child {
    text-align: right;
    font-weight: 700;
}

.receipt-summary-row.total {
    margin-top: 8px;
    margin-bottom: 9px;
    padding: 9px 0;
    border-top: 1px solid #dce2dd;
    border-bottom: 1px solid #dce2dd;
    font-size: 11px;
    font-weight: 900;
}

.receipt-summary-row.total span:last-child {
    color: #176f3d;
    font-size: 12px;
}


/* =========================================================
   RECEIPT PAYMENT
========================================================= */

.receipt-payment {
    padding: 9px 10px;
    border-radius: 6px;
    background: #f5f8f5;
}

.receipt-payment-title {
    margin-bottom: 6px;
    font-size: 7px;
    color: #6c776f;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.receipt-payment-row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 5px;
    font-size: 8px;
}

.receipt-payment-row:last-child {
    margin-bottom: 0;
}

.receipt-payment-row strong {
    color: #176f3d;
}


/* =========================================================
   RECEIPT FOOTER
========================================================= */

.receipt-footer {
    text-align: center;
    padding-top: 13px;
    border-top: 1px dashed #aeb7b0;
}

.receipt-footer p {
    margin: 3px 0;
    font-size: 7px;
    color: #69736c;
}

.receipt-footer strong {
    display: block;
    margin-bottom: 4px;
    font-size: 9px;
    color: #303830;
}


/* =========================================================
   RECEIPT ACTIONS
========================================================= */

.receipt-modal-actions {
    display: grid;
    grid-template-columns: 1fr 1.35fr;
    gap: 8px;
    padding: 12px 16px 16px;
    background: #fff;
    border-top: 1px solid #e3e8e4;
    border-radius: 0 0 15px 15px;
}

.receipt-btn {
    border: 0;
    border-radius: 8px;
    padding: 10px;
    font-size: 9px;
    font-weight: 850;
    cursor: pointer;
}

.receipt-btn.primary {
    background: #176f3d !important;
    color: #fff !important;
    border: 1px solid #176f3d !important;
    box-shadow: 0 7px 18px rgba(23,111,61,.20) !important;
    font-weight: 850 !important;
}

.receipt-btn.primary:hover {
    background: #125b31 !important;
    color: #fff !important;
}

.receipt-btn.secondary {
    background: #edf1ee;
    color: #526058;
}

.receipt-btn:hover {
    transform: translateY(-1px);
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .pos-page {
        grid-template-columns: 1fr;
    }

    .pos-products {
        max-height: none;
    }

    .pos-cart {
        max-height: none;
    }
}

@media (max-width: 760px) {

    .pos-products {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .pos-toast-container {
        top: 12px;
        right: 12px;
        width: calc(100vw - 24px);
    }
}

@media (max-width: 520px) {

    .pos-panel {
        padding: 14px;
        border-radius: 14px;
    }

    .pos-products {
        grid-template-columns: 1fr;
    }

    .payment-methods {
        grid-template-columns: 1fr;
    }

    .receipt-modal {
        padding: 8px;
    }

    .receipt-modal-box {
        max-height: calc(100vh - 16px);
    }

    .receipt-paper {
        width: 80mm;
        max-width: calc(100% - 10px);
        margin: 10px auto;
    }

    .receipt-modal-actions {
        grid-template-columns: 1fr;
    }

    .checkout-confirm-actions {
        flex-direction: column-reverse;
    }

    .checkout-confirm-btn {
        width: 100%;
    }
}


/* =========================================================
   PRINT STRUK THERMAL
========================================================= */

@media print {

    body * {
        visibility: hidden !important;
    }

    .receipt-modal,
    .receipt-modal * {
        visibility: visible !important;
    }

    .receipt-modal {
        position: absolute !important;
        inset: 0 !important;
        display: block !important;
        padding: 0 !important;
        background: #fff !important;
    }

    .receipt-modal-box {
        width: 100% !important;
        max-height: none !important;
        overflow: visible !important;
        background: #fff !important;
        box-shadow: none !important;
        border-radius: 0 !important;
    }

    .receipt-modal-head,
    .receipt-modal-actions {
        display: none !important;
    }

    .receipt-paper {
        width: 80mm !important;
        max-width: 80mm !important;
        margin: 0 auto !important;
        padding: 5mm !important;
        box-shadow: none !important;
    }

    @page {
        size: 80mm auto;
        margin: 0;
    }
}

</style>


<!-- =========================================================
     TOAST
========================================================= -->

<div
    class="pos-toast-container"
    id="toastContainer"
></div>


<!-- =========================================================
     MODAL KONFIRMASI TRANSAKSI
========================================================= -->

<div
    class="checkout-confirm-modal"
    id="checkoutConfirmModal"
    aria-hidden="true"
>

    <div
        class="checkout-confirm-box"
        role="dialog"
        aria-modal="true"
        aria-labelledby="checkoutConfirmTitle"
    >

        <div class="checkout-confirm-head">

            <div class="checkout-confirm-icon">
                ✓
            </div>

            <h3 id="checkoutConfirmTitle">
                Konfirmasi Transaksi
            </h3>

        </div>


        <div class="checkout-confirm-body">

            <p class="checkout-confirm-question">
                Pastikan detail transaksi sudah benar
                sebelum transaksi disimpan.
            </p>


            <div class="checkout-confirm-info">

                <div class="checkout-confirm-row">

                    <span>
                        Total Transaksi
                    </span>

                    <strong id="confirmTotal">
                        Rp 0
                    </strong>

                </div>


                <div class="checkout-confirm-row">

                    <span>
                        Metode Pembayaran
                    </span>

                    <strong id="confirmPayment">
                        Tunai
                    </strong>

                </div>


                <div class="checkout-confirm-row checkout-confirm-total">

                    <span>
                        Konfirmasi
                    </span>

                    <strong>
                        Simpan transaksi?
                    </strong>

                </div>

            </div>

        </div>


        <div class="checkout-confirm-actions">

            <button
                type="button"
                class="checkout-confirm-btn cancel"
                onclick="closeCheckoutConfirm()"
            >
                Batal
            </button>


            <button
                type="button"
                class="checkout-confirm-btn confirm"
                onclick="submitConfirmedCheckout()"
            >
                Simpan Transaksi
            </button>

        </div>

    </div>

</div>


<!-- =========================================================
     POS
========================================================= -->

<div class="pos-page">


    <!-- =====================================================
         PRODUK
    ====================================================== -->

    <section class="pos-panel">

        <div class="pos-panel-head">

            <div>

                <h2>Pilih Produk</h2>

                <p>
                    Pilih produk yang akan dimasukkan ke keranjang.
                </p>

            </div>

            <span class="pos-count">

                <?= (int) $products->num_rows ?>

                produk

            </span>

        </div>


        <div class="pos-search-wrap">

            <span class="pos-search-icon">
                ⌕
            </span>

            <input
                type="search"
                id="productSearch"
                class="pos-search"
                placeholder="Cari produk / barcode..."
                autocomplete="off"
                oninput="filterProducts()"
            >


            <button
                type="button"
                class="pos-search-clear"
                onclick="clearProductSearch()"
                title="Bersihkan"
            >
                ×
            </button>

        </div>


        <div
            class="pos-products"
            id="productGrid"
        >

            <?php if ($products->num_rows === 0): ?>

                <div class="pos-empty">

                    <div>

                        <strong>
                            Belum ada produk
                        </strong>

                        Tambahkan produk terlebih dahulu.

                    </div>

                </div>

            <?php else: ?>

                <?php while ($p = $products->fetch_assoc()): ?>

                    <?php

                    $stock =
                        (int) $p["stock"];

                    $price =
                        (float) $p["price"];


                    $product_data = [

                        "id" =>
                            (int) $p["id"],

                        "name" =>
                            $p["name"],

                        "price" =>
                            $price,

                        "stock" =>
                            $stock,

                        "barcode" =>
                            $p["barcode"] ?? "",

                        "category" =>
                            $p["category"] ?? ""

                    ];


                    $image_path = "";


                    if (!empty($p["image"])) {

                        $image_path =
                            $base_url .
                            "/uploads/products/" .
                            rawurlencode(
                                $p["image"]
                            );
                    }

                    ?>


                    <button
                        type="button"
                        class="pos-product <?= $stock <= 0 ? "out-of-stock" : "" ?>"
                        data-product-id="<?= (int) $p["id"] ?>"
                        data-price="<?= htmlspecialchars((string) $price) ?>"
                        data-stock="<?= $stock ?>"
                        data-name="<?= htmlspecialchars(strtolower($p["name"])) ?>"
                        data-category="<?= htmlspecialchars(strtolower($p["category"] ?? "")) ?>"
                        data-barcode="<?= htmlspecialchars(strtolower($p["barcode"] ?? "")) ?>"
                        onclick='addProduct(<?= json_encode(
                            $product_data,
                            JSON_HEX_TAG |
                            JSON_HEX_APOS |
                            JSON_HEX_QUOT |
                            JSON_HEX_AMP
                        ) ?>)'
                    >

                        <div class="pos-product-image">

                            <?php if ($image_path): ?>

                                <img
                                    src="<?= htmlspecialchars($image_path) ?>"
                                    alt="<?= htmlspecialchars($p["name"]) ?>"
                                    loading="lazy"
                                    onerror="this.style.display='none';this.nextElementSibling.style.display='block';"
                                >

                                <span
                                    class="no-image"
                                    style="display:none;"
                                >
                                    📦
                                </span>

                            <?php else: ?>

                                <span class="no-image">
                                    📦
                                </span>

                            <?php endif; ?>

                        </div>


                        <span class="pos-product-name">
                            <?= htmlspecialchars($p["name"]) ?>
                        </span>


                        <span class="pos-product-category">

                            <?= htmlspecialchars(
                                $p["category"] ??
                                "Tanpa kategori"
                            ) ?>

                        </span>


                        <span class="pos-product-price">
                            <?= rupiah($price) ?>
                        </span>


                        <span
                            class="pos-product-stock <?= $stock <= 0 ? "empty" : "" ?>"
                        >

                            <?php if ($stock <= 0): ?>

                                Stok Habis

                            <?php else: ?>

                                Stok <?= $stock ?>

                            <?php endif; ?>

                        </span>


                        <?php if (!empty($p["barcode"])): ?>

                            <span class="pos-product-code">
                                #<?= htmlspecialchars($p["barcode"]) ?>
                            </span>

                        <?php endif; ?>

                    </button>

                <?php endwhile; ?>

            <?php endif; ?>

        </div>

    </section>


    <!-- =====================================================
         KERANJANG
    ====================================================== -->

    <section class="pos-panel">

        <div class="pos-panel-head">

            <div>

                <h2>Keranjang</h2>

                <p>
                    Cek barang dan pembayaran.
                </p>

            </div>

        </div>


        <form
            method="POST"
            id="checkoutForm"
            onsubmit="return confirmCheckout()"
        >

            <input
                type="hidden"
                name="cart"
                id="cartInput"
            >


            <div
                class="pos-cart"
                id="cart"
            ></div>


            <div class="pos-total">

                <span>
                    Total
                </span>

                <strong id="total">
                    Rp 0
                </strong>

            </div>


            <div class="payment-box">

                <div class="payment-title">
                    PEMBAYARAN
                </div>


                <div class="payment-methods">

                    <button
                        type="button"
                        class="payment-method active"
                        data-method="cash"
                        onclick="setPayment('cash')"
                    >

                        <span class="payment-icon">
                            💵
                        </span>

                        Tunai

                    </button>


                    <button
                        type="button"
                        class="payment-method"
                        data-method="debit"
                        onclick="setPayment('debit')"
                    >

                        <span class="payment-icon">
                            💳
                        </span>

                        Debit

                    </button>


                    <button
                        type="button"
                        class="payment-method"
                        data-method="transfer"
                        onclick="setPayment('transfer')"
                    >

                        <span class="payment-icon">
                            🏦
                        </span>

                        Transfer

                    </button>

                </div>


                <input
                    type="hidden"
                    name="payment_method"
                    id="paymentMethod"
                    value="cash"
                >


                <div
                    class="cash-detail"
                    id="cashDetail"
                >

                    <label for="paid">
                        UANG DIBAYAR
                    </label>


                    <div class="cash-input-wrap">

                        <span class="cash-input-prefix">
                            Rp
                        </span>


                        <input
                            type="number"
                            name="paid"
                            id="paid"
                            min="0"
                            step="1"
                            placeholder="Nominal pembayaran"
                            inputmode="numeric"
                            oninput="calculateChange()"
                        >

                    </div>


                    <div
                        class="change-box"
                        id="changeBox"
                    >

                        <span>
                            Kembalian
                        </span>

                        <strong id="change">
                            Rp 0
                        </strong>

                    </div>

                </div>


                <label class="receipt-option">

                    <input
                        type="checkbox"
                        name="receipt"
                        id="receipt"
                        value="1"
                        checked
                    >

                    Cetak / simpan struk

                </label>


                <button
                    type="submit"
                    class="checkout-btn"
                    id="checkoutButton"
                    disabled
                >
                    Simpan Transaksi
                </button>


                <div class="pos-note">

                    <strong>Catatan:</strong>
                    Tunai masuk kas fisik.
                    Debit dan transfer non-tunai.

                </div>

            </div>

        </form>

    </section>

</div>

<!-- =========================================================
     MODAL STRUK
========================================================== -->

<?php if (is_array($last_sale)): ?>

    <?php

    $receipt_items =
        is_array($last_sale["items"] ?? null)
            ? $last_sale["items"]
            : [];


    $receipt_payment =
        $last_sale["payment_method"] ?? "cash";


    $receipt_payment_label = match ($receipt_payment) {

        "debit" =>
            "Debit",

        "transfer" =>
            "Transfer",

        default =>
            "Tunai"
    };


    $receipt_created =
        $last_sale["created_at"] ??
        date("Y-m-d H:i:s");


    $receipt_total =
        (float) (
            $last_sale["total"] ?? 0
        );


    /*
     * Pada tabel sales tidak ada kolom subtotal.
     * Karena transaksi saat ini tidak menggunakan diskon,
     * subtotal disamakan dengan total.
     */

    $receipt_subtotal =
        (float) (
            $last_sale["subtotal"] ??
            $receipt_total
        );


    $receipt_discount =
        (float) (
            $last_sale["discount"] ?? 0
        );


    $receipt_paid =
        (float) (
            $last_sale["paid"] ?? 0
        );


    $receipt_change =
        (float) (
            $last_sale["change_amount"] ?? 0
        );

    ?>


    <!-- =====================================================
         MODAL STRUK
    ====================================================== -->

    <div
        class="receipt-modal"
        id="receiptModal"
    >

        <div
            class="receipt-modal-box"
            role="dialog"
            aria-modal="true"
            aria-label="Struk transaksi"
        >


            <!-- =================================================
                 HEADER MODAL
            ================================================== -->

            <div class="receipt-modal-head">

                <div class="receipt-modal-head-left">

                    <div class="receipt-success-icon">
                        ✓
                    </div>

                    <div>

                        <strong>
                            Transaksi Berhasil
                        </strong>

                        <span>
                            Struk transaksi siap dicetak
                        </span>

                    </div>

                </div>


                <button
                    type="button"
                    class="receipt-close"
                    onclick="closeReceipt()"
                    aria-label="Tutup"
                >
                    ×
                </button>

            </div>



            <!-- =================================================
                 STRUK
            ================================================== -->

            <div
                class="receipt-paper"
                id="receiptPaper"
            >


                <!-- =================================================
                     IDENTITAS TOKO
                     LOGO DIHAPUS SESUAI PERMINTAAN
                ================================================== -->

                <div class="receipt-shop">

                    <h2>
                        PERTANIAN INDAH JAYA
                    </h2>


                    <p>
                        Jl.Gereja No.10, Jepon
                    </p>

                </div>



                <!-- =================================================
                     META TRANSAKSI
                ================================================== -->

                <div class="receipt-meta">

                    <div class="receipt-meta-row">

                        <span>
                            Invoice
                        </span>

                        <span>
                            <?= htmlspecialchars(
                                $last_sale["invoice"] ?? "-",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </span>

                    </div>


                    <div class="receipt-meta-row">

                        <span>
                            Tanggal
                        </span>

                        <span>
                            <?= htmlspecialchars(
                                date(
                                    "d/m/Y H:i",
                                    strtotime($receipt_created)
                                ),
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </span>

                    </div>


                    <div class="receipt-meta-row">

                        <span>
                            Kasir
                        </span>

                        <span>
                            <?= htmlspecialchars(
                                $last_sale["cashier"] ?? "Kasir",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </span>

                    </div>

                </div>



                <!-- =================================================
                     DAFTAR PRODUK
                ================================================== -->

                <div class="receipt-items">

                    <?php if (empty($receipt_items)): ?>

                        <div class="receipt-item">

                            <div class="receipt-item-name">
                                Tidak ada detail produk.
                            </div>

                        </div>

                    <?php else: ?>


                        <?php foreach (
                            $receipt_items
                            as $receipt_item
                        ): ?>

                            <div class="receipt-item">

                                <div class="receipt-item-name">

                                    <?= htmlspecialchars(
                                        $receipt_item["name"] ??
                                        "Produk",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </div>


                                <div class="receipt-item-detail">

                                    <span>

                                        <?= (int) (
                                            $receipt_item["qty"] ?? 0
                                        ) ?>

                                        ×

                                        <?= rupiah(
                                            $receipt_item["price"] ?? 0
                                        ) ?>

                                    </span>


                                    <span>

                                        <?= rupiah(
                                            $receipt_item["subtotal"] ?? 0
                                        ) ?>

                                    </span>

                                </div>

                            </div>

                        <?php endforeach; ?>


                    <?php endif; ?>

                </div>



                <!-- =================================================
                     RINGKASAN
                ================================================== -->

                <div class="receipt-summary">


                    <div class="receipt-summary-row">

                        <span>
                            Subtotal
                        </span>

                        <span>
                            <?= rupiah(
                                $receipt_subtotal
                            ) ?>
                        </span>

                    </div>


                    <?php if ($receipt_discount > 0): ?>

                        <div class="receipt-summary-row">

                            <span>
                                Diskon
                            </span>

                            <span>
                                -
                                <?= rupiah(
                                    $receipt_discount
                                ) ?>
                            </span>

                        </div>

                    <?php endif; ?>


                    <div class="receipt-summary-row total">

                        <span>
                            TOTAL
                        </span>

                        <span>
                            <?= rupiah(
                                $receipt_total
                            ) ?>
                        </span>

                    </div>



                    <!-- =================================================
                         DETAIL PEMBAYARAN
                    ================================================== -->

                    <div class="receipt-payment">

                        <div class="receipt-payment-title">
                            Detail Pembayaran
                        </div>


                        <div class="receipt-payment-row">

                            <span>
                                Metode
                            </span>

                            <strong>
                                <?= htmlspecialchars(
                                    $receipt_payment_label,
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>
                            </strong>

                        </div>


                        <?php if ($receipt_payment === "cash"): ?>

                            <div class="receipt-payment-row">

                                <span>
                                    Dibayar
                                </span>

                                <span>
                                    <?= rupiah(
                                        $receipt_paid
                                    ) ?>
                                </span>

                            </div>


                            <div class="receipt-payment-row">

                                <span>
                                    Kembalian
                                </span>

                                <strong>
                                    <?= rupiah(
                                        $receipt_change
                                    ) ?>
                                </strong>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>



                <!-- =================================================
                     FOOTER STRUK
                ================================================== -->

                <div class="receipt-footer">

                    <strong>
                        TERIMA KASIH
                    </strong>


                    <p>
                        Atas kepercayaan dan kunjungan Anda.
                    </p>


                    <p>
                        Simpan struk ini sebagai bukti transaksi.
                    </p>


                    <p>
                        Pertanian Indah Jaya
                    </p>

                </div>

            </div>



            <!-- =================================================
                 TOMBOL AKSI
            ================================================== -->

            <div class="receipt-modal-actions">

                <button
                    type="button"
                    class="receipt-btn secondary"
                    onclick="closeReceipt()"
                >
                    Tutup
                </button>


                <button
                    type="button"
                    class="receipt-btn primary"
                    onclick="printReceipt()"
                >
                    🖨 Cetak Struk
                </button>

            </div>

        </div>

    </div>


    <?php

    /*
     * Data struk hanya digunakan sekali setelah redirect.
     * Setelah halaman dimuat, data session dihapus.
     */

    unset(
        $_SESSION["last_sale"]
    );

    ?>

<?php endif; ?>



<script>

/* =========================================================
   STATE
========================================================= */

let cart = [];

let paymentMethod = "cash";

let submitting = false;


/*
 * Menandai bahwa modal konfirmasi sudah dilewati.
 * Form kemudian dikirim menggunakan submit() secara langsung.
 */

let checkoutConfirmed = false;



/* =========================================================
   FORMAT RUPIAH
========================================================= */

function formatRupiah(value)
{
    return "Rp " +
        Number(value || 0)
            .toLocaleString("id-ID");
}



/* =========================================================
   ESCAPE HTML
========================================================= */

function escapeHtml(value)
{
    return String(value)
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}



/* =========================================================
   CART TOTAL
========================================================= */

function getCartTotal()
{
    return cart.reduce(
        (sum, item) =>
            sum +
            (
                Number(item.price) *
                Number(item.qty)
            ),
        0
    );
}



/* =========================================================
   ADD PRODUCT
========================================================= */

function addProduct(product)
{
    product.id =
        Number(product.id);

    product.price =
        Number(product.price);

    product.stock =
        Number(product.stock);


    if (product.stock <= 0) {

        showToast(
            "warning",
            "Stok Habis",
            "Produk \"" +
            product.name +
            "\" sedang tidak tersedia."
        );

        return;
    }


    const existing =
        cart.find(
            item =>
                item.id ===
                product.id
        );


    if (existing) {

        if (
            existing.qty >=
            existing.stock
        ) {

            showToast(
                "warning",
                "Stok Maksimal",
                "Jumlah " +
                product.name +
                " sudah mencapai stok yang tersedia."
            );

            return;
        }


        existing.qty++;

    } else {

        cart.push({

            id:
                product.id,

            name:
                product.name,

            price:
                product.price,

            stock:
                product.stock,

            qty:
                1
        });
    }


    renderCart();
}



/* =========================================================
   CHANGE QTY
========================================================= */

function changeQty(
    id,
    difference
)
{
    const item =
        cart.find(
            product =>
                product.id ===
                Number(id)
        );


    if (!item) {
        return;
    }


    item.qty +=
        Number(difference);


    if (item.qty <= 0) {

        removeProduct(id);

        return;
    }


    if (
        item.qty >
        item.stock
    ) {

        item.qty =
            item.stock;


        showToast(
            "warning",
            "Stok Maksimal",
            "Jumlah produk tidak dapat melebihi stok."
        );
    }


    renderCart();
}



/* =========================================================
   REMOVE PRODUCT
========================================================= */

function removeProduct(id)
{
    const item =
        cart.find(
            product =>
                product.id ===
                Number(id)
        );


    cart =
        cart.filter(
            product =>
                product.id !==
                Number(id)
        );


    if (item) {

        showToast(
            "success",
            "Produk Dihapus",
            item.name +
            " dihapus dari keranjang."
        );
    }


    renderCart();
}



/* =========================================================
   RENDER CART
========================================================= */

function renderCart()
{
    const container =
        document.getElementById(
            "cart"
        );


    const cartInput =
        document.getElementById(
            "cartInput"
        );


    const totalElement =
        document.getElementById(
            "total"
        );


    if (!container) {
        return;
    }


    let total = 0;


    if (cart.length === 0) {

        container.innerHTML = `

            <div class="pos-empty">

                <div>

                    <strong>
                        Keranjang kosong
                    </strong>

                    Klik produk untuk menambahkannya.

                </div>

            </div>

        `;

    } else {

        container.innerHTML =
            cart.map(
                item => {

                    const subtotal =
                        Number(item.price) *
                        Number(item.qty);


                    total +=
                        subtotal;


                    return `

                        <div class="pos-cart-item">

                            <div>

                                <div class="pos-cart-name">
                                    ${escapeHtml(item.name)}
                                </div>

                                <span class="pos-cart-price">
                                    ${formatRupiah(item.price)}
                                    × ${item.qty}
                                </span>

                                <div class="pos-cart-controls">

                                    <button
                                        type="button"
                                        class="pos-qty-btn"
                                        onclick="changeQty(${item.id}, -1)"
                                    >
                                        −
                                    </button>

                                    <span class="pos-qty">
                                        ${item.qty}
                                    </span>

                                    <button
                                        type="button"
                                        class="pos-qty-btn"
                                        onclick="changeQty(${item.id}, 1)"
                                    >
                                        +
                                    </button>

                                    <button
                                        type="button"
                                        class="pos-remove"
                                        onclick="removeProduct(${item.id})"
                                    >
                                        Hapus
                                    </button>

                                </div>

                            </div>


                            <div class="pos-cart-subtotal">
                                ${formatRupiah(subtotal)}
                            </div>

                        </div>

                    `;
                }
            ).join("");
    }


    if (totalElement) {

        totalElement.innerText =
            formatRupiah(total);
    }


    if (cartInput) {

        cartInput.value =
            JSON.stringify(
                cart.map(
                    item => ({

                        id:
                            item.id,

                        qty:
                            item.qty
                    })
                )
            );
    }


    updateCheckoutButton();

    calculateChange();
}



/* =========================================================
   CHECKOUT BUTTON
========================================================= */

function updateCheckoutButton()
{
    const button =
        document.getElementById(
            "checkoutButton"
        );


    if (!button) {
        return;
    }


    button.disabled =
        cart.length === 0 ||
        submitting;
}



/* =========================================================
   PAYMENT METHOD
========================================================= */

function setPayment(method)
{
    paymentMethod =
        method;


    const paymentInput =
        document.getElementById(
            "paymentMethod"
        );


    if (paymentInput) {

        paymentInput.value =
            method;
    }


    document
        .querySelectorAll(
            ".payment-method"
        )
        .forEach(
            button => {

                button.classList.toggle(
                    "active",
                    button.dataset.method ===
                    method
                );
            }
        );


    const cashDetail =
        document.getElementById(
            "cashDetail"
        );


    if (!cashDetail) {
        return;
    }


    if (
        method === "cash"
    ) {

        cashDetail.style.display =
            "block";

    } else {

        cashDetail.style.display =
            "none";


        const paidInput =
            document.getElementById(
                "paid"
            );


        if (paidInput) {
            paidInput.value = "";
        }
    }


    calculateChange();
}



/* =========================================================
   CALCULATE CHANGE
========================================================= */

function calculateChange()
{
    const total =
        getCartTotal();


    const paid =
        Number(
            document.getElementById(
                "paid"
            )?.value || 0
        );


    const changeBox =
        document.getElementById(
            "changeBox"
        );


    const changeElement =
        document.getElementById(
            "change"
        );


    if (
        !changeElement ||
        !changeBox
    ) {
        return;
    }


    if (
        paymentMethod !==
        "cash"
    ) {

        changeElement.innerText =
            formatRupiah(0);

        changeBox.classList.remove(
            "insufficient"
        );

        return;
    }


    const difference =
        paid - total;


    if (
        difference < 0
    ) {

        changeElement.innerText =
            "Kurang " +
            formatRupiah(
                Math.abs(difference)
            );

        changeBox.classList.add(
            "insufficient"
        );

    } else {

        changeElement.innerText =
            formatRupiah(
                difference
            );

        changeBox.classList.remove(
            "insufficient"
        );
    }
}



/* =========================================================
   CHECKOUT VALIDATION
========================================================= */

function confirmCheckout()
{
    if (submitting) {
        return false;
    }


    const cartInput =
        document.getElementById(
            "cartInput"
        );


    if (cartInput) {

        cartInput.value =
            JSON.stringify(
                cart.map(
                    item => ({

                        id:
                            item.id,

                        qty:
                            item.qty
                    })
                )
            );
    }


    if (cart.length === 0) {

        showToast(
            "warning",
            "Keranjang Kosong",
            "Tambahkan produk terlebih dahulu."
        );

        return false;
    }


    const total =
        getCartTotal();


    if (total <= 0) {

        showToast(
            "error",
            "Transaksi Tidak Valid",
            "Total transaksi harus lebih dari Rp 0."
        );

        return false;
    }


    /*
     * Validasi pembayaran tunai
     */

    if (
        paymentMethod ===
        "cash"
    ) {

        const paid =
            Number(
                document.getElementById(
                    "paid"
                )?.value || 0
            );


        if (paid <= 0) {

            showToast(
                "warning",
                "Pembayaran Belum Diisi",
                "Masukkan nominal uang yang diterima."
            );


            const paidInput =
                document.getElementById(
                    "paid"
                );


            if (paidInput) {
                paidInput.focus();
            }


            return false;
        }


        if (paid < total) {

            showToast(
                "warning",
                "Pembayaran Kurang",
                "Uang yang diterima masih kurang " +
                formatRupiah(
                    total - paid
                ) +
                "."
            );


            const paidInput =
                document.getElementById(
                    "paid"
                );


            if (paidInput) {
                paidInput.focus();
            }


            return false;
        }
    }


    /*
     * Jika validasi sudah lolos,
     * buka modal konfirmasi custom.
     */

    if (!checkoutConfirmed) {

        openCheckoutConfirm();

        return false;
    }


    return true;
}



/* =========================================================
   OPEN CHECKOUT CONFIRMATION
========================================================= */

function openCheckoutConfirm()
{
    const modal =
        document.getElementById(
            "checkoutConfirmModal"
        );


    if (!modal) {
        return;
    }


    const total =
        getCartTotal();


    const methodLabel = {

        cash:
            "Tunai",

        debit:
            "Debit",

        transfer:
            "Transfer"
    };


    const confirmTotal =
        document.getElementById(
            "confirmTotal"
        );


    const confirmPayment =
        document.getElementById(
            "confirmPayment"
        );


    if (confirmTotal) {

        confirmTotal.innerText =
            formatRupiah(total);
    }


    if (confirmPayment) {

        confirmPayment.innerText =
            methodLabel[paymentMethod] ||
            "Tunai";
    }


    modal.classList.add(
        "show"
    );


    modal.setAttribute(
        "aria-hidden",
        "false"
    );


    document.body.style.overflow =
        "hidden";
}



/* =========================================================
   CLOSE CHECKOUT CONFIRMATION
========================================================= */

function closeCheckoutConfirm()
{
    const modal =
        document.getElementById(
            "checkoutConfirmModal"
        );


    if (!modal) {
        return;
    }


    modal.classList.remove(
        "show"
    );


    modal.setAttribute(
        "aria-hidden",
        "true"
    );


    /*
     * Jangan mengubah submitting di sini.
     * User masih boleh membuka modal lagi.
     */

    document.body.style.overflow =
        "";
}



/* =========================================================
   SUBMIT AFTER CONFIRMATION
========================================================= */

function submitConfirmedCheckout()
{
    if (submitting) {
        return;
    }


    /*
     * Tandai bahwa user sudah mengonfirmasi.
     */

    checkoutConfirmed = true;

    submitting = true;


    const button =
        document.getElementById(
            "checkoutButton"
        );


    const receipt =
        document.getElementById(
            "receipt"
        )?.checked;


    if (button) {

        button.disabled = true;

        button.classList.add(
            "processing"
        );


        button.innerText =
            receipt
                ? "Menyimpan & menyiapkan struk..."
                : "Menyimpan transaksi...";
    }


    closeCheckoutConfirm();


    /*
     * Submit langsung agar onsubmit tidak
     * memunculkan modal kedua kalinya.
     */

    const form =
        document.getElementById(
            "checkoutForm"
        );


    if (!form) {

        submitting = false;

        showToast(
            "error",
            "Form Tidak Ditemukan",
            "Form transaksi tidak dapat diproses."
        );

        return;
    }


    form.submit();
}



/* =========================================================
   SEARCH
========================================================= */

function filterProducts()
{
    const input =
        document.getElementById(
            "productSearch"
        );


    if (!input) {
        return;
    }


    const keyword =
        (
            input.value || ""
        )
        .toLowerCase()
        .trim();


    document
        .querySelectorAll(
            ".pos-product"
        )
        .forEach(
            card => {

                const text =
                    (
                        card.dataset.name +
                        " " +
                        card.dataset.category +
                        " " +
                        card.dataset.barcode
                    )
                    .toLowerCase();


                card.style.display =
                    text.includes(keyword)
                        ? ""
                        : "none";
            }
        );
}



/* =========================================================
   CLEAR SEARCH
========================================================= */

function clearProductSearch()
{
    const input =
        document.getElementById(
            "productSearch"
        );


    if (!input) {
        return;
    }


    input.value = "";

    filterProducts();

    input.focus();
}



/* =========================================================
   TOAST
========================================================= */

function showToast(
    type,
    title,
    message,
    invoice = "",
    total = 0,
    showReceiptButton = false
)
{
    const container =
        document.getElementById(
            "toastContainer"
        );


    if (!container) {
        return;
    }


    const toast =
        document.createElement(
            "div"
        );


    let icon = "✓";


    if (type === "error") {
        icon = "!";
    }


    if (type === "warning") {
        icon = "!";
    }


    toast.className =
        "pos-toast " +
        type;


    toast.innerHTML = `

        <div class="pos-toast-icon">
            ${icon}
        </div>

        <div class="pos-toast-content">

            <div class="pos-toast-title">
                ${escapeHtml(title)}
            </div>

            <div class="pos-toast-message">
                ${escapeHtml(message)}
            </div>

            ${
                invoice
                    ? `
                        <div class="pos-toast-invoice">
                            ${escapeHtml(invoice)}
                        </div>
                    `
                    : ""
            }

        </div>


        <button
            type="button"
            class="pos-toast-close"
            aria-label="Tutup"
        >
            ×
        </button>


        ${
            showReceiptButton
                ? `
                    <div class="pos-toast-action">

                        <strong class="pos-toast-total">
                            ${formatRupiah(total)}
                        </strong>

                        <button
                            type="button"
                            class="pos-toast-receipt"
                        >
                            Lihat Struk
                        </button>

                    </div>
                `
                : ""
        }


        <div class="pos-toast-progress"></div>

    `;


    container.appendChild(
        toast
    );


    const closeButton =
        toast.querySelector(
            ".pos-toast-close"
        );


    if (closeButton) {

        closeButton.addEventListener(
            "click",
            function()
            {
                removeToast(toast);
            }
        );
    }


    const receiptButton =
        toast.querySelector(
            ".pos-toast-receipt"
        );


    if (receiptButton) {

        receiptButton.addEventListener(
            "click",
            function()
            {
                openReceipt();

                removeToast(
                    toast
                );
            }
        );
    }


    const timer =
        setTimeout(
            function()
            {
                removeToast(
                    toast
                );
            },
            6000
        );


    toast.dataset.timer =
        String(timer);
}



/* =========================================================
   REMOVE TOAST
========================================================= */

function removeToast(toast)
{
    if (!toast) {
        return;
    }


    const timer =
        toast.dataset.timer;


    if (timer) {

        clearTimeout(
            Number(timer)
        );
    }


    if (
        toast.classList.contains(
            "hide"
        )
    ) {
        return;
    }


    toast.classList.add(
        "hide"
    );


    setTimeout(
        function()
        {
            toast.remove();
        },
        280
    );
}



/* =========================================================
   OPEN RECEIPT
========================================================= */

function openReceipt()
{
    const modal =
        document.getElementById(
            "receiptModal"
        );


    if (!modal) {
        return;
    }


    modal.classList.add(
        "show"
    );


    document.body.style.overflow =
        "hidden";
}



/* =========================================================
   CLOSE RECEIPT
========================================================= */

function closeReceipt()
{
    const modal =
        document.getElementById(
            "receiptModal"
        );


    if (!modal) {
        return;
    }


    modal.classList.remove(
        "show"
    );


    document.body.style.overflow =
        "";
}



/* =========================================================
   PRINT RECEIPT
========================================================= */

function printReceipt()
{
    window.print();
}



/* =========================================================
   KEYBOARD
========================================================= */

document.addEventListener(
    "keydown",
    function(event)
    {
        /*
         * F2 = fokus pencarian
         */

        if (
            event.key === "F2"
        ) {

            event.preventDefault();


            const search =
                document.getElementById(
                    "productSearch"
                );


            if (search) {

                search.focus();

                search.select();
            }
        }


        /*
         * ESC = tutup modal
         */

        if (
            event.key === "Escape"
        ) {

            /*
             * Prioritas pertama:
             * modal konfirmasi transaksi
             */

            const confirmModal =
                document.getElementById(
                    "checkoutConfirmModal"
                );


            if (
                confirmModal &&
                confirmModal.classList.contains(
                    "show"
                )
            ) {

                closeCheckoutConfirm();

                return;
            }


            /*
             * Prioritas kedua:
             * modal struk
             */

            const modal =
                document.getElementById(
                    "receiptModal"
                );


            if (
                modal &&
                modal.classList.contains(
                    "show"
                )
            ) {

                closeReceipt();

                return;
            }


            /*
             * Jika sedang fokus search,
             * kosongkan pencarian.
             */

            const search =
                document.getElementById(
                    "productSearch"
                );


            if (
                document.activeElement ===
                search
            ) {

                clearProductSearch();
            }
        }
    }
);



/* =========================================================
   CLICK OUTSIDE MODAL
========================================================= */

document.addEventListener(
    "click",
    function(event)
    {
        /*
         * Modal konfirmasi
         */

        const confirmModal =
            document.getElementById(
                "checkoutConfirmModal"
            );


        if (
            confirmModal &&
            event.target === confirmModal
        ) {

            closeCheckoutConfirm();

            return;
        }


        /*
         * Modal struk
         */

        const modal =
            document.getElementById(
                "receiptModal"
            );


        if (
            modal &&
            event.target === modal
        ) {

            closeReceipt();
        }
    }
);



/* =========================================================
   INIT
========================================================= */

document.addEventListener(
    "DOMContentLoaded",
    function()
    {
        /*
         * Render keranjang
         */

        renderCart();


        /*
         * Default pembayaran = tunai
         */

        setPayment("cash");


        /*
         * Pesan dari redirect
         */

        const successMessage =
            <?= json_encode(
                $message,
                JSON_UNESCAPED_UNICODE
            ) ?>;


        const invoice =
            <?= json_encode(
                $last_invoice,
                JSON_UNESCAPED_UNICODE
            ) ?>;


        const receiptExists =
            !!document.getElementById(
                "receiptModal"
            );


        /*
         * SUCCESS TOAST
         */

        <?php if ($message): ?>

            showToast(
                "success",
                "Transaksi Berhasil",
                "Pembayaran berhasil diproses dan transaksi telah tersimpan.",
                invoice,
                <?= json_encode(
                    $last_sale["total"] ?? 0
                ) ?>,
                receiptExists
            );

        <?php endif; ?>


        /*
         * ERROR TOAST
         */

        <?php if ($error): ?>

            showToast(
                "error",
                "Transaksi Gagal",
                <?= json_encode(
                    $error,
                    JSON_UNESCAPED_UNICODE
                ) ?>
            );

        <?php endif; ?>


        /*
         * Jika ada struk dari transaksi terakhir,
         * otomatis buka modal struk.
         */

        if (receiptExists) {

            openReceipt();
        }
    }
);

</script>


<?php

require __DIR__ . "/../includes/footer.php";

?>
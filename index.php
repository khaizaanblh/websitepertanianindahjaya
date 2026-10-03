<?php

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/auth.php";

require_login();

$role      = $_SESSION['user']['role'] ?? '';
$user_id   = (int) ($_SESSION['user']['id'] ?? 0);
$user_name = $_SESSION['user']['name'] ?? 'Kasir';

$base_url = get_base_url();

/* =========================================================
   CABANG AKTIF DARI SESSION LOGIN
=========================================================

   PENTING:
   Untuk Administrator, cabang yang dipilih pada halaman login
   adalah cabang kerja aktif. Jangan mengambil ulang branch_id
   dari users.branch_id karena itu dapat mengembalikan Admin ke
   cabang asal akun (misalnya Cabang Utama) walaupun saat login
   memilih Cabang 2.

   Untuk keamanan, branch_id dari session tetap divalidasi ke tabel
   branches dan statusnya harus active.
========================================================= */

$session_branch_id = (int) (
    $_SESSION['branch']['id']
    ?? $_SESSION['user']['branch_id']
    ?? 0
);

$branch_id   = 0;
$branch_name = '';
$branch_status = '';

if ($session_branch_id > 0) {

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

        $branch_stmt->bind_param("i", $session_branch_id);
        $branch_stmt->execute();

        $branch_row = $branch_stmt
            ->get_result()
            ->fetch_assoc();

        $branch_stmt->close();

        if ($branch_row) {
            $branch_id = (int) ($branch_row['id'] ?? 0);
            $branch_name = trim((string) ($branch_row['name'] ?? ''));
            $branch_status = (string) ($branch_row['status'] ?? '');
        }
    }
}

if (
    $branch_id <= 0 ||
    $branch_status !== 'active'
) {
    unset($_SESSION['branch']);

    redirect_pos(
        'error',
        'Cabang aktif tidak valid atau sudah tidak aktif. Silakan login kembali.'
    );
}

/*
 * Sinkronkan hanya konteks cabang yang sudah divalidasi.
 * Jangan mengambil branch_id Admin kembali dari users.branch_id.
 */
$_SESSION['branch']['id'] = $branch_id;
$_SESSION['branch']['name'] = $branch_name;
$_SESSION['user']['branch_id'] = $branch_id;
$_SESSION['user']['branch_name'] = $branch_name;


/* =========================================================
   HELPER
========================================================= */

function rupiah($number)
{
    return "Rp " . number_format(
        (float) $number,
        0,
        ',',
        '.'
    );
}


function esc($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function redirect_pos($type, $message)
{
    $url = strtok(
        $_SERVER['REQUEST_URI'],
        '?'
    );

    header(
        "Location: " .
        $url .
        "?" .
        urlencode($type) .
        "=" .
        urlencode($message)
    );

    exit;
}


class PosBusinessException extends RuntimeException
{
}


/* =========================================================
   PESAN
========================================================= */

$success_message = $_GET['success'] ?? '';
$error_message   = $_GET['error'] ?? '';


/* =========================================================
   AMBIL DATA STRUK TERAKHIR
=========================================================

   Data ini dibuat setelah transaksi berhasil.
   Setelah halaman membaca datanya, session langsung
   dihapus agar struk tidak muncul lagi ketika refresh.
========================================================= */

$last_sale = $_SESSION['last_sale'] ?? null;

if ($last_sale) {
    unset($_SESSION['last_sale']);
}


/* =========================================================
   PROSES TRANSAKSI
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'save_sale') {

        $cart_json      = $_POST['cart'] ?? '';
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $paid           = (float) ($_POST['paid'] ?? 0);
        $receipt        = isset($_POST['receipt']) ? 1 : 0;


        /* -------------------------------------------------
           VALIDASI DASAR
        ------------------------------------------------- */

        if ($user_id <= 0) {

            redirect_pos(
                'error',
                'Sesi pengguna tidak valid. Silakan login kembali.'
            );
        }


        if (!in_array(
            $payment_method,
            ['cash', 'debit', 'transfer'],
            true
        )) {

            redirect_pos(
                'error',
                'Metode pembayaran tidak valid.'
            );
        }


        $cart = json_decode(
            $cart_json,
            true
        );


        if (!is_array($cart) || empty($cart)) {

            redirect_pos(
                'error',
                'Keranjang transaksi masih kosong.'
            );
        }


        /* -------------------------------------------------
           BERSIHKAN CART
        ------------------------------------------------- */

        $clean_cart = [];

        foreach ($cart as $item) {

            $product_id = (int) (
                $item['product_id'] ?? 0
            );

            $qty = (int) (
                $item['qty'] ?? 0
            );


            if (
                $product_id <= 0 ||
                $qty <= 0
            ) {
                continue;
            }


            if (isset(
                $clean_cart[$product_id]
            )) {

                $clean_cart[$product_id] += $qty;

            } else {

                $clean_cart[$product_id] = $qty;
            }
        }


        if (empty($clean_cart)) {

            redirect_pos(
                'error',
                'Produk dalam keranjang tidak valid.'
            );
        }


        /* -------------------------------------------------
           TRANSACTION
        ------------------------------------------------- */

        $transaction_started = false;

        try {

            $conn->begin_transaction();

            $transaction_started = true;


            /* -------------------------------------------------
               CEK SHIFT KASIR
            ------------------------------------------------- */

            if ($role === 'kasir') {

                $stmt_shift = $conn->prepare("
                    SELECT id
                    FROM shifts
                    WHERE user_id = ?
                      AND status = 'open'
                    ORDER BY id DESC
                    LIMIT 1
                    FOR UPDATE
                ");


                if (!$stmt_shift) {

                    throw new PosBusinessException(
                        'Gagal memeriksa shift kasir.'
                    );
                }


                $stmt_shift->bind_param(
                    "i",
                    $user_id
                );


                $stmt_shift->execute();


                $shift = $stmt_shift
                    ->get_result()
                    ->fetch_assoc();


                $stmt_shift->close();


                if (!$shift) {

                    throw new PosBusinessException(
                        'Belum ada shift aktif. Buka shift terlebih dahulu sebelum melakukan transaksi.'
                    );
                }
            }


            /* -------------------------------------------------
               SIAPKAN DATA PRODUK
               LOCK STOCK
            ------------------------------------------------- */

            $product_stmt = $conn->prepare("
                SELECT
                    id,
                    name,
                    barcode,
                    price,
                    stock
                FROM products
                WHERE id = ?
                  AND branch_id = ?
                FOR UPDATE
            ");


            if (!$product_stmt) {

                throw new PosBusinessException(
                    'Gagal mempersiapkan pemeriksaan produk.'
                );
            }


            $items = [];

            $subtotal = 0;


            foreach (
                $clean_cart
                as $product_id => $qty
            ) {

                $product_stmt->bind_param(
                    "ii",
                    $product_id,
                    $branch_id
                );


                $product_stmt->execute();


                $product = $product_stmt
                    ->get_result()
                    ->fetch_assoc();


                if (!$product) {

                    throw new PosBusinessException(
                        'Produk dengan ID ' .
                        $product_id .
                        ' tidak ditemukan.'
                    );
                }


                $stock = (int) $product['stock'];

                $price = (float) $product['price'];


                if ($stock <= 0) {

                    throw new PosBusinessException(
                        'Stok produk "' .
                        $product['name'] .
                        '" sudah habis.'
                    );
                }


                if ($qty > $stock) {

                    throw new PosBusinessException(
                        'Stok "' .
                        $product['name'] .
                        '" tidak mencukupi. Stok tersedia: ' .
                        $stock .
                        '.'
                    );
                }


                $line_total =
                    $price *
                    $qty;


                $items[] = [

                    'product_id' =>
                        $product_id,

                    'name' =>
                        $product['name'],

                    'barcode' =>
                        $product['barcode'],

                    'qty' =>
                        $qty,

                    'price' =>
                        $price,

                    'subtotal' =>
                        $line_total,
                ];


                $subtotal +=
                    $line_total;
            }


            $product_stmt->close();


            if (empty($items)) {

                throw new PosBusinessException(
                    'Tidak ada produk valid dalam transaksi.'
                );
            }


            /* -------------------------------------------------
               DISCOUNT
            ------------------------------------------------- */

            $discount = 0;

            $total =
                $subtotal -
                $discount;


            if ($total < 0) {
                $total = 0;
            }


            /* -------------------------------------------------
               PEMBAYARAN
            ------------------------------------------------- */

            if (
                $payment_method === 'cash'
            ) {

                if ($paid < $total) {

                    throw new PosBusinessException(
                        'Uang pembayaran kurang. Total transaksi ' .
                        rupiah($total) .
                        '.'
                    );
                }


                $change_amount =
                    $paid -
                    $total;

            } else {

                $paid =
                    $total;

                $change_amount =
                    0;
            }


            /* -------------------------------------------------
               NOMOR INVOICE
            ------------------------------------------------- */

            $invoice = '';


            for (
                $attempt = 0;
                $attempt < 10;
                $attempt++
            ) {

                $invoice =
                    "TRX-" .
                    date("Ymd-His") .
                    "-" .
                    random_int(
                        100,
                        999
                    );


                $check_invoice =
                    $conn->prepare("
                        SELECT id
                        FROM sales
                        WHERE invoice = ?
                        LIMIT 1
                    ");


                if (!$check_invoice) {

                    throw new PosBusinessException(
                        'Gagal memeriksa nomor invoice.'
                    );
                }


                $check_invoice->bind_param(
                    "s",
                    $invoice
                );


                $check_invoice->execute();


                $exists =
                    $check_invoice
                        ->get_result()
                        ->fetch_assoc();


                $check_invoice->close();


                if (!$exists) {
                    break;
                }


                $invoice = '';
            }


            if ($invoice === '') {

                throw new PosBusinessException(
                    'Gagal membuat nomor invoice unik.'
                );
            }


            /* -------------------------------------------------
               WAKTU TRANSAKSI
            ------------------------------------------------- */

            $sale_created_at =
                date('Y-m-d H:i:s');


            /* -------------------------------------------------
               INSERT SALES
            ------------------------------------------------- */

            $sale_stmt = $conn->prepare("
                INSERT INTO sales (
                    invoice,
                    cashier_id,
                    branch_id,
                    total,
                    payment_method,
                    paid,
                    change_amount,
                    receipt
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");


            if (!$sale_stmt) {

                throw new PosBusinessException(
                    'Gagal mempersiapkan transaksi penjualan.'
                );
            }


            $sale_stmt->bind_param(
                "siidsddi",
                $invoice,
                $user_id,
                $branch_id,
                $total,
                $payment_method,
                $paid,
                $change_amount,
                $receipt
            );


            if (!$sale_stmt->execute()) {

                $sale_stmt->close();

                throw new PosBusinessException(
                    'Transaksi penjualan gagal disimpan.'
                );
            }


            $sale_id =
                $conn->insert_id;


            $sale_stmt->close();


            if ($sale_id <= 0) {

                throw new PosBusinessException(
                    'ID transaksi tidak berhasil dibuat.'
                );
            }


            /* -------------------------------------------------
               INSERT SALE ITEMS
            ------------------------------------------------- */

            $item_stmt = $conn->prepare("
                INSERT INTO sale_items (
                    sale_id,
                    product_id,
                    qty,
                    price
                )
                VALUES (?, ?, ?, ?)
            ");


            if (!$item_stmt) {

                throw new PosBusinessException(
                    'Gagal mempersiapkan detail transaksi.'
                );
            }


            /* -------------------------------------------------
               UPDATE STOCK
            ------------------------------------------------- */

            $stock_stmt = $conn->prepare("
                UPDATE products
                SET stock = stock - ?
                WHERE id = ?
                  AND branch_id = ?
                  AND stock >= ?
            ");


            if (!$stock_stmt) {

                $item_stmt->close();

                throw new PosBusinessException(
                    'Gagal mempersiapkan pengurangan stok.'
                );
            }


            foreach ($items as $item) {

                $item_sale_id =
                    $sale_id;

                $item_product =
                    (int) $item['product_id'];

                $item_qty =
                    (int) $item['qty'];

                $item_price =
                    (float) $item['price'];


                /* INSERT DETAIL */

                $item_stmt->bind_param(
                    "iiid",
                    $item_sale_id,
                    $item_product,
                    $item_qty,
                    $item_price
                );


                if (!$item_stmt->execute()) {

                    throw new PosBusinessException(
                        'Detail produk gagal disimpan.'
                    );
                }


                /* UPDATE STOCK */

                $stock_stmt->bind_param(
                    "iiii",
                    $item_qty,
                    $item_product,
                    $branch_id,
                    $item_qty
                );


                if (!$stock_stmt->execute()) {

                    throw new PosBusinessException(
                        'Stok produk gagal diperbarui.'
                    );
                }


                if (
                    $stock_stmt->affected_rows !== 1
                ) {

                    throw new PosBusinessException(
                        'Stok produk berubah atau tidak mencukupi. Transaksi dibatalkan.'
                    );
                }
            }


            $item_stmt->close();

            $stock_stmt->close();


            /* -------------------------------------------------
               COMMIT
            ------------------------------------------------- */

            $conn->commit();

            $transaction_started = false;


            /* -------------------------------------------------
               SIMPAN DATA STRUK
            -------------------------------------------------

               Hanya dibuat jika checkbox struk aktif.
               Data dibuat lengkap agar modal tidak lagi
               menghasilkan warning.
            ------------------------------------------------- */

            if ($receipt) {

                $_SESSION['last_sale'] = [

                    'sale_id' =>
                        $sale_id,

                    'invoice' =>
                        $invoice,

                    'cashier' =>
                        $user_name,

                    'created_at' =>
                        $sale_created_at,

                    'items' =>
                        $items,

                    'subtotal' =>
                        $subtotal,

                    'discount' =>
                        $discount,

                    'total' =>
                        $total,

                    'payment_method' =>
                        $payment_method,

                    'paid' =>
                        $paid,

                    'change_amount' =>
                        $change_amount,

                    'receipt' =>
                        $receipt,
                ];

            } else {

                unset(
                    $_SESSION['last_sale']
                );
            }


            redirect_pos(
                'success',
                'Transaksi ' .
                $invoice .
                ' berhasil disimpan. Total ' .
                rupiah($total) .
                '.'
            );

        }


        /* -----------------------------------------------------
           ERROR BISNIS
        ----------------------------------------------------- */

        catch (PosBusinessException $e) {

            if ($transaction_started) {
                $conn->rollback();
            }


            redirect_pos(
                'error',
                $e->getMessage()
            );
        }


        /* -----------------------------------------------------
           ERROR SISTEM
        ----------------------------------------------------- */

        catch (Throwable $e) {

            if ($transaction_started) {
                $conn->rollback();
            }


            error_log(
                "[POS SYSTEM ERROR] " .
                $e->getMessage() .
                " | File: " .
                $e->getFile() .
                " | Line: " .
                $e->getLine()
            );


            redirect_pos(
                'error',
                'Transaksi gagal diproses karena terjadi kesalahan sistem. Silakan coba lagi.'
            );
        }
    }
}


/* =========================================================
   PRODUK
========================================================= */

$products = [];

/*
 * PENTING: POS hanya mengambil produk milik cabang user yang login.
 * Jangan gunakan SELECT products tanpa filter branch_id karena produk
 * antar-cabang berada pada tabel yang sama.
 */
$product_list_stmt = $conn->prepare("
    SELECT
        id,
        name,
        image,
        barcode,
        category,
        price,
        stock,
        min_stock
    FROM products
    WHERE branch_id = ?
    ORDER BY
        CASE
            WHEN stock > 0 THEN 0
            ELSE 1
        END,
        name ASC
");

if ($product_list_stmt) {

    $product_list_stmt->bind_param("i", $branch_id);
    $product_list_stmt->execute();

    $result = $product_list_stmt->get_result();

    while (
        $row =
            $result->fetch_assoc()
    ) {

        $row['id'] =
            (int) $row['id'];

        $row['price'] =
            (float) $row['price'];

        $row['stock'] =
            (int) $row['stock'];

        $row['min_stock'] =
            (int) $row['min_stock'];


        $products[] =
            $row;
    }

    $product_list_stmt->close();
}


/* =========================================================
   DATA BARCODE
========================================================= */

$barcode_map = [];

foreach ($products as $product) {

    $barcode =
        trim(
            (string) (
                $product['barcode'] ?? ''
            )
        );


    if ($barcode === '') {
        continue;
    }


    $barcode_map[$barcode] = [

        'id' =>
            $product['id'],

        'name' =>
            $product['name'],

        'price' =>
            $product['price'],

        'stock' =>
            $product['stock'],

        'category' =>
            $product['category'],
    ];
}


/* =========================================================
   PRODUK TERSEDIA
========================================================= */

$available_products = 0;

foreach ($products as $product) {

    if (
        (int) $product['stock'] > 0
    ) {

        $available_products++;
    }
}


/* =========================================================
   SHIFT AKTIF
========================================================= */

$active_shift = null;

if ($role === 'kasir') {

    $stmt = $conn->prepare("
        SELECT
            id,
            opening_cash,
            start_time,
            status
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


        $active_shift =
            $stmt
                ->get_result()
                ->fetch_assoc();


        $stmt->close();
    }
}


/* =========================================================
   CATEGORY
========================================================= */

$categories = [];

foreach ($products as $product) {

    $category = trim(
        (string) $product['category']
    );


    if (
        $category !== '' &&
        !in_array(
            $category,
            $categories,
            true
        )
    ) {

        $categories[] =
            $category;
    }
}

sort($categories);


/* =========================================================
   HEADER
========================================================= */

require_once __DIR__ . "/includes/header.php";

?>

<style>

/* =========================================================
   POS
========================================================= */

.pos-page {
    max-width: 1500px;
    margin: 0 auto;
}

.pos-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 18px;
}

.pos-header h2 {
    margin: 0;
    color: #17221b;
    font-size: 25px;
    letter-spacing: -.045em;
}

.pos-header p {
    margin: 6px 0 0;
    color: #718078;
    font-size: 10px;
}

.pos-header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}


/* =========================================================
   ALERT
========================================================= */

.pos-alert {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
    padding: 12px 14px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 700;
}

.pos-alert-success {
    border: 1px solid #cfe5d4;
    background: #f1f9f2;
    color: #176f3d;
}

.pos-alert-error {
    border: 1px solid #efd0d0;
    background: #fff5f5;
    color: #b53e3e;
}


/* =========================================================
   SHIFT
========================================================= */

.pos-shift {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 15px;
    padding: 13px 15px;
    border: 1px solid #dce9de;
    border-radius: 13px;
    background: #f6faf6;
}

.pos-shift strong {
    display: block;
    color: #1b2c20;
    font-size: 11px;
}

.pos-shift span {
    display: block;
    margin-top: 3px;
    color: #77837b;
    font-size: 9px;
}


/* =========================================================
   MAIN
========================================================= */

.pos-layout {
    display: grid;
    grid-template-columns:
        minmax(0, 1.35fr)
        minmax(350px, .65fr);
    gap: 16px;
}

.pos-panel {
    min-width: 0;
    padding: 19px;
    border: 1px solid #dfe7e0;
    border-radius: 17px;
    background: #fff;
    box-shadow: 0 8px 25px rgba(23,60,36,.05);
}

.pos-panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}

.pos-panel-header h3 {
    margin: 0;
    color: #17221b;
    font-size: 14px;
}

.pos-panel-header p {
    margin: 4px 0 0;
    color: #77837b;
    font-size: 9px;
}


/* =========================================================
   BARCODE
========================================================= */

.barcode-box {
    padding: 12px;
    border: 1px solid #dce9de;
    border-radius: 13px;
    background: linear-gradient(
        135deg,
        #f2f8f2,
        #fafcf9
    );
    margin-bottom: 13px;
}

.barcode-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}

.barcode-title strong {
    color: #1b2b20;
    font-size: 10px;
}

.barcode-title span {
    color: #77837b;
    font-size: 8px;
}

.barcode-row {
    display: flex;
    gap: 8px;
}

.barcode-input {
    flex: 1;
    min-width: 0;
    height: 42px;
    padding: 0 13px;
    border: 1px solid #cfdcd1;
    border-radius: 10px;
    outline: none;
    background: #fff;
    color: #17221b;
    font-size: 13px;
    font-weight: 700;
}

.barcode-input:focus {
    border-color: #176f3d;
    box-shadow:
        0 0 0 3px rgba(23,111,61,.10);
}

.barcode-button {
    min-width: 110px;
}

.barcode-hint {
    margin-top: 7px;
    color: #7b867e;
    font-size: 8px;
    line-height: 1.5;
}

.barcode-status {
    min-height: 14px;
    margin-top: 7px;
    font-size: 9px;
    font-weight: 700;
}

.barcode-status.success {
    color: #176f3d;
}

.barcode-status.error {
    color: #c84b4b;
}


/* =========================================================
   SEARCH
========================================================= */

.pos-search {
    position: relative;
    margin-bottom: 13px;
}

.pos-search input {
    width: 100%;
    box-sizing: border-box;
    height: 39px;
    padding: 0 13px;
    border: 1px solid #dce5de;
    border-radius: 10px;
    outline: none;
    font-size: 10px;
    background: #fafcfa;
}

.pos-search input:focus {
    border-color: #176f3d;
    background: #fff;
}


/* =========================================================
   CATEGORY
========================================================= */

.pos-category {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    margin-bottom: 13px;
    padding-bottom: 2px;
}

.pos-category button {
    flex: 0 0 auto;
    padding: 7px 11px;
    border: 1px solid #dce5de;
    border-radius: 30px;
    background: #fff;
    color: #68756c;
    font-size: 8px;
    font-weight: 800;
    cursor: pointer;
}

.pos-category button.active {
    border-color: #176f3d;
    background: #176f3d;
    color: #fff;
}


/* =========================================================
   PRODUCT
========================================================= */

.pos-products {
    display: grid;
    grid-template-columns:
        repeat(4, minmax(0, 1fr));
    gap: 9px;
    max-height: 610px;
    overflow-y: auto;
    padding-right: 3px;
}

.pos-product {
    position: relative;
    min-width: 0;
    padding: 8px;
    border: 1px solid #e0e7e1;
    border-radius: 12px;
    background: #fff;
    text-align: left;
    cursor: pointer;
    transition: .16s ease;
}

.pos-product:hover {
    transform: translateY(-2px);
    border-color: #b9d0bd;
    box-shadow:
        0 8px 20px rgba(23,60,36,.07);
}

.pos-product.selected {
    border-color: #176f3d;
    box-shadow:
        0 0 0 2px rgba(23,111,61,.09);
}

.pos-product.disabled {
    opacity: .48;
    cursor: not-allowed;
}

.pos-product-image,
.pos-product-placeholder {
    width: 100%;
    height: 100px;
    object-fit: cover;
    display: grid;
    place-items: center;
    border-radius: 9px;
    margin-bottom: 8px;
    background: #f0f4f1;
}

.pos-product-placeholder {
    background:
        linear-gradient(
            135deg,
            #edf4ec,
            #f8eee5
        );
    color: #176f3d;
    font-size: 27px;
}

.pos-product-name {
    display: block;
    min-height: 28px;
    color: #1c2b21;
    font-size: 9px;
    font-weight: 850;
    line-height: 1.4;
}

.pos-product-category {
    display: block;
    margin-top: 3px;
    color: #8a948d;
    font-size: 7px;
}

.pos-product-price {
    display: block;
    margin-top: 6px;
    color: #176f3d;
    font-size: 10px;
    font-weight: 900;
}

.pos-product-stock {
    display: block;
    margin-top: 3px;
    color: #77837b;
    font-size: 7px;
}

.pos-product-barcode {
    display: block;
    margin-top: 4px;
    color: #9aa39d;
    font-size: 7px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}


/* =========================================================
   CART
========================================================= */

.cart-empty {
    padding: 35px 15px;
    border: 1px dashed #d5dfd7;
    border-radius: 12px;
    background: #fafcfa;
    color: #7b867e;
    text-align: center;
    font-size: 10px;
    line-height: 1.7;
}

.cart-list {
    display: grid;
    gap: 8px;
    max-height: 390px;
    overflow-y: auto;
}

.cart-item {
    padding: 11px;
    border: 1px solid #e1e8e2;
    border-radius: 11px;
    background: #fafcfa;
}

.cart-item-main {
    display: flex;
    justify-content: space-between;
    gap: 10px;
}

.cart-item-name {
    display: block;
    color: #1c2b21;
    font-size: 10px;
    font-weight: 850;
}

.cart-item-code {
    display: block;
    margin-top: 3px;
    color: #89938c;
    font-size: 7px;
}

.cart-item-subtotal {
    white-space: nowrap;
    color: #176f3d;
    font-size: 10px;
    font-weight: 900;
}

.cart-item-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 8px;
}

.cart-item-price {
    color: #7c877f;
    font-size: 8px;
}

.qty-control {
    display: flex;
    align-items: center;
    gap: 6px;
}

.qty-control button {
    width: 27px;
    height: 27px;
    border: 1px solid #d5dfd7;
    border-radius: 7px;
    background: #fff;
    color: #1c2b21;
    cursor: pointer;
    font-weight: 900;
}

.qty-control button:hover {
    border-color: #176f3d;
}

.qty-control strong {
    min-width: 20px;
    text-align: center;
    color: #1c2b21;
    font-size: 10px;
}

.cart-remove {
    border: 0 !important;
    color: #c84b4b !important;
}


/* =========================================================
   TOTAL
========================================================= */

.pos-total {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    margin-top: 13px;
    padding: 15px;
    border: 1px solid #dce9de;
    border-radius: 12px;
    background: #f5faf5;
}

.pos-total span {
    color: #526057;
    font-size: 10px;
    font-weight: 800;
}

.pos-total strong {
    color: #176f3d;
    font-size: 20px;
    font-weight: 900;
}


/* =========================================================
   PAYMENT
========================================================= */

.payment-section {
    margin-top: 15px;
}

.payment-section label {
    display: block;
    margin-bottom: 7px;
    color: #536058;
    font-size: 9px;
    font-weight: 800;
}

.payment-methods {
    display: grid;
    grid-template-columns:
        repeat(3, minmax(0, 1fr));
    gap: 7px;
}

.payment-method {
    padding: 10px 5px;
    border: 1px solid #dce5de;
    border-radius: 9px;
    background: #fff;
    color: #68756c;
    font-size: 9px;
    font-weight: 800;
    cursor: pointer;
}

.payment-method.active {
    border-color: #176f3d;
    background: #176f3d;
    color: #fff;
}

.payment-input {
    width: 100%;
    box-sizing: border-box;
    height: 40px;
    margin-top: 9px;
    padding: 0 11px;
    border: 1px solid #dce5de;
    border-radius: 9px;
    outline: none;
    font-size: 11px;
}

.payment-input:focus {
    border-color: #176f3d;
}

.change-box {
    margin-top: 8px;
    padding: 10px;
    border-radius: 9px;
    background: #f2f8f2;
    color: #176f3d;
    font-size: 9px;
}

.transfer-box {
    margin-top: 9px;
    padding: 11px;
    border: 1px solid #dce5de;
    border-radius: 10px;
    background: #fafcfa;
    color: #536058;
    font-size: 9px;
    line-height: 1.7;
}


/* =========================================================
   RECEIPT CHECKBOX
========================================================= */

.receipt-option {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
    padding: 10px;
    border: 1px solid #e1e8e2;
    border-radius: 9px;
    background: #fafcfa;
    color: #5e6a62;
    font-size: 9px;
    cursor: pointer;
}


/* =========================================================
   CHECKOUT
========================================================= */

.checkout-button {
    width: 100%;
    min-height: 45px;
    margin-top: 12px;
    border: 0;
    border-radius: 10px;
    background: #176f3d;
    color: #fff;
    font-size: 10px;
    font-weight: 900;
    cursor: pointer;
}

.checkout-button:hover {
    background: #125e33;
}

.checkout-button:disabled {
    opacity: .6;
    cursor: wait;
}



/* =========================================================
   POS SYSTEM MODALS
========================================================= */
.pos-system-modal { position: fixed; inset: 0; z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px; background: rgba(17,28,21,.58); backdrop-filter: blur(5px); }
.pos-system-dialog { width: min(500px,100%); max-height: 90vh; overflow: auto; padding: 24px; border: 1px solid #dfe8e1; border-radius: 20px; background: #fff; box-shadow: 0 25px 70px rgba(0,0,0,.22); }
.pos-system-icon { width:46px; height:46px; display:grid; place-items:center; margin-bottom:14px; border-radius:14px; background:#edf7ef; color:#176f3d; font-size:21px; font-weight:900; }
.pos-system-modal.warning .pos-system-icon { background:#fff6df; color:#a36a00; }
.pos-system-modal.danger .pos-system-icon { background:#fff0f0; color:#b53333; }
.pos-system-dialog h3 { margin:0; color:#17221b; font-size:18px; letter-spacing:-.02em; }
.pos-system-dialog p { margin:7px 0 0; color:#66736b; font-size:11px; line-height:1.55; }
.pos-confirm-list { display:grid; gap:8px; margin:18px 0; padding:13px; border:1px solid #e2e9e3; border-radius:13px; background:#f8fbf8; }
.pos-confirm-item { display:flex; align-items:center; justify-content:space-between; gap:12px; color:#445149; font-size:11px; }
.pos-confirm-item strong { color:#17221b; text-align:right; }
.pos-confirm-total { margin-top:5px; padding-top:10px; border-top:1px dashed #cfdad1; font-size:13px; }
.pos-confirm-total strong { color:#176f3d; font-size:17px; }
.pos-system-actions { display:grid; grid-template-columns:1fr 1fr; gap:9px; margin-top:18px; }
.pos-system-actions button { min-height:43px; border-radius:11px; font-weight:800; cursor:pointer; }
.pos-system-cancel { border:1px solid #d8e1da; background:#fff; color:#56635b; }
.pos-system-confirm { border:1px solid #176f3d; background:#176f3d; color:#fff; }
.pos-system-confirm:hover { background:#125b31; }
@media (max-width:650px) { .pos-system-modal{padding:12px;} .pos-system-dialog{padding:19px;border-radius:16px;} .pos-system-actions{grid-template-columns:1fr;} }

/* =========================================================
   THERMAL RECEIPT MODAL
========================================================= */

.pos-receipt-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(17,28,21,.58);
    backdrop-filter: blur(4px);
}

.pos-receipt-box {
    width: min(460px, 100%);
    max-height: 92vh;
    overflow: auto;
    padding: 16px;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 25px 70px rgba(0,0,0,.25);
}

.pos-receipt-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 12px;
}

.pos-receipt-actions h3 {
    margin: 0;
    color: #17221b;
    font-size: 14px;
}

.pos-receipt-actions button {
    border: 0;
    background: transparent;
    color: #77837b;
    cursor: pointer;
    font-size: 9px;
    font-weight: 800;
}


/* =========================================================
   58MM RECEIPT
========================================================= */

.thermal-receipt {
    width: 58mm;
    max-width: 100%;
    box-sizing: border-box;
    margin: 0 auto;
    padding: 5mm;
    background: #fff;
    color: #000;
    font-family: Arial, Helvetica, sans-serif;
}

.thermal-head {
    text-align: center;
    padding-bottom: 3mm;
}

.thermal-head strong {
    display: block;
    font-size: 15px;
    letter-spacing: .04em;
}

.thermal-head span {
    display: block;
    margin-top: 1mm;
    font-size: 9px;
}

.thermal-meta {
    font-size: 9px;
}

.thermal-meta div {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    padding: 1.2mm 0;
}

.thermal-meta span {
    color: #444;
}

.thermal-meta b {
    text-align: right;
    font-weight: 700;
}

.thermal-divider {
    border-top: 1px dashed #000;
    margin: 2mm 0;
}

.thermal-items {
    width: 100%;
    border-collapse: collapse;
    font-size: 9px;
}

.thermal-items th,
.thermal-items td {
    padding: 1.4mm 0;
    border: 0;
    vertical-align: top;
}

.thermal-items th {
    border-bottom: 1px solid #000;
    font-weight: 700;
}

.thermal-items th:nth-child(2),
.thermal-items td:nth-child(2) {
    width: 10mm;
    text-align: center;
}

.thermal-items th:last-child,
.thermal-items td:last-child {
    text-align: right;
    white-space: nowrap;
}

.thermal-items td small {
    display: block;
    margin-top: 1px;
    color: #555;
    font-size: 7.5px;
}

.thermal-summary {
    font-size: 9px;
}

.thermal-summary div {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    padding: 1mm 0;
}

.thermal-total {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 1mm 0;
    font-size: 12px;
}

.thermal-total strong {
    font-size: 14px;
}

.thermal-payment {
    margin-top: 2mm;
    font-size: 9px;
}

.thermal-payment div {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    padding: 1mm 0;
}

.thermal-footer {
    margin-top: 2mm;
    padding-top: 3mm;
    border-top: 1px dashed #000;
    text-align: center;
    font-size: 8px;
    line-height: 1.5;
}

.thermal-footer b,
.thermal-footer span {
    display: block;
}

.thermal-footer b {
    margin-bottom: 1mm;
    font-size: 9px;
}

.pos-receipt-buttons {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

.pos-receipt-buttons button {
    flex: 1;
}


/* =========================================================
   PRINT 58MM
========================================================= */

@media print {

    @page {
        size: 58mm auto;
        margin: 0;
    }

    html,
    body {
        width: 58mm !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }

    body.printing-receipt * {
        visibility: hidden !important;
    }

    body.printing-receipt .pos-receipt-modal,
    body.printing-receipt .pos-receipt-modal * {
        visibility: visible !important;
    }

    body.printing-receipt .pos-receipt-modal {
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        width: 58mm !important;
        min-height: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }

    body.printing-receipt .pos-receipt-box {
        width: 58mm !important;
        max-width: 58mm !important;
        max-height: none !important;
        overflow: visible !important;
        margin: 0 !important;
        padding: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
    }

    body.printing-receipt .pos-receipt-actions,
    body.printing-receipt .pos-receipt-buttons {
        display: none !important;
    }

    body.printing-receipt .thermal-receipt {
        width: 58mm !important;
        max-width: 58mm !important;
        padding: 3mm !important;
        box-sizing: border-box !important;
    }
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1200px) {

    .pos-products {
        grid-template-columns:
            repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 950px) {

    .pos-layout {
        grid-template-columns: 1fr;
    }

    .pos-products {
        max-height: none;
    }
}

@media (max-width: 650px) {

    .pos-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .barcode-row {
        flex-direction: column;
    }

    .barcode-button {
        width: 100%;
    }

    .pos-products {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .payment-methods {
        grid-template-columns: 1fr;
    }

    .pos-shift {
        align-items: flex-start;
        flex-direction: column;
    }

    .pos-receipt-modal {
        padding: 8px;
    }

    .pos-receipt-box {
        max-height: 96vh;
        padding: 10px;
    }
}

</style>


<div class="pos-page">

    <!-- =====================================================
         ALERT
    ====================================================== -->

    <?php if ($success_message): ?>

        <div class="pos-alert pos-alert-success">

            âœ“

            <span>
                <?= esc($success_message) ?>
            </span>

        </div>

    <?php endif; ?>


    <?php if ($error_message): ?>

        <div class="pos-alert pos-alert-error">

            !

            <span>
                <?= esc($error_message) ?>
            </span>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         SHIFT
    ====================================================== -->

    <?php if ($role === 'kasir'): ?>

        <?php if ($active_shift): ?>

            <div class="pos-shift">

                <div>

                    <strong>
                        Shift kasir aktif
                    </strong>

                    <span>

                        Dimulai
                        <?= date(
                            "d/m/Y H:i",
                            strtotime(
                                $active_shift['start_time']
                            )
                        ) ?>

                        Â· Kas awal
                        <?= rupiah(
                            $active_shift['opening_cash']
                        ) ?>

                    </span>

                </div>

                <span class="badge badge-success">
                    AKTIF
                </span>

            </div>

        <?php else: ?>

            <div
                class="pos-alert pos-alert-error"
                style="justify-content:space-between;"
            >

                <span>
                    Shift belum aktif.
                    Transaksi tidak dapat diproses.
                </span>

                <a
                    href="<?= $base_url ?>/admin/shifts.php"
                    class="btn"
                    style="text-decoration:none;"
                >
                    Buka Shift
                </a>

            </div>

        <?php endif; ?>

    <?php endif; ?>


    <!-- =====================================================
         POS LAYOUT
    ====================================================== -->

    <div class="pos-layout">


        <!-- =================================================
             PRODUK
        ================================================== -->

        <div class="pos-panel">

            <div class="pos-panel-header">

                <div>

                    <h3>
                        Pilih Produk
                    </h3>

                    <p>
                        <?= number_format(
                            $available_products
                        ) ?>
                        produk tersedia.
                    </p>

                </div>

                <span class="badge">
                    <?= number_format(
                        count($products)
                    ) ?>
                    produk
                </span>

            </div>

            <!-- SEARCH -->

            <div class="pos-search">

                <input
                    type="search"
                    id="productSearch"
                    placeholder="Cari nama produk, kategori, atau barcode..."
                    autocomplete="off"
                >

            </div>


            <!-- CATEGORY -->

            <div class="pos-category">

                <button
                    type="button"
                    class="active"
                    data-category="all"
                >
                    Semua
                </button>

                <?php foreach (
                    $categories
                    as $category
                ): ?>

                    <button
                        type="button"
                        data-category="<?= esc($category) ?>"
                    >
                        <?= esc($category) ?>
                    </button>

                <?php endforeach; ?>

            </div>


            <!-- PRODUCT GRID -->

            <div
                class="pos-products"
                id="productGrid"
            >

                <?php foreach (
                    $products
                    as $product
                ): ?>

                    <?php

                    $stock =
                        (int) $product['stock'];

                    $barcode =
                        trim(
                            (string) (
                                $product['barcode'] ?? ''
                            )
                        );

                    $image =
                        trim(
                            (string) (
                                $product['image'] ?? ''
                            )
                        );

                    ?>

                    <button
                        type="button"
                        class="
                            pos-product
                            <?= $stock <= 0
                                ? 'disabled'
                                : ''
                            ?>
                        "
                        data-id="<?= (int) $product['id'] ?>"
                        data-name="<?= esc(
                            strtolower(
                                $product['name']
                            )
                        ) ?>"
                        data-category="<?= esc(
                            strtolower(
                                $product['category']
                            )
                        ) ?>"
                        data-barcode="<?= esc($barcode) ?>"
                        data-stock="<?= $stock ?>"
                        data-price="<?= esc($product['price']) ?>"
                        <?= $stock <= 0
                            ? 'disabled'
                            : ''
                        ?>
                    >

                        <?php if ($image !== ''): ?>

                            <img
                                src="<?= $base_url ?>/uploads/products/<?= rawurlencode($image) ?>"
                                class="pos-product-image"
                                alt="<?= esc($product['name']) ?>"
                                onerror="
                                    this.style.display='none';
                                    this.nextElementSibling.style.display='grid';
                                "
                            >

                            <div
                                class="pos-product-placeholder"
                                style="display:none;"
                            >
                                ðŸŒ±
                            </div>

                        <?php else: ?>

                            <div class="pos-product-placeholder">
                                ðŸŒ±
                            </div>

                        <?php endif; ?>


                        <span class="pos-product-name">
                            <?= esc($product['name']) ?>
                        </span>


                        <span class="pos-product-category">
                            <?= esc($product['category']) ?>
                        </span>


                        <span class="pos-product-price">
                            <?= rupiah($product['price']) ?>
                        </span>


                        <span class="pos-product-stock">

                            <?= $stock > 0
                                ? 'Stok ' . number_format($stock)
                                : 'STOK HABIS'
                            ?>

                        </span>


                        <span class="pos-product-barcode">

                            <?= $barcode !== ''
                                ? esc($barcode)
                                : 'Barcode belum diisi'
                            ?>

                        </span>

                    </button>

                <?php endforeach; ?>


                <?php if (empty($products)): ?>

                    <div class="cart-empty">
                        Belum ada produk yang terdaftar.
                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- =================================================
             CART
        ================================================== -->

        <div class="pos-panel">

            <div class="pos-panel-header">

                <div>

                    <h3>
                        Keranjang
                    </h3>

                    <p>
                        Periksa jumlah dan pembayaran.
                    </p>

                </div>

                <span
                    class="badge"
                    id="cartCount"
                >
                    0 item
                </span>

            </div>


            <div
                id="cartList"
                class="cart-list"
            >

                <div class="cart-empty">

                    Belum ada produk.

                    <br>

                    Scan barcode atau pilih produk
                    untuk memulai transaksi.

                </div>

            </div>


            <!-- TOTAL -->

            <div class="pos-total">

                <span>
                    Total Transaksi
                </span>

                <strong id="cartTotal">
                    Rp 0
                </strong>

            </div>


            <!-- PAYMENT -->

            <div class="payment-section">

                <label>
                    METODE PEMBAYARAN
                </label>


                <div class="payment-methods">

                    <button
                        type="button"
                        class="payment-method active"
                        data-payment="cash"
                    >
                        Tunai
                    </button>

                    <button
                        type="button"
                        class="payment-method"
                        data-payment="debit"
                    >
                        Debit
                    </button>

                    <button
                        type="button"
                        class="payment-method"
                        data-payment="transfer"
                    >
                        Transfer
                    </button>

                </div>


                <div id="paymentBox"></div>

            </div>


            <!-- RECEIPT -->

            <label class="receipt-option">

                <input
                    type="checkbox"
                    id="receiptInput"
                    checked
                >

                <span>
                    Tampilkan dan cetak struk transaksi.
                </span>

            </label>


            <!-- CHECKOUT -->

            <button
                type="button"
                id="checkoutButton"
                class="checkout-button"
            >
                Selesaikan Transaksi
            </button>


            <form
                method="POST"
                id="saleForm"
                style="display:none;"
            >

                <input
                    type="hidden"
                    name="action"
                    value="save_sale"
                >

                <input
                    type="hidden"
                    name="cart"
                    id="cartInput"
                >

                <input
                    type="hidden"
                    name="payment_method"
                    id="paymentMethodInput"
                    value="cash"
                >

                <input
                    type="hidden"
                    name="paid"
                    id="paidFormInput"
                    value="0"
                >

                <input
                    type="hidden"
                    name="receipt"
                    id="receiptFormInput"
                    value="1"
                >

            </form>

        </div>

    </div>

</div>


<?php if ($last_sale): ?>

<!-- =========================================================
     STRUK TRANSAKSI BERHASIL
========================================================= -->

<div
    class="pos-receipt-modal"
    id="saleModal"
>

    <div class="pos-receipt-box">

        <div class="pos-receipt-actions">

            <h3>
                Transaksi Berhasil
            </h3>

            <button
                type="button"
                onclick="closeReceiptModal()"
            >
                Tutup
            </button>

        </div>


        <div class="thermal-receipt">

            <!-- HEADER TOKO -->

            <div class="thermal-head">

                <strong>
                    PERTANIAN INDAH JAYA
                </strong>

                <span>
                    Toko Pertanian & Perlengkapan
                </span>

                <span>
                    Terima kasih telah berbelanja
                </span>

            </div>


            <!-- INFORMASI TRANSAKSI -->

            <div class="thermal-meta">

                <div>
                    <span>Invoice</span>

                    <b>
                        <?= esc(
                            $last_sale['invoice'] ?? '-'
                        ) ?>
                    </b>
                </div>


                <div>
                    <span>Tanggal</span>

                    <b>

                        <?php

                        $receipt_date =
                            $last_sale['created_at']
                            ?? date('Y-m-d H:i:s');

                        ?>

                        <?= esc(
                            date(
                                'd/m/Y H:i',
                                strtotime($receipt_date)
                            )
                        ) ?>

                    </b>

                </div>


                <div>
                    <span>Kasir</span>

                    <b>
                        <?= esc(
                            $last_sale['cashier']
                            ?? $user_name
                        ) ?>
                    </b>
                </div>

            </div>


            <div class="thermal-divider"></div>


            <!-- ITEM -->

            <table class="thermal-items">

                <thead>

                    <tr>

                        <th>
                            Barang
                        </th>

                        <th>
                            Qty
                        </th>

                        <th>
                            Subtotal
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php

                $receipt_items =
                    $last_sale['items']
                    ?? [];

                ?>

                <?php if (!empty($receipt_items)): ?>

                    <?php foreach (
                        $receipt_items
                        as $item
                    ): ?>

                        <tr>

                            <td>

                                <?= esc(
                                    $item['name'] ?? '-'
                                ) ?>

                                <small>
                                    <?= rupiah(
                                        $item['price'] ?? 0
                                    ) ?>
                                </small>

                            </td>


                            <td>

                                <?= (int) (
                                    $item['qty'] ?? 0
                                ) ?>

                            </td>


                            <td>

                                <?= rupiah(
                                    $item['subtotal'] ?? 0
                                ) ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="3">
                            Tidak ada detail barang.
                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>


            <div class="thermal-divider"></div>


            <!-- RINGKASAN -->

            <div class="thermal-summary">

                <div>

                    <span>
                        Subtotal
                    </span>

                    <strong>
                        <?= rupiah(
                            $last_sale['subtotal'] ?? 0
                        ) ?>
                    </strong>

                </div>


                <?php if (
                    (float) (
                        $last_sale['discount'] ?? 0
                    ) > 0
                ): ?>

                    <div>

                        <span>
                            Diskon
                        </span>

                        <strong>
                            -
                            <?= rupiah(
                                $last_sale['discount']
                            ) ?>
                        </strong>

                    </div>

                <?php endif; ?>

            </div>


            <div class="thermal-divider"></div>


            <!-- TOTAL -->

            <div class="thermal-total">

                <span>
                    TOTAL
                </span>

                <strong>
                    <?= rupiah(
                        $last_sale['total'] ?? 0
                    ) ?>
                </strong>

            </div>


            <!-- PEMBAYARAN -->

            <div class="thermal-payment">

                <div>

                    <span>
                        Pembayaran
                    </span>

                    <strong>

                        <?php

                        $payment_label = [
                            'cash' =>
                                'Tunai',

                            'debit' =>
                                'Debit',

                            'transfer' =>
                                'Transfer',
                        ];

                        echo esc(
                            $payment_label[
                                $last_sale['payment_method']
                                ?? 'cash'
                            ]
                            ?? 'Tunai'
                        );

                        ?>

                    </strong>

                </div>


                <div>

                    <span>
                        Dibayar
                    </span>

                    <strong>
                        <?= rupiah(
                            $last_sale['paid'] ?? 0
                        ) ?>
                    </strong>

                </div>


                <div>

                    <span>
                        Kembalian
                    </span>

                    <strong>
                        <?= rupiah(
                            $last_sale['change_amount']
                            ?? 0
                        ) ?>
                    </strong>

                </div>

            </div>


            <!-- FOOTER -->

            <div class="thermal-footer">

                <b>
                    PERTANIAN INDAH JAYA
                </b>

                <span>
                    Terima kasih atas kunjungan Anda.
                </span>

                <span>
                    Barang yang sudah dibeli tidak dapat
                    dikembalikan tanpa ketentuan toko.
                </span>

            </div>

        </div>


        <!-- BUTTON -->

        <div class="pos-receipt-buttons">

            <button
                type="button"
                class="btn btn-primary"
                onclick="printReceipt()"
            >
                Cetak Struk
            </button>


            <button
                type="button"
                class="btn"
                onclick="closeReceiptModal()"
            >
                Selesai
            </button>

        </div>

    </div>

</div>

<?php endif; ?>


<script>

const PRODUCT_DATA = <?= json_encode(
    $products,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
) ?>;


const BARCODE_MAP = <?= json_encode(
    $barcode_map,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
) ?>;


/* =========================================================
   STATE
========================================================= */

let cart = [];

let paymentMethod = 'cash';

let activeCategory = 'all';

let checkoutProcessing = false;


/* =========================================================
   FORMAT
========================================================= */

function rupiahJS(number)
{
    return 'Rp ' +
        Number(number || 0)
            .toLocaleString('id-ID');
}


/* =========================================================
   PRODUCT
========================================================= */

function getProduct(productId)
{
    return PRODUCT_DATA.find(
        product =>
            Number(product.id) ===
            Number(productId)
    );
}


/* =========================================================
   BARCODE
========================================================= */

function normalizeBarcode(value)
{
    return String(value || '')
        .trim();
}


function barcodeMessage(
    message,
    type = ''
)
{
    const element =
        document.getElementById(
            'barcodeStatus'
        );


    if (!element) {
        return;
    }


    element.className =
        'barcode-status ' +
        type;


    element.textContent =
        message;
}


/* =========================================================
   ADD PRODUCT
========================================================= */

function addProduct(
    productId,
    source = 'manual'
)
{
    const product =
        getProduct(productId);


    if (!product) {

        barcodeMessage(
            'Produk tidak ditemukan.',
            'error'
        );

        return false;
    }


    const stock =
        Number(product.stock);


    if (stock <= 0) {

        barcodeMessage(
            product.name +
            ' sedang habis.',
            'error'
        );

        return false;
    }


    const existing =
        cart.find(
            item =>
                Number(item.product_id) ===
                Number(product.id)
        );


    if (existing) {

        if (
            existing.qty >=
            stock
        ) {

            barcodeMessage(
                'Jumlah ' +
                product.name +
                ' sudah mencapai stok tersedia.',
                'error'
            );

            return false;
        }


        existing.qty++;

    } else {

        cart.push({

            product_id:
                Number(product.id),

            qty: 1

        });
    }


    renderCart();


    if (source === 'barcode') {

        barcodeMessage(
            'âœ“ ' +
            product.name +
            ' ditambahkan.',
            'success'
        );
    }


    return true;
}


/* =========================================================
   BARCODE SCAN
========================================================= */

function scanBarcode()
{
    const input =
        document.getElementById(
            'barcodeInput'
        );


    const barcode =
        normalizeBarcode(
            input.value
        );


    if (!barcode) {

        barcodeMessage(
            'Scan atau masukkan barcode terlebih dahulu.',
            'error'
        );

        input.focus();

        return;
    }


    const product =
        BARCODE_MAP[barcode];


    if (!product) {

        barcodeMessage(
            'Barcode "' +
            barcode +
            '" tidak ditemukan.',
            'error'
        );

        input.select();

        return;
    }


    const success =
        addProduct(
            product.id,
            'barcode'
        );


    if (success) {

        input.value = '';

        input.focus();
    }
}


/* =========================================================
   SEARCH
========================================================= */

function filterProducts()
{
    const search =
        document
            .getElementById(
                'productSearch'
            )
            .value
            .toLowerCase()
            .trim();


    document
        .querySelectorAll(
            '.pos-product'
        )
        .forEach(button => {

            const name =
                button.dataset.name ||
                '';

            const category =
                button.dataset.category ||
                '';

            const barcode =
                button.dataset.barcode ||
                '';


            const categoryMatch =
                activeCategory === 'all' ||
                category ===
                activeCategory.toLowerCase();


            const searchMatch =
                search === '' ||
                name.includes(search) ||
                category.includes(search) ||
                barcode.includes(search);


            button.style.display =
                categoryMatch &&
                searchMatch
                    ? ''
                    : 'none';
        });
}


document
    .getElementById(
        'productSearch'
    )
    .addEventListener(
        'input',
        filterProducts
    );


/* =========================================================
   CATEGORY
========================================================= */

document
    .querySelectorAll(
        '.pos-category button'
    )
    .forEach(button => {

        button.addEventListener(
            'click',
            function() {

                document
                    .querySelectorAll(
                        '.pos-category button'
                    )
                    .forEach(
                        item =>
                            item.classList.remove(
                                'active'
                            )
                    );


                this.classList.add(
                    'active'
                );


                activeCategory =
                    this.dataset.category ||
                    'all';


                filterProducts();
            }
        );
    });


/* =========================================================
   PRODUCT BUTTON
========================================================= */

document
    .querySelectorAll(
        '.pos-product:not([disabled])'
    )
    .forEach(button => {

        button.addEventListener(
            'click',
            function() {

                addProduct(
                    Number(
                        this.dataset.id
                    )
                );


                this.classList.add(
                    'selected'
                );


                setTimeout(
                    () =>
                        this.classList.remove(
                            'selected'
                        ),
                    300
                );
            }
        );
    });


/* =========================================================
   CART
========================================================= */

function renderCart()
{
    const cartList =
        document.getElementById(
            'cartList'
        );


    const cartCount =
        document.getElementById(
            'cartCount'
        );


    const cartTotal =
        document.getElementById(
            'cartTotal'
        );


    if (!cart.length) {

        cartList.innerHTML = `
            <div class="cart-empty">
                Belum ada produk.
                <br>
                Scan barcode atau pilih produk
                untuk memulai transaksi.
            </div>
        `;


        cartCount.textContent =
            '0 item';


        cartTotal.textContent =
            'Rp 0';


        updatePayment();

        return;
    }


    let total = 0;

    let totalQty = 0;


    let html =
        '';


    cart.forEach(
        (item, index) => {

            const product =
                getProduct(
                    item.product_id
                );


            if (!product) {
                return;
            }


            const qty =
                Number(item.qty);

            const price =
                Number(product.price);

            const subtotal =
                qty * price;


            total += subtotal;

            totalQty += qty;


            html += `

                <div class="cart-item">

                    <div class="cart-item-main">

                        <div>

                            <span class="cart-item-name">
                                ${escapeHtml(product.name)}
                            </span>

                            <span class="cart-item-code">
                                ${
                                    product.barcode
                                        ? 'Barcode: ' +
                                          escapeHtml(
                                              product.barcode
                                          )
                                        : 'Barcode belum diisi'
                                }
                            </span>

                        </div>

                        <span class="cart-item-subtotal">
                            ${rupiahJS(subtotal)}
                        </span>

                    </div>


                    <div class="cart-item-bottom">

                        <span class="cart-item-price">
                            ${rupiahJS(price)}
                            / item
                        </span>


                        <div class="qty-control">

                            <button
                                type="button"
                                onclick="changeQty(${index}, -1)"
                            >
                                âˆ’
                            </button>


                            <strong>
                                ${qty}
                            </strong>


                            <button
                                type="button"
                                onclick="changeQty(${index}, 1)"
                            >
                                +
                            </button>


                            <button
                                type="button"
                                class="cart-remove"
                                onclick="removeCart(${index})"
                                title="Hapus"
                            >
                                Ã—
                            </button>

                        </div>

                    </div>

                </div>

            `;
        }
    );


    cartList.innerHTML =
        html;


    cartCount.textContent =
        totalQty +
        ' item';


    cartTotal.textContent =
        rupiahJS(total);


    updatePayment();
}


/* =========================================================
   QTY
========================================================= */

function changeQty(
    index,
    amount
)
{
    if (!cart[index]) {
        return;
    }


    const product =
        getProduct(
            cart[index].product_id
        );


    if (!product) {
        return;
    }


    const newQty =
        cart[index].qty +
        amount;


    if (newQty <= 0) {

        cart.splice(
            index,
            1
        );

        renderCart();

        return;
    }


    if (
        newQty >
        Number(product.stock)
    ) {

        barcodeMessage(
            'Jumlah melebihi stok ' +
            product.name +
            '.',
            'error'
        );

        return;
    }


    cart[index].qty =
        newQty;


    renderCart();
}


function removeCart(index)
{
    cart.splice(
        index,
        1
    );

    renderCart();
}


/* =========================================================
   PAYMENT METHOD
========================================================= */

document
    .querySelectorAll(
        '.payment-method'
    )
    .forEach(button => {

        button.addEventListener(
            'click',
            function() {

                document
                    .querySelectorAll(
                        '.payment-method'
                    )
                    .forEach(
                        item =>
                            item.classList.remove(
                                'active'
                            )
                    );


                this.classList.add(
                    'active'
                );


                paymentMethod =
                    this.dataset.payment;


                document
                    .getElementById(
                        'paymentMethodInput'
                    )
                    .value =
                    paymentMethod;


                updatePayment();
            }
        );
    });


/* =========================================================
   TOTAL
========================================================= */

function getCartTotal()
{
    return cart.reduce(
        (total, item) => {

            const product =
                getProduct(
                    item.product_id
                );


            if (!product) {
                return total;
            }


            return total +
                (
                    Number(product.price) *
                    Number(item.qty)
                );

        },
        0
    );
}


/* =========================================================
   PAYMENT
========================================================= */

function updatePayment()
{
    const paymentBox =
        document.getElementById(
            'paymentBox'
        );


    const total =
        getCartTotal();


    if (
        paymentMethod === 'cash'
    ) {

        paymentBox.innerHTML = `

            <input
                type="number"
                id="paidInput"
                class="payment-input"
                min="0"
                step="1000"
                placeholder="Nominal uang pembeli"
            >

            <div
                id="changeBox"
                class="change-box"
            >
                Kembalian:
                <strong>
                    Rp 0
                </strong>
            </div>

        `;


        const paidInput =
            document.getElementById(
                'paidInput'
            );


        paidInput.addEventListener(
            'input',
            updateChange
        );


    } else if (
        paymentMethod === 'debit'
    ) {

        paymentBox.innerHTML = `

            <div class="transfer-box">

                ðŸ’³ <strong>Debit</strong>

                <br>

                Pastikan pembayaran telah
                berhasil di mesin EDC sebelum
                menyelesaikan transaksi.

            </div>

        `;

    } else {

        paymentBox.innerHTML = `

            <div class="transfer-box">

                ðŸ¦ <strong>Transfer</strong>

                <br>

                <strong>
                    Rekening Pertanian Indah Jaya
                </strong>

                <br>

                BRI â€¢ 1234-01-009876-53-2

                <br>

                a.n. Pertanian Indah Jaya

            </div>

        `;
    }
}


/* =========================================================
   CHANGE
========================================================= */

function updateChange()
{
    const input =
        document.getElementById(
            'paidInput'
        );


    const changeBox =
        document.getElementById(
            'changeBox'
        );


    if (
        !input ||
        !changeBox
    ) {
        return;
    }


    const paid =
        Number(
            input.value || 0
        );


    const total =
        getCartTotal();


    const change =
        Math.max(
            0,
            paid - total
        );


    changeBox.innerHTML =
        'Kembalian: <strong>' +
        rupiahJS(change) +
        '</strong>';
}


/* =========================================================
   SYSTEM MODAL
========================================================= */
function closeSystemModal(){ const modal=document.getElementById('posSystemModal'); if(modal) modal.remove(); }

function showSystemNotice(title,message,type='warning',focusId=''){
    closeSystemModal();
    const modal=document.createElement('div');
    modal.id='posSystemModal'; modal.className='pos-system-modal '+type;
    const icon=type==='danger'?'!':'âœ“';
    modal.innerHTML=`<div class="pos-system-dialog" role="dialog" aria-modal="true" aria-labelledby="posSystemTitle"><div class="pos-system-icon">${icon}</div><h3 id="posSystemTitle">${escapeHtml(title)}</h3><p>${escapeHtml(message).replace(/\n/g,'<br>')}</p><div class="pos-system-actions" style="grid-template-columns:1fr;"><button type="button" class="pos-system-confirm" id="posSystemOk">Mengerti</button></div></div>`;
    document.body.appendChild(modal);
    const ok=document.getElementById('posSystemOk'); ok.focus();
    ok.addEventListener('click',()=>{ closeSystemModal(); if(focusId) setTimeout(()=>document.getElementById(focusId)?.focus(),50); });
    modal.addEventListener('click',e=>{if(e.target===modal) closeSystemModal();});
}

function showOrderConfirmation(total,paid,receipt){
    closeSystemModal();
    const totalQty=cart.reduce((sum,item)=>sum+Number(item.qty||0),0);
    const change=Math.max(0,paid-total);
    const methodLabel=paymentMethod==='cash'?'Tunai':(paymentMethod==='debit'?'Debit':'Transfer');
    const modal=document.createElement('div'); modal.id='posSystemModal'; modal.className='pos-system-modal';
    modal.innerHTML=`<div class="pos-system-dialog" role="dialog" aria-modal="true" aria-labelledby="confirmOrderTitle"><div class="pos-system-icon">âœ“</div><h3 id="confirmOrderTitle">Konfirmasi Pesanan</h3><p>Periksa kembali detail transaksi sebelum pesanan diproses.</p><div class="pos-confirm-list"><div class="pos-confirm-item"><span>Jumlah barang</span><strong>${totalQty} item</strong></div><div class="pos-confirm-item"><span>Metode pembayaran</span><strong>${escapeHtml(methodLabel)}</strong></div>${paymentMethod==='cash'?`<div class="pos-confirm-item"><span>Uang dibayar</span><strong>${rupiahJS(paid)}</strong></div><div class="pos-confirm-item"><span>Kembalian</span><strong>${rupiahJS(change)}</strong></div>`:''}<div class="pos-confirm-item pos-confirm-total"><span>Total transaksi</span><strong>${rupiahJS(total)}</strong></div></div><p style="font-size:10px;">Setelah dikonfirmasi, transaksi akan disimpan dan stok akan otomatis dikurangi.</p><div class="pos-system-actions"><button type="button" class="pos-system-cancel" id="cancelOrderConfirm">Periksa Lagi</button><button type="button" class="pos-system-confirm" id="confirmOrderButton">Ya, Proses Transaksi</button></div></div>`;
    document.body.appendChild(modal);
    document.getElementById('cancelOrderConfirm').focus();
    document.getElementById('cancelOrderConfirm').addEventListener('click',closeSystemModal);
    document.getElementById('confirmOrderButton').addEventListener('click',()=>{
        if(checkoutProcessing) return;
        document.getElementById('cartInput').value=JSON.stringify(cart.map(item=>({product_id:Number(item.product_id),qty:Number(item.qty)})));
        document.getElementById('paidFormInput').value=paid;
        document.getElementById('receiptFormInput').value=receipt;
        document.getElementById('paymentMethodInput').value=paymentMethod;
        checkoutProcessing=true;
        const checkoutButton=document.getElementById('checkoutButton'); checkoutButton.disabled=true; checkoutButton.textContent='Menyimpan transaksi...';
        const confirmButton=document.getElementById('confirmOrderButton'); confirmButton.disabled=true; confirmButton.textContent='Memproses...';
        document.getElementById('saleForm').submit();
    });
    modal.addEventListener('click',e=>{if(e.target===modal) closeSystemModal();});
}

/* =========================================================
   CHECKOUT
========================================================= */
document.getElementById('checkoutButton').addEventListener('click',function(){
    if(checkoutProcessing) return;
    if(!cart.length){ showSystemNotice('Keranjang Kosong','Belum ada produk yang dipilih. Tambahkan produk terlebih dahulu sebelum menyelesaikan transaksi.','warning'); return; }
    const total=getCartTotal();
    if(total<=0){ showSystemNotice('Transaksi Tidak Valid','Total transaksi tidak valid. Periksa kembali produk dan jumlah barang di keranjang.','danger'); return; }
    let paid=0;
    if(paymentMethod==='cash'){
        const paidInput=document.getElementById('paidInput');
        paid=Number(paidInput?.value||0);
        if(paid<total){ showSystemNotice('Uang Pembayaran Kurang','Uang pembayaran belum mencukupi untuk menyelesaikan transaksi.\n\nTotal: '+rupiahJS(total)+'\nDibayar: '+rupiahJS(paid)+'\nKurang: '+rupiahJS(total-paid),'danger','paidInput'); return; }
    } else { paid=total; }
    const receipt=document.getElementById('receiptInput').checked?1:0;
    showOrderConfirmation(total,paid,receipt);
});


/* =========================================================
   BARCODE ENTER
========================================================= */

document
    .getElementById(
        'barcodeInput'
    )
    .addEventListener(
        'keydown',
        function(event) {

            if (
                event.key ===
                'Enter'
            ) {

                event.preventDefault();

                scanBarcode();
            }
        }
    );


/* =========================================================
   SCAN BUTTON
========================================================= */

document
    .getElementById(
        'scanButton'
    )
    .addEventListener(
        'click',
        function() {

            scanBarcode();
        }
    );


/* =========================================================
   ESCAPE HTML
========================================================= */

function escapeHtml(value)
{
    return String(value ?? '')
        .replace(
            /&/g,
            '&amp;'
        )
        .replace(
            /</g,
            '&lt;'
        )
        .replace(
            />/g,
            '&gt;'
        )
        .replace(
            /"/g,
            '&quot;'
        )
        .replace(
            /'/g,
            '&#039;'
        );
}


/* =========================================================
   RECEIPT MODAL
========================================================= */

function closeReceiptModal()
{
    const modal =
        document.getElementById(
            'saleModal'
        );


    if (!modal) {
        return;
    }


    modal.remove();
}


function printReceipt()
{
    const modal =
        document.getElementById(
            'saleModal'
        );


    if (!modal) {
        return;
    }


    document.body.classList.add(
        'printing-receipt'
    );


    window.print();
}


window.addEventListener(
    'afterprint',
    function() {

        document.body.classList.remove(
            'printing-receipt'
        );
    }
);


/* =========================================================
   CLICK OUTSIDE MODAL
========================================================= */

document.addEventListener(
    'click',
    function(event) {

        const modal =
            document.getElementById(
                'saleModal'
            );


        if (
            modal &&
            event.target === modal
        ) {

            closeReceiptModal();
        }
    }
);


/* =========================================================
   ESCAPE CLOSE RECEIPT
========================================================= */

document.addEventListener(
    'keydown',
    function(event) {

        if (
            event.key === 'Escape'
        ) {

            const modal =
                document.getElementById(
                    'saleModal'
                );


            if (modal) {
                closeReceiptModal();
            }
        }
    }
);


/* =========================================================
   AUTO FOCUS BARCODE
========================================================= */

window.addEventListener(
    'load',
    function() {

        const input =
            document.getElementById(
                'barcodeInput'
            );


        if (
            input &&
            window.innerWidth >= 800
        ) {

            input.focus();
        }
    }
);


/* =========================================================
   SHORTCUT F2
========================================================= */

document.addEventListener(
    'keydown',
    function(event) {

        if (
            event.key === 'F2'
        ) {

            event.preventDefault();


            const input =
                document.getElementById(
                    'barcodeInput'
                );


            input?.focus();
        }
    }
);


/* =========================================================
   INITIAL
========================================================= */

renderCart();


</script>


<?php

require_once __DIR__ . "/includes/footer.php";

?>

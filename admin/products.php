<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

require_admin();

$base_url = get_base_url();

$message = "";
$error   = "";


/* =========================================================
   CABANG AKTIF
========================================================= */

$branch_id = (int) (
    $_SESSION['branch']['id']
    ?? $_SESSION['user']['branch_id']
    ?? 0
);

$branch_name = trim(
    $_SESSION['branch']['name']
    ?? $_SESSION['user']['branch_name']
    ?? ''
);


/*
 * Pastikan cabang yang tersimpan di session benar-benar ada
 * dan masih aktif.
 */
if ($branch_id > 0) {

    $stmt = $conn->prepare("
        SELECT id, name, status
        FROM branches
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $branch_id
    );

    $stmt->execute();

    $active_branch = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if (!$active_branch) {

        $branch_id = 0;
        $branch_name = "";
        $error = "Cabang aktif tidak ditemukan.";

    } elseif ($active_branch['status'] !== 'active') {

        $branch_id = 0;
        $branch_name = "";
        $error = "Cabang yang sedang dipilih sudah tidak aktif.";

    } else {

        $branch_name = $active_branch['name'];
    }

} else {

    $error = "Cabang aktif belum dipilih. Silakan login kembali.";
}


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


function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}


/*
 * Redirect agar parameter pencarian/filter tetap dipertahankan.
 */
function redirect_products(
    $message = "",
    $error = "",
    $search = "",
    $stock_filter = ""
) {
    global $base_url;

    $params = [];

    if ($message !== "") {
        $params['success'] = $message;
    }

    if ($error !== "") {
        $params['error'] = $error;
    }

    if ($search !== "") {
        $params['search'] = $search;
    }

    if ($stock_filter !== "") {
        $params['stock'] = $stock_filter;
    }

    $url = $base_url . "/admin/products.php";

    if (!empty($params)) {
        $url .= "?" . http_build_query($params);
    }

    header("Location: " . $url);
    exit;
}


/*
 * Cari barcode yang sama HANYA di cabang aktif.
 */
function find_duplicate_barcode(
    $barcode,
    $exclude_id = 0
) {
    global $conn, $branch_id;

    if (
        $branch_id <= 0 ||
        $barcode === null ||
        trim($barcode) === ""
    ) {
        return null;
    }

    $barcode = trim($barcode);

    if ($exclude_id > 0) {

        $stmt = $conn->prepare("
            SELECT
                id,
                name
            FROM products
            WHERE barcode = ?
              AND branch_id = ?
              AND id != ?
            LIMIT 1
        ");

        $stmt->bind_param(
            "sii",
            $barcode,
            $branch_id,
            $exclude_id
        );

    } else {

        $stmt = $conn->prepare("
            SELECT
                id,
                name
            FROM products
            WHERE barcode = ?
              AND branch_id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            "si",
            $barcode,
            $branch_id
        );
    }

    $stmt->execute();

    $result = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return $result ?: null;
}


/*
 * Upload gambar produk.
 */
function upload_product_image(
    $field_name,
    &$error_message
) {
    $error_message = "";

    if (
        !isset($_FILES[$field_name]) ||
        $_FILES[$field_name]['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if (
        $_FILES[$field_name]['error'] !== UPLOAD_ERR_OK
    ) {
        $error_message = "Upload gambar gagal.";
        return null;
    }

    if (
        $_FILES[$field_name]['size'] >
        2 * 1024 * 1024
    ) {
        $error_message =
            "Ukuran gambar maksimal 2 MB.";

        return null;
    }

    $tmp_file =
        $_FILES[$field_name]['tmp_name'];

    $image_info = @getimagesize($tmp_file);

    if ($image_info === false) {

        $error_message =
            "File yang diupload bukan gambar yang valid.";

        return null;
    }

    $mime = $image_info['mime'] ?? "";

    $allowed = [
        "image/jpeg",
        "image/png",
        "image/webp"
    ];

    if (!in_array($mime, $allowed, true)) {

        $error_message =
            "Format gambar harus JPG, PNG, atau WEBP.";

        return null;
    }

    $extension_map = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp"
    ];

    $extension =
        $extension_map[$mime] ?? "jpg";

    $upload_dir =
        __DIR__ . "/../uploads/products/";

    if (!is_dir($upload_dir)) {

        if (!mkdir($upload_dir, 0777, true)) {

            $error_message =
                "Folder upload gambar tidak dapat dibuat.";

            return null;
        }
    }

    $image_name =
        bin2hex(random_bytes(16))
        . "."
        . $extension;

    $destination =
        $upload_dir . $image_name;

    if (
        !move_uploaded_file(
            $tmp_file,
            $destination
        )
    ) {

        $error_message =
            "Gambar gagal disimpan.";

        return null;
    }

    return $image_name;
}


/*
 * Hapus file gambar produk.
 */
function delete_product_image($filename)
{
    if (
        !$filename ||
        !is_string($filename)
    ) {
        return;
    }

    $filename = basename($filename);

    $path =
        __DIR__
        . "/../uploads/products/"
        . $filename;

    if (is_file($path)) {
        @unlink($path);
    }
}


/* =========================================================
   VALIDASI CABANG
========================================================= */

if ($branch_id <= 0) {

    /*
     * Jangan izinkan proses perubahan data jika cabang
     * tidak valid.
     */
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? null
        : null;
}


/* =========================================================
   PESAN DARI REDIRECT
========================================================= */

if (isset($_GET['success'])) {
    $message = trim($_GET['success']);
}

if (isset($_GET['error'])) {
    $error = trim($_GET['error']);
}


/* =========================================================
   HAPUS PRODUK
========================================================= */

if (
    $branch_id > 0 &&
    isset($_GET['delete'])
) {

    $id = (int) $_GET['delete'];

    if ($id <= 0) {

        $error =
            "Produk yang dipilih tidak valid.";

    } else {

        /*
         * Ambil produk hanya dari cabang aktif.
         */
        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                image
            FROM products
            WHERE id = ?
              AND branch_id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            "ii",
            $id,
            $branch_id
        );

        $stmt->execute();

        $product = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if (!$product) {

            $error =
                "Produk tidak ditemukan pada cabang aktif.";

        } else {

            /*
             * Jangan hapus produk yang sudah digunakan
             * dalam transaksi.
             */
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM sale_items
                WHERE product_id = ?
            ");

            $stmt->bind_param(
                "i",
                $id
            );

            $stmt->execute();

            $used = $stmt
                ->get_result()
                ->fetch_assoc();

            $stmt->close();

            if (
                (int) ($used['total'] ?? 0) > 0
            ) {

                $error =
                    "Produk \""
                    . $product['name']
                    . "\" tidak dapat dihapus karena sudah digunakan dalam transaksi.";

            } else {

                /*
                 * DELETE tetap dikunci branch_id.
                 */
                $stmt = $conn->prepare("
                    DELETE FROM products
                    WHERE id = ?
                      AND branch_id = ?
                ");

                $stmt->bind_param(
                    "ii",
                    $id,
                    $branch_id
                );

                if ($stmt->execute()) {

                    if (
                        $stmt->affected_rows === 1
                    ) {

                        delete_product_image(
                            $product['image'] ?? null
                        );

                        $message =
                            "Produk berhasil dihapus.";

                    } else {

                        $error =
                            "Produk tidak ditemukan pada cabang aktif.";
                    }

                } else {

                    $error =
                        "Produk tidak dapat dihapus.";
                }

                $stmt->close();
            }
        }
    }
}


/* =========================================================
   TAMBAH STOK
========================================================= */

if (
    $branch_id > 0 &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'add_stock'
) {

    $product_id =
        (int) ($_POST['product_id'] ?? 0);

    $quantity =
        (int) ($_POST['quantity'] ?? 0);

    if (
        $product_id <= 0 ||
        $quantity <= 0
    ) {

        $error =
            "Jumlah stok harus lebih dari 0.";

    } else {

        /*
         * Pastikan produk benar-benar milik cabang aktif.
         */
        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                stock
            FROM products
            WHERE id = ?
              AND branch_id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            "ii",
            $product_id,
            $branch_id
        );

        $stmt->execute();

        $product = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if (!$product) {

            $error =
                "Produk tidak ditemukan pada cabang aktif.";

        } else {

            /*
             * Update stok juga menggunakan branch_id.
             */
            $stmt = $conn->prepare("
                UPDATE products
                SET stock = stock + ?
                WHERE id = ?
                  AND branch_id = ?
            ");

            $stmt->bind_param(
                "iii",
                $quantity,
                $product_id,
                $branch_id
            );

            if ($stmt->execute()) {

                if (
                    $stmt->affected_rows === 1
                ) {

                    $message =
                        "Stok produk \""
                        . $product['name']
                        . "\" berhasil ditambahkan.";

                } else {

                    $error =
                        "Stok tidak dapat diperbarui.";
                }

            } else {

                $error =
                    "Gagal menambahkan stok.";
            }

            $stmt->close();
        }
    }
}


/* =========================================================
   TAMBAH PRODUK
========================================================= */

if (
    $branch_id > 0 &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'add_product'
) {

    $name =
        trim($_POST['name'] ?? '');

    $category =
        trim($_POST['category'] ?? '');

    $price =
        (float) ($_POST['price'] ?? 0);

    $stock =
        (int) ($_POST['stock'] ?? 0);

    $min_stock =
        (int) ($_POST['min_stock'] ?? 5);

    $barcode =
        trim($_POST['barcode'] ?? '');

    // Barcode kosong harus menjadi NULL agar UNIQUE index
    // tidak menolak lebih dari satu produk tanpa barcode.
    if ($barcode === '') {
        $barcode = null;
    }

    if ($name === '') {

        $error =
            "Nama produk wajib diisi.";

    } elseif ($category === '') {

        $error =
            "Kategori wajib diisi.";

    } elseif ($price <= 0) {

        $error =
            "Harga harus lebih dari 0.";

    } elseif ($stock < 0) {

        $error =
            "Stok tidak boleh negatif.";

    } elseif ($min_stock < 0) {

        $error =
            "Minimum stok tidak boleh negatif.";

    } else {

        /*
         * Nama produk hanya dianggap duplikat
         * DI CABANG AKTIF.
         *
         * Jadi:
         * Cabang 1 = Pupuk Urea
         * Cabang 2 = Pupuk Urea
         * diperbolehkan.
         */
        $check = $conn->prepare("
            SELECT
                id,
                name
            FROM products
            WHERE LOWER(name) = LOWER(?)
              AND branch_id = ?
            LIMIT 1
        ");

        $check->bind_param(
            "si",
            $name,
            $branch_id
        );

        $check->execute();

        $existing = $check
            ->get_result()
            ->fetch_assoc();

        $check->close();

        if ($existing) {

            $error =
                "Produk \""
                . $existing['name']
                . "\" sudah tersedia di cabang "
                . $branch_name
                . ". Gunakan menu tambah stok.";

        } else {

            /*
             * Barcode hanya unik dalam cabang aktif.
             */
            if ($barcode !== '') {

                $duplicate_barcode =
                    find_duplicate_barcode(
                        $barcode
                    );

                if ($duplicate_barcode) {

                    $error =
                        "Barcode \""
                        . $barcode
                        . "\" sudah digunakan oleh produk \""
                        . $duplicate_barcode['name']
                        . "\" di cabang "
                        . $branch_name
                        . ".";
                }
            }


            /*
             * Upload gambar.
             */
            $image_name = null;

            if ($error === "") {

                $upload_error = "";

                $image_name =
                    upload_product_image(
                        'image',
                        $upload_error
                    );

                if ($upload_error !== "") {
                    $error = $upload_error;
                }
            }


            /*
             * Simpan produk.
             */
            if ($error === "") {

                $stmt = $conn->prepare("
                    INSERT INTO products
                    (
                        name,
                        image,
                        barcode,
                        category,
                        price,
                        stock,
                        min_stock,
                        branch_id
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmt) {

                    $error =
                        "Gagal mempersiapkan penyimpanan produk.";

                    if ($image_name) {
                        delete_product_image($image_name);
                    }

                } else {

                    $stmt->bind_param(
                        "ssssdiii",
                        $name,
                        $image_name,
                        $barcode,
                        $category,
                        $price,
                        $stock,
                        $min_stock,
                        $branch_id
                    );

                    if ($stmt->execute()) {

                        $message =
                            "Produk berhasil ditambahkan ke cabang "
                            . $branch_name
                            . ".";

                    } else {

                        /*
                         * Jika gagal insert, hapus gambar
                         * yang sudah terupload.
                         */
                        if ($image_name) {
                            delete_product_image(
                                $image_name
                            );
                        }

                        if (
                            $stmt->errno === 1062
                        ) {

                            $error =
                                "Barcode sudah digunakan pada cabang ini.";

                        } else {

                            $error =
                                "Gagal menyimpan produk.";
                        }
                    }

                    $stmt->close();
                }
            }
        }
    }
}


/* =========================================================
   EDIT PRODUK
========================================================= */

if (
    $branch_id > 0 &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'edit_product'
) {

    $id =
        (int) ($_POST['id'] ?? 0);

    $name =
        trim($_POST['name'] ?? '');

    $category =
        trim($_POST['category'] ?? '');

    $price =
        (float) ($_POST['price'] ?? 0);

    $min_stock =
        (int) ($_POST['min_stock'] ?? 5);

    $barcode =
        trim($_POST['barcode'] ?? '');

    // Barcode kosong harus menjadi NULL agar UNIQUE index
    // tidak menolak lebih dari satu produk tanpa barcode.
    if ($barcode === '') {
        $barcode = null;
    }

    $remove_image =
        isset($_POST['remove_image'])
        && $_POST['remove_image'] === '1';


    if ($id <= 0) {

        $error =
            "Produk tidak valid.";

    } elseif ($name === '') {

        $error =
            "Nama produk wajib diisi.";

    } elseif ($category === '') {

        $error =
            "Kategori wajib diisi.";

    } elseif ($price <= 0) {

        $error =
            "Harga harus lebih dari 0.";

    } elseif ($min_stock < 0) {

        $error =
            "Minimum stok tidak boleh negatif.";

    } else {

        /*
         * Ambil produk berdasarkan ID + branch_id.
         */
        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                image,
                barcode
            FROM products
            WHERE id = ?
              AND branch_id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            "ii",
            $id,
            $branch_id
        );

        $stmt->execute();

        $old_product = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();


        if (!$old_product) {

            $error =
                "Produk tidak ditemukan pada cabang aktif.";

        } else {

            /*
             * Cek nama produk hanya di cabang aktif.
             */
            $check = $conn->prepare("
                SELECT
                    id,
                    name
                FROM products
                WHERE LOWER(name) = LOWER(?)
                  AND branch_id = ?
                  AND id != ?
                LIMIT 1
            ");

            $check->bind_param(
                "sii",
                $name,
                $branch_id,
                $id
            );

            $check->execute();

            $duplicate_name = $check
                ->get_result()
                ->fetch_assoc();

            $check->close();


            if ($duplicate_name) {

                $error =
                    "Nama produk tersebut sudah digunakan "
                    . "di cabang "
                    . $branch_name
                    . ".";

            } else {

                /*
                 * Cek barcode hanya di cabang aktif.
                 */
                if ($barcode !== '') {

                    $duplicate_barcode =
                        find_duplicate_barcode(
                            $barcode,
                            $id
                        );

                    if ($duplicate_barcode) {

                        $error =
                            "Barcode \""
                            . $barcode
                            . "\" sudah digunakan oleh produk \""
                            . $duplicate_barcode['name']
                            . "\" di cabang ini.";
                    }
                }


                /*
                 * Upload gambar baru jika ada.
                 */
                $new_image = null;

                if ($error === "") {

                    $upload_error = "";

                    $new_image =
                        upload_product_image(
                            'edit_image',
                            $upload_error
                        );

                    if ($upload_error !== "") {
                        $error = $upload_error;
                    }
                }


                if ($error === "") {

                    /*
                     * Tentukan gambar yang disimpan.
                     */
                    $final_image =
                        $old_product['image'] ?? null;

                    if ($new_image !== null) {

                        $final_image = $new_image;

                    } elseif ($remove_image) {

                        $final_image = null;
                    }


                    /*
                     * Update wajib menggunakan:
                     * id + branch_id
                     */
                    $stmt = $conn->prepare("
                        UPDATE products
                        SET
                            name = ?,
                            category = ?,
                            price = ?,
                            min_stock = ?,
                            barcode = ?,
                            image = ?
                        WHERE id = ?
                          AND branch_id = ?
                    ");

                    if (!$stmt) {

                        if ($new_image) {
                            delete_product_image(
                                $new_image
                            );
                        }

                        $error =
                            "Gagal mempersiapkan perubahan produk.";

                    } else {

                        $stmt->bind_param(
                            "ssdissii",
                            $name,
                            $category,
                            $price,
                            $min_stock,
                            $barcode,
                            $final_image,
                            $id,
                            $branch_id
                        );

                        if ($stmt->execute()) {

                            if (
                                $stmt->affected_rows >= 0
                            ) {

                                /*
                                 * Hapus gambar lama setelah
                                 * update berhasil.
                                 */
                                if (
                                    $new_image !== null &&
                                    !empty(
                                        $old_product['image']
                                    )
                                ) {

                                    delete_product_image(
                                        $old_product['image']
                                    );

                                } elseif (
                                    $remove_image &&
                                    !empty(
                                        $old_product['image']
                                    )
                                ) {

                                    delete_product_image(
                                        $old_product['image']
                                    );
                                }

                                $message =
                                    "Produk berhasil diperbarui.";

                            } else {

                                $error =
                                    "Produk tidak ditemukan pada cabang aktif.";
                            }

                        } else {

                            if ($new_image) {
                                delete_product_image(
                                    $new_image
                                );
                            }

                            if (
                                $stmt->errno === 1062
                            ) {

                                $error =
                                    "Barcode sudah digunakan pada cabang ini.";

                            } else {

                                $error =
                                    "Gagal memperbarui produk.";
                            }
                        }

                        $stmt->close();
                    }
                }
            }
        }
    }
}


/* =========================================================
   SEARCH & FILTER
========================================================= */

$search =
    trim($_GET['search'] ?? '');

$stock_filter =
    trim($_GET['stock'] ?? '');


/*
 * Produk SELALU diawali dengan branch_id.
 */
$where = [
    "branch_id = ?"
];

$params = [
    $branch_id
];

$types = "i";


/*
 * Search.
 */
if ($search !== '') {

    $where[] = "
        (
            name LIKE ?
            OR category LIKE ?
            OR barcode LIKE ?
        )
    ";

    $like =
        "%" . $search . "%";

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;

    $types .= "sss";
}


/*
 * Filter stok.
 */
switch ($stock_filter) {

    case 'low':

        $where[] =
            "stock > 0 AND stock <= min_stock";

        break;


    case 'out':

        $where[] =
            "stock <= 0";

        break;


    case 'safe':

        $where[] =
            "stock > min_stock + 5";

        break;


    case 'check':

        $where[] =
            "stock > min_stock
             AND stock <= min_stock + 5";

        break;
}


$where_sql =
    implode(
        " AND ",
        $where
    );


/* =========================================================
   DATA PRODUK
========================================================= */

$products = [];


$sql = "
    SELECT
        id,
        name,
        image,
        barcode,
        category,
        price,
        stock,
        min_stock,
        created_at
    FROM products
    WHERE {$where_sql}
    ORDER BY name ASC
";


$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param(
        $types,
        ...$params
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while (
        $row = $result->fetch_assoc()
    ) {

        $row['id'] =
            (int) $row['id'];

        $row['price'] =
            (float) $row['price'];

        $row['stock'] =
            (int) $row['stock'];

        $row['min_stock'] =
            (int) $row['min_stock'];

        $products[] = $row;
    }

    $stmt->close();
}


/* =========================================================
   STATISTIK STOK CABANG AKTIF
========================================================= */

$total_products = 0;
$out_stock      = 0;
$low_stock      = 0;
$check_stock    = 0;


if ($branch_id > 0) {

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_products,

            COALESCE(
                SUM(
                    CASE
                        WHEN stock <= 0
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS out_stock,

            COALESCE(
                SUM(
                    CASE
                        WHEN stock > 0
                         AND stock <= min_stock
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS low_stock,

            COALESCE(
                SUM(
                    CASE
                        WHEN stock > min_stock
                         AND stock <= min_stock + 5
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS check_stock

        FROM products
        WHERE branch_id = ?
    ");

    $stmt->bind_param(
        "i",
        $branch_id
    );

    $stmt->execute();

    $stats =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    if ($stats) {

        $total_products =
            (int) $stats['total_products'];

        $out_stock =
            (int) $stats['out_stock'];

        $low_stock =
            (int) $stats['low_stock'];

        $check_stock =
            (int) $stats['check_stock'];
    }
}


/* =========================================================
   INCLUDE HEADER
========================================================= */

require_once __DIR__ . "/../includes/header.php";

?>


<style>

/* =========================================================
   PRODUCTS PAGE
========================================================= */

.products-page {
    width: 100%;
}

.products-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 20px;
}

.products-heading h2 {
    margin: 0;
}

.products-heading p {
    margin: 6px 0 0;
    color: var(--muted);
    font-size: 12px;
}

.product-alert {
    padding: 13px 15px;
    border-radius: 10px;
    margin-bottom: 16px;
    font-size: 12px;
    border: 1px solid;
}

.product-alert.success {
    background: #f0faf3;
    border-color: #cce8d4;
    color: #176b3a;
}

.product-alert.error {
    background: #fff5f5;
    border-color: #f0cccc;
    color: #a52c2c;
}


/* =========================================================
   BRANCH INFO
========================================================= */

.active-branch-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 14px 16px;
    margin-bottom: 18px;
    background: #f7faf7;
    border: 1px solid var(--border);
    border-radius: 12px;
}

.active-branch-info {
    display: flex;
    align-items: center;
    gap: 11px;
}

.active-branch-icon {
    width: 36px;
    height: 36px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    background: #e7f3e9;
    color: var(--green);
    font-weight: 900;
}

.active-branch-info strong {
    display: block;
    font-size: 12px;
}

.active-branch-info span {
    display: block;
    margin-top: 3px;
    color: var(--muted);
    font-size: 10px;
}


/* =========================================================
   STATISTICS
========================================================= */

.products-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}

.product-stat {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 17px;
    box-shadow: 0 8px 25px #173c2408;
}

.product-stat small {
    display: block;
    color: var(--muted);
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: .07em;
    font-weight: 800;
}

.product-stat strong {
    display: block;
    margin-top: 8px;
    font-size: 24px;
    letter-spacing: -.04em;
}

.product-stat span {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    font-size: 10px;
}

.product-stat.danger strong {
    color: #c33b3b;
}

.product-stat.warning strong {
    color: #b27815;
}

.product-stat.success strong {
    color: var(--green);
}


/* =========================================================
   PRODUCTS TOOLBAR
========================================================= */

.products-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    margin-bottom: 15px;
}

.products-toolbar-left {
    display: flex;
    align-items: center;
    gap: 9px;
    flex: 1;
}

.products-search {
    width: 100%;
    max-width: 420px;
}

.products-search input {
    width: 100%;
    padding: 11px 13px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: #fff;
    color: var(--text);
    outline: none;
}

.products-search input:focus {
    border-color: #9bbda5;
    box-shadow: 0 0 0 3px #176f3d12;
}

.products-count {
    white-space: nowrap;
    font-size: 10px;
    color: var(--muted);
}


/* =========================================================
   STOCK FILTER
========================================================= */

.stock-filter {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-bottom: 16px;
}

.stock-filter a {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 10px;
    border: 1px solid var(--border);
    border-radius: 8px;
    text-decoration: none;
    color: var(--muted);
    background: #fff;
    font-size: 10px;
    font-weight: 700;
}

.stock-filter a:hover {
    border-color: #9dbca5;
    color: var(--green);
}

.stock-filter a.active {
    background: #edf7ef;
    color: var(--green);
    border-color: #c9e2cf;
}


/* =========================================================
   PRODUCT TABLE
========================================================= */

.product-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.product-thumb {
    width: 46px;
    height: 46px;
    border-radius: 10px;
    object-fit: cover;
    border: 1px solid var(--border);
    background: #f5f8f5;
    flex: 0 0 46px;
}

.product-thumb.placeholder {
    display: grid;
    place-items: center;
    font-size: 19px;
}

.product-cell strong {
    display: block;
    font-size: 11px;
}

.product-cell small {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    font-size: 9px;
}

.action-buttons {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 5px;
}

.btn-small {
    padding: 6px 8px !important;
    font-size: 10px !important;
}

.empty-state {
    text-align: center;
    padding: 35px !important;
    color: var(--muted);
}


/* =========================================================
   IMAGE UPLOAD
========================================================= */

.image-upload {
    padding: 14px;
    border: 1px dashed #cbd8ce;
    border-radius: 12px;
    background: #fafcfa;
}

.image-upload input[type="file"] {
    width: 100%;
}

.form-help {
    display: block;
    margin-top: 6px;
    color: var(--muted);
    font-size: 9px;
}

.current-image-box {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.current-image {
    width: 64px;
    height: 64px;
    border-radius: 11px;
    object-fit: cover;
    border: 1px solid var(--border);
    background: #f5f8f5;
}

.current-image-placeholder {
    width: 64px;
    height: 64px;
    border-radius: 11px;
    display: grid;
    place-items: center;
    background: #edf5ee;
    border: 1px solid var(--border);
    font-size: 22px;
}

.current-image-text strong {
    display: block;
    font-size: 11px;
}

.current-image-text span {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    font-size: 9px;
}

.new-image-preview {
    display: none;
    margin-top: 12px;
}

.new-image-preview img {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 12px;
    border: 1px solid var(--border);
}

.image-remove-option {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 10px;
    font-size: 10px;
    color: var(--muted);
}

.image-remove-option input {
    width: auto !important;
}


/* =========================================================
   PRODUCT NOTICE
========================================================= */

.product-notice {
    position: fixed;
    right: 22px;
    bottom: 22px;
    z-index: 9999;
    width: min(380px, calc(100vw - 40px));
    padding: 14px 16px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: 0 18px 45px #173c2420;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    animation: productNoticeIn .18s ease;
}

.product-notice.success {
    border-color: #cce8d4;
}

.product-notice.error {
    border-color: #efcaca;
}

.product-notice-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: grid;
    place-items: center;
    flex: 0 0 28px;
    background: #edf7ef;
    color: var(--green);
    font-weight: 900;
}

.product-notice.error .product-notice-icon {
    background: #fff0f0;
    color: #b53333;
}

.product-notice strong {
    display: block;
    font-size: 11px;
}

.product-notice span {
    display: block;
    margin-top: 3px;
    font-size: 10px;
    color: var(--muted);
    line-height: 1.45;
}

.product-notice-close {
    margin-left: auto;
    border: 0;
    background: transparent;
    color: var(--muted);
    cursor: pointer;
    font-size: 16px;
}

@keyframes productNoticeIn {
    from {
        opacity: 0;
        transform: translateY(8px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1050px) {

    .products-stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .products-toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .products-toolbar-left {
        width: 100%;
    }

    .products-search {
        max-width: none;
    }
}

@media (max-width: 650px) {

    .products-heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .products-heading .btn {
        width: 100%;
    }

    .products-stats {
        grid-template-columns: 1fr 1fr;
    }

    .active-branch-box {
        align-items: flex-start;
        flex-direction: column;
    }

    .products-toolbar-left {
        flex-direction: column;
        align-items: stretch;
    }

    .table-wrapper {
        overflow-x: auto;
    }

    .product-notice {
        right: 15px;
        bottom: 15px;
        width: calc(100vw - 30px);
    }
}

</style>

<style>
/* =========================================================
   PRODUCTS PAGE SAFETY / MODAL FIX
   - Menjamin menu sidebar tetap bisa diklik
   - Modal tidak tampil sebagai konten biasa
   - Tombol dan area aksi tetap menerima klik
========================================================= */

html,
body {
    overflow-x: hidden;
}

.sidebar {
    position: relative;
    z-index: 5000;
}

.sidebar-menu {
    position: relative;
    z-index: 5001;
}

.sidebar-menu a {
    position: relative;
    z-index: 5002;
    pointer-events: auto !important;
    cursor: pointer;
}

.main {
    position: relative;
    z-index: 1;
    min-width: 0;
}

.content {
    min-width: 0;
}

.products-page {
    position: relative;
    z-index: 2;
    width: 100%;
    min-width: 0;
}

.products-page button,
.products-page a,
.products-page input,
.products-page select,
.products-page textarea,
.products-page label {
    pointer-events: auto;
}

.products-page button,
.products-page .btn {
    cursor: pointer;
}

.products-page button:disabled {
    cursor: not-allowed;
}

.table-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.table-wrapper .table {
    min-width: 920px;
}

/* =========================================================
   MODAL â€” fallback lengkap agar tidak mengacaukan layout
========================================================= */

.modal {
    position: fixed !important;
    inset: 0 !important;
    z-index: 10000 !important;
    display: none !important;
    align-items: center !important;
    justify-content: center !important;
    width: 100vw !important;
    height: 100vh !important;
    padding: 20px !important;
    box-sizing: border-box !important;
    background: rgba(16, 28, 20, .58) !important;
    backdrop-filter: blur(3px);
    overflow-y: auto !important;
}

.modal.show {
    display: flex !important;
}

.modal-box {
    position: relative;
    z-index: 10001;
    width: min(760px, 100%);
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    box-sizing: border-box;
    padding: 20px;
    background: #fff;
    border: 1px solid #dfe8e1;
    border-radius: 16px;
    box-shadow: 0 25px 70px rgba(0,0,0,.24);
    color: var(--text, #17241b);
}

.modal-box.small-modal {
    width: min(460px, 100%);
}

.modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 18px;
}

.modal-header h3 {
    margin: 0;
    font-size: 16px;
    color: var(--text, #17241b);
}

.modal-header p {
    margin: 5px 0 0;
    color: var(--muted, #718078);
    font-size: 10px;
    line-height: 1.5;
}

.modal-close {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    display: grid;
    place-items: center;
    border: 1px solid #dfe7e1;
    border-radius: 9px;
    background: #f8faf8;
    color: #66736b;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
}

.modal-close:hover {
    background: #eef5ef;
    color: #176f3d;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.form-group {
    min-width: 0;
    margin-bottom: 14px;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    color: var(--text, #17241b);
    font-size: 10px;
    font-weight: 800;
}

.form-group input,
.form-group select,
.form-group textarea,
.modal-box input[type="text"],
.modal-box input[type="number"] {
    width: 100%;
    box-sizing: border-box;
    padding: 10px 11px;
    border: 1px solid #d8e2da;
    border-radius: 9px;
    outline: none;
    background: #fff;
    color: var(--text, #17241b);
    font: inherit;
    font-size: 11px;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus,
.modal-box input:focus {
    border-color: #8db79a;
    box-shadow: 0 0 0 3px rgba(23,111,61,.08);
}

.modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 18px;
    padding-top: 15px;
    border-top: 1px solid #e7ede8;
}

.info-box {
    padding: 12px 13px;
    border: 1px solid #dce7de;
    border-radius: 10px;
    background: #f7faf7;
    color: #5b685f;
    font-size: 10px;
    line-height: 1.55;
}

.current-image-box {
    flex-wrap: wrap;
}

@media (max-width: 700px) {
    .modal {
        align-items: flex-start !important;
        padding: 12px !important;
    }

    .modal-box {
        max-height: calc(100vh - 24px);
        padding: 16px;
        border-radius: 14px;
    }

    .form-grid {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .modal-footer {
        flex-direction: column-reverse;
        align-items: stretch;
    }

    .modal-footer .btn {
        width: 100%;
    }

    .table-wrapper .table {
        min-width: 820px;
    }
}
</style>

<style>
/* =========================================================
   PROFESSIONAL PRODUCTS UI â€” FINAL OVERRIDE
   Tidak mengubah PHP/backend; hanya merapikan tampilan,
   klik, tabel, aksi, dan modal.
========================================================= */

.products-page {
    box-sizing: border-box;
    padding: 2px 0 28px;
    color: var(--text, #17241b);
}

.products-page * {
    box-sizing: border-box;
}

.products-heading {
    padding: 2px 0 4px;
}

.products-heading h2 {
    font-size: 24px;
    line-height: 1.2;
    letter-spacing: -.025em;
    color: #17241b;
}

.products-heading p {
    font-size: 12px;
    line-height: 1.5;
}

.products-heading .btn-primary {
    min-height: 42px;
    padding: 0 16px;
    border-radius: 10px;
    box-shadow: 0 7px 18px rgba(23,111,61,.14);
}

.active-branch-box {
    background: linear-gradient(135deg,#f7fbf8,#f1f8f3);
    border-color: #d9e7dc;
    box-shadow: 0 5px 18px rgba(23,60,36,.045);
}

.products-stats {
    gap: 12px;
}

.product-stat {
    min-height: 118px;
    padding: 17px 18px;
    border-color: #e1e9e3;
    box-shadow: 0 5px 18px rgba(23,60,36,.045);
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
}

.product-stat:hover {
    transform: translateY(-1px);
    border-color: #cbdccf;
    box-shadow: 0 9px 24px rgba(23,60,36,.075);
}

.product-stat strong {
    font-size: 25px;
}

.panel {
    position: relative;
    z-index: 2;
    overflow: visible;
    border: 1px solid #dfe8e2;
    border-radius: 16px;
    box-shadow: 0 8px 28px rgba(23,60,36,.055);
    background: #fff;
}

.panel-header {
    padding: 18px 20px;
    border-bottom: 1px solid #edf1ee;
}

.panel-header h3 {
    font-size: 15px;
    color: #17241b;
}

.panel-header p {
    line-height: 1.45;
}

.products-toolbar {
    padding: 16px 20px 4px;
    margin-bottom: 0;
}

.products-toolbar-left {
    min-width: 0;
}

.products-search {
    max-width: 520px;
}

.products-search input {
    height: 40px;
    border-radius: 10px;
    border-color: #d9e4dc;
    background: #fbfdfb;
}

.products-search input:focus {
    border-color: #6ea681;
    box-shadow: 0 0 0 3px rgba(23,111,61,.09);
    background: #fff;
}

.products-toolbar .btn {
    min-height: 40px;
    border-radius: 10px;
}

.stock-filter {
    padding: 10px 20px 16px;
    margin: 0;
    border-bottom: 1px solid #edf1ee;
}

.stock-filter a {
    min-height: 32px;
    padding: 0 11px;
    border-radius: 9px;
    transition: all .15s ease;
}

.stock-filter a.active {
    box-shadow: inset 0 0 0 1px rgba(23,111,61,.03);
}

.table-wrapper {
    position: relative;
    z-index: 3;
    width: 100%;
    overflow-x: auto;
    overflow-y: visible;
    border-radius: 0 0 16px 16px;
}

.table-wrapper .table {
    width: 100%;
    min-width: 1020px;
    border-collapse: separate;
    border-spacing: 0;
}

.table-wrapper .table th {
    position: sticky;
    top: 0;
    z-index: 4;
    background: #f8faf8;
    color: #68766d;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: .055em;
    white-space: nowrap;
}

.table-wrapper .table td {
    vertical-align: middle;
    background: #fff;
}

.table-wrapper .table tbody tr {
    transition: background .12s ease;
}

.table-wrapper .table tbody tr:hover td {
    background: #fbfdfb;
}

.product-cell {
    min-width: 240px;
}

.product-thumb {
    width: 48px;
    height: 48px;
    flex-basis: 48px;
    border-radius: 11px;
}

.action-buttons {
    position: relative;
    z-index: 20;
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    gap: 6px;
    min-width: 240px;
}

.action-buttons .btn,
.action-buttons a {
    position: relative;
    z-index: 21;
    pointer-events: auto !important;
    white-space: nowrap;
    min-height: 31px;
    border-radius: 8px;
    text-decoration: none;
}

.action-buttons .btn-small {
    padding: 6px 9px !important;
}

.products-page .btn,
.products-page button,
.products-page a {
    -webkit-tap-highlight-color: transparent;
}

.products-page .btn:focus-visible,
.products-page button:focus-visible,
.products-page a:focus-visible,
.products-page input:focus-visible {
    outline: 3px solid rgba(23,111,61,.15);
    outline-offset: 2px;
}

/* Jangan biarkan elemen kosong/overlay menutup tombol. */
.products-page .panel,
.products-page .panel-header,
.products-page .table-wrapper,
.products-page .action-buttons {
    pointer-events: auto;
}

/* Modal selalu berada di atas sidebar dan seluruh halaman. */
body:has(.modal.show) .sidebar {
    z-index: 5000;
}

.modal.show {
    pointer-events: auto !important;
}

.modal.show .modal-box,
.modal.show .modal-box * {
    pointer-events: auto;
}

.modal-box {
    border-radius: 18px;
    padding: 22px;
}

.form-grid {
    gap: 14px;
}

.form-group label {
    margin-bottom: 6px;
    font-size: 10px;
    font-weight: 800;
    color: #34443a;
}

.form-group input,
.form-group select,
.form-group textarea {
    min-height: 40px;
    border-radius: 9px;
    border-color: #d8e2db;
    background: #fcfdfc;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #6ea681;
    box-shadow: 0 0 0 3px rgba(23,111,61,.08);
    background: #fff;
    outline: none;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding-top: 18px;
    margin-top: 18px;
    border-top: 1px solid #edf1ee;
}

.modal-footer .btn {
    min-height: 39px;
    border-radius: 9px;
}

@media (max-width: 900px) {
    .products-heading h2 {
        font-size: 21px;
    }

    .products-stats {
        grid-template-columns: repeat(2, minmax(0,1fr));
    }
}

@media (max-width: 640px) {
    .products-page {
        padding-bottom: 18px;
    }

    .products-heading {
        gap: 12px;
    }

    .products-heading .btn-primary {
        width: 100%;
    }

    .products-stats {
        grid-template-columns: 1fr 1fr;
        gap: 9px;
    }

    .product-stat {
        min-height: 105px;
        padding: 13px;
    }

    .product-stat strong {
        font-size: 21px;
    }

    .products-toolbar {
        padding-left: 13px;
        padding-right: 13px;
    }

    .stock-filter {
        padding-left: 13px;
        padding-right: 13px;
    }

    .modal {
        padding: 10px !important;
    }

    .modal-box {
        max-height: calc(100vh - 20px);
        padding: 16px;
        border-radius: 14px;
    }

    .modal-footer {
        flex-direction: column-reverse;
    }

    .modal-footer .btn {
        width: 100%;
    }
}
</style>


<div class="products-page">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="products-heading">

        <div>

            <h2>
                Produk & Stok
            </h2>

            <p>
                Kelola produk, harga, gambar, dan persediaan barang.
            </p>

        </div>


        <button
            type="button"
            class="btn btn-primary"
            onclick="openModal('addProductModal')"
            <?= $branch_id <= 0 ? 'disabled' : '' ?>
        >
            + Tambah Produk
        </button>

    </div>


    <!-- =====================================================
         PESAN
    ====================================================== -->

    <?php if ($message !== ""): ?>

        <div class="product-alert success">

            <?= e($message) ?>

        </div>

    <?php endif; ?>


    <?php if ($error !== ""): ?>

        <div class="product-alert error">

            <?= e($error) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         CABANG AKTIF
    ====================================================== -->

    <?php if ($branch_id > 0): ?>

        <div class="active-branch-box">

            <div class="active-branch-info">

                <div class="active-branch-icon">
                    â—
                </div>

                <div>

                    <strong>
                        Cabang Aktif: <?= e($branch_name) ?>
                    </strong>

                    <span>
                        Produk dan stok yang ditampilkan hanya berasal
                        dari cabang ini.
                    </span>

                </div>

            </div>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         STATISTIK
    ====================================================== -->

    <div class="products-stats">


        <div class="product-stat">

            <small>
                Total Produk
            </small>

            <strong>
                <?= number_format($total_products) ?>
            </strong>

            <span>
                produk di cabang aktif
            </span>

        </div>


        <div class="product-stat danger">

            <small>
                Stok Habis
            </small>

            <strong>
                <?= number_format($out_stock) ?>
            </strong>

            <span>
                produk tanpa stok
            </span>

        </div>


        <div class="product-stat warning">

            <small>
                Stok Menipis
            </small>

            <strong>
                <?= number_format($low_stock) ?>
            </strong>

            <span>
                di bawah minimum
            </span>

        </div>


        <div class="product-stat success">

            <small>
                Perlu Cek
            </small>

            <strong>
                <?= number_format($check_stock) ?>
            </strong>

            <span>
                mendekati minimum
            </span>

        </div>


    </div>


    <!-- =====================================================
         PANEL PRODUK
    ====================================================== -->

    <div class="panel">

        <div class="panel-header">

            <div>

                <h3>
                    Daftar Produk
                </h3>

                <p>
                    Cari produk terlebih dahulu untuk menambah stok
                    barang lama.
                </p>

            </div>

            <span class="products-count">
                <?= number_format(count($products)) ?>
                produk ditampilkan
            </span>

        </div>


        <!-- =================================================
             SEARCH
        ================================================== -->

        <form
            method="GET"
            class="products-toolbar"
        >

            <div class="products-toolbar-left">

                <div class="products-search">

                    <input
                        type="text"
                        name="search"
                        value="<?= e($search) ?>"
                        placeholder="Cari nama produk, kategori, atau barcode..."
                    >

                </div>


                <button
                    type="submit"
                    class="btn"
                >
                    Cari
                </button>


                <?php if (
                    $search !== '' ||
                    $stock_filter !== ''
                ): ?>

                    <a
                        href="<?= $base_url ?>/admin/products.php"
                        class="btn"
                        style="text-decoration:none;"
                    >
                        Reset
                    </a>

                <?php endif; ?>

            </div>

        </form>


        <!-- =================================================
             FILTER STOK
        ================================================== -->

        <div class="stock-filter">


            <a
                href="<?= $base_url ?>/admin/products.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>"
                class="<?= $stock_filter === '' ? 'active' : '' ?>"
            >
                Semua
            </a>


            <a
                href="<?= $base_url ?>/admin/products.php?<?= http_build_query([
                    'search' => $search,
                    'stock' => 'out'
                ]) ?>"
                class="<?= $stock_filter === 'out' ? 'active' : '' ?>"
            >
                Habis
            </a>


            <a
                href="<?= $base_url ?>/admin/products.php?<?= http_build_query([
                    'search' => $search,
                    'stock' => 'low'
                ]) ?>"
                class="<?= $stock_filter === 'low' ? 'active' : '' ?>"
            >
                Menipis
            </a>


            <a
                href="<?= $base_url ?>/admin/products.php?<?= http_build_query([
                    'search' => $search,
                    'stock' => 'check'
                ]) ?>"
                class="<?= $stock_filter === 'check' ? 'active' : '' ?>"
            >
                Perlu Cek
            </a>


            <a
                href="<?= $base_url ?>/admin/products.php?<?= http_build_query([
                    'search' => $search,
                    'stock' => 'safe'
                ]) ?>"
                class="<?= $stock_filter === 'safe' ? 'active' : '' ?>"
            >
                Aman
            </a>


        </div>


        <!-- =================================================
             TABLE
        ================================================== -->

        <div class="table-wrapper">

            <table class="table">

                <thead>

                <tr>

                    <th>
                        Produk
                    </th>

                    <th>
                        Kategori
                    </th>

                    <th>
                        Barcode
                    </th>

                    <th>
                        Harga
                    </th>

                    <th>
                        Stok
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Aksi
                    </th>

                </tr>

                </thead>


                <tbody>


                <?php if (!$products): ?>

                    <tr>

                        <td
                            colspan="7"
                            class="empty-state"
                        >
                            Produk tidak ditemukan pada cabang
                            <?= e($branch_name) ?>.

                        </td>

                    </tr>

                <?php endif; ?>


                <?php foreach ($products as $product): ?>


                    <?php

                    $stock =
                        (int) $product['stock'];

                    $min_stock =
                        (int) $product['min_stock'];


                    if ($stock <= 0) {

                        $status =
                            "Habis";

                        $status_class =
                            "badge-danger";

                    } elseif (
                        $stock <= $min_stock
                    ) {

                        $status =
                            "Menipis";

                        $status_class =
                            "badge-warning";

                    } elseif (
                        $stock <= $min_stock + 5
                    ) {

                        $status =
                            "Perlu Cek";

                        $status_class =
                            "badge-warning";

                    } else {

                        $status =
                            "Aman";

                        $status_class =
                            "badge-success";
                    }

                    ?>


                    <tr>


                        <!-- PRODUK -->

                        <td>

                            <div class="product-cell">


                                <?php if (
                                    !empty($product['image'])
                                ): ?>

                                    <img
                                        src="<?= $base_url ?>/uploads/products/<?= rawurlencode($product['image']) ?>"
                                        alt="<?= e($product['name']) ?>"
                                        class="product-thumb"
                                        onerror="this.style.display='none';"
                                    >

                                <?php else: ?>

                                    <div class="product-thumb placeholder">
                                        ðŸ“¦
                                    </div>

                                <?php endif; ?>


                                <div>

                                    <strong>
                                        <?= e($product['name']) ?>
                                    </strong>

                                    <small>
                                        ID #<?= (int) $product['id'] ?>
                                    </small>

                                </div>


                            </div>

                        </td>


                        <!-- KATEGORI -->

                        <td>
                            <?= e($product['category']) ?>
                        </td>


                        <!-- BARCODE -->

                        <td>

                            <?php if (
                                trim(
                                    (string) $product['barcode']
                                ) !== ''
                            ): ?>

                                <?= e($product['barcode']) ?>

                            <?php else: ?>

                                <span class="muted">
                                    -
                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- HARGA -->

                        <td>

                            <strong>
                                <?= rupiah($product['price']) ?>
                            </strong>

                        </td>


                        <!-- STOK -->

                        <td>

                            <strong>
                                <?= number_format($stock) ?>
                            </strong>

                            <div
                                class="mini"
                                style="margin-top:3px;"
                            >
                                Min.
                                <?= number_format($min_stock) ?>
                            </div>

                        </td>


                        <!-- STATUS -->

                        <td>

                            <span
                                class="badge <?= $status_class ?>"
                            >
                                <?= $status ?>
                            </span>

                        </td>


                        <!-- AKSI -->

                        <td>

                            <div class="action-buttons">


                                <!-- TAMBAH STOK -->

                                <button
                                    type="button"
                                    class="btn btn-small"
                                    onclick='openStockModal(
                                        <?= (int) $product['id'] ?>,
                                        <?= json_encode(
                                            $product['name'],
                                            JSON_HEX_TAG |
                                            JSON_HEX_APOS |
                                            JSON_HEX_AMP |
                                            JSON_HEX_QUOT
                                        ) ?>
                                    )'
                                >
                                    + Stok
                                </button>


                                <!-- EDIT -->

                                <button
                                    type="button"
                                    class="btn btn-small"
                                    onclick='openEditModal(
                                        <?= (int) $product['id'] ?>,
                                        <?= json_encode(
                                            $product['name'],
                                            JSON_HEX_TAG |
                                            JSON_HEX_APOS |
                                            JSON_HEX_AMP |
                                            JSON_HEX_QUOT
                                        ) ?>,
                                        <?= json_encode(
                                            $product['category'],
                                            JSON_HEX_TAG |
                                            JSON_HEX_APOS |
                                            JSON_HEX_AMP |
                                            JSON_HEX_QUOT
                                        ) ?>,
                                        <?= json_encode(
                                            (float) $product['price']
                                        ) ?>,
                                        <?= (int) $product['min_stock'] ?>,
                                        <?= json_encode(
                                            $product['barcode'] ?? '',
                                            JSON_HEX_TAG |
                                            JSON_HEX_APOS |
                                            JSON_HEX_AMP |
                                            JSON_HEX_QUOT
                                        ) ?>,
                                        <?= json_encode(
                                            $product['image'] ?? '',
                                            JSON_HEX_TAG |
                                            JSON_HEX_APOS |
                                            JSON_HEX_AMP |
                                            JSON_HEX_QUOT
                                        ) ?>
                                    )'
                                >
                                    Edit
                                </button>


                                <!-- HAPUS -->

                                <a
                                    href="?delete=<?= (int) $product['id'] ?>&search=<?= urlencode($search) ?>&stock=<?= urlencode($stock_filter) ?>"
                                    class="btn btn-small btn-danger"
                                    onclick='return confirmProductDelete(
                                        this.href,
                                        <?= json_encode(
                                            $product['name'],
                                            JSON_HEX_TAG |
                                            JSON_HEX_APOS |
                                            JSON_HEX_AMP |
                                            JSON_HEX_QUOT
                                        ) ?>
                                    )'
                                >
                                    Hapus
                                </a>


                            </div>

                        </td>


                    </tr>


                <?php endforeach; ?>


                </tbody>

            </table>

        </div>

    </div>

</div>


<!-- =========================================================
     MODAL TAMBAH PRODUK
========================================================= -->

<div
    class="modal"
    id="addProductModal"
>

    <div class="modal-box">


        <div class="modal-header">

            <div>

                <h3>
                    Tambah Produk Baru
                </h3>

                <p>
                    Tambahkan produk untuk cabang
                    <?= e($branch_name) ?>.
                </p>

            </div>


            <button
                type="button"
                class="modal-close"
                onclick="closeModal('addProductModal')"
            >
                Ã—
            </button>

        </div>


        <form
            method="POST"
            enctype="multipart/form-data"
        >

            <input
                type="hidden"
                name="action"
                value="add_product"
            >


            <div class="form-grid">


                <div class="form-group">

                    <label>
                        Nama Produk
                    </label>

                    <input
                        type="text"
                        name="name"
                        placeholder="Contoh: Pupuk NPK"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Kategori
                    </label>

                    <input
                        type="text"
                        name="category"
                        list="categorySuggestions"
                        placeholder="Contoh: Pupuk"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Harga Jual
                    </label>

                    <input
                        type="number"
                        name="price"
                        min="1"
                        step="1"
                        placeholder="Contoh: 125000"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Stok Awal
                    </label>

                    <input
                        type="number"
                        name="stock"
                        min="0"
                        value="0"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Batas Minimum Stok
                    </label>

                    <input
                        type="number"
                        name="min_stock"
                        min="0"
                        value="5"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Barcode
                    </label>

                    <input
                        type="text"
                        name="barcode"
                        placeholder="Opsional"
                    >

                    <small class="form-help">
                        Barcode hanya harus unik pada cabang
                        <?= e($branch_name) ?>.
                    </small>

                </div>


            </div>


            <datalist id="categorySuggestions">

                <option value="Pupuk">
                <option value="Benih">
                <option value="Pestisida">
                <option value="Herbisida">
                <option value="Insektisida">
                <option value="Fungisida">
                <option value="Perlengkapan">

            </datalist>


            <div class="form-group">

                <label>
                    Gambar Produk
                </label>

                <div class="image-upload">

                    <input
                        type="file"
                        name="image"
                        id="addImage"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    >

                    <small class="form-help">
                        JPG, PNG, WEBP. Maksimal 2 MB.
                    </small>

                    <div
                        class="new-image-preview"
                        id="addImagePreview"
                    >
                        <img
                            id="addImagePreviewImg"
                            src=""
                            alt="Preview"
                        >
                    </div>

                </div>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn"
                    onclick="closeModal('addProductModal')"
                >
                    Batal
                </button>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Simpan Produk
                </button>

            </div>

        </form>

    </div>

</div>


<!-- =========================================================
     MODAL TAMBAH STOK
========================================================= -->

<div
    class="modal"
    id="stockModal"
>

    <div class="modal-box small-modal">


        <div class="modal-header">

            <div>

                <h3>
                    Tambah Stok
                </h3>

                <p id="stockProductName">
                    Produk
                </p>

            </div>


            <button
                type="button"
                class="modal-close"
                onclick="closeModal('stockModal')"
            >
                Ã—
            </button>

        </div>


        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="add_stock"
            >

            <input
                type="hidden"
                name="product_id"
                id="stockProductId"
            >


            <div class="form-group">

                <label>
                    Jumlah Stok yang Ditambahkan
                </label>

                <input
                    type="number"
                    name="quantity"
                    min="1"
                    value="1"
                    required
                >

            </div>


            <div class="info-box">

                Produk yang sudah tersedia tidak perlu
                dibuat ulang. Tambahkan jumlah stoknya di sini.

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn"
                    onclick="closeModal('stockModal')"
                >
                    Batal
                </button>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Tambahkan Stok
                </button>

            </div>

        </form>

    </div>

</div>


<!-- =========================================================
     MODAL EDIT PRODUK
========================================================= -->

<div
    class="modal"
    id="editProductModal"
>

    <div class="modal-box">


        <div class="modal-header">

            <div>

                <h3>
                    Edit Produk
                </h3>

                <p>
                    Ubah informasi produk cabang aktif.
                </p>

            </div>


            <button
                type="button"
                class="modal-close"
                onclick="closeModal('editProductModal')"
            >
                Ã—
            </button>

        </div>


        <form
            method="POST"
            enctype="multipart/form-data"
        >

            <input
                type="hidden"
                name="action"
                value="edit_product"
            >

            <input
                type="hidden"
                name="id"
                id="editId"
            >


            <div class="form-grid">


                <div class="form-group">

                    <label>
                        Nama Produk
                    </label>

                    <input
                        type="text"
                        name="name"
                        id="editName"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Kategori
                    </label>

                    <input
                        type="text"
                        name="category"
                        id="editCategory"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Harga Jual
                    </label>

                    <input
                        type="number"
                        name="price"
                        id="editPrice"
                        min="1"
                        step="1"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Minimum Stok
                    </label>

                    <input
                        type="number"
                        name="min_stock"
                        id="editMinStock"
                        min="0"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Barcode
                    </label>

                    <input
                        type="text"
                        name="barcode"
                        id="editBarcode"
                    >

                </div>


            </div>


            <div class="form-group">

                <label>
                    Gambar Produk
                </label>


                <div class="image-upload">


                    <div
                        class="current-image-box"
                        id="currentImageBox"
                    >

                        <div
                            class="current-image-placeholder"
                            id="currentImagePlaceholder"
                        >
                            ðŸ“¦
                        </div>


                        <img
                            src=""
                            alt="Gambar produk"
                            class="current-image"
                            id="currentImage"
                            style="display:none;"
                        >


                        <div class="current-image-text">

                            <strong>
                                Gambar saat ini
                            </strong>

                            <span id="currentImageText">
                                Belum ada gambar.
                            </span>

                        </div>

                    </div>


                    <input
                        type="file"
                        name="edit_image"
                        id="editImage"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    >


                    <small class="form-help">
                        Pilih gambar baru jika ingin mengganti.
                        Maksimal 2 MB.
                    </small>


                    <div
                        class="new-image-preview"
                        id="editImagePreview"
                    >

                        <img
                            id="editImagePreviewImg"
                            src=""
                            alt="Preview gambar baru"
                        >

                    </div>


                    <label
                        class="image-remove-option"
                        id="removeImageOption"
                        style="display:none;"
                    >

                        <input
                            type="checkbox"
                            name="remove_image"
                            value="1"
                            id="removeImage"
                        >

                        Hapus gambar produk saat ini

                    </label>


                </div>

            </div>


            <div class="info-box">

                Untuk menambah jumlah barang, gunakan tombol
                <strong>+ Stok</strong>.
                Stok tidak diubah melalui menu edit.

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn"
                    onclick="closeModal('editProductModal')"
                >
                    Batal
                </button>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Simpan Perubahan
                </button>

            </div>

        </form>

    </div>

</div>


<!-- =========================================================
     MODAL KONFIRMASI HAPUS
========================================================= -->

<div
    class="modal"
    id="productConfirmModal"
>

    <div class="modal-box small-modal">


        <div class="modal-header">

            <div>

                <h3>
                    Hapus Produk
                </h3>

                <p>
                    Konfirmasi penghapusan produk.
                </p>

            </div>


            <button
                type="button"
                class="modal-close"
                onclick="closeModal('productConfirmModal')"
            >
                Ã—
            </button>

        </div>


        <div class="info-box">

            <strong id="confirmDeleteName">
                Produk
            </strong>

            <br><br>

            Produk yang sudah digunakan dalam transaksi
            tidak dapat dihapus.

        </div>


        <div class="modal-footer">

            <button
                type="button"
                class="btn"
                onclick="closeModal('productConfirmModal')"
            >
                Batal
            </button>


            <button
                type="button"
                class="btn btn-danger"
                onclick="executeProductDelete()"
            >
                Ya, Hapus Produk
            </button>

        </div>

    </div>

</div>


<script>

/* =========================================================
   MODAL
========================================================= */

function openModal(id)
{
    const modal =
        document.getElementById(id);

    if (!modal) {
        return;
    }

    modal.classList.add("show");

    document.body.style.overflow = "hidden";
}


function closeModal(id)
{
    const modal =
        document.getElementById(id);

    if (!modal) {
        return;
    }

    modal.classList.remove("show");

    if (
        !document.querySelector(".modal.show")
    ) {
        document.body.style.overflow = "";
    }
}


/* =========================================================
   TAMBAH STOK
========================================================= */

function openStockModal(id, name)
{
    document.getElementById(
        "stockProductId"
    ).value = id;

    document.getElementById(
        "stockProductName"
    ).textContent = name;

    openModal("stockModal");
}


/* =========================================================
   EDIT PRODUK
========================================================= */

function openEditModal(
    id,
    name,
    category,
    price,
    minStock,
    barcode,
    image
)
{
    document.getElementById(
        "editId"
    ).value = id;

    document.getElementById(
        "editName"
    ).value = name;

    document.getElementById(
        "editCategory"
    ).value = category;

    document.getElementById(
        "editPrice"
    ).value = price;

    document.getElementById(
        "editMinStock"
    ).value = minStock;

    document.getElementById(
        "editBarcode"
    ).value = barcode || "";


    const currentImage =
        document.getElementById(
            "currentImage"
        );

    const placeholder =
        document.getElementById(
            "currentImagePlaceholder"
        );

    const imageText =
        document.getElementById(
            "currentImageText"
        );

    const removeOption =
        document.getElementById(
            "removeImageOption"
        );

    const removeCheckbox =
        document.getElementById(
            "removeImage"
        );


    removeCheckbox.checked = false;


    if (image) {

        currentImage.src =
            "<?= $base_url ?>/uploads/products/"
            + encodeURIComponent(image);

        currentImage.style.display =
            "block";

        placeholder.style.display =
            "none";

        imageText.textContent =
            image;

        removeOption.style.display =
            "flex";

    } else {

        currentImage.src = "";

        currentImage.style.display =
            "none";

        placeholder.style.display =
            "grid";

        imageText.textContent =
            "Belum ada gambar.";

        removeOption.style.display =
            "none";
    }


    document.getElementById(
        "editImagePreview"
    ).style.display = "none";

    document.getElementById(
        "editImage"
    ).value = "";


    openModal("editProductModal");
}


/* =========================================================
   KONFIRMASI HAPUS
========================================================= */

let pendingDeleteUrl = "";


function confirmProductDelete(
    url,
    productName
)
{
    pendingDeleteUrl = url;

    document.getElementById(
        "confirmDeleteName"
    ).textContent =
        'Yakin ingin menghapus "' +
        productName +
        '"?';

    openModal(
        "productConfirmModal"
    );

    return false;
}


function executeProductDelete()
{
    if (
        pendingDeleteUrl === ""
    ) {
        return;
    }

    window.location.href =
        pendingDeleteUrl;
}


/* =========================================================
   NOTIFICATION
========================================================= */

function showProductNotice(
    message,
    type = "error"
)
{
    const old =
        document.querySelector(
            ".product-notice"
        );

    if (old) {
        old.remove();
    }


    const notice =
        document.createElement("div");

    notice.className =
        "product-notice " +
        type;


    const icon =
        type === "success"
            ? "âœ“"
            : "!";


    notice.innerHTML =
        '<div class="product-notice-icon">' +
            icon +
        '</div>' +

        '<div>' +
            '<strong>' +
                (
                    type === "success"
                        ? "Berhasil"
                        : "Periksa kembali"
                ) +
            '</strong>' +

            '<span>' +
                escapeHtml(message) +
            '</span>' +
        '</div>' +

        '<button type="button" class="product-notice-close">' +
            "Ã—" +
        '</button>';


    document.body.appendChild(
        notice
    );


    notice
        .querySelector(
            ".product-notice-close"
        )
        .addEventListener(
            "click",
            function () {
                notice.remove();
            }
        );


    setTimeout(
        function () {

            if (
                notice &&
                notice.parentNode
            ) {
                notice.remove();
            }

        },
        4500
    );
}


function escapeHtml(value)
{
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}


/* =========================================================
   VALIDASI GAMBAR
========================================================= */

function validateImageFile(
    file
)
{
    if (!file) {
        return true;
    }


    if (
        file.size >
        2 * 1024 * 1024
    ) {

        showProductNotice(
            "Ukuran gambar maksimal 2 MB.",
            "error"
        );

        return false;
    }


    const allowed = [
        "image/jpeg",
        "image/png",
        "image/webp"
    ];


    if (
        !allowed.includes(
            file.type
        )
    ) {

        showProductNotice(
            "Format gambar harus JPG, PNG, atau WEBP.",
            "error"
        );

        return false;
    }


    return true;
}


/* =========================================================
   PREVIEW GAMBAR TAMBAH
========================================================= */

const addImage =
    document.getElementById(
        "addImage"
    );


if (addImage) {

    addImage.addEventListener(
        "change",
        function ()
        {
            const file =
                this.files[0];

            if (
                !validateImageFile(
                    file
                )
            ) {

                this.value = "";

                return;
            }


            const preview =
                document.getElementById(
                    "addImagePreview"
                );

            const previewImage =
                document.getElementById(
                    "addImagePreviewImg"
                );


            if (!file) {

                preview.style.display =
                    "none";

                return;
            }


            const reader =
                new FileReader();


            reader.onload =
                function (event)
                {
                    previewImage.src =
                        event.target.result;

                    preview.style.display =
                        "block";
                };


            reader.readAsDataURL(
                file
            );
        }
    );
}


/* =========================================================
   PREVIEW GAMBAR EDIT
========================================================= */

const editImage =
    document.getElementById(
        "editImage"
    );


if (editImage) {

    editImage.addEventListener(
        "change",
        function ()
        {
            const file =
                this.files[0];

            if (
                !validateImageFile(
                    file
                )
            ) {

                this.value = "";

                return;
            }


            const preview =
                document.getElementById(
                    "editImagePreview"
                );

            const previewImage =
                document.getElementById(
                    "editImagePreviewImg"
                );


            if (!file) {

                preview.style.display =
                    "none";

                return;
            }


            const reader =
                new FileReader();


            reader.onload =
                function (event)
                {
                    previewImage.src =
                        event.target.result;

                    preview.style.display =
                        "block";
                };


            reader.readAsDataURL(
                file
            );
        }
    );
}


/* =========================================================
   RESET REMOVE IMAGE
========================================================= */

const removeImage =
    document.getElementById(
        "removeImage"
    );


if (removeImage) {

    removeImage.addEventListener(
        "change",
        function ()
        {
            const currentImage =
                document.getElementById(
                    "currentImage"
                );

            const placeholder =
                document.getElementById(
                    "currentImagePlaceholder"
                );


            if (this.checked) {

                currentImage.style.opacity =
                    "0.35";

                placeholder.style.opacity =
                    "0.35";

            } else {

                currentImage.style.opacity =
                    "1";

                placeholder.style.opacity =
                    "1";
            }
        }
    );
}


/* =========================================================
   KLIK DI LUAR MODAL
========================================================= */

window.addEventListener(
    "click",
    function (event)
    {
        if (
            event.target.classList.contains(
                "modal"
            )
        ) {

            event.target.classList.remove(
                "show"
            );

            if (
                !document.querySelector(
                    ".modal.show"
                )
            ) {

                document.body.style.overflow =
                    "";
            }
        }
    }
);


/* =========================================================
   ESC
========================================================= */

window.addEventListener(
    "keydown",
    function (event)
    {
        if (
            event.key === "Escape"
        ) {

            const modals =
                document.querySelectorAll(
                    ".modal.show"
                );

            modals.forEach(
                function (modal)
                {
                    modal.classList.remove(
                        "show"
                    );
                }
            );

            document.body.style.overflow =
                "";
        }
    }
);

</script>


<?php

require_once __DIR__ . "/../includes/footer.php";

?>

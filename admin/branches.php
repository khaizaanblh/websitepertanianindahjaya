<?php

require_once __DIR__ . "/../includes/auth.php";
require_admin();

require_once __DIR__ . "/../config/database.php";

$base_url = "/kasir_pertanian";

$message = "";
$error   = "";


/* =========================================================
   HELPER
========================================================= */

function esc($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}


/* =========================================================
   PROSES TAMBAH / EDIT / STATUS
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";


    /* =====================================================
       TAMBAH CABANG
    ===================================================== */

    if ($action === "add") {

        $name    = trim($_POST["name"] ?? "");
        $address = trim($_POST["address"] ?? "");
        $phone   = trim($_POST["phone"] ?? "");
        $status  = $_POST["status"] ?? "active";


        if ($name === "") {

            $error = "Nama cabang wajib diisi.";

        } elseif (!in_array($status, ["active", "inactive"], true)) {

            $error = "Status cabang tidak valid.";

        } else {

            $stmt = $conn->prepare("
                SELECT id
                FROM branches
                WHERE name = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                "s",
                $name
            );

            $stmt->execute();

            $exists = $stmt
                ->get_result()
                ->fetch_assoc();

            $stmt->close();


            if ($exists) {

                $error = "Nama cabang tersebut sudah digunakan.";

            } else {

                $stmt = $conn->prepare("
                    INSERT INTO branches
                    (
                        name,
                        address,
                        phone,
                        status
                    )
                    VALUES (?, ?, ?, ?)
                ");

                $stmt->bind_param(
                    "ssss",
                    $name,
                    $address,
                    $phone,
                    $status
                );


                if ($stmt->execute()) {

                    $message = "Cabang berhasil ditambahkan.";

                } else {

                    $error = "Gagal menambahkan cabang.";
                }

                $stmt->close();
            }
        }
    }


    /* =====================================================
       EDIT CABANG
    ===================================================== */

    elseif ($action === "edit") {

        $id      = (int) ($_POST["id"] ?? 0);
        $name    = trim($_POST["name"] ?? "");
        $address = trim($_POST["address"] ?? "");
        $phone   = trim($_POST["phone"] ?? "");
        $status  = $_POST["status"] ?? "active";


        if ($id <= 0) {

            $error = "Cabang tidak valid.";

        } elseif ($name === "") {

            $error = "Nama cabang wajib diisi.";

        } elseif (!in_array($status, ["active", "inactive"], true)) {

            $error = "Status cabang tidak valid.";

        } else {

            $stmt = $conn->prepare("
                SELECT id
                FROM branches
                WHERE name = ?
                  AND id != ?
                LIMIT 1
            ");

            $stmt->bind_param(
                "si",
                $name,
                $id
            );

            $stmt->execute();

            $exists = $stmt
                ->get_result()
                ->fetch_assoc();

            $stmt->close();


            if ($exists) {

                $error = "Nama cabang tersebut sudah digunakan.";

            } else {

                $stmt = $conn->prepare("
                    UPDATE branches
                    SET
                        name = ?,
                        address = ?,
                        phone = ?,
                        status = ?
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    "ssssi",
                    $name,
                    $address,
                    $phone,
                    $status,
                    $id
                );


                if ($stmt->execute()) {

                    $message = "Data cabang berhasil diperbarui.";

                } else {

                    $error = "Gagal memperbarui data cabang.";
                }

                $stmt->close();
            }
        }
    }


    /* =====================================================
       UBAH STATUS
    ===================================================== */

    elseif ($action === "toggle_status") {

        $id = (int) ($_POST["id"] ?? 0);

        if ($id <= 0) {

            $error = "Cabang tidak valid.";

        } else {

            $stmt = $conn->prepare("
                SELECT
                    id,
                    name,
                    status
                FROM branches
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                "i",
                $id
            );

            $stmt->execute();

            $branch = $stmt
                ->get_result()
                ->fetch_assoc();

            $stmt->close();


            if (!$branch) {

                $error = "Cabang tidak ditemukan.";

            } else {

                $new_status =
                    $branch["status"] === "active"
                        ? "inactive"
                        : "active";


                $stmt = $conn->prepare("
                    UPDATE branches
                    SET status = ?
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    "si",
                    $new_status,
                    $id
                );


                if ($stmt->execute()) {

                    $message =
                        $new_status === "active"
                            ? "Cabang berhasil diaktifkan."
                            : "Cabang berhasil dinonaktifkan.";

                } else {

                    $error = "Gagal mengubah status cabang.";
                }

                $stmt->close();
            }
        }
    }
}


/* =========================================================
   DATA CABANG
========================================================= */

$result = $conn->query("
    SELECT
        b.id,
        b.name,
        b.address,
        b.phone,
        b.status,
        b.created_at,

        (
            SELECT COUNT(*)
            FROM users u
            WHERE u.branch_id = b.id
        ) AS total_users,

        (
            SELECT COUNT(*)
            FROM sales s
            WHERE s.branch_id = b.id
        ) AS total_sales

    FROM branches b

    ORDER BY
        b.status = 'active' DESC,
        b.name ASC
");

$branches = $result
    ? $result->fetch_all(MYSQLI_ASSOC)
    : [];


/* =========================================================
   STATISTIK
========================================================= */

$total_branches = count($branches);

$active_branches = 0;
$inactive_branches = 0;

foreach ($branches as $branch) {

    if ($branch["status"] === "active") {
        $active_branches++;
    } else {
        $inactive_branches++;
    }
}


/* =========================================================
   HEADER
========================================================= */

require_once __DIR__ . "/../includes/header.php";

?>

<style>

/* =========================================================
   RINGKASAN CABANG
========================================================= */

.branch-summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}

.branch-stat-card {
    background: #fff;
    border: 1px solid #e2ebe4;
    border-radius: 15px;
    padding: 18px;
    box-shadow: 0 8px 25px rgba(23,60,36,.05);
}

.branch-stat-card small {
    display: block;
    color: #718078;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .06em;
}

.branch-stat-card strong {
    display: block;
    margin-top: 8px;
    font-size: 25px;
    color: #173c24;
}

.branch-stat-card span {
    display: block;
    margin-top: 4px;
    color: #718078;
    font-size: 10px;
}


/* =========================================================
   HEADER HALAMAN
========================================================= */

.branch-page-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
}

.branch-page-head h2 {
    margin: 0;
}

.branch-page-head p {
    margin: 5px 0 0;
    color: #718078;
    font-size: 11px;
}


/* =========================================================
   NOTIFIKASI PROFESIONAL
========================================================= */

.branch-notification {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 18px;
    padding: 14px 44px 14px 15px;
    border-radius: 13px;
    border: 1px solid;
    box-shadow: 0 8px 25px rgba(23,60,36,.07);
    animation: notificationSlide .25s ease-out;
}

.branch-notification-icon {
    width: 32px;
    height: 32px;
    flex: 0 0 32px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    font-size: 15px;
    font-weight: 900;
}

.branch-notification-content {
    min-width: 0;
    padding-top: 1px;
}

.branch-notification-title {
    display: block;
    margin-bottom: 3px;
    font-size: 12px;
    font-weight: 900;
}

.branch-notification-message {
    margin: 0;
    font-size: 11px;
    line-height: 1.5;
}

.branch-notification-close {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 27px;
    height: 27px;
    padding: 0;
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: inherit;
    cursor: pointer;
    font-size: 15px;
    opacity: .65;
    transition: .15s ease;
}

.branch-notification-close:hover {
    opacity: 1;
    background: rgba(0,0,0,.05);
}

.branch-notification.success {
    background: #f1faf4;
    border-color: #cce8d4;
    color: #176f3d;
}

.branch-notification.success .branch-notification-icon {
    background: #dff3e5;
    color: #176f3d;
}

.branch-notification.error {
    background: #fff5f5;
    border-color: #efd0d0;
    color: #a43b3b;
}

.branch-notification.error .branch-notification-icon {
    background: #f8dddd;
    color: #a43b3b;
}

.branch-notification.hide {
    animation: notificationHide .25s ease forwards;
}

@keyframes notificationSlide {
    from {
        opacity: 0;
        transform: translateY(-7px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes notificationHide {
    from {
        opacity: 1;
        transform: translateY(0);
    }

    to {
        opacity: 0;
        transform: translateY(-7px);
    }
}


/* =========================================================
   TABEL CABANG
========================================================= */

.branch-table td {
    vertical-align: middle;
}

.branch-name {
    font-weight: 850;
    color: #173c24;
}

.branch-address {
    margin-top: 4px;
    color: #718078;
    font-size: 10px;
    line-height: 1.5;
}

.branch-phone {
    color: #526058;
    font-size: 11px;
}

.branch-meta {
    margin-top: 4px;
    color: #718078;
    font-size: 10px;
}

.status-active {
    background: #e7f6eb;
    color: #176f3d;
    border: 1px solid #cde8d4;
}

.status-inactive {
    background: #f3f4f4;
    color: #68736d;
    border: 1px solid #dfe4e0;
}

.branch-actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.branch-empty {
    text-align: center;
    padding: 45px 20px !important;
    color: #718078;
}

.branch-empty strong {
    display: block;
    color: #173c24;
    font-size: 14px;
    margin-bottom: 6px;
}


/* =========================================================
   MODAL TAMBAH / EDIT
========================================================= */

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(12, 31, 19, .48);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 9999;
}

.modal-backdrop.show {
    display: flex;
}

.branch-modal {
    width: 100%;
    max-width: 520px;
    background: #fff;
    border-radius: 18px;
    padding: 22px;
    box-shadow: 0 25px 70px rgba(0,0,0,.20);
}

.branch-modal-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 18px;
}

.branch-modal-head h3 {
    margin: 0;
    font-size: 18px;
}

.branch-modal-head p {
    margin: 5px 0 0;
    color: #718078;
    font-size: 10px;
}

.branch-form {
    display: grid;
    gap: 14px;
}

.branch-form label {
    display: block;
    margin-bottom: 6px;
    font-size: 11px;
    font-weight: 800;
    color: #33443a;
}

.branch-form input,
.branch-form textarea,
.branch-form select {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 12px;
    border: 1px solid #dce5de;
    border-radius: 10px;
    background: #fbfdfb;
    color: #24352b;
    font-size: 12px;
    outline: none;
}

.branch-form textarea {
    min-height: 90px;
    resize: vertical;
}

.branch-form input:focus,
.branch-form textarea:focus,
.branch-form select:focus {
    border-color: #176f3d;
    box-shadow: 0 0 0 3px rgba(23,111,61,.08);
}

.branch-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 4px;
}

.btn-danger-outline {
    border: 1px solid #e3caca;
    color: #a43b3b;
    background: #fff;
}

.btn-danger-outline:hover {
    background: #fff5f5;
}


/* =========================================================
   MODAL KONFIRMASI STATUS CABANG
========================================================= */

.branch-confirm-overlay {
    position: fixed;
    inset: 0;

    display: none;
    align-items: center;
    justify-content: center;

    padding: 20px;

    background: rgba(12, 31, 19, .55);

    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);

    z-index: 10001;

    opacity: 0;
    transition: opacity .2s ease;
}

.branch-confirm-overlay.show {
    display: flex;
    opacity: 1;
}

.branch-confirm-modal {
    width: 100%;
    max-width: 430px;

    background: #fff;

    border: 1px solid #e1e9e3;
    border-radius: 20px;

    padding: 24px;

    box-shadow:
        0 28px 80px rgba(0,0,0,.24),
        0 8px 30px rgba(23,60,36,.10);

    transform: translateY(10px) scale(.98);
    transition: transform .2s ease;
}

.branch-confirm-overlay.show .branch-confirm-modal {
    transform: translateY(0) scale(1);
}

.branch-confirm-icon {
    width: 50px;
    height: 50px;

    display: grid;
    place-items: center;

    margin-bottom: 16px;

    border-radius: 15px;

    font-size: 21px;
    font-weight: 900;
}

.branch-confirm-icon.deactivate {
    background: #fff0f0;
    color: #a43b3b;
}

.branch-confirm-icon.activate {
    background: #e7f6eb;
    color: #176f3d;
}

.branch-confirm-kicker {
    display: block;

    margin-bottom: 5px;

    color: #718078;

    font-size: 9px;
    font-weight: 900;

    text-transform: uppercase;
    letter-spacing: .08em;
}

.branch-confirm-content h3 {
    margin: 0;

    color: #173c24;

    font-size: 18px;
    line-height: 1.3;
}

.branch-confirm-content p {
    margin: 8px 0 0;

    color: #68736d;

    font-size: 11px;
    line-height: 1.65;
}

.branch-confirm-branch {
    display: inline-flex;
    align-items: center;

    margin-top: 13px;
    padding: 8px 11px;

    border-radius: 9px;

    background: #f5f8f5;
    border: 1px solid #dfe8e1;

    color: #173c24;

    font-size: 11px;
    font-weight: 850;
}

.branch-confirm-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;

    margin-top: 23px;
}

.branch-confirm-actions .btn {
    min-width: 105px;
}

.branch-confirm-btn {
    border: 0;
    color: #fff;
    font-weight: 850;
}

.branch-confirm-btn.deactivate {
    background: #a43b3b;
}

.branch-confirm-btn.deactivate:hover {
    background: #8e3030;
}

.branch-confirm-btn.activate {
    background: #176f3d;
}

.branch-confirm-btn.activate:hover {
    background: #125b31;
}


@media(max-width: 900px) {

    .branch-summary-grid {
        grid-template-columns: 1fr;
    }

    .branch-page-head {
        align-items: flex-start;
        flex-direction: column;
    }

}


@media(max-width: 620px) {

    .branch-actions {
        flex-direction: column;
    }

    .branch-actions .btn {
        width: 100%;
    }

    .branch-modal {
        padding: 18px;
    }

    .branch-notification {
        padding-right: 40px;
    }

    .branch-confirm-modal {
        padding: 20px;
        border-radius: 17px;
    }

    .branch-confirm-actions {
        flex-direction: column-reverse;
    }

    .branch-confirm-actions .btn {
        width: 100%;
    }

}

</style>


<div class="page-title branch-page-head">

    <div>

        <h2>
            Manajemen Cabang
        </h2>

        <p>
            Kelola daftar cabang toko dan status operasionalnya.
        </p>

    </div>


    <button
        type="button"
        class="btn btn-primary"
        onclick="openBranchModal('add')"
    >
        + Tambah Cabang
    </button>

</div>


<?php if ($message): ?>

<div
    class="branch-notification success"
    id="branchSuccessNotification"
    role="status"
    aria-live="polite"
>

    <div class="branch-notification-icon">
        ✓
    </div>

    <div class="branch-notification-content">

        <span class="branch-notification-title">
            Berhasil
        </span>

        <p class="branch-notification-message">
            <?= esc($message) ?>
        </p>

    </div>

    <button
        type="button"
        class="branch-notification-close"
        onclick="closeBranchNotification('branchSuccessNotification')"
        aria-label="Tutup notifikasi"
        title="Tutup"
    >
        ×
    </button>

</div>

<?php endif; ?>


<?php if ($error): ?>

<div
    class="branch-notification error"
    id="branchErrorNotification"
    role="alert"
    aria-live="assertive"
>

    <div class="branch-notification-icon">
        !
    </div>

    <div class="branch-notification-content">

        <span class="branch-notification-title">
            Perhatian
        </span>

        <p class="branch-notification-message">
            <?= esc($error) ?>
        </p>

    </div>

    <button
        type="button"
        class="branch-notification-close"
        onclick="closeBranchNotification('branchErrorNotification')"
        aria-label="Tutup notifikasi"
        title="Tutup"
    >
        ×
    </button>

</div>

<?php endif; ?>


<!-- =========================================================
     RINGKASAN CABANG
========================================================= -->

<div class="branch-summary-grid">

    <div class="branch-stat-card">

        <small>
            Total Cabang
        </small>

        <strong>
            <?= $total_branches ?>
        </strong>

        <span>
            seluruh cabang terdaftar
        </span>

    </div>


    <div class="branch-stat-card">

        <small>
            Cabang Aktif
        </small>

        <strong>
            <?= $active_branches ?>
        </strong>

        <span>
            dapat digunakan untuk operasional
        </span>

    </div>


    <div class="branch-stat-card">

        <small>
            Cabang Nonaktif
        </small>

        <strong>
            <?= $inactive_branches ?>
        </strong>

        <span>
            tidak digunakan untuk operasional baru
        </span>

    </div>

</div>


<!-- =========================================================
     DAFTAR CABANG
========================================================= -->

<div class="panel">

    <div class="panel-header">

        <div>

            <h3>
                Daftar Cabang
            </h3>

            <p>
                Cabang aktif dapat dipilih untuk operasional, kas, dan laporan.
            </p>

        </div>

    </div>


    <div class="table-wrapper">

        <table class="table branch-table">

            <thead>

                <tr>

                    <th>
                        Cabang
                    </th>

                    <th>
                        Kontak
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Data
                    </th>

                    <th>
                        Aksi
                    </th>

                </tr>

            </thead>

            <tbody>

            <?php if (empty($branches)): ?>

                <tr>

                    <td
                        colspan="5"
                        class="branch-empty"
                    >

                        <strong>
                            Belum ada cabang
                        </strong>

                        Tambahkan cabang pertama untuk mulai mengatur operasional toko.

                    </td>

                </tr>

            <?php else: ?>

                <?php foreach ($branches as $branch): ?>

                    <tr>

                        <td>

                            <div class="branch-name">

                                <?= esc($branch["name"]) ?>

                            </div>

                            <?php if (!empty($branch["address"])): ?>

                                <div class="branch-address">

                                    <?= nl2br(esc($branch["address"])) ?>

                                </div>

                            <?php else: ?>

                                <div class="branch-address">
                                    Alamat belum diisi.
                                </div>

                            <?php endif; ?>

                        </td>


                        <td>

                            <div class="branch-phone">

                                <?= !empty($branch["phone"])
                                    ? esc($branch["phone"])
                                    : "-"
                                ?>

                            </div>

                        </td>


                        <td>

                            <?php if ($branch["status"] === "active"): ?>

                                <span class="badge status-active">
                                    Aktif
                                </span>

                            <?php else: ?>

                                <span class="badge status-inactive">
                                    Nonaktif
                                </span>

                            <?php endif; ?>

                        </td>


                        <td>

                            <div class="branch-meta">

                                <?= (int) $branch["total_users"] ?>
                                pengguna

                            </div>

                            <div class="branch-meta">

                                <?= (int) $branch["total_sales"] ?>
                                transaksi

                            </div>

                        </td>


                        <td>

                            <div class="branch-actions">

                                <button
                                    type="button"
                                    class="btn"
                                    onclick='openBranchModal(
                                        "edit",
                                        <?= json_encode([
                                            "id"      => (int) $branch["id"],
                                            "name"    => $branch["name"],
                                            "address" => $branch["address"],
                                            "phone"   => $branch["phone"],
                                            "status"  => $branch["status"]
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                                    )'
                                >
                                    Edit
                                </button>


                                <!-- FORM STATUS CABANG -->
                                <form
                                    method="POST"
                                    style="display:inline"
                                    class="branch-status-form"
                                    data-branch-name="<?= esc($branch["name"]) ?>"
                                    data-status="<?= esc($branch["status"]) ?>"
                                >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="toggle_status"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int) $branch["id"] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="btn <?= $branch["status"] === "active"
                                            ? "btn-danger-outline"
                                            : ""
                                        ?>"
                                    >

                                        <?= $branch["status"] === "active"
                                            ? "Nonaktifkan"
                                            : "Aktifkan"
                                        ?>

                                    </button>

                                </form>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- =========================================================
     MODAL TAMBAH / EDIT CABANG
========================================================= -->

<div
    id="branchModal"
    class="modal-backdrop"
    onclick="closeBranchModal(event)"
>

    <div
        class="branch-modal"
        onclick="event.stopPropagation()"
    >

        <div class="branch-modal-head">

            <div>

                <h3 id="branchModalTitle">
                    Tambah Cabang
                </h3>

                <p>
                    Isi informasi cabang dengan benar.
                </p>

            </div>


            <button
                type="button"
                class="btn"
                onclick="closeBranchModal()"
                aria-label="Tutup formulir"
            >
                ✕
            </button>

        </div>


        <form
            method="POST"
            class="branch-form"
        >

            <input
                type="hidden"
                name="action"
                id="branchAction"
                value="add"
            >

            <input
                type="hidden"
                name="id"
                id="branchId"
                value=""
            >


            <div>

                <label for="branchName">
                    Nama Cabang
                </label>

                <input
                    type="text"
                    name="name"
                    id="branchName"
                    maxlength="100"
                    required
                    placeholder="Contoh: Cabang 3"
                >

            </div>


            <div>

                <label for="branchAddress">
                    Alamat
                </label>

                <textarea
                    name="address"
                    id="branchAddress"
                    placeholder="Masukkan alamat lengkap cabang..."
                ></textarea>

            </div>


            <div>

                <label for="branchPhone">
                    Nomor Telepon
                </label>

                <input
                    type="text"
                    name="phone"
                    id="branchPhone"
                    maxlength="30"
                    placeholder="Contoh: 08xxxxxxxxxx"
                >

            </div>


            <div>

                <label for="branchStatus">
                    Status
                </label>

                <select
                    name="status"
                    id="branchStatus"
                >

                    <option value="active">
                        Aktif
                    </option>

                    <option value="inactive">
                        Nonaktif
                    </option>

                </select>

            </div>


            <div class="branch-modal-actions">

                <button
                    type="button"
                    class="btn"
                    onclick="closeBranchModal()"
                >
                    Batal
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                >

                    <span id="branchSubmitText">
                        Simpan Cabang
                    </span>

                </button>

            </div>

        </form>

    </div>

</div>


<!-- =========================================================
     MODAL KONFIRMASI STATUS CABANG
========================================================= -->

<div
    id="branchStatusModal"
    class="branch-confirm-overlay"
    aria-hidden="true"
    onclick="closeBranchStatusModal(event)"
>

    <div
        class="branch-confirm-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="branchStatusTitle"
        aria-describedby="branchStatusMessage"
        onclick="event.stopPropagation()"
    >

        <div
            class="branch-confirm-icon"
            id="branchStatusIcon"
        >
            !
        </div>


        <div class="branch-confirm-content">

            <span class="branch-confirm-kicker">
                Konfirmasi Perubahan Status
            </span>

            <h3 id="branchStatusTitle">
                Ubah Status Cabang
            </h3>

            <p id="branchStatusMessage">
                Apakah Anda yakin ingin mengubah status cabang ini?
            </p>

            <div
                class="branch-confirm-branch"
                id="branchStatusBranch"
            >
                -
            </div>

        </div>


        <div class="branch-confirm-actions">

            <button
                type="button"
                class="btn"
                id="branchStatusCancel"
            >
                Batal
            </button>


            <button
                type="button"
                class="btn branch-confirm-btn"
                id="branchStatusConfirm"
            >
                Ya, Lanjutkan
            </button>

        </div>

    </div>

</div>


<script>

/* =========================================================
   NOTIFIKASI
========================================================= */

function closeBranchNotification(id)
{
    const notification =
        document.getElementById(id);

    if (!notification) {
        return;
    }

    notification.classList.add("hide");

    setTimeout(function ()
    {
        if (notification) {
            notification.remove();
        }
    }, 250);
}


document.addEventListener(
    "DOMContentLoaded",
    function ()
    {
        const notifications =
            document.querySelectorAll(
                ".branch-notification"
            );

        notifications.forEach(
            function (notification)
            {
                setTimeout(
                    function ()
                    {
                        closeBranchNotification(
                            notification.id
                        );
                    },
                    4500
                );
            }
        );
    }
);


/* =========================================================
   MODAL TAMBAH / EDIT CABANG
========================================================= */

function openBranchModal(
    mode,
    data = null
)
{
    const modal =
        document.getElementById(
            "branchModal"
        );

    const title =
        document.getElementById(
            "branchModalTitle"
        );

    const action =
        document.getElementById(
            "branchAction"
        );

    const id =
        document.getElementById(
            "branchId"
        );

    const name =
        document.getElementById(
            "branchName"
        );

    const address =
        document.getElementById(
            "branchAddress"
        );

    const phone =
        document.getElementById(
            "branchPhone"
        );

    const status =
        document.getElementById(
            "branchStatus"
        );

    const submitText =
        document.getElementById(
            "branchSubmitText"
        );


    if (
        mode === "edit" &&
        data
    ) {

        title.textContent =
            "Edit Cabang";

        action.value =
            "edit";

        id.value =
            data.id;

        name.value =
            data.name || "";

        address.value =
            data.address || "";

        phone.value =
            data.phone || "";

        status.value =
            data.status || "active";

        submitText.textContent =
            "Simpan Perubahan";

    } else {

        title.textContent =
            "Tambah Cabang";

        action.value =
            "add";

        id.value =
            "";

        name.value =
            "";

        address.value =
            "";

        phone.value =
            "";

        status.value =
            "active";

        submitText.textContent =
            "Simpan Cabang";
    }


    modal.classList.add(
        "show"
    );


    setTimeout(
        function ()
        {
            name.focus();
        },
        100
    );
}


function closeBranchModal(event)
{
    if (
        event &&
        event.target &&
        event.target.id !== "branchModal"
    ) {
        return;
    }

    const modal =
        document.getElementById(
            "branchModal"
        );

    if (modal) {
        modal.classList.remove(
            "show"
        );
    }
}


/* =========================================================
   MODAL KONFIRMASI STATUS CABANG
========================================================= */

let pendingBranchStatusForm = null;


function openBranchStatusModal(form)
{
    const modal =
        document.getElementById(
            "branchStatusModal"
        );

    const title =
        document.getElementById(
            "branchStatusTitle"
        );

    const message =
        document.getElementById(
            "branchStatusMessage"
        );

    const branchNameElement =
        document.getElementById(
            "branchStatusBranch"
        );

    const icon =
        document.getElementById(
            "branchStatusIcon"
        );

    const confirmButton =
        document.getElementById(
            "branchStatusConfirm"
        );


    if (
        !modal ||
        !form
    ) {
        return;
    }


    pendingBranchStatusForm =
        form;


    const branchName =
        form.dataset.branchName ||
        "Cabang ini";

    const currentStatus =
        form.dataset.status ||
        "inactive";

    const isActive =
        currentStatus === "active";


    if (isActive) {

        title.textContent =
            "Nonaktifkan Cabang?";

        message.textContent =
            "Cabang yang dinonaktifkan tidak dapat digunakan untuk operasional baru. Data dan riwayat transaksi tetap tersimpan.";

        icon.textContent =
            "!";

        icon.className =
            "branch-confirm-icon deactivate";

        confirmButton.textContent =
            "Ya, Nonaktifkan";

        confirmButton.className =
            "btn branch-confirm-btn deactivate";

    } else {

        title.textContent =
            "Aktifkan Kembali Cabang?";

        message.textContent =
            "Cabang yang diaktifkan kembali dapat digunakan untuk operasional, kas, dan transaksi sesuai hak akses.";

        icon.textContent =
            "✓";

        icon.className =
            "branch-confirm-icon activate";

        confirmButton.textContent =
            "Ya, Aktifkan";

        confirmButton.className =
            "btn branch-confirm-btn activate";
    }


    branchNameElement.textContent =
        branchName;


    modal.classList.add(
        "show"
    );

    modal.setAttribute(
        "aria-hidden",
        "false"
    );


    setTimeout(
        function ()
        {
            confirmButton.focus();
        },
        100
    );
}


function closeBranchStatusModal(event)
{
    if (
        event &&
        event.target &&
        event.target.id !== "branchStatusModal"
    ) {
        return;
    }

    const modal =
        document.getElementById(
            "branchStatusModal"
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


    pendingBranchStatusForm =
        null;
}


/* =========================================================
   FORM STATUS CABANG
========================================================= */

document.addEventListener(
    "DOMContentLoaded",
    function ()
    {
        const forms =
            document.querySelectorAll(
                ".branch-status-form"
            );


        forms.forEach(
            function (form)
            {
                form.addEventListener(
                    "submit",
                    function (event)
                    {
                        event.preventDefault();

                        openBranchStatusModal(
                            form
                        );
                    }
                );
            }
        );
    }
);


/* =========================================================
   TOMBOL KONFIRMASI
========================================================= */

document.addEventListener(
    "DOMContentLoaded",
    function ()
    {
        const confirmButton =
            document.getElementById(
                "branchStatusConfirm"
            );

        const cancelButton =
            document.getElementById(
                "branchStatusCancel"
            );


        if (confirmButton) {

            confirmButton.addEventListener(
                "click",
                function ()
                {
                    if (
                        !pendingBranchStatusForm
                    ) {
                        closeBranchStatusModal();
                        return;
                    }


                    const form =
                        pendingBranchStatusForm;


                    pendingBranchStatusForm =
                        null;


                    /*
                     * Submit langsung agar
                     * event submit tidak memanggil
                     * modal konfirmasi lagi.
                     */

                    HTMLFormElement
                        .prototype
                        .submit
                        .call(form);
                }
            );
        }


        if (cancelButton) {

            cancelButton.addEventListener(
                "click",
                function ()
                {
                    closeBranchStatusModal();
                }
            );
        }
    }
);


/* =========================================================
   KEYBOARD ESCAPE
========================================================= */

document.addEventListener(
    "keydown",
    function (event)
    {
        if (
            event.key !== "Escape"
        ) {
            return;
        }


        const statusModal =
            document.getElementById(
                "branchStatusModal"
            );


        if (
            statusModal &&
            statusModal.classList.contains(
                "show"
            )
        ) {

            closeBranchStatusModal();

            return;
        }


        closeBranchModal();
    }
);

</script>
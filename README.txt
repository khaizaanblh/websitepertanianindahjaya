TaniKasir - PHP + MySQL untuk Apache/XAMPP

INSTALASI:
1. Salin folder kasir_pertanian ke C:\xampp\htdocs\
2. Jalankan Apache dan MySQL di XAMPP.
3. Buka phpMyAdmin -> Import -> pilih sql/kasir_pertanian.sql
4. Pastikan config/database.php sesuai username/password MySQL.
5. Buka http://localhost/kasir_pertanian/login.php

AKUN DEMO:
Admin: admin / admin123
Kasir: kasir / kasir123

CATATAN:
Versi awal ini sudah mencakup login role, dashboard, produk/stok, pengguna, transaksi kasir, pengurangan stok, riwayat, dan laporan harian. Untuk produksi disarankan menambah CSRF, validasi stok di server, edit/hapus produk, cetak struk, backup database, dan pengaturan toko.

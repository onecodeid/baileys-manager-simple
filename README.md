# WhatsApp Device Manager - Web Panel (PHP)

Web dashboard panel berbasis Native PHP (7.4+) dengan Frontend Vue.js 3 / Vuetify 3 (CDN) untuk mengelola sesi WhatsApp dan berinteraksi dengan API REST Baileys Node.js.

---

## 1. Prerequisites

Sebelum menjalankan dashboard web panel ini, pastikan sistem Anda memiliki:
* **Web Server**: Apache (XAMPP / Laragon / Nginx).
* **PHP**: Versi 7.4 atau lebih baru.
  * Pastikan extension `pdo_mysql` dan `curl` telah diaktifkan di file `php.ini`.
* **MySQL / MariaDB**: Server database aktif.
* **Layanan Backend Node.js**: Service yang berada di folder `s/` harus sudah terinstal dan dijalankan menggunakan PM2 (port default `3000`).

---

## 2. Database Setup

Ikuti langkah-langkah di bawah ini untuk menyiapkan database MySQL:

1. **Buat Database Baru**:
   Buat database kosong bernama `baileys_manager` di server MySQL Anda.
   ```sql
   CREATE DATABASE baileys_manager;
   ```

2. **Import Skema Awal (`db.sql`)**:
   Import file database `db.sql` yang ada di root directory ke dalam database yang baru dibuat:
   ```bash
   mysql -u [username] -p baileys_manager < db.sql
   ```
   *(Atau import melalui phpMyAdmin / TablePlus / DBeaver).*

3. **Terapkan Migrasi Kolom Label (`migrate_add_label.sql`)**:
   Untuk memastikan fitur label kustom sesi berjalan dengan baik, jalankan script migrasi berikut:
   ```bash
   mysql -u [username] -p baileys_manager < migrate_add_label.sql
   ```

4. **Seeding User Default (`seed_users.php` atau `users_app_seed.sql`)**:
   Aplikasi membutuhkan akun login di halaman awal. Anda bisa melakukan seeding user dengan menjalankan perintah CLI PHP berikut dari root folder:
   ```bash
   php seed_users.php
   ```
   Secara default, akun administrator berikut akan dibuat:
   * **Email**: `admin@example.com`
   * **Password**: `admin123`
   
   > **Catatan Keamanan:** Setelah berhasil menjalankan `seed_users.php`, silakan hapus file tersebut dari server demi keamanan.

---

## 3. Instalasi & Konfigurasi Web Panel

1. **Salin & Konfigurasi `.env`**:
   Salin file `.env.example` menjadi `.env` di root directory:
   ```bash
   cp .env.example .env
   ```
   Buka file `.env` baru tersebut, lalu sesuaikan kredensial MySQL Anda:
   ```env
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=baileys_manager
   DB_USER=root
   DB_PASS=YOUR_DB_PASSWORD
   ```

2. **Jalankan Aplikasi**:
   * **Melalui Local PHP Server (Development)**:
     Anda dapat menjalankan server bawaan PHP langsung dari root directory:
     ```bash
     php -S localhost:8000
     ```
     Lalu buka browser di alamat [http://localhost:8000](http://localhost:8000).
   * **Melalui Apache (XAMPP / Laragon)**:
     Pindahkan folder `baileys-manager-simple` ini ke dalam folder `htdocs` atau `www`, lalu akses melalui virtual host atau URL lokal (misal: `http://localhost/baileys-manager-simple`).

---

## 4. Cara Penggunaan Panel

1. Pastikan Service API Node.js di folder `/s` sudah aktif dan berjalan di PM2.
2. Buka dashboard web panel di browser, lalu login dengan email `admin@example.com` dan password `admin123`.
3. Masuk ke tab **WhatsApp Sessions** untuk menambahkan slot session baru.
4. Scan kode QR yang muncul menggunakan WhatsApp Anda (**Linked Devices / Perangkat Tertaut**).
5. Setelah statusnya **CONNECTED**, Anda dapat bereksperimen mengirim pesan via tab **Playground** atau menggunakan cURL/PHP SDK contoh yang ada di tab **API Tutorial & Code Examples**.

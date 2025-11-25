# Personal Finance Tracker

Aplikasi pencatat keuangan pribadi sederhana dengan konsep Kakeibo (Needs, Wants, Culture, Unexpected). Dibuat dengan PHP native dan SQLite, sangat cocok untuk shared hosting.

## ✨ Fitur Utama

- **Single File Application** - Hanya satu file PHP + satu database SQLite
- **Kakeibo Categories** - Manajemen keuangan berdasarkan 4 kategori: Needs, Wants, Culture, Unexpected
- **Transaction Management** - Tambah, edit, hapus transaksi dengan fields lengkap
- **Interactive Charts** - Visualisasi data dengan SVG (pie chart & line chart)
- **Filter & Search** - Filter berdasarkan tanggal, kategori, dan cari berdasarkan deskripsi
- **Export Data** - Export transaksi ke format CSV
- **Responsive Design** - Tampilan mobile-friendly
- **Secure** - Dilengkapi CSRF protection, SQL injection prevention, dan XSS prevention

## 🚀 Teknologi

- **Backend**: PHP 7.4+ (native, tanpa framework)
- **Database**: SQLite 3
- **Charts**: SVG (native, tanpa library eksternal)
- **Frontend**: HTML5, CSS3, Vanilla JavaScript
- **Security**: Prepared statements, CSRF tokens, input validation

## 📋 Persyaratan Sistem

- PHP 7.4 atau lebih baru
- Ekstensi SQLite untuk PHP (pdo_sqlite)
- Web server (Apache/Nginx)
- Permissions write untuk directory aplikasi

## 📦 Instalasi

### 1. Download Files
```
/home/your-directory/
├── index.php          (aplikasi utama)
└── README.md          (dokumentasi)
```

### 2. Set Permissions
```bash
chmod 755 /path/to/your/directory/
chmod 644 /path/to/your/index.php
```

### 3. Upload ke Server
Upload semua file ke directory web server Anda (public_html, www, atau htdocs).

### 4. Akses Aplikasi
Buka browser dan akses: `http://your-domain.com/path-to-app/`

Database SQLite akan otomatis dibuat saat pertama kali aplikasi dijalankan.

## 📖 Panduan Penggunaan

### Dashboard
Dashboard menampilkan:
- **Summary Cards**: Total Income, Total Expense, Net Balance
- **Transaction Form**: Form untuk menambah transaksi baru
- **Charts**: Pie chart distribusi expense dan line chart trend bulanan
- **Filters**: Filter dan search untuk transactions
- **Transactions Table**: Daftar semua transaksi dengan actions

### Menambah Transaksi

1. Pilih tipe transaksi (Expense/Income)
2. Isi tanggal transaksi
3. Isi jumlah (angka positif)
4. Pilih kategori (Needs/Wants/Culture/Unexpected)
5. Pilih subkategori (otomatis terisi berdasarkan kategori)
6. Isi metode pembayaran (opsional)
7. Isi deskripsi transaksi
8. Klik "Add Transaction"

**Catatan**: Expense akan disimpan sebagai angka negatif (untuk perhitungan), Income sebagai angka positif.

### Subkategori Default

**Needs:**
- Housing
- Food & Groceries
- Transportation
- Healthcare
- Insurance

**Wants:**
- Entertainment
- Dining Out
- Shopping
- Travel

**Culture:**
- Education
- Books & Media
- Hobbies

**Unexpected:**
- Emergency
- Car Repair
- Medical Emergency

### Filter & Search

- **Date Range**: Filter transaksi berdasarkan rentang tanggal
- **Category**: Filter berdasarkan kategori Kakeibo
- **Search**: Cari transaksi berdasarkan deskripsi
- **Export CSV**: Download data hasil filter dalam format CSV

### Edit/Hapus Transaksi

- Klik tombol "Edit" pada row transaksi untuk mengedit
- Klik tombol "Delete" untuk menghapus transaksi
- Konfirmasi diperlukan sebelum menghapus

## 🗂️ Struktur Database

### Table: transactions
| Field | Type | Description |
|-------|------|-------------|
| id | INTEGER PRIMARY KEY | Unique transaction ID |
| tanggal | DATE | Transaction date |
| jumlah | DECIMAL(15,2) | Amount (negative for expense, positive for income) |
| kategori | VARCHAR(50) | Kakeibo category |
| subkategori | VARCHAR(100) | Subcategory |
| deskripsi | TEXT | Transaction description |
| metode_pembayaran | VARCHAR(50) | Payment method |
| created_at | DATETIME | Creation timestamp |
| updated_at | DATETIME | Last update timestamp |

### Table: categories
| Field | Type | Description |
|-------|------|-------------|
| id | INTEGER PRIMARY KEY | Unique ID |
| category_name | VARCHAR(50) | Category name |
| subcategory_name | VARCHAR(100) | Subcategory name |
| is_default | BOOLEAN | Whether it's a default subcategory |

## 🔒 Keamanan

Aplikasi ini mengimplementasikan beberapa lapisan keamanan:

1. **CSRF Protection**
   - Setiap form memiliki CSRF token
   - Token diverifikasi sebelum memproses request

2. **SQL Injection Prevention**
   - Menggunakan prepared statements untuk semua query database
   - Tidak ada string concatenation dalam query SQL

3. **XSS Prevention**
   - Semua output user di-escape dengan htmlspecialchars()
   - Validasi dan sanitasi input

4. **Input Validation**
   - Validasi required fields
   - Validasi format data (tanggal, angka, dll)
   - Validasi kategori dan subkategori

5. **File Permissions**
   - Database file permissions diset ke 0660
   - Hanya owner dan group yang bisa read/write

## 🎨 Kustomisasi

### Mengubah Warna Kategori

Edit konstanta `$CATEGORY_COLORS` di file index.php:

```php
$CATEGORY_COLORS = [
    'Needs' => '#3498db',       // Blue
    'Wants' => '#2ecc71',       // Green
    'Culture' => '#9b59b6',     // Purple
    'Unexpected' => '#e74c3c'   // Red
];
```

### Menambah Subkategori

Edit function `getSubcategories()` dan JavaScript `subcategories` object untuk menambah subkategori baru.

### Mengubah Tema CSS

Edit section `<style>` dalam file index.php untuk mengubah tampilan aplikasi.

## 📊 Grafik

Grafik dibuat menggunakan SVG native tanpa library eksternal:

- **Pie Chart**: Menampilkan distribusi expense berdasarkan kategori
- **Line Chart**: Menampilkan trend expense bulanan (6 bulan terakhir)
- **Summary Cards**: Menampilkan total income, expense, dan net balance

## 💾 Export Data

### CSV Export

- Klik tombol "Export CSV" pada section filters
- Data yang di-export sesuai dengan filter yang aktif
- Format: Tanggal;Jumlah;Kategori;Subkategori;Deskripsi;Metode Pembayaran
- Compatible dengan Excel dan Google Sheets

## 🔧 Troubleshooting

### Database Error
```
Error: Database connection failed
```
**Solusi**:
1. Pastikan ekstensi PHP SQLite sudah terinstall
2. Periksa permissions directory dan file
3. Pastikan web server memiliki write permission

### Charts Tidak Muncul
**Solusi**:
1. Periksa apakah error reporting dimatikan di production
2. Pastikan browser mendukung SVG
3. Clear browser cache

### Transaksi Tidak Bisa Ditambah
**Solusi**:
1. Periksa semua field required sudah terisi
2. Pastikan tanggal dalam format yang benar (YYYY-MM-DD)
3. Pastikan jumlah dalam format angka (gunakan titik untuk desimal)

### Export CSV Error
**Solusi**:
1. Pastikan browser mengizinkan download
2. Check apakah ada output sebelum header CSV
3. Pastikan memory limit PHP cukup untuk data besar

## 📈 Optimasi Performa

### Untuk Data Banyak

1. **Pagination**: Tambahkan pagination untuk transactions table
2. **Index**: Database sudah memiliki index pada kolom yang sering diquery
3. **Limit**: Batasi data yang dimuat untuk charts (sudah menggunakan 6 bulan terakhir)

### Untuk Shared Hosting

1. **Caching**: Enable opcode cache (OPcache)
2. **Compression**: Enable gzip compression
3. **Database Size**: Backup dan rotate database secara berkala

## 🔄 Maintenance

### Backup Database

```bash
# Manual backup
cp finance.db finance_backup_$(date +%Y%m%d).db

# Restore backup
cp finance_backup_20240101.db finance.db
```

### Clear Old Data

```sql
-- Hapus transaksi lebih dari 2 tahun
DELETE FROM transactions WHERE tanggal < date('now', '-2 years');
```

### Optimize Database

```sql
-- Vacuum dan analyze untuk optimasi
VACUUM;
ANALYZE;
```

## 🐛 Known Issues

1. Subkategori tidak bisa di-manage dari UI (hardcoded dalam aplikasi)
2. PDF export belum diimplementasikan (cukup gunakan browser's Print to PDF)
3. Multi-user belum didukung (single-user application)

## 📝 Changelog

### Version 1.0.0
- Initial release
- Basic CRUD operations
- Kakeibo categories
- SVG charts (pie & line)
- CSV export
- Filter & search
- Responsive design
- Security features (CSRF, SQL injection prevention, XSS prevention)

## 📄 License

This project is open source and available under the MIT License.

## 👨‍💻 Author

Created with ❤️ using PHP & SQLite

## 🤝 Contributing

Feel free to fork this project and submit pull requests for improvements!

## 📞 Support

If you encounter any issues or have questions, please open an issue in the repository.
# Simple Expense Tracker

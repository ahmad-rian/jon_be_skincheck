# Deploy Laravel ke aaPanel

## Prasyarat Server

| Kebutuhan  | Minimum           |
|------------|--------------------|
| PHP        | 8.3+               |
| MySQL      | 8.0+               |
| Node.js    | 18+                |
| Composer   | 2.x                |
| RAM        | 1 GB+              |

---

## Step 1: Install Software di aaPanel

Buka **aaPanel > App Store**, install:

- **Nginx** (atau Apache)
- **MySQL 8.0+**
- **PHP 8.3** (atau 8.4)
- **phpMyAdmin** (opsional)

### Install PHP Extensions

Buka **aaPanel > App Store > PHP 8.3 > Settings > Install Extensions**, pastikan aktif:

- `fileinfo`
- `opcache`
- `mbstring`
- `xml`
- `curl`
- `zip`
- `bcmath`
- `pdo_mysql`
- `openssl`
- `tokenizer`
- `json`
- `ctype`

### Disable PHP Functions yang Diblokir

Buka **aaPanel > App Store > PHP 8.3 > Settings > Disabled Functions**, hapus fungsi berikut dari daftar (Laravel butuh ini):

```
putenv
proc_open
pcntl_signal
pcntl_alarm
```

---

## Step 2: Install Node.js & Composer

SSH ke server:

```bash
# Install Node.js 18+ via nvm
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
source ~/.bashrc
nvm install 18
nvm use 18

# Pastikan composer sudah ada (aaPanel biasanya sudah install)
composer --version
```

---

## Step 3: Buat Database

Buka **aaPanel > Databases > Add Database**:

| Field    | Value        |
|----------|--------------|
| Name     | `skincheck`  |
| Username | `skincheck`  |
| Password | (buat password kuat) |
| Encoding | `utf8mb4`    |

---

## Step 4: Upload Project

### Opsi A: Upload via Git (Rekomendasi)

```bash
cd /www/wwwroot/
git clone git@github.com:ahmad-rian/jon_be_skincheck.git yourdomain.com
cd yourdomain.com
```

Atau menggunakan HTTPS:

```bash
git clone https://github.com/ahmad-rian/jon_be_skincheck.git yourdomain.com
```

### Opsi B: Upload ZIP via aaPanel

1. Download ZIP dari https://github.com/ahmad-rian/jon_be_skincheck
2. Upload ke `/www/wwwroot/yourdomain.com/` via aaPanel File Manager
3. Extract

---

## Step 5: Install Dependencies & Build

```bash
cd /www/wwwroot/yourdomain.com

# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Install Node dependencies & build frontend
npm install
npm run build

# Setelah build selesai, node_modules bisa dihapus untuk hemat space
rm -rf node_modules
```

---

## Step 6: Konfigurasi Environment

```bash
# Copy environment file
cp .env.example .env

# Generate app key
php artisan key:generate
```

Edit `.env`:

```env
APP_NAME="SkinCheck AI"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=skincheck
DB_USERNAME=skincheck
DB_PASSWORD=password_database_kamu

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

GROQ_API_KEY=your_groq_api_key_here
GROQ_MODEL=llama-3.3-70b-versatile
```

Jalankan migration:

```bash
php artisan migrate --force
```

---

## Step 7: Set Permissions

```bash
cd /www/wwwroot/yourdomain.com

# Set ownership
chown -R www:www .

# Set directory permissions
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;

# Storage & cache harus writable
chmod -R 775 storage bootstrap/cache
chown -R www:www storage bootstrap/cache
```

---

## Step 8: Buat Website di aaPanel

Buka **aaPanel > Websites > Add Site**:

| Field       | Value                                    |
|-------------|------------------------------------------|
| Domain      | `yourdomain.com`                         |
| Root Dir    | `/www/wwwroot/yourdomain.com/public`     |
| PHP Version | PHP 8.3                                  |
| Database    | (sudah dibuat di Step 3)                 |

> **PENTING**: Root directory harus mengarah ke folder `/public`, bukan root project.

---

## Step 9: Konfigurasi Nginx

Buka **aaPanel > Websites > yourdomain.com > Settings > Site Config**

Ganti isi konfigurasi Nginx (di dalam block `server { }`) menjadi:

```nginx
server {
    listen 80;
    listen 443 ssl http2;
    server_name yourdomain.com www.yourdomain.com;

    root /www/wwwroot/yourdomain.com/public;
    index index.php index.html;

    # SSL (di-manage oleh aaPanel, biarkan baris SSL yang sudah ada)

    # Laravel routing
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/tmp/php-cgi-83.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # Timeout untuk API yang butuh waktu lama (Groq AI)
        fastcgi_read_timeout 120;
    }

    # Block akses ke file sensitif
    location ~ /\.(?!well-known) {
        deny all;
    }

    location ~ \.(env|log|md)$ {
        deny all;
    }

    # Cache static assets
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2|woff|ttf)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    access_log /www/wwwlogs/yourdomain.com.log;
    error_log /www/wwwlogs/yourdomain.com.error.log;
}
```

> **Note**: Sesuaikan `fastcgi_pass` dengan versi PHP kamu. Cek path socket di aaPanel > PHP Settings.

---

## Step 10: SSL Certificate

Buka **aaPanel > Websites > yourdomain.com > Settings > SSL**:

1. Pilih **Let's Encrypt**
2. Centang domain
3. Klik **Apply**
4. Aktifkan **Force HTTPS**

---

## Step 11: Optimasi Production

```bash
cd /www/wwwroot/yourdomain.com

# Cache konfigurasi, routes, views
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Optimize autoloader
composer dump-autoload --optimize
```

---

## Step 12: Setup Queue Worker (Opsional)

Jika menggunakan queue/jobs, buat Supervisor di aaPanel.

Buka **aaPanel > App Store > Supervisor** (install jika belum ada).

Buat proses baru:

| Field       | Value                                                            |
|-------------|------------------------------------------------------------------|
| Name        | `laravel-queue`                                                  |
| Command     | `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`    |
| Directory   | `/www/wwwroot/yourdomain.com`                                    |
| User        | `www`                                                            |
| Processes   | `1`                                                              |

---

## Step 13: Setup Cron (Scheduler)

Buka **aaPanel > Cron Jobs > Add Cron Job**:

| Field    | Value                                                                   |
|----------|-------------------------------------------------------------------------|
| Type     | Shell Script                                                            |
| Name     | `laravel-scheduler`                                                     |
| Period   | Every 1 Minute                                                          |
| Script   | `cd /www/wwwroot/yourdomain.com && php artisan schedule:run >> /dev/null 2>&1` |

---

## Verifikasi

Setelah semua selesai, test API:

```bash
# Dari server
curl "https://yourdomain.com/api/skin-article?q=Eczema&cf=85"

# Harus return JSON dengan status: true
```

Cek halaman test di browser:

```
https://yourdomain.com/skin-article-test
```

---

## Troubleshooting

### 500 Internal Server Error
```bash
# Cek Laravel log
tail -50 /www/wwwroot/yourdomain.com/storage/logs/laravel.log

# Cek permissions
chown -R www:www storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### 502 Bad Gateway / API timeout
```bash
# Naikkan PHP timeout
# aaPanel > PHP 8.3 > Settings > Performance
# Set max_execution_time = 120

# Naikkan Nginx timeout di site config
# fastcgi_read_timeout 120;
```

### CORS Error (jika dipanggil dari domain lain)
Tambahkan middleware CORS atau install:
```bash
# Laravel sudah punya CORS built-in, edit config/cors.php
php artisan config:publish cors
```

### Groq API Error
```bash
# Test koneksi ke Groq dari server
curl -X POST https://api.groq.com/openai/v1/chat/completions \
  -H "Authorization: Bearer $GROQ_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"model":"llama-3.3-70b-versatile","messages":[{"role":"user","content":"hi"}]}'
```

### PubMed API Lambat
PubMed terkadang lambat dari server tertentu. Pastikan `allow_url_fopen = On` di PHP settings dan tidak ada firewall yang memblokir outbound HTTPS.

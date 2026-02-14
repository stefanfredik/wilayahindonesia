# Wilayah REST API

Quick reference untuk endpoint API. Dokumentasi lengkap ada di [README.md](../README.md).

## Base URL

```
http://localhost:8080/api
```

## Endpoint

| Method | Endpoint                          | Keterangan                           |
|--------|-----------------------------------|--------------------------------------|
| GET    | `/api`                            | Health check & daftar endpoint       |
| GET    | `/api/provinces`                  | Semua provinsi                       |
| GET    | `/api/regencies`                  | Kabupaten/kota                       |
| GET    | `/api/districts`                  | Kecamatan                            |
| GET    | `/api/villages`                   | Kelurahan/desa                       |
| GET    | `/api/regions`                    | Query generic (level + parent)       |
| GET    | `/api/search?q={keyword}`         | Pencarian wilayah                    |
| GET    | `/api/{code}`                     | Detail wilayah + children            |
| GET    | `/api/boundaries/{code}`          | Boundary polygon                     |
| GET    | `/api/boundaries/{code}?format=geojson` | Boundary GeoJSON Feature       |
| GET    | `/api/boundaries/{code}/children` | Children GeoJSON FeatureCollection   |

## Parameter Umum

| Parameter    | Tersedia di                     | Keterangan                              |
|--------------|---------------------------------|-----------------------------------------|
| `boundaries` | provinces, regencies, detail    | `1` = sertakan polygon boundary         |
| `limit`      | semua list endpoint             | Jumlah per halaman (default 100)        |
| `offset`     | semua list endpoint             | Offset pagination                       |
| `format`     | boundaries                      | `geojson` untuk output GeoJSON          |

## Kode Wilayah

```
Level 1 (Provinsi)     : 51            → Bali
Level 2 (Kabupaten)    : 51.04         → Gianyar
Level 3 (Kecamatan)    : 51.04.01      → Sukawati
Level 4 (Desa)         : 51.04.01.2003 → Guwang
```

Input fleksibel: `5104` dan `51.04` keduanya valid.

## Konfigurasi

Copy `.env.example` → `.env`:

```env
DB_HOST=mysql
DB_NAME=wilayah
DB_USER=wilayah
DB_PASS=wilayah
DB_CHARSET=utf8mb4
```

## Struktur

```
api/
├── index.php              # Router utama
├── .htaccess              # URL rewriting (Apache)
├── .env / .env.example    # Konfigurasi database
├── test.php               # Smoke test (php api/test.php)
├── config/
│   └── database.php       # PDO singleton
├── helpers/
│   └── functions.php      # Helper functions
└── handlers/
    ├── provinces.php
    ├── regencies.php
    ├── districts.php
    ├── villages.php
    ├── regions.php
    ├── search.php
    ├── region_detail.php
    └── boundaries.php
```
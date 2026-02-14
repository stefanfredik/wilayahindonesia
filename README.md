# Wilayah API

RESTful API untuk data wilayah administratif Indonesia lengkap dengan **batas wilayah (boundaries)** hingga level desa/kelurahan.

**Data source:** Kepmendagri No 300.2.2-2138 Tahun 2025

---

## Daftar Isi

- [Instalasi](#instalasi)
- [Konfigurasi](#konfigurasi)
- [Struktur Database](#struktur-database)
- [Format Kode Wilayah](#format-kode-wilayah)
- [Endpoint API](#endpoint-api)
  - [Health Check](#1-health-check)
  - [Provinsi](#2-provinsi)
  - [Kabupaten/Kota](#3-kabupatenkota)
  - [Kecamatan](#4-kecamatan)
  - [Kelurahan/Desa](#5-kelurahandesa)
  - [Region Generic](#6-region-generic)
  - [Detail Wilayah](#7-detail-wilayah)
  - [Pencarian](#8-pencarian)
  - [Boundary Wilayah](#9-boundary-wilayah)
  - [Boundary GeoJSON](#10-boundary-geojson)
  - [Boundary Children](#11-boundary-children-geojson-featurecollection)
- [Format Response](#format-response)
- [Contoh Penggunaan](#contoh-penggunaan)
- [Struktur Project](#struktur-project)

---

## Instalasi

### Prasyarat

- Docker & Docker Compose

### Langkah

```bash
# 1. Clone repository
git clone <repo-url> wilayah
cd wilayah

# 2. Copy konfigurasi environment
cp .env.example .env

# 3. Jalankan dengan Docker Compose
docker compose up -d --build

# 4. Tunggu database siap (± 2-5 menit untuk import data awal)
docker compose logs -f mysql

# 5. API siap di http://localhost:8080/api
curl http://localhost:8080/api
```

### Menghentikan

```bash
docker compose down        # Stop tanpa hapus data
docker compose down -v     # Stop + hapus data (reset database)
```

---

## Konfigurasi

Environment variable dapat diatur di file `.env`:

| Variable             | Default   | Keterangan                  |
|----------------------|-----------|-----------------------------|
| `APP_PORT`           | `8080`    | Port aplikasi               |
| `DB_PORT`            | `3306`    | Port MySQL                  |
| `DB_NAME`            | `wilayah` | Nama database               |
| `DB_USER`            | `wilayah` | Username database           |
| `DB_PASS`            | `wilayah` | Password database           |
| `MYSQL_ROOT_PASSWORD`| `secret`  | Password root MySQL         |
| `PMA_PORT`           | `8888`    | Port phpMyAdmin (opsional)  |

---

## Struktur Database

| Tabel                | Jumlah Record | Keterangan                                      |
|----------------------|---------------|-------------------------------------------------|
| `wilayah`            | 91.599        | Kode + nama semua level (provinsi s/d desa)     |
| `wilayah_level_1_2`  | 552           | Detail provinsi & kabupaten (lat, lng, boundary) |
| `wilayah_boundaries` | 90.803        | Polygon batas wilayah semua level                |
| `wilayah_luas`       | —             | Data luas wilayah (km²)                         |
| `wilayah_penduduk`   | —             | Data jumlah penduduk                            |
| `wilayah_pulau`      | —             | Data kepulauan                                  |

### Tabel `wilayah_boundaries`

| Kolom    | Tipe          | Keterangan                                        |
|----------|---------------|---------------------------------------------------|
| `kode`   | varchar(13)   | Kode wilayah (primary key)                        |
| `nama`   | varchar(100)  | Nama wilayah                                      |
| `lat`    | double        | Latitude centroid                                 |
| `lng`    | double        | Longitude centroid                                |
| `path`   | longtext      | Polygon boundary dalam format JSON `[[[lat,lng]]]`|
| `status` | int           | Status data                                       |

---

## Format Kode Wilayah

Kode wilayah menggunakan format bertitik:

| Level | Format            | Contoh          | Keterangan      |
|-------|-------------------|-----------------|-----------------|
| 1     | `XX`              | `51`            | Provinsi        |
| 2     | `XX.XX`           | `51.04`         | Kabupaten/Kota  |
| 3     | `XX.XX.XX`        | `51.04.01`      | Kecamatan       |
| 4     | `XX.XX.XX.XXXX`   | `51.04.01.2003` | Kelurahan/Desa  |

> API juga menerima input tanpa titik: `5104` → `51.04`, `510401` → `51.04.01`, `5104012003` → `51.04.01.2003`

---

## Endpoint API

Base URL: `http://localhost:8080/api`

### 1. Health Check

```
GET /api
GET /api/health
```

Response: status API, versi, dan daftar endpoint.

**Contoh:**
```bash
curl http://localhost:8080/api
```

---

### 2. Provinsi

```
GET /api/provinces
```

| Parameter    | Tipe   | Default | Keterangan                 |
|--------------|--------|---------|----------------------------|
| `boundaries` | bool   | `false` | Sertakan polygon boundary  |
| `limit`      | int    | `100`   | Maks 1000                  |
| `offset`     | int    | `0`     | Pagination offset          |

**Contoh:**
```bash
# Daftar semua provinsi
curl "http://localhost:8080/api/provinces"

# Dengan boundary polygon
curl "http://localhost:8080/api/provinces?boundaries=1"

# Pagination
curl "http://localhost:8080/api/provinces?limit=10&offset=0"
```

**Response:**
```json
{
  "status": true,
  "data": [
    {
      "code": "51",
      "name": "Bali",
      "level": 1,
      "capital": "Denpasar",
      "coordinates": {
        "latitude": -8.2324587,
        "longitude": 115.1635082
      },
      "elevation": null,
      "timezone": 8,
      "area": 5590.49,
      "population": 4317404,
      "status": "1"
    }
  ],
  "meta": {
    "total": 38,
    "count": 38,
    "limit": 100,
    "offset": 0,
    "boundaries": false
  }
}
```

---

### 3. Kabupaten/Kota

```
GET /api/regencies
```

| Parameter    | Tipe   | Default | Keterangan                   |
|--------------|--------|---------|------------------------------|
| `province`   | string | —       | Filter per provinsi (2 digit)|
| `boundaries` | bool   | `false` | Sertakan polygon boundary    |
| `limit`      | int    | `100`   | Maks 1000                    |
| `offset`     | int    | `0`     | Pagination offset            |

**Contoh:**
```bash
# Semua kabupaten/kota
curl "http://localhost:8080/api/regencies"

# Kabupaten di Bali
curl "http://localhost:8080/api/regencies?province=51"

# Dengan boundary
curl "http://localhost:8080/api/regencies?province=51&boundaries=1"
```

---

### 4. Kecamatan

```
GET /api/districts
```

| Parameter  | Tipe   | Default | Keterangan                          |
|------------|--------|---------|-------------------------------------|
| `regency`  | string | —       | Filter per kabupaten (`XX.XX`)      |
| `province` | string | —       | Filter per provinsi (2 digit)       |
| `limit`    | int    | `100`   | Maks 1000                           |
| `offset`   | int    | `0`     | Pagination offset                   |

**Contoh:**
```bash
# Kecamatan di Kab. Gianyar
curl "http://localhost:8080/api/districts?regency=51.04"

# Atau tanpa titik
curl "http://localhost:8080/api/districts?regency=5104"

# Semua kecamatan di Bali
curl "http://localhost:8080/api/districts?province=51"
```

**Response:**
```json
{
  "status": true,
  "data": [
    {
      "code": "51.04.01",
      "name": "Sukawati",
      "level": 3,
      "coordinates": {
        "latitude": -8.59481913516794,
        "longitude": 115.27362828937953
      },
      "area": 34.59,
      "population": 130645,
      "province_code": "51",
      "regency_code": "51.04"
    }
  ],
  "meta": {
    "total": 7,
    "count": 7,
    "limit": 100,
    "offset": 0
  }
}
```

---

### 5. Kelurahan/Desa

```
GET /api/villages
```

| Parameter  | Tipe   | Default | Keterangan                           |
|------------|--------|---------|--------------------------------------|
| `district` | string | —       | Filter per kecamatan (`XX.XX.XX`)    |
| `regency`  | string | —       | Filter per kabupaten (`XX.XX`)       |
| `province` | string | —       | Filter per provinsi (2 digit)        |
| `limit`    | int    | `100`   | Maks 1000                            |
| `offset`   | int    | `0`     | Pagination offset                    |

**Contoh:**
```bash
# Desa di Kec. Sukawati
curl "http://localhost:8080/api/villages?district=51.04.01"

# Semua desa di Kab. Gianyar (pakai pagination)
curl "http://localhost:8080/api/villages?regency=51.04&limit=50&offset=0"

# Semua desa di Bali
curl "http://localhost:8080/api/villages?province=51&limit=100"
```

**Response:**
```json
{
  "status": true,
  "data": [
    {
      "code": "51.04.01.2003",
      "name": "Guwang",
      "level": 4,
      "coordinates": {
        "latitude": -8.613737044920835,
        "longitude": 115.28525139339001
      },
      "area": 2.63,
      "population": 7892,
      "province_code": "51",
      "regency_code": "51.04",
      "district_code": "51.04.01"
    }
  ],
  "meta": {
    "total": 12,
    "count": 12,
    "limit": 100,
    "offset": 0
  }
}
```

---

### 6. Region Generic

```
GET /api/regions
```

Endpoint universal untuk query wilayah apapun.

| Parameter    | Tipe   | Default | Keterangan                          |
|--------------|--------|---------|-------------------------------------|
| `level`      | int    | —       | 1=provinsi, 2=kab, 3=kec, 4=desa   |
| `parent`     | string | —       | Kode wilayah induk                  |
| `limit`      | int    | `100`   | Maks 1000                           |
| `offset`     | int    | `0`     | Pagination offset                   |

**Contoh:**
```bash
# Semua provinsi
curl "http://localhost:8080/api/regions?level=1"

# Kabupaten di Bali
curl "http://localhost:8080/api/regions?level=2&parent=51"

# Kecamatan di Gianyar
curl "http://localhost:8080/api/regions?level=3&parent=51.04"

# Desa di Sukawati
curl "http://localhost:8080/api/regions?level=4&parent=51.04.01"
```

---

### 7. Detail Wilayah

```
GET /api/{kode}
```

Mengembalikan detail lengkap satu wilayah beserta daftar wilayah anak (children).

| Parameter    | Tipe   | Default | Keterangan                          |
|--------------|--------|---------|-------------------------------------|
| `boundaries` | bool   | `false` | Sertakan boundary pada children     |

**Contoh:**
```bash
# Detail Provinsi Bali + daftar kabupaten
curl "http://localhost:8080/api/51"

# Detail Kab. Gianyar + daftar kecamatan
curl "http://localhost:8080/api/51.04"

# Detail Kec. Sukawati + daftar desa
curl "http://localhost:8080/api/51.04.01"

# Detail Kec. Sukawati + daftar desa DENGAN boundary
curl "http://localhost:8080/api/51.04.01?boundaries=1"

# Detail Desa Guwang
curl "http://localhost:8080/api/51.04.01.2003"
```

**Response (detail desa):**
```json
{
  "status": true,
  "data": {
    "code": "51.04.01.2003",
    "name": "Guwang",
    "level": 4,
    "coordinates": {
      "latitude": -8.613737044920835,
      "longitude": 115.28525139339001
    },
    "area": 2.63,
    "population": 7892,
    "province_code": "51",
    "regency_code": "51.04",
    "district_code": "51.04.01",
    "boundaries": [
      [
        [-8.613810959, 115.297091995],
        [-8.614198993, 115.296248031],
        "... polygon coordinates ..."
      ]
    ],
    "children": []
  }
}
```

---

### 8. Pencarian

```
GET /api/search
```

| Parameter | Tipe   | Default | Keterangan                          |
|-----------|--------|---------|-------------------------------------|
| `q`       | string | —       | **Wajib**. Kata kunci pencarian     |
| `level`   | int    | —       | Filter level (1-4)                  |
| `limit`   | int    | `20`    | Maks 100                            |

**Contoh:**
```bash
# Cari "Guwang"
curl "http://localhost:8080/api/search?q=guwang"

# Cari "Sukawati" hanya level desa
curl "http://localhost:8080/api/search?q=sukawati&level=4"

# Cari "Gianyar" semua level
curl "http://localhost:8080/api/search?q=gianyar"
```

---

### 9. Boundary Wilayah

```
GET /api/boundaries/{kode}
```

Polygon batas wilayah. Mendukung **semua level** (provinsi s/d desa).

| Parameter | Tipe   | Default | Keterangan                         |
|-----------|--------|---------|------------------------------------|
| `format`  | string | `raw`   | `raw` atau `geojson`               |

**Contoh:**
```bash
# Boundary Provinsi Bali
curl "http://localhost:8080/api/boundaries/51"

# Boundary Kab. Gianyar
curl "http://localhost:8080/api/boundaries/51.04"

# Boundary Kec. Sukawati
curl "http://localhost:8080/api/boundaries/51.04.01"

# Boundary Desa Guwang
curl "http://localhost:8080/api/boundaries/51.04.01.2003"
```

**Response (raw):**
```json
{
  "status": true,
  "data": {
    "code": "51.04.01.2003",
    "name": "Guwang",
    "center": {
      "latitude": -8.613737044920835,
      "longitude": 115.28525139339001
    },
    "boundaries": [
      [
        [-8.613810959, 115.297091995],
        [-8.614198993, 115.296248031],
        [-8.614604010, 115.292816028],
        "... titik koordinat [lat, lng] ..."
      ]
    ]
  }
}
```

---

### 10. Boundary GeoJSON

```
GET /api/boundaries/{kode}?format=geojson
```

Output dalam format **GeoJSON Feature** standar (koordinat `[lng, lat]`).

**Contoh:**
```bash
curl "http://localhost:8080/api/boundaries/51.04.01.2003?format=geojson"
```

**Response:**
```json
{
  "type": "Feature",
  "properties": {
    "code": "51.04.01.2003",
    "name": "Guwang"
  },
  "geometry": {
    "type": "Polygon",
    "coordinates": [
      [
        [115.297091995, -8.613810959],
        [115.296248031, -8.614198993],
        "... koordinat [lng, lat] sesuai spec GeoJSON ..."
      ]
    ]
  }
}
```

> Dapat langsung digunakan di Leaflet.js, Mapbox, Google Maps, QGIS, dll.

---

### 11. Boundary Children (GeoJSON FeatureCollection)

```
GET /api/boundaries/{kode}/children
```

Semua batas wilayah anak sebagai **GeoJSON FeatureCollection**. Ideal untuk menampilkan peta.

**Contoh:**
```bash
# Semua batas kabupaten di Bali
curl "http://localhost:8080/api/boundaries/51/children"

# Semua batas kecamatan di Gianyar
curl "http://localhost:8080/api/boundaries/51.04/children"

# Semua batas desa di Kec. Sukawati
curl "http://localhost:8080/api/boundaries/51.04.01/children"
```

**Response:**
```json
{
  "type": "FeatureCollection",
  "properties": {
    "parent_code": "51.04.01",
    "parent_name": "Sukawati",
    "count": 12
  },
  "features": [
    {
      "type": "Feature",
      "properties": {
        "code": "51.04.01.2001",
        "name": "Batubulan",
        "center": {
          "latitude": -8.617835949,
          "longitude": 115.257347462
        }
      },
      "geometry": {
        "type": "Polygon",
        "coordinates": [[ [115.26, -8.60], "..." ]]
      }
    },
    {
      "type": "Feature",
      "properties": {
        "code": "51.04.01.2003",
        "name": "Guwang",
        "center": { "latitude": -8.6137, "longitude": 115.2852 }
      },
      "geometry": {
        "type": "Polygon",
        "coordinates": [[ "..." ]]
      }
    }
  ]
}
```

---

## Format Response

### Success

```json
{
  "status": true,
  "data": { ... },
  "meta": { ... }
}
```

### Error

```json
{
  "status": false,
  "message": "Region not found."
}
```

### HTTP Status Codes

| Code | Keterangan           |
|------|----------------------|
| 200  | OK                   |
| 400  | Bad Request          |
| 404  | Not Found            |
| 405  | Method Not Allowed   |
| 500  | Internal Server Error|

---

## Contoh Penggunaan

### Navigasi Hirarkis (Provinsi → Desa)

```bash
# 1. Lihat semua provinsi
curl "http://localhost:8080/api/provinces"

# 2. Pilih Bali (51) → lihat kabupaten
curl "http://localhost:8080/api/regencies?province=51"

# 3. Pilih Gianyar (51.04) → lihat kecamatan
curl "http://localhost:8080/api/districts?regency=51.04"

# 4. Pilih Sukawati (51.04.01) → lihat desa
curl "http://localhost:8080/api/villages?district=51.04.01"

# 5. Lihat detail Desa Guwang
curl "http://localhost:8080/api/51.04.01.2003"
```

### Menampilkan Peta dengan Leaflet.js

```javascript
// Ambil batas semua desa di Kec. Sukawati sebagai GeoJSON
fetch('/api/boundaries/51.04.01/children')
  .then(r => r.json())
  .then(geojson => {
    L.geoJSON(geojson, {
      style: { color: '#e94560', weight: 2, fillOpacity: 0.2 },
      onEachFeature: (feature, layer) => {
        layer.bindPopup(`<b>${feature.properties.name}</b><br>${feature.properties.code}`);
      }
    }).addTo(map);
  });
```

### Menampilkan Boundary Satu Desa

```javascript
// Ambil boundary Desa Guwang dalam format GeoJSON
fetch('/api/boundaries/51.04.01.2003?format=geojson')
  .then(r => r.json())
  .then(feature => {
    const layer = L.geoJSON(feature, {
      style: { color: 'red', weight: 3, fillOpacity: 0.3 }
    }).addTo(map);
    map.fitBounds(layer.getBounds());
  });
```

### Pencarian Wilayah

```bash
# Cari semua wilayah bernama "Denpasar"
curl "http://localhost:8080/api/search?q=denpasar"

# Cari hanya desa
curl "http://localhost:8080/api/search?q=denpasar&level=4"
```

---

## Struktur Project

```
wilayah/
├── .env                        # Konfigurasi environment
├── .env.example                # Template environment
├── Dockerfile                  # PHP 8.2 + Apache
├── docker-compose.yml          # Docker Compose (3 services)
├── README.md                   # Dokumentasi ini
├── api/
│   ├── index.php               # Router utama
│   ├── .htaccess               # URL rewrite rules
│   ├── config/
│   │   └── database.php        # Koneksi database (PDO)
│   ├── helpers/
│   │   └── functions.php       # Helper functions
│   └── handlers/
│       ├── provinces.php       # GET /api/provinces
│       ├── regencies.php       # GET /api/regencies
│       ├── districts.php       # GET /api/districts
│       ├── villages.php        # GET /api/villages
│       ├── regions.php         # GET /api/regions
│       ├── region_detail.php   # GET /api/{code}
│       ├── search.php          # GET /api/search
│       └── boundaries.php      # GET /api/boundaries/{code}
└── db/
    └── wilayah_full.sql        # Database lengkap (semua tabel)
```

### Services (Docker Compose)

| Service     | Container       | Port  | Keterangan          |
|-------------|-----------------|-------|---------------------|
| `app`       | `wilayah-app`   | 8080  | PHP 8.2 + Apache    |
| `mysql`     | `wilayah-mysql` | 3306  | MySQL 8.0           |
| `phpmyadmin`| `wilayah-pma`   | 8888  | phpMyAdmin (debug)  |

---

## Statistik Data

| Level            | Jumlah | Dengan Boundary |
|------------------|--------|-----------------|
| Provinsi         | 38     | 34              |
| Kabupaten/Kota   | 514    | 514             |
| Kecamatan        | 7.285  | 7.277           |
| Kelurahan/Desa   | 83.762 | 82.978          |
| **Total**        | **91.599** | **90.803**  |

---

## Lisensi

MIT License — Data wilayah berdasarkan Kepmendagri No 300.2.2-2138 Tahun 2025.

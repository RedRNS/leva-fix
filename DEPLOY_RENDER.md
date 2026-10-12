# Deploy backend ke Render (paket gratis)

Stack gratis: Render (web service, Docker) + Neon (Postgres). Antrean (queue worker) jalan di container yang sama.

## Batasan paket gratis
- Server tidur setelah 15 menit tanpa request; request pertama setelahnya butuh ~1 menit.
- Disk sementara: file upload PDF hilang saat restart. Database aman karena di Neon.
- Tidak ada shell di Render gratis. Migrasi dan pengisian katalog tools otomatis jalan saat start (`docker/start.sh`).

## Langkah
1. Buat database di neon.tech, salin **connection string** (`postgresql://...`).
2. Di Render: New -> Blueprint -> pilih repo ini. Isi env yang diminta:
   - `APP_KEY` (format `base64:...`, buat dengan `openssl rand -base64 32`)
   - `APP_URL` = URL service Render (nanti `https://api.leva.my.id`)
   - `DB_URL` = connection string Neon
   - `OPENAI_API_KEY` = key OpenRouter
   - `GEMINI_API_KEY`
   - `SCRAPER_SECRET_KEY` = string acak >= 32 karakter
3. Tunggu deploy, lalu buka `https://<service>.onrender.com/up` -> harus "OK".
4. Di Vercel (project frontend) set `VITE_API_BASE_URL=https://<service>.onrender.com/api`, lalu Redeploy.

Qdrant opsional: tanpa `QDRANT_HOST`, pencarian otomatis memakai pencocokan kata kunci.

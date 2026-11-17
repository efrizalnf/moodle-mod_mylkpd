# MyLKPD (mod_mylkpd) — LKPD Interaktif untuk Moodle

Versi 1.3.0 (beta) · Moodle 4.1+ · dibuat oleh **Zalira**

Plugin aktivitas Moodle (4.1+). Guru cukup mengunggah LKPD (PDF atau Word), siswa mengerjakan
langsung di atas halaman LKPD, lalu guru menilai dari Moodle. Nilai otomatis masuk Gradebook.

## Instalasi / update
1. Site administration > Plugins > Install plugins > unggah ZIP (atau ekstrak ke `moodle/mod/mylkpd`).
2. Site administration > Notifications untuk menjalankan instalasi/upgrade database.
3. Site administration > Development > Purge caches.

## Format file
| File | Cara tampil |
|---|---|
| PDF | Selalu bisa, ditampilkan dengan PDF.js. |
| DOCX | Jika situs punya pengonversi dokumen (unoconv/Google Drive): dikonversi ke PDF (tata letak paling akurat). Jika tidak: ditampilkan langsung di browser dengan docx-preview. |
| DOC, ODT | Butuh pengonversi dokumen. Tanpa itu, simpan ulang sebagai DOCX atau PDF. |

Mode tampilan (pdf/docx) dipilih saat LKPD pertama kali dibuka dan diingat per aktivitas,
supaya posisi jawaban siswa tidak bergeser. Mode direset hanya jika guru mengganti file.

## Cara mengerjakan (pengaturan aktivitas)
- **Anotasi**: siswa menulis/mencoret di atas halaman (PDF atau Word).
- **Edit teks** (khusus .docx): siswa mengetik langsung di paragraf dan sel tabel LKPD.
  Tombol Enter membuat baris baru di paragraf yang sama; tempel (paste) selalu sebagai teks biasa.
  Hanya paragraf yang diubah yang disimpan (dibersihkan dengan HTMLPurifier Moodle).

## Gambar dari siswa
Tombol **🖼 Gambar** (baris Sisipkan) untuk menambahkan foto/gambar ke LKPD, misalnya foto hasil praktikum.
- Gambar diperkecil di browser (sisi terpanjang maks. 1600 px) sebelum diunggah, lalu diletakkan
  di tengah halaman yang sedang dilihat.
- **Seret gambar** untuk memindahkan, **seret kotak di sudut kanan bawah** untuk mengubah ukuran
  (proporsi tetap). Bisa dihapus dengan Penghapus dan dibatalkan dengan Urungkan.
- Disimpan di file storage Moodle (area `answerimage`, per jawaban), bukan di database.
  Hanya pemilik jawaban, anggota kelompoknya, dan guru yang bisa membuka gambarnya.
- Server memeriksa isi file (JPG/PNG/GIF/WebP), ukuran maks. 5 MB, maks. 30 gambar per LKPD.
  Gambar yang sudah tidak dipakai dihapus saat LKPD dikumpulkan.
- Ikut tersinkron pada kerja kelompok dan tampil di halaman penilaian guru.

## Anotasi dan catatan guru
Di halaman penilaian, guru bisa langsung bekerja di LKPD siswa (atau kelompok):
- Toolbar yang sama dengan siswa, ditambah **📝 Catatan**: catatan tempel di halaman yang terbuka saat
  diklik. Warna awal guru merah.
- Guru bisa membetulkan jawaban siswa (paragraf atau kotak teks); bagian itu diberi label nama guru.
  Pada LKPD Word, alat awal guru adalah **Ketik**, jadi teks siswa bisa langsung diedit.
- Anotasi guru diberi label "Nama (Guru)" dan **tidak bisa dipindah atau dihapus siswa**
  (dijaga di server). Siswa bisa membuka dan membaca catatannya.
- Tersimpan otomatis (ada tombol **Simpan anotasi**), tetap bisa dilakukan setelah LKPD dikumpulkan,
  dan tidak mengubah "terakhir diubah" milik siswa. Saat tombol simpan nilai ditekan, anotasi
  yang belum terkirim disimpan dulu.
- Siswa melihat anotasi guru langsung (tanpa memuat ulang) jika sedang membuka LKPD.

## Pemeriksaan esai dengan AI (Claude / OpenAI)
Opsional, bisa diaktifkan/nonaktifkan di **Administrasi situs > Plugin > Modul aktivitas > MyLKPD**:
penyedia (Claude atau OpenAI/kompatibel OpenAI), API key, model, dan URL dasar API (untuk proxy/router).
Lalu aktifkan per aktivitas di pengaturan aktivitas ("Pemeriksaan esai dengan AI").

**Pola penyelarasan soal ↔ kunci jawaban (best practice yang dipakai):**
1. Guru membuka **Kunci jawaban** (tombol di halaman Jawaban siswa). Untuk setiap soal esai diisi:
   nomor, soal, kunci jawaban (jawaban ideal), poin penilaian (satu per baris), dan skor maksimum.
   **Deteksi soal** mengisi otomatis dari soal bernomor (1. 2. 3. …) pada LKPD Word.
2. Setiap soal **dihubungkan ke area jawabannya** di LKPD: klik paragraf/sel tabel tempat siswa
   mengetik (mode Edit teks), dan/atau seret kotak di halaman untuk jawaban yang ditulis di atas
   halaman (mode Anotasi/PDF). Deteksi soal sudah menyarankan areanya (paragraf/sel sampai soal berikutnya).
3. Saat diperiksa, jawaban siswa untuk tiap soal diambil **persis dari area tersebut**, jadi tidak ada
   tebakan teks mana milik soal mana. Teks asli area (mis. "Jawab: ....") ikut dikirim agar AI hanya
   menilai tambahan siswa.
4. AI menerima soal, kunci, poin penilaian, skor maksimum, dan jawaban dalam JSON, lalu wajib membalas
   JSON (skor, poin terpenuhi/kurang, umpan balik singkat). Skor dibatasi ke skor maksimum di server,
   jawaban siswa diperlakukan sebagai data (instruksi di dalam jawaban diabaikan).
5. Di halaman penilaian, **Periksa dengan AI** menampilkan saran skor dan umpan balik per soal.
   **Pakai sebagai nilai dan umpan balik** mengisi form nilai (diskalakan ke nilai maksimum aktivitas);
   guru tetap memeriksa sebelum menyimpan. Dengan rubrik, hanya umpan balik yang diisi.

Data yang dikirim ke penyedia AI tercatat di Privacy API (lokasi eksternal).

## Penilaian dengan rubrik
MyLKPD mendukung *Advanced grading* Moodle (rubrik dan panduan penilaian), sama seperti Assignment.
1. Di halaman **Jawaban siswa** klik **Atur rubrik** (atau Pengaturan aktivitas > Nilai >
   Metode penilaian: *Rubrik*).
2. Buat kriteria dan level beserta poinnya dengan editor rubrik Moodle, lalu simpan sebagai siap pakai.
3. Saat menilai, guru mengklik level di setiap kriteria. Nilai dihitung otomatis sesuai nilai maksimum
   aktivitas dan masuk ke Gradebook (untuk kerja kelompok: ke semua anggota).
4. Siswa melihat rubrik beserta level yang dipilih guru di kartu status LKPD.
Tanpa rubrik, guru tetap bisa mengisi nilai langsung.

## Edit setelah dikumpulkan
Pengaturan "Edit setelah dikumpulkan": *Ya, sampai dinilai guru* (bawaan), *Ya, kapan saja*, atau *Tidak*.
Jika diizinkan, siswa melihat tombol **Edit lagi** di bilah aksi bawah (tempat tombol Kumpulkan): LKPD kembali menjadi draf, lalu dikumpulkan ulang.
Pada kerja kelompok, Edit lagi membuka LKPD untuk seluruh kelompok. Guru selalu bisa membuka kembali
lewat halaman penilaian.

## Kerja kelompok (kolaborasi)
- Centang **Kerja kelompok** di pengaturan aktivitas. Memakai grup kursus; pilih grouping di
  "Pengaturan umum modul" untuk membatasi grup. Siswa tanpa grup tidak bisa mengerjakan.
- Satu LKPD per kelompok. Tersimpan otomatis (AJAX) dan disinkronkan tiap 3 detik.
- Daftar anggota online tampil di atas LKPD. Paragraf yang sedang diketik teman dikunci
  sementara dengan label "<nama> sedang mengetik", jadi tidak saling menimpa.
- Urungkan (Undo) hanya membatalkan perubahan milik sendiri.
- Satu anggota menekan "Kumpulkan", LKPD terkunci untuk seluruh kelompok.
- Bilah bawah: status penyimpanan ("Tersimpan pukul 10:32", atau pesan gagal beserta alasannya),
  tombol **Simpan sekarang** dan **Kumpulkan LKPD**.
- **Nama penulis** tampil sebagai label berwarna di setiap jawaban dan paragraf yang diedit
  (bisa disembunyikan dengan "Tampilkan nama penulis"). Juga tampil di halaman penilaian guru.
- Guru menilai per kelompok (nilai masuk ke semua anggota) dan melihat tabel **Kontribusi**
  (siapa terakhir mengubah paragraf/jawaban). Di mode baca, paragraf yang diubah diberi
  warna sesuai anggota, dengan keterangan "Terakhir diubah oleh ...".
- "Cara mengerjakan" dan "Kerja kelompok" terkunci setelah ada siswa yang mulai mengerjakan.

## Toolbar (referensi CKEditor)
Satu panel yang menempel di atas saat halaman digulir, selalu di bawah bar yang dipasang tema
(navbar, navigasi sekunder "MyLKPD · Settings · Advanced grading", dll.).
Baris 1 (alat): **Ketik** (khusus DOCX), Pindah, Teks, Centang, Silang, Lingkari, Stabilo, Garis, Pensil,
Penghapus | Urungkan/Ulangi.
Baris 2: format teks paragraf (khusus DOCX): Tebal, Miring, Garis bawah, Coret, Pangkat, Indeks,
Warna teks, Stabilo teks, Hapus format | 5 warna, 3 ketebalan | Sisipkan: **Persamaan** (LaTeX dengan palet template dan
pratinjau, dirender KaTeX), **Simbol** (±, ×, √, π, °, panah, huruf Yunani, dll.), **Arab** (keyboard
huruf hijaiyah + harakat + angka Arab), **Tautan**.

- File DOCX: alat Ketik dan alat gambar tersedia bersamaan (gambar/coretan di atas halaman Word).
- File PDF: alat gambar + Persamaan/Tautan diletakkan di halaman; Simbol/Arab masuk ke kotak teks.
- Gambar Word "di belakang teks" (latar halaman, banner, bingkai) ditempatkan di posisi aslinya di
  halaman, di belakang teks LKPD, sehingga tata letak template tetap rapi.
- Alamat web yang diketik siswa otomatis menjadi tautan. Klik tautan di paragraf memunculkan balon
  "↗ Buka tautan" (Ctrl/Cmd+klik langsung membuka); di mode baca tautan langsung terbuka di tab baru.
  Alamat web di kotak teks ditampilkan sebagai tautan di bawah kotaknya.
- URL/kata panjang di tabel dipecah ke baris berikutnya, sehingga tabel tidak melebar keluar halaman.
- Persamaan di paragraf disimpan sebagai teks `\(LaTeX\)` (aman dari HTMLPurifier); klik untuk mengedit.
- Kotak teks memakai `dir="auto"`, jadi jawaban berbahasa Arab otomatis rata kanan.
Posisi gambar disimpan dalam persen halaman, jadi tetap pas di HP maupun laptop.

## Struktur
- version.php, lib.php, locallib.php, mod_form.php
- view.php (siswa), ajax.php (autosave/sinkron), upload.php (unggah gambar), keys.php (kunci jawaban),
  aicheck.php (pemeriksaan AI), settings.php (pengaturan plugin), classes/ai (klien & pemeriksa AI), submissions.php (daftar), grade.php (penilaian), index.php
- db/install.xml, db/upgrade.php, db/access.php
- classes/event, classes/form, classes/privacy
- js/worksheet.js (editor), styles.css
- js/pdfjs (PDF.js 3.11.174), js/docx (docx-preview 0.4.1 + JSZip 3.10.1), js/katex (KaTeX 0.16.29) — lihat thirdpartylibs.xml
- lang/en, lang/id

## Belum ada
Backup/restore kursus, kunci jawaban otomatis, coretan koreksi dari guru, riwayat versi.
Sinkronisasi memakai polling (bukan WebSocket): cocok untuk kelompok kecil (2–8 siswa).

## Pembuat
MyLKPD dikembangkan oleh **Zalira**. Keterangan "MyLKPD · dibuat oleh Zalira" tampil di bawah LKPD dan di
halaman pengaturan plugin.

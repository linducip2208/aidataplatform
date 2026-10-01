# OpenCode Go + Muse Spark

Integrasi provider **OpenCode Go** (`https://opencode.ai/zen/go/v1`) ke
registry BYOK Laravel, memakai **Responses API** (`POST /responses`) untuk
model **muse-spark-1.3-contributor**. Tidak ada SDK baru: semua HTTP via
`Illuminate\Support\Facades\Http`.

Arsitektur mengikuti pola existing: metadata di database, API key tidak
pernah disimpan. Kode: `app/Services/OpenCodeGoAdapter.php`,
`app/Services/ModelRouter.php`, `app/Services/LlmToolGateway.php`,
`config/ai_providers.php`.

## 1. Setup

1. Buka **Administrasi → Provider AI → Tambah provider**.
2. Tipe: **OpenCode Go**. Base URL terisi otomatis
   `https://opencode.ai/zen/go/v1` (bisa diganti via
   `OPENCODE_GO_BASE_URL` atau per baris).
3. Tempel API key ke field **sementara** (tidak pernah disimpan).
4. **Uji koneksi** → `GET /models` asli + latensi.
5. **Discovery model** → `GET /models` dinormalisasi; di halaman edit
   hasilnya **tersimpan** ke `ai_provider_models`, di halaman tambah
   hanya pratinjau. Muse Spark muncul otomatis jika API
   mengiklankannya — tidak ada allowlist.
6. Pilih model (datalist / tombol **Use**), lalu **Uji model** →
   `POST /responses` asli memakai model tersebut.
7. **Simpan**, lalu **Terbitkan** seperti provider lain (blok env
   `LLM_PROVIDER=opencode-go`, `OPENCODE_GO_API_KEY`, …).

Timeout/retry per provider: kolom `timeout_seconds` (1–600) dan
`max_retries` (0–10); kosong = default config.

## 2. API yang dipakai

| Operasi         | Request                          | Auth            |
|-----------------|----------------------------------|-----------------|
| Test connection | `GET /models`                    | Bearer API key  |
| Discovery       | `GET /models` → normalisasi      | Bearer API key  |
| Inference       | `POST /responses`                | Bearer API key  |
| Streaming       | `POST /responses` + `stream: true` (SSE) | Bearer API key |

Body inference: `{model, input, max_output_tokens?, temperature?,
top_p?, tools?, tool_choice?, metadata?}`. Field lain tidak diteruskan.

Respons diparse defensif: bentuk kanonis Responses dulu
(`output[].message.content[].output_text` + `output[].function_call`),
lalu fallback (`output_text`/`text`, bentuk chat `choices[0]`, …).
Usage dinormalisasi ke
`input_tokens/output_tokens/total_tokens[/reasoning_tokens]`
(models `prompt_tokens`/`completion_tokens` dipetakan; total diturunkan
bila hilang).

## 3. Model discovery & capabilities

Setiap entri dinormalisasi ke
`{external_id, name, capabilities[], metadata{}}`. Kapabilitas
**dideteksi** dari respons (array `capabilities`, flag boolean umum,
prefix `supports_*`) — tidak dihardcode. Field metadata yang namanya
mengandung `key/secret/token/password` dibuang sebelum disimpan.

## 4. Responses API & tool calling

`LlmToolGateway` memegang allowlist internal. Yang dieksekusi lokal
(terhadap database Laravel, read-only):

- `list_datasets`, `get_dataset_schema`, `describe_columns`,
  `preview_dataset` (pratinjau metadata; isi baris milik file sumber /
  engine, tidak difabrikasi).

Nama engine-owned (`run_sql`, `run_python`, `get_statistics`,
`create_chart`, `save_analysis`) dan semua nama lain (shell,
filesystem, network, eval) ditolak dengan error terstruktur — tidak
pernah dieksekusi, tidak pernah difake. Hanya tool executable yang
diiklankan ke model via `tools`.

## 5. Streaming

`streamComplete()` membaca SSE (`stream: true` Guzzle) dan menghasilkan
event internal: `AIStreamStarted`, `AITextDelta`, `AIToolCall`,
`AIResponseCompleted`, `AIResponseFailed`. `AIToolResult` dihasilkan
eksekutor tool (gateway), bukan dari wire. Parser frame SSE murni
(`parseSseBuffer`) diunit-test tanpa socket.

## 6. Retry, health, usage

- Retry hanya untuk status transien (408, 429, 500, 502, 503, 504;
  lihat `config/ai_providers.php`). Error auth/validasi
  (400/401/403/404/422) **tidak pernah** di-retry. `Retry-After`
  (detik, dibatasi config) dipatuhi; selain itu backoff eksponensial.
- Health: `healthy` (200 + ada model), `degraded` (200 tanpa model),
  `unhealthy` (+ latensi, status HTTP, jumlah model, waktu cek).
- Usage: lihat bagian 2. Ledger biaya tetap milik engine
  (`/ai/usage`); adapter mengembalikan usage per request untuk
  ditampilkan (`test-model`) dan diteruskan pemanggil.

## 7. Privasi & keamanan

- API key: input sementara per request; **tidak ada kolom**,
  tidak masuk DB, log, audit, session, exception, atau Git.
  Semua note di-scrub (`[redacted]`) sebelum disimpan/ditampilkan.
- Model Router tidak pernah memegang secret: adapter dibuat dengan key
  dari pemanggil.
- Deployment single-organisasi: registry provider global, hanya admin
  aktif (`role:admin`) boleh kelola (route + test mengunci ini).

## 8. Region availability

Endpoint publik tunggal (lihat base URL di atas). Tidak ada pinning
region di sisi Laravel; latensi dilaporkan apa adanya oleh uji
koneksi/model/health.

## 9. Troubleshooting

| Gejala | Artinya |
|---|---|
| `HTTP 401: key rejected` | Key salah / tidak punya akses model. |
| `HTTP 404` | Path salah — cek Base URL (tanpa `/responses` di ujung). |
| `Unreachable: …` | DNS/TLS/timeout — cek jaringan & firewall egress 443. |
| Discovery kosong tapi 200 | API tidak mengiklankan model → status `degraded`. |
| `Model … is not supported` | Model tidak aktif untuk key tersebut. |
| Uji model 422 (form) | Tipe bukan OpenCode Go / field model kosong. |

## 10. Live test

Tanpa key, `OpenCodeGoLiveTest` **SKIP** (tidak difake):

```powershell
$env:OPENCODE_GO_API_KEY="sk-..."
php artisan test --filter=OpenCodeGoLiveTest
```

## 11. Batasan jujur

- Eksekusi LLM langsung (BYOK probe/inference) berjalan di Laravel;
  orkestrasi data-science tetap milik FastAPI engine
  (`POST /api/v1/ai/chat`), yang membaca `LLM_*` dari env hasil
  publish. Agar engine memakai Muse Spark, engine perlu adapter
  Responses di sisinya (di luar repo Laravel ini).
- `run_sql`/`run_python`/statistik/chart/analisis-tersimpan belum
  punya eksekutor lokal — gateway menolak eksplisit, bukan fake.

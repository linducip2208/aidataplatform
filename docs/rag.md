# RAG (Retrieval-Augmented Generation)

Grounds answers in indexed document text. Two engine endpoints, both behind the service key:
`POST /api/v1/rag/ingest` to index, `POST /api/v1/rag/query` to retrieve. Laravel exposes the
query side as `POST /api/rag/query`. There is no indexing queue and no dataset-scoped indexing.

## 1. Ingest then query

```bash
# index a document
curl -s -X POST http://localhost:8001/api/v1/rag/ingest \
  -H "X-Service-Key: $SERVICE_API_KEY" -H 'Content-Type: application/json' \
  -d '{"title":"Panduan refund","content":"Refund diterima maksimal 14 hari setelah transaksi ...","source":"handbook.pdf","doc_type":"pdf"}'
# -> {"success": true, "data": {"document_id": 5, "n_chunks": 3, "status": "created", "truncated": false}}
```

`status` is one of `created` (first time), `updated` (the same `source`/`title` with
different text — the chunks are replaced in place, the row keeps its id), `unchanged` (the
same text again, nothing rewritten) and `empty` (`content` was blank). Indexing is
idempotent on the SHA-256 of the content, so re-running a bulk load is safe.

```bash
# retrieve
curl -s -X POST http://localhost:8001/api/v1/rag/query \
  -H "X-Service-Key: $SERVICE_API_KEY" -H 'Content-Type: application/json' \
  -d '{"query":"refund policy","top_k":5}'
# -> {"success": true, "data": {"answer": "...", "citations": [...], "n_results": 5,
#        "chunks": [{"content": "...", "score": 0.8123, "document_id": 5, "chunk_index": 1}, ...]}}
```

`chunks` is the ranked evidence the answer was written from; `citations` names the documents
it came from. `answer` is a natural-language synthesis of the retrieved chunks, not a
passthrough of one of them.

`RagIngestRequest` takes `title`, `content`, `source` (default `api`) and `doc_type`
(default `txt`). `RagQueryRequest` takes `query` and `top_k`, 1–20, default 5. Both are
`200`, not `202` — indexing is synchronous inside the request.

Through Laravel, with a bearer token:

```bash
curl -s -X POST http://localhost:8080/api/rag/query \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"question":"How long is the refund window?","top_k":5}'
```

`question` and `query` are interchangeable (`required_without` each other), `top_k` is
validated 1–20. The response is `{"data": {"answer": "...", "citations": [...]}}`.

## 2. How it works

1. **Chunk.** `chunk_text` normalises whitespace, then walks the text in windows of 800
   characters with 120 characters of overlap (`CHUNK_SIZE` and `OVERLAP`, module constants in
   `ai-engine/app/ai/rag.py`), preferring to break after a sentence when one falls in the second
   half of the window. These two numbers are the defaults of `chunk_text` and of `ingest_text`,
   not settings — there is no `RAG_CHUNK_SIZE` or `RAG_CHUNK_OVERLAP` variable, and neither
   endpoint accepts a chunk size. Change the constants in `ai-engine/app/ai/rag.py` to tune
   them, then re-index. A single ingest is capped at 200 000 characters
   (`MAX_INGEST_CHARS`, and 1000 chunks) and comes back with `truncated: true` when the cap
   hit.
2. **Embed.** `llm.embed` calls `{base_url}/embeddings` with `LLM_EMBEDDING_MODEL` when an
   API key is configured. Without a key — or when the call returns fewer vectors than it was
   given — it falls back to `_hash_embed`: a deterministic 128-dimension bag-of-words vector,
   normalised, capped at 20 000 tokens. That is why the platform still answers without an LLM
   key, and why those answers are much weaker. Note the embedding column is declared
   `Vector(1536)`, so a real provider must return 1536 dimensions to fit.
3. **Store.** One `rag_documents` row per `(source, title, content hash)`, one `rag_chunks`
   row per chunk with `chunk_index`, `content` and `embedding`.
4. **Retrieve.** `query` loads up to **2000** chunks (`SCAN_LIMIT`), scores each one with a
   Python cosine over the two vectors, adds `+0.05` when the query text appears verbatim in
   the chunk, sorts descending, takes the top `top_k` as
   `{content, score, document_id, chunk_index}` and asks the LLM to answer from them. With no
   LLM configured the answer is a summary of the retrieved chunks rather than a model
   synthesis.

`read_document` can pull text out of PDF (`pypdf`), DOCX (`python-docx`), HTML (tags
stripped) and plain text, but only `ingest_text` is wired to an endpoint — it takes the
content as a string. There is no file-upload route for RAG, and no "index this dataset"
route: the assistant does not retrieve from your uploaded datasets.

## 3. There is no vector index

`rag_chunks.embedding` has no HNSW or IVFFlat index, and `query` does not issue a similarity
search in SQL — it reads the rows and scores them in the process. Two consequences:

- Retrieval is O(n) over the first 2000 chunks, and `top_k` is applied *after* scoring, so
  with more than 2000 chunks the tail is invisible to every query.
- The `+0.05` keyword boost is a rerank on top of cosine, computed in Python alongside
  a BM25-lite keyword score — no database extension is involved in this code path.

Keep the corpus small, or add a real ANN index and an `ORDER BY embedding <=> :q LIMIT :k`
query before scaling. On this stack the `embedding` column is plain `JSON` (the pgvector
`Vector(1536)` type is only used when the optional `pgvector` package is installed, which
the MySQL deployment deliberately does not do).

## 4. Changing the embedding model

The engine accepts two names for the same setting, with the same meaning:
`LLM_EMBEDDING_MODEL` (the name in `ai-engine/.env`, default
`text-embedding-3-small`) and `EMBED_MODEL` (the name the root `.env` uses, default
`sentence-transformers/all-MiniLM-L6-v2`). Whichever is present supplies the value, so the
`EMBED_MODEL` line in the shipped root `.env` is effective — pick one and set the same model
on both services to avoid a surprise.

To switch to a different dimension you must change the Alembic column type and re-index every
document — old vectors are not comparable. There is no re-index endpoint; re-send
`POST /api/v1/rag/ingest` for each document with the same `source` and `title`, which replaces
that document's chunks in place, and delete the `rag_documents` rows you want gone yourself.

## 5. Ops

- Nothing prunes `rag_documents` or `rag_chunks`. Beat runs a placeholder nightly sync, an
  hourly AI report and the per-minute alert evaluation, none of which touch the corpus.
  Storage is roughly the document text plus the JSON embedding per row —
  a 1536-dimension vector is several KB as JSON, so a million chunks is several gigabytes in `mysql-data`.
- Watch for the `2000`-chunk ceiling as the corpus grows. The first symptom is
  "irrelevant citations" rather than an error.
- An offline hash-embedding corpus and an online API-embedding corpus must not be mixed:
  the cosine compares the first `min(len(a), len(b))` elements, so a 128-dimension fallback
  vector silently scores against a prefix of a 1536-dimension real one.
- Answer quality depends on the provider. Both `POST /api/v1/rag/query` and Laravel's
  `POST /api/rag/query` return the same grounded `answer` plus `citations`; the assistant
  (`POST /api/agent/chat`) is a separate path that grounds itself in the warehouse through
  tools rather than in the corpus. See `ai-agent.md`.

## 6. Hybrid search, rerank, citations (enterprise)

Retrieval is a hybrid of two signals over the same `SCAN_LIMIT` candidate set, fused after
per-set max-normalisation as `0.65 * vector + 0.35 * keyword`:

- **vector**: cosine between the query embedding and the stored chunk embedding (0 when
  either side has no usable vector, e.g. a chunk indexed by a different model dimension).
- **keyword**: BM25-lite over chunk text — saturated tf (`k1 = 1.2`), smoothed idf computed
  over the scanned set (no index needed), length-normalised (`b = 0.75`).

`POST /api/v1/rag/query` accepts `?hybrid=true|false` (default `true`) and
`?rerank=true|false` (default `true`); Laravel's `POST /api/rag/query` accepts the same two
as optional booleans and forwards them as engine query params. `hybrid=false` restores the
legacy vector-only path (normalised cosine plus the historical `+0.05` verbatim boost).

**Rerank** is a deterministic, cross-encoder-free re-score that only reorders — it never
adds or drops a candidate:

```
rerank = 0.55 * fused + 0.25 * term_coverage + 0.15 * exact_phrase + 0.05 * length_prior
```

`term_coverage` is the fraction of distinct query terms in the chunk, `exact_phrase` is 1.0
for the verbatim query (0.4 for a 4-word run), `length_prior` peaks at 600 characters. A
real cross-encoder is used only if one is already installed in the image (none is; no model
is ever downloaded at runtime) — checked by import, no pip installs. `rerank=false` keeps
the fused order and says so in `limitations`.

**Citations** carry chunk ids plus source/title plus verifiable char offsets:

```json
{"chunk_id": 9, "document_id": 5, "chunk_index": 1, "source": "handbook.pdf",
 "title": "Panduan refund", "score": 0.87, "char_start": 0, "char_end": 6}
```

`content[char_start:char_end]` is the earliest verbatim query-term hit in the chunk;
`char_start`/`char_end` are `null` when the match is purely semantic. `evidence[]` rows add
the full content plus the score breakdown (`vector`, `keyword`, `fused`, `rerank`,
`features`); `chunks` remains as the legacy alias of the compact hit list.

**Answer shaping.** Every query returns
`{answer, evidence[], citations[], chunks[], n_results, confidence, limitations}`.
`confidence` is 0..1 (0 when nothing matched, capped at 0.6 on keyword-only degraded runs);
`limitations` names each degradation: embedding outage, `SCAN_LIMIT` bound, disabled
hybrid/rerank, ACL-hidden chunks, or no chunk above threshold. Empty corpus is an explicit
"no match" answer, never an invented one.

## 7. Document ACL (enterprise)

Documents carry a visibility level — `public`, `internal`, or `confidential` —
stored in the document `meta` (no schema change; pre-ACL documents read back
as `public`). Set it at ingest with `POST /api/v1/rag/ingest?visibility=...`
(unknown values are 422; first ingest wins on re-ingest, so a content update
can never silently re-tag the audience).

Enforcement happens in the engine **before retrieval**: `POST
/api/v1/rag/query?allow=public,internal` drops every chunk whose document is
not allowed before scoring, so unauthorized text never reaches rerank,
evidence, citations, or the answer. The hidden count is reported in
`limitations`. Omitting `allow` keeps the legacy allow-all behaviour for
direct engine callers.

Laravel maps the caller on every proxied query and the caller never chooses:
viewer → `public`, analyst → `public,internal`, admin →
`public,internal,confidential` — plus `user_id` (the authenticated account)
on every call for per-owner matching. `POST /api/rag/documents`
(admin/analyst) indexes with `visibility`, forcing `owner` to the caller for
`private`. A `private` document is visible only to its owner; without a
caller identity it stays hidden even from allow-all listings.

# RAG (Retrieval-Augmented Generation)

Grounds agent answers in dataset content via pgvector (`ai.embeddings`).
Queue: `rag`. Embeddings: `EMBED_MODEL=sentence-transformers/all-MiniLM-L6-v2` (dim 384).

## 1. Index then query

```bash
curl -X POST http://fastapi:8000/api/v1/rag/index -H "X-Service-Key: $SERVICE_API_KEY" \
 -H 'Content-Type: application/json' -d '{"dataset_id":"<uuid>","chunk_size":1000,"overlap":150}'
# -> 202 {job_id, queue: rag}
curl -X POST http://fastapi:8000/api/v1/rag/query -H "X-Service-Key: $SERVICE_API_KEY" \
 -H 'Content-Type: application/json' -d '{"dataset_id":"<uuid>","question":"Refund policy?","top_k":8}'
# -> {answer, citations:[{chunk_id, score, preview}]}
```

Defaults `RAG_CHUNK_SIZE=1000`, `RAG_CHUNK_OVERLAP=150`, `RAG_TOP_K=8` (override per call).
UI: dataset **RAG** tab (index button + Q&A box with citations).

## 2. How it works

1. Extract text (CSV rows → markdown-ish docs; Excel/parquet same; JSON flattened).
2. Chunk (size/overlap) → embed (`EMBED_MODEL`) → upsert `ai.embeddings(dataset_id,
   chunk_id, content, embedding vector(384), meta JSONB)` with HNSW index.
3. Query: embed question → cosine top-K → LLM synthesizes with citations.
4. `rag_queries_total` counter → Grafana "RAG Queries" stat.

## 3. Changing the embed model

Embedding dim is schema-bound (`vector(384)`). To switch models: set new `EMBED_MODEL`
+ `EMBED_DIM`, run migration to alter column, then **re-index every dataset**
(`POST /rag/index` per id) — old vectors are incompatible. Never mix dims in one table.

## 4. Quality tips

- Dirty source → dirty retrieval: require quality `pass` before indexing.
- `top_k=8` balances recall/cost; raise to 16 for long docs, lower to 4 for chatty latency.
- `pg_trgm` complements vector search for exact-term (IDs, codes) — agent fuses both.
- PII: embeddings inherit dataset ACL; viewer role sees answers but not raw chunks.

## 5. Ops

- Index job progress via `GET /api/v1/jobs/{job_id}`; large datasets chunk in background
  (300 s ceiling irrelevant — poll, don't block).
- Storage: ~2 KB/vector + text; 1 M chunks ≈ 3-4 GB. Monitor `pgdata` volume.
- Re-index after source re-upload (new `dataset_id` version) — old index retained for audit.

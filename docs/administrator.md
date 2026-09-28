# Administrator Guide

For platform admins (prod + staging). Assumes Docker stack healthy (`healthcheck.sh`).

## 1. Accounts

Seeded (`README.md`): `admin@example.com / Admin123!` etc. — force-rotate day one:
UI **Users** or `php artisan tinker` (`Hash::make`). Roles: admin (approve, override,
users, retention), analyst (upload, quality, train, RAG index), viewer (dashboards, chat).
Deactivate leavers immediately; review `audit logs` monthly.

## 2. Daily / weekly ops

- Daily: glance Grafana (queue depth, failed imports/ML), `docker compose ps`,
  `backups/` non-empty (cron 02:00). Investigate `failed` jobs via worker logs.
- Weekly: `docker system df` (prune if > 70%), review quarantined datasets
  (approve/reject with note), check `GRAFANA` quality trend, `apt update` on host.
- Monthly: restore drill to staging (`backup-restore.md`), rotate `SERVICE_API_KEY`
  (update `.env`, `up -d laravel fastapi celery-worker celery-beat`), review LLM spend.

## 3. Governance queue

**Models** page: approve `staged → production` per checklist (`model-governance.md`);
quarantined-data models show red banner (override requires note). **Quality** page:
per-dataset threshold override + re-profile. All actions land in `ml.approvals` + Laravel audit.

## 4. Quotas / retention

Enforce `MAX_UPLOAD_MB=500` (raise only with disk headroom: 1 upload ≈ 3× size across
raw/staging/warehouse). Beat purges: draft models 90 d, quality history 90 d, raw
payloads per policy — warehouse facts never auto-delete. `BACKUP_RETENTION_DAYS=14` local.

## 5. Incidents

1. `healthcheck.sh` to scope (db/redis/api/worker). 2. `logs` for error. 3. Mitigate:
   scale workers, revoke keys if leak, restore DB if corruption (`restore.sh`). 4. Postmortem
   in repo issue. Escalation: app errors → Laravel owner; data/ML wrong → AI-engine owner;
   host/docker → infra (this stack). Contacts + on-call rotation: record here per org.

## 6. Upgrades

`git pull && docker compose up -d --build && migrate --force && healthcheck.sh`
(snapshot DB first: `make backup`). Pin base image bumps (PHP/Redis/Prometheus) to
maintenance windows; read CHANGELOG + run `tests/run.sh` before + after.

## 7. User management details

Create users via UI (**Users → Invite**) or tinker:

```bash
docker compose exec laravel php artisan tinker
>>> \App\Models\User::create(['name'=>'Ops','email'=>'ops@example.com','password'=>Hash::make('...'),'role'=>'admin']);
```

Enforce SSO/LDAP if org requires (configure in `application/config/auth.php`, other
agent's area — file a ticket, don't hand-edit vendored config). Review role matrix
quarterly; viewers must never see quarantine raw rows or draft model params.

## 8. Disk / capacity planning

Rule of thumb per dataset: raw 1×, staging 1×, warehouse 1×, embeddings ~0.5× source
text. Monitor `docker system df` + `pgdata` volume; alert at 70%. Add disk or raise
`BACKUP_RETENTION_DAYS` pruning. Postgres bloat: monthly `VACUUM (ANALYZE)` via Beat
or cron; embeddings HNSW reindex after bulk deletes (`REINDEX INDEX CONCURRENTLY`).

## 9. Audit exports for compliance

```sql
SELECT * FROM ml.approvals ORDER BY created_at DESC;   -- governance trail
SELECT * FROM warehouse.quality_reports WHERE verdict='quarantine';  -- data holds
```

Export CSV from UI or `psql \copy`. Retain 1 year minimum; store alongside DB dumps
in S3 (`BACKUP_S3_PREFIX`). Document reviewer sign-off per quarter in the admin log.

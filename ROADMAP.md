# Asset Vault — Roadmap

**Package:** `kernpfad/craft-asset-vault`  
**Handle:** `asset-vault`

## Status

**P0–P2 (AV-01–AV-08) sind erledigt** und auf `main`. **AV-09** (Matrix/Relations-Roundtrip) folgt in diesem Branch.

| ID | Item | Status |
|---|---|---|
| AV-01 | Archive/Restore Dry-Run + Konflikte | ✅ |
| AV-02 | Audit-Log | ✅ |
| AV-03 | Null-Guards (volumeId / indexing session) | ✅ |
| AV-04 | Retention + Console Purge | ✅ |
| AV-05 | Bulk Archive ohne Löschen (Queue + Progress) | ✅ |
| AV-06 | Asset-Index Sources: Vaulted / Missing on FS | ✅ |
| AV-07 | Eager Named Transforms nach Restore | ✅ |
| AV-08 | Events beforeArchive / afterRestore | ✅ |
| AV-09 | Complex field round-trip (Matrix / Relations) | ✅ |

## P3 — mögliche nächste Schritte

Noch nicht entschieden / priorisiert:

| ID | Item | Hinweis |
|---|---|---|
| AV-09 | Complex field round-trip (Matrix / Relations) | ✅ erledigt |
| AV-10 | Vault-Kopie optional in separates Volume | Disaster-Recovery näher an Offsite; Scope/Semantik klären |
| AV-11 | CP-Benachrichtigung nach Bulk-Archive-Job | Queue-Widget reicht heute; Toast/Flash optional |
| AV-12 | Missing-on-FS als Element-Condition-Rule | Ergänzung zur Source; Filter kombinierbar mit Volumes |

## Nicht tun

- Vault als Ersatz für Offsite-Backup (S3 Versioning) verkaufen ohne Docs-Klarstellung

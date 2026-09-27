# PayGate

Manual payment-collection platform (Admin · Partners · Branches).

Stack: Laravel 13 · PHP 8.5 (FrankenPHP) · PostgreSQL 18 · Redis 8 + Horizon · React/TypeScript via Inertia · Docker.

| Document                                                   | For                                                                                       |
| ---------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| [Document/Developer-Guide.md](Document/Developer-Guide.md) | **Start here.** Setup, URLs, logins, commands, troubleshooting                            |
| [Document/Requirements.md](Document/Requirements.md)       | **Business baseline v1.4** (accepted): business model, money flow, flows, financial model |
| [Document/Database.md](Document/Database.md)               | **Database design** (implemented): tables, ledger rules, write paths                      |
| [Document/Deployment.md](Document/Deployment.md)           | **First-time database setup on staging/production**, and every later release              |
| [Document/Legacy-API.md](Document/Legacy-API.md)           | The old platform's partner API (reference only)                                           |
| [Document/Architecture.md](Document/Architecture.md)       | Architecture, flows, security, roadmap                                                    |

## Quick start

Needs only Docker Desktop and `make`.

```bash
sudo sh -c 'echo "127.0.0.1 paygate.local api.paygate.local pay.paygate.local" >> /etc/hosts'
make setup
```

| URL                          | What               | Login                                                             |
| ---------------------------- | ------------------ | ----------------------------------------------------------------- |
| http://paygate.local         | Portals            | `admin@paygate.local` / `password` (see guide for all demo users) |
| http://localhost:8080        | Adminer (database) | `paygate` / `secret`, database `paygate`                          |
| http://localhost:8025        | Mailpit (emails)   | none                                                              |
| http://paygate.local/horizon | Queues             | none (local)                                                      |

`make help` lists all commands.

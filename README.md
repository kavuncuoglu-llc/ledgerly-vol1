# Ledgerly (Volume 1 companion app)

Companion code for **Large Language Models for Laravel Developers, Volume 1: The Foundations of Large Language Models**.

Ledgerly is a small, fictional invoicing product with customers, invoices, a 120-article help center, and a support inbox. Over the course of the volume you bolt a support assistant onto it, one chapter at a time. At `ch01` it is a plain Laravel app with seed data and **no AI code at all**.

The repository is public and free to use. See [LICENSE](LICENSE) for the terms (personal and educational use; not for republishing or resale).

## Chapter tags

Every chapter that changes the app has a Git tag, and `vol2-start` marks the hand-off to Volume 2 (*Running, Training, and Securing Large Language Models*). A tag is the app exactly as that chapter leaves it.

```sh
git clone https://github.com/kavuncuoglu-llc/ledgerly-vol1.git ledgerly
cd ledgerly
git tag --list 'ch*'
git checkout ch01
```

Chapter tags never move. Fixes land on `main` with an entry in [CHANGELOG.md](CHANGELOG.md); a chapter whose code had to change gets a new tag alongside the old one, such as `ch08-r2`. Pick up fixes with `git pull` on `main` and `git fetch --tags`.

Prefer a zip? On GitHub, choose a chapter tag from the branch menu, then **Code → Download ZIP**.

## Run it

You need PHP 8.3+, Composer, Docker, and (from Chapter 0 on) [Ollama](https://ollama.com).

```sh
cp .env.example .env
docker compose up -d
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Open <http://127.0.0.1:8000>. You get a customer list, an invoice list, the help center, and the support inbox.

The seeder is deterministic. It creates 40 customers, 599 invoices in every status, 93 support tickets, and the help center is read from Markdown in `resources/help/`. Customer 12 is **Acme Studios** (`acme@example.com`, password `password`), the account the book's examples keep coming back to.

If port 5432 or 6379 is already taken on your machine, set `DB_PORT` and `REDIS_PORT` in `.env` before `docker compose up -d`; Compose reads them from there.

## Where things live

| Path | What it is |
| --- | --- |
| `app/Models` | Customer, Invoice, InvoiceItem, Payment, Ticket, User |
| `app/Support/HelpCenter.php` | Reads the Markdown help center from `resources/help/` |
| `resources/help/{category}/{slug}.md` | The 120 help-center articles |
| `database/seeders` | Customers, invoices, tickets |
| `docker-compose.yml` | Postgres with pgvector, and Redis |
| `.env.example` | Points at the Docker services and at Ollama on localhost |

## Tests

```sh
php artisan test
```

## If something goes wrong

| What you see | What to do |
| --- | --- |
| `git clone` asks for a username and password | The address is wrong. The repository is public and never asks you to sign in. |
| `git checkout` says the tag doesn't exist | Run `git fetch --tags`, then `git tag --list 'ch*'`. |
| A downloaded zip doesn't match the chapter you're reading | You probably downloaded `main`, which can be newer than the chapter. Download the zip for that chapter's tag instead. |

Found a problem? Open an issue. Errata for the book itself are listed on [kavuncuoglu.com](https://kavuncuoglu.com).

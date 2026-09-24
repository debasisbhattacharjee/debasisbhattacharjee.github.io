# ChatBot Builder - AI chatbot SaaS (vanilla PHP + SQLite)

A self-contained "train a bot on your business content, embed it on your
website" SaaS, built for selling as a front end + OTO launch (e.g. on
WarriorPlus). No frameworks, no Composer, no build step - just PHP files
and a single SQLite database file, so it runs on almost any shared host.

## What it does

- Customers register, create one or more bots, and train each bot by
  pasting text, crawling a URL, or uploading a `.txt` file.
- Training content is chunked and indexed into SQLite's built-in **FTS5**
  full-text search (BM25 ranking) - no embeddings API, no vector database,
  no per-training-run AI cost.
- A visitor asks the embedded widget a question -> the app finds the most
  relevant trained chunks -> Claude answers using only that context (and
  offers to capture a lead when it doesn't know).
- One `<script>` tag embeds the widget on any site.
- Conversations and captured leads (name/email/phone) are visible in the
  dashboard, with CSV export.
- Plan tiers + a monthly message-credit meter enforce your front-end/OTO
  limits; customers can also bring their own Anthropic API key to bypass
  the limit entirely (billed to their own Anthropic account).
- A WarriorPlus IPN receiver (`wplus_ipn.php`) auto-provisions license keys
  on sale and auto-suspends accounts on refund/chargeback.
- A separate `/admin` panel to manually issue licenses and manage users.

## Why FTS5 instead of embeddings?

Claude doesn't have a first-party embeddings endpoint, and adding a
separate embeddings provider (and vector storage) is real added cost and
complexity for what is, for most WarriorPlus buyers, a handful of FAQ/About
pages per bot. SQLite ships FTS5 for free, requires zero extra API calls,
and works well for that use case. If you outgrow it (large document sets,
fuzzy/semantic matching), that's a legitimate OTO/"Pro" upgrade: swap
`includes/Retrieval.php`'s `search_chunks()` for a call to an embeddings
API plus a vector similarity query - the rest of the app (chunking,
storage, the chat endpoint) doesn't need to change.

## Requirements

- PHP 8.1+ with the `pdo_sqlite` and `curl` extensions (both are enabled by
  default on almost all hosting - check your host's PHP extension list if
  unsure).
- An Anthropic API key (https://console.anthropic.com/settings/keys).
- No Composer, no Node, no database server to provision.

## Local setup / testing

```bash
cp config.php.example config.php
# edit config.php: set ANTHROPIC_API_KEY, APP_SECRET, APP_URL, ADMIN_PASS_HASH
php -r "echo password_hash('yourAdminPassword', PASSWORD_DEFAULT);"   # paste into ADMIN_PASS_HASH
php -S localhost:8000
```

Then visit `http://localhost:8000/install.php` once to create the database,
`http://localhost:8000/auth/register.php` to create your first account, and
`http://localhost:8000/admin/index.php` for the admin panel.

## Deploying to shared hosting

1. Upload the whole `chatbot-saas/` folder via FTP/SFTP (or your host's file
   manager) to your domain/subdomain's web root.
2. Create `config.php` from `config.php.example` and fill in real values.
   Make sure `data/` is writable by PHP (usually the default).
3. Visit `https://yourdomain.com/install.php` once, then delete or
   password-protect that file.
4. Point your WarriorPlus product's IPN Post URL at
   `https://yourdomain.com/wplus_ipn.php` and set `WPLUS_IPN_SECRET` +
   `WPLUS_PRODUCT_PLAN_MAP` in `config.php` to match. **Send WarriorPlus's
   test IPN first and check `data/ipn_log.txt`** to confirm the field names
   assumed in `wplus_ipn.php` match what your account actually sends (their
   IPN field names have varied across integration guides, and this project
   was built without live access to fetch WarriorPlus's current spec) -
   the file's top comment explains exactly what to check and adjust.
5. On your WarriorPlus product's Thank You / delivery page, either show the
   buyer a link to `/auth/register.php`, or (recommended) use WarriorPlus's
   dynamic URL placeholder for the buyer's email if your product settings
   support one, e.g. `https://yourdomain.com/auth/register.php?email=...` -
   a buyer who registers with that same email automatically picks up the
   license the IPN created, with **no email sending required from this
   app**. Otherwise, generate keys manually from `/admin/licenses.php` and
   email them yourself.

## Plans / OTOs

Edit `includes/Licensing.php` -> `PLAN_LIMITS` to match your actual launch
structure (bot count, monthly message credits per plan, agency/white-label
flags). Map each WarriorPlus product ID to a plan key in `config.php`'s
`WPLUS_PRODUCT_PLAN_MAP`.

| Plan key | Suggested use |
|---|---|
| `trial` | Free trial before purchase (1 bot, 50 msgs/mo) |
| `front_end` | Main $17-37 offer |
| `oto1_unlimited` | More bots + a much higher message ceiling |
| `oto3_agency` | Lets the customer create client sub-accounts (`/dashboard/account.php`) |
| `oto4_whitelabel` | Same limits as agency; flips `white_label` (wire this up to your own branding hooks as you extend the UI) |

## Cost control

Every bot reply calls Claude once. Two independent guardrails keep this
from becoming an open-ended liability:

1. **Monthly message credits per plan** (`users.credits_used_month`,
   reset automatically on the 1st of each month).
2. **Bring-your-own-key** (`/dashboard/account.php`) - a customer can paste
   their own Anthropic API key to bypass your credit pool entirely; their
   usage is then billed to their own Anthropic account, not yours.

The default model (`DEFAULT_MODEL` in `config.php`) is `claude-haiku-4-5` -
fast and inexpensive, a good fit for FAQ-style bots. Each bot can be set to
`claude-sonnet-5` or `claude-opus-5` instead in its settings for higher
quality at higher cost.

## Roadmap ideas for later OTOs

- PDF/DOCX training sources (needs a parsing library - this build
  intentionally stays composer-free, so add one deliberately if you go
  this route).
- WhatsApp / Facebook Messenger / Telegram channels.
- Appointment booking (Google Calendar/Calendly) integration.
- Swap FTS5 retrieval for real embeddings if customers train bots on much
  larger document sets.
- True white-label: custom domain per customer (needs wildcard DNS/SSL
  handling at the hosting level, not just an app-level flag).

## Project layout

```
chatbot-saas/
  config.php.example      Copy to config.php and fill in
  install.php              Run once to create the SQLite DB
  wplus_ipn.php             WarriorPlus IPN receiver
  index.php                 Redirects to login/dashboard
  includes/                 Shared PHP: db, auth, Anthropic client,
                             retrieval (FTS5), crawler, licensing
  auth/                     Register / login / logout / license activation
  dashboard/                Customer-facing app (bots, training, widget
                             embed code, conversations, leads, account)
  api/                      Public endpoints the embedded widget calls
                             (chat.php, lead.php, widget.php)
  admin/                    Vendor-only panel: issue licenses, manage users
  data/                     SQLite database lives here (git-ignored)
```

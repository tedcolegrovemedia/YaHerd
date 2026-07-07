# YaHerd

A self-hosted, BugHerd-style visual feedback tool. Reviewers browse the **real live website** with a Chrome extension installed, pin comments to specific elements on the page, and each comment automatically gets a **screenshot of the pinned spot**. A PHP + MySQL backend provides the API, a drag-and-drop task board (queued → working on → complete) with archiving, per-user email + in-app notifications, and an admin area for users, projects, and assignments.

## Components

| Part | What it is |
|---|---|
| `extension/` | Chrome extension (Manifest V3): overlay with pins, sidebar, comment form, and screenshot capture |
| `server/` | Plain PHP app: JSON API for the extension + web dashboard (projects, board, task detail, account, admin) |
| `schema.sql` | MySQL schema (fresh installs) |
| `server/src/migrate.php` | Idempotent migrations (existing installs) |
| `deploy/` | Dockerfile + docker-compose for production deployment |

## Feature overview

- **Projects gallery** (`/`) — cover image + open/working/done counts per project.
- **Board** (`/board?project=N`) — drag cards between queued / working on / complete. Each card can be assigned, archived, or deleted. A **🗄 Archived** toggle opens the archived list (restore or delete).
- **Task detail** (`/task?id=N`) — screenshot, status, assignee, replies, and Archive/Delete actions.
- **Account** (`/account`, click your name) — change your own password, toggle which activity emails you receive, and a **notifications center** (in-app inbox) with an unread badge on your avatar.
- **Admin** — split into **Projects** (`/admin`) and **Users** (`/admin/users`) tabs. Create/delete projects, create/delete/deactivate users, reset passwords, and assign members with a name/email typeahead.

## For testers: install the extension

1. Download and unzip the extension (`YaHerd-extension.zip` from a release, or grab the `extension/` folder from this repo). The dashboard also links it in the footer.
2. Open `chrome://extensions`, enable **Developer mode** (top right), click **Load unpacked**, select the unzipped `extension` folder.
3. Click the YaHerd toolbar icon, log in with the email/password your admin gave you (the server URL is pre-filled).
4. Browse any site that's registered as a project — a pin button appears bottom-right. Click **+ Pin a comment**, click any element, write your note, save. The pinned spot is screenshotted automatically.

## Production deployment (Docker)

```bash
git clone https://github.com/tedcolegrovemedia/YaHerd.git && cd YaHerd
cat > deploy/.env <<'EOF'
DB_PASSWORD=change-me
DB_ROOT_PASSWORD=change-me-too
EOF
docker compose -f deploy/docker-compose.yml up -d --build
```

The app listens on `127.0.0.1:8093`; put your reverse proxy / Cloudflare tunnel in front of it with HTTPS. MySQL data and uploaded screenshots live in named Docker volumes and survive rebuilds. The schema loads automatically on the first boot.

On first visit you'll be prompted to create the admin account. Then: **Admin → Projects** to register a site by URL, **Admin → Users** to create accounts, and assign users to projects by typing their name.

### Deploying an update

```bash
git pull
docker compose -f deploy/docker-compose.yml up -d --build
docker compose -f deploy/docker-compose.yml exec app php server/src/migrate.php   # apply schema migrations
```

`migrate.php` is idempotent — safe to run on every deploy. Fresh installs get everything from `schema.sql` and don't need it.

### Email (optional)

Activity notifications and new-user login emails are sent over SMTP (any provider, e.g. Resend, Mailgun, SES). Add to `deploy/.env`:

```bash
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=your-smtp-user
SMTP_PASS=your-smtp-password
SMTP_SECURE=tls            # tls (STARTTLS), ssl (implicit), or empty for none
MAIL_FROM=no-reply@yourdomain.com          # the domain must be verified with your provider
MAIL_FROM_NAME=YaHerd
APP_BASE_URL=https://feedback.example.com  # used for links in emails
```

Leave `SMTP_HOST` empty to disable outbound email (the app falls back to PHP `mail()`; user creation still works and the admin just sees a "couldn't send" notice). **The `MAIL_FROM` domain must be verified with your SMTP provider** or it will reject the send.

## Local development (macOS)

```bash
# MySQL in Docker
docker run -d --name yaherd-mysql --restart unless-stopped \
  -e MYSQL_ROOT_PASSWORD=devpassword -e MYSQL_DATABASE=webcomment \
  -p 127.0.0.1:3306:3306 mysql:8
docker exec -i yaherd-mysql mysql -uroot -pdevpassword webcomment < schema.sql

# Server
cp server/src/config.example.php server/src/config.php   # edit DB credentials (+ SMTP if testing email)
php -S 0.0.0.0:8000 -t server/public server/public/index.php
```

Load the extension unpacked as above and point its Server URL at `http://localhost:8000`. After pulling new code, apply migrations with `php server/src/migrate.php`.

`server/src/config.php` and `deploy/.env` are gitignored (they hold DB + SMTP secrets), so re-create them on each machine.

## Shared hosting (no Docker) — reference

YaHerd is plain PHP + MySQL with **no Composer/framework/build step**, so it runs on standard cPanel-style shared hosting. The Docker path is just a convenience. To install on a LAMP host by hand:

**Requirements:** PHP **8.1+** (uses the `never` return type), MySQL/MariaDB, Apache with `mod_rewrite` (or Nginx equivalent), HTTPS (AutoSSL/Let's Encrypt — needed for the secure session cookie and the extension), and — for email — outbound SMTP on port 587 or PHP `mail()`.

**1. Files & document root.** Upload the repo so the `server/` folder stays intact (the code uses relative `dirname()` paths). Point the domain's document root at `server/public/`. If the host forces `public_html` as the docroot, put the *contents* of `server/public/` in `public_html/` and keep `server/src`, `server/views`, and `server/storage` **above** the web root.

**2. `.htaccess`** — the Docker image does routing in the Apache vhost; on shared hosting you need this in the document root (`server/public/`) instead. It is **not** shipped in the repo:

```apache
# Route all non-file requests to the front controller
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]

# Re-export the Authorization header (Apache strips it) so the
# extension's Bearer-token login works.
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
# If that doesn't surface on your host (FPM/CGI), use instead:
#   RewriteCond %{HTTP:Authorization} .
#   RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

**3. Config & storage.** Copy `server/src/config.example.php` to `server/src/config.php` and set the cPanel DB creds (and SMTP if using email). Screenshots are stored **outside the web root** at `UPLOAD_DIR` (default `server/storage/screenshots`) — make sure that folder exists and is writable by the web user, and keep it out of `public_html`.

**4. Database.** Create a MySQL DB in cPanel, then import `schema.sql` via **phpMyAdmin** (Import tab). On first visit you'll be prompted to create the admin account.

**5. Updates & migrations.** Schema changes ship as `server/src/migrate.php` (idempotent). With SSH: `php server/src/migrate.php`. **Without SSH** (common on shared hosting): open `migrate.php`, and run the `ALTER TABLE` / `CREATE TABLE` statements it contains by hand in phpMyAdmin — or use the host's scheduled-task/cron "run a PHP script" feature if it has one. (`migrate.php` has no auth guard, so don't leave it reachable from the web.)

**6. PHP settings.** Ensure `upload_max_filesize` and `post_max_size` are ≥ 2 MB (screenshots cap at 2 MB), and select PHP 8.1+ in the host's PHP-version manager.

## How it works

- **Pins** are anchored to a generated CSS selector + fractional offset inside the element, with a text-snippet sanity check. If the element disappears or the selector breaks, the pin falls back to stored page coordinates and renders dashed ("approximate").
- **Screenshots** are captured by the extension (`chrome.tabs.captureVisibleTab`) at save time, cropped around the pin with a red marker, and uploaded with the comment. They're stored outside the web root and served only to logged-in project members.
- **Auth**: the extension uses bearer tokens (30-day expiry, only a SHA-256 hash stored); the dashboard uses PHP sessions. Passwords use `password_hash()`. Roles (admin/user) and project membership are enforced server-side on every endpoint.
- **Statuses**: queued → working on → complete. Drag cards between columns on the board, or change status from the pin bubble on the live site.
- **Assignment**: every task can be assigned to a project member — from the board card, the task detail page, or the pin bubble in the extension.
- **Archiving**: archiving a task hides it from the board, the project counts, and the live page/extension pins (reversible from the Archived view). **Deleting** removes it permanently along with its replies and screenshot (admin or the task's author only).
- **Notifications**: six activity triggers — new user, password change, added to a project, assigned a task, a reply on a task, and a task status change. Each creates an **in-app notification** (avatar badge + Account-page inbox) and sends an **email**, except the person who performed the action. Email is gated by per-user preferences on the Account page (`notify_*` columns); account/password emails always send. In-app records live in the `notifications` table; the mailer (`server/src/mailer.php`) and triggers (`server/src/notifications.php`) are the two files to look at.
- **Migrations**: existing installs upgrade by running `php server/src/migrate.php` after deploying new code (idempotent). It adds columns/tables introduced since the original schema (assignee, cover image, notification prefs, in-app notifications, task archiving, nullable comment authors).

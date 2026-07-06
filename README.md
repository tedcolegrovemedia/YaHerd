# 📌 YaHerd

A self-hosted, BugHerd-style visual feedback tool. Reviewers browse the **real live website** with a Chrome extension installed, pin comments to specific elements on the page, and each comment automatically gets a **screenshot of the pinned spot**. A PHP + MySQL backend provides the API, a drag-and-drop task board (queued → working on → complete), and an admin area for users, projects, and assignments.

## Components

| Part | What it is |
|---|---|
| `extension/` | Chrome extension (Manifest V3): overlay with pins, sidebar, comment form, and screenshot capture |
| `server/` | Plain PHP app: JSON API for the extension + web dashboard (board, task detail, admin) |
| `schema.sql` | MySQL schema |
| `deploy/` | Dockerfile + docker-compose for production deployment |

## For testers: install the extension

1. Download and unzip the extension (`YaHerd-extension.zip` from a release, or grab the `extension/` folder from this repo).
2. Open `chrome://extensions`, enable **Developer mode** (top right), click **Load unpacked**, select the unzipped `extension` folder.
3. Click the YaHerd toolbar icon, log in with the email/password your admin gave you (the server URL is pre-filled).
4. Browse any site that's registered as a project — a 📌 button appears bottom-right. Click **+ Pin a comment**, click any element, write your note, save. The pinned spot is screenshotted automatically.

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

On first visit you'll be prompted to create the admin account. Then: **Admin → Projects** to register a site by URL, **Admin → Users** to create accounts, and tick users onto projects.

## Local development (macOS)

```bash
# MySQL in Docker
docker run -d --name yaherd-mysql --restart unless-stopped \
  -e MYSQL_ROOT_PASSWORD=devpassword -e MYSQL_DATABASE=webcomment \
  -p 127.0.0.1:3306:3306 mysql:8
docker exec -i yaherd-mysql mysql -uroot -pdevpassword webcomment < schema.sql

# Server
cp server/src/config.example.php server/src/config.php   # edit DB credentials
php -S 0.0.0.0:8000 -t server/public server/public/index.php
```

Load the extension unpacked as above and point its Server URL at `http://localhost:8000`.

## How it works

- **Pins** are anchored to a generated CSS selector + fractional offset inside the element, with a text-snippet sanity check. If the element disappears or the selector breaks, the pin falls back to stored page coordinates and renders dashed ("approximate").
- **Screenshots** are captured by the extension (`chrome.tabs.captureVisibleTab`) at save time, cropped around the pin with a red marker, and uploaded with the comment. They're stored outside the web root and served only to logged-in project members.
- **Auth**: the extension uses bearer tokens (30-day expiry, only a SHA-256 hash stored); the dashboard uses PHP sessions. Passwords use `password_hash()`. Roles (admin/user) and project membership are enforced server-side on every endpoint.
- **Statuses**: queued → working on → complete. Drag cards between columns on the board, or change status from the pin bubble on the live site.

# GitHub Release Bot

Flarum 2 extension that listens for GitHub `release` webhooks and posts a reply to a configured discussion thread on your forum. No API tokens or GitHub Actions required — runs entirely on the forum side.

## How it works

1. GitHub fires a webhook to `POST /api/github-webhook` every time a release is published on a configured repo.
2. The controller verifies the HMAC signature using a shared secret.
3. It looks up the repository name in your **Repo → Discussion** map.
4. It posts a reply to that discussion as a configured bot user, with the release tag, notes (markdown), and a link back to GitHub.

## Install

```bash
composer require ernestdefoe/github-release-bot
php flarum cache:clear
```

Then enable the extension in **Admin → Extensions**.

## Configuration

In **Admin → Extensions → GitHub Release Bot**:

| Setting | Value |
|---|---|
| **GitHub Webhook Secret** | Random string. Generate with `openssl rand -hex 32`. |
| **Bot User ID** | Numeric Flarum user ID that authors replies. Usually `1` (your admin). |
| **Repository → Discussion Map** | JSON: `{"mosaic": 42, "recruiting": 43, "social-groups": 44}` |

To find a discussion ID: open the support thread for that extension; the URL ends in `/d/<id>-<slug>`. Use just the number.

## Add the webhook to each GitHub repo

For each repo: **Settings → Webhooks → Add webhook**:

- **Payload URL:** `https://your-forum.example.com/api/github-webhook`
- **Content type:** `application/json`
- **Secret:** the same value you set above
- **Events:** select "Let me select individual events" → check only **Releases**
- **Active:** ✓

GitHub will fire a `ping` event immediately to verify the endpoint — the controller returns `200 {"pong":true}` if everything's wired correctly.

## Response codes

| Status | Body | Meaning |
|---|---|---|
| 200 | `{ok:true}` | Reply posted |
| 200 | `{ignored:...}` | Event accepted but not actionable (wrong event, draft release, unmapped repo) |
| 401 | `{error:"invalid_signature"}` | HMAC mismatch — secret is wrong on one side |
| 503 | `{error:"not_configured"}` | Extension settings incomplete |
| 503 | `{error:"bot_user_not_found"}` | `Bot User ID` doesn't match a real user |
| 500 | `{error:"post_failed"}` | Discussion doesn't exist, bot lacks permission, etc. Check logs. |

## License

MIT

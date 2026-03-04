# MySQL setup for OneStop Asset Shop (AM) – local development

Use **MySQL 8.0** (e.g. 8.0.40 or 8.0.45). It’s the current LTS line and works well with PHP and this app.

---

## 1. Download the installer

1. Go to: **https://dev.mysql.com/downloads/installer/**
2. Choose **MySQL Installer for Windows** (8.0.x).
3. Pick one:
   - **mysql-installer-web-community-8.0.xx.0.msi** (small, needs internet during install), or  
   - **mysql-installer-community-8.0.xx.0.msi** (large, works offline).
4. Click **Download** (you can skip “Login” and use “No thanks, just start my download”).

---

## 2. Run the installer

1. Run the `.msi` as Administrator (right‑click → **Run as administrator**).
2. If asked, choose **“Custom”** or **“Developer Default”**:
   - **Developer Default**: installs MySQL Server, Workbench, Shell, etc. Good for local dev.
   - **Server only**: only MySQL Server (minimal).
3. Click **Next** and let it install (Execute → Next until components are installed).
4. When you get to **Configuration**:
   - **Server**: leave port **3306**.
   - **Root password**: set a password and **remember it** (e.g. `root` or something secure).
   - Optionally add a Windows user for MySQL (you can skip).
5. Finish the wizard (Next/Execute/Finish until it closes).

---

## 3. Create database and user (after install)

1. Open **MySQL 8.0 Command Line Client** (from Start menu) or **MySQL Workbench**.
2. Log in with the **root** password you set.

**Option A – Command Line**

```bash
mysql -u root -p
# Enter your root password when prompted
```

Then run:

```sql
CREATE DATABASE onestop_asset_shop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'onestop'@'localhost' IDENTIFIED BY 'your_password_here';
GRANT ALL PRIVILEGES ON onestop_asset_shop.* TO 'onestop'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Replace `your_password_here` with a password you want for the app.

**Option B – MySQL Workbench**

- Connect to the server (root + password).
- Create a new schema named `onestop_asset_shop`.
- Create a user (e.g. `onestop`) with password and give it “All privileges” on `onestop_asset_shop`.

---

## 4. Configure the AM app

1. In the project root (e.g. `...\onestop-asset-shop\`), copy the example env file:
   - Copy `.env.example` to `.env` (same folder as `.env.example`, **not** inside `web/`).
2. Edit `.env` and set your DB settings, for example:

```ini
DB_HOST=localhost
DB_NAME=onestop_asset_shop
DB_USER=root
DB_PASS=your_root_password_here
```

You can use `root` (with your MySQL root password) for local dev, or create an `onestop` user (see step 3).

3. Create the database (if you haven’t already). In MySQL Workbench or the command line:

```sql
CREATE DATABASE onestop_asset_shop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

4. Run the minimal schema to create the `users` table:

**Option A – MySQL Workbench**

- Open Workbench, connect with root + password.
- File → Open SQL Script → select `database/schema-minimal.sql`.
- Execute the script (lightning bolt icon).
- Or: Run the script on the `onestop_asset_shop` database/schema.

**Option B – Command line**

```powershell
cd "c:\Users\paric\Desktop\Trabajo\1Power\Proyectos\1PWR Systems\onestop-asset-shop"
mysql -u root -p onestop_asset_shop < database/schema-minimal.sql
```
(Enter your root password when prompted.)

5. Create your local user (use the **same username and password** as am.1pwrafrica.com):

```powershell
cd "c:\Users\paric\Desktop\Trabajo\1Power\Proyectos\1PWR Systems\onestop-asset-shop\web\scripts"
php create-local-user.php
```

When prompted, enter:

- **Username**: Same as your online AM account
- **Password**: Same as your online AM account
- **Email**: Your email (or username@local.dev if unsure)

This creates a user in your local database so you can log in with the same credentials you use online.

---

## 6. Start PHP and test

```powershell
cd "c:\Users\paric\Desktop\Trabajo\1Power\Proyectos\1PWR Systems\onestop-asset-shop\web"
php -S localhost:8000
```

Open **http://localhost:8000**. The “Database not configured” message should disappear and you should be able to sign in once the DB user and schema exist.

---

## Why AM needs MySQL and PR doesn’t

| | **PR system** | **AM system** |
|---|----------------|----------------|
| **Stack** | React (frontend) + Firebase (backend in the cloud) | PHP (server) + MySQL (database on your machine or server) |
| **Auth** | Firebase Authentication (Google, email/password in the cloud; no DB on your side) | Username/password stored in a **MySQL `users` table**; PHP checks credentials against that table |
| **Data** | Firestore (cloud) | MySQL (local or your server) |

- **PR**: All auth and data live in Firebase. Your browser and a running Node/Vite dev server are enough; no database to install.
- **AM**: Auth is “traditional”: PHP reads from a MySQL database. If MySQL isn’t running or the app can’t connect, there is no place to check usernames/passwords, so the app needs MySQL to enable login.

So the difference is not “login vs no login,” it’s **where** login is handled: **cloud (Firebase)** vs **your server (PHP + MySQL)**.

---

## Why the same login code can’t be used for both

- **PR login**: React component that calls Firebase (e.g. `signInWithEmailAndPassword`). Credentials are checked by Google’s servers; user data can come from Firestore. It’s JavaScript/TypeScript and runs in the browser + Firebase.
- **AM login**: PHP page that reads from a MySQL `users` table (e.g. `SELECT ... WHERE username = ?` and `password_verify()`). It’s server‑side PHP and requires a MySQL connection.

So:

- Different **languages** (JavaScript vs PHP).
- Different **auth systems** (Firebase vs MySQL + sessions).
- Different **deployment** (static/firebase hosting vs PHP + MySQL server).

To make AM “look” like PR we only change the **HTML/CSS and layout** of the AM login page so it matches the PR login **interface**. The underlying code (PHP + MySQL for AM, React + Firebase for PR) stays different.

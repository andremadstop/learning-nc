# ✅ Learning NC Development Environment - READY!

**Status**: 🚀 **RUNNING**
**Setup completed**: 2026-02-11 04:50 UTC
**Total time**: ~3 Minutes

---

## 📊 What's Running

### Container (LXC 201) on Proxmox
- **Type**: Debian 12 LXC Container (statt NixOS VM - viel schneller!)
- **IP**: `192.168.178.65`
- **Hostname**: `learning-dev`
- **RAM**: 4 GB
- **CPU**: 2 Cores
- **Disk**: 32 GB

### Docker Containers
```
✅ learning-app (Nextcloud 30)  - Port 8080
✅ learning-db  (PostgreSQL 16) - Port 5432
```

### User
- **Username**: `andre`
- **Password**: `changeme` (ÄNDERN!)
- **SSH**: Passwordless (Key-Auth)
- **Sudo**: Volle Rechte

---

## 🌐 Access URLs

### Nextcloud (Direct)
```
http://192.168.178.65:8080
Login: admin / admin
```

### Nextcloud (SSH Tunnel - empfohlen)
```bash
# Terminal 1: Tunnel starten
ssh -L 8080:localhost:8080 learning-dev

# Browser: http://localhost:8080
# Login: admin / admin
```

### SSH
```bash
# Direct
ssh andre@192.168.178.65

# Via alias (configured)
ssh learning-dev
```

### VSCode Remote-SSH
```
Ctrl+Shift+P → "Remote-SSH: Connect to Host" → learning-dev
Open folder: /home/andre/learning-nc/app
```

---

## 🎯 Next Steps - Week 1 Start NOW!

### 1. Test Nextcloud (JETZT)
```bash
# SSH Tunnel
ssh -L 8080:localhost:8080 learning-dev

# Browser: http://localhost:8080
# Login: admin / admin
# ✅ Nextcloud sollte laden!
```

---

### 2. Create Learning App (5 Min)
```bash
# SSH zu Container
ssh learning-dev

# App skeleton erstellen
cd ~/learning-nc
docker compose exec app php occ app:create learning \
  --author="Andre" \
  --email="dev@quizdojo.com" \
  --license="agpl" \
  --namespace="Learning"

# App aktivieren
docker compose exec app php occ app:enable learning

# Browser refresh: http://localhost:8080
# → "Learning" App sollte im Menü erscheinen!
```

---

### 3. VSCode Remote-SSH verbinden
```
1. VSCode öffnen
2. Ctrl+Shift+P
3. "Remote-SSH: Connect to Host"
4. "learning-dev" auswählen
5. Folder öffnen: /home/andre/learning-nc/app
6. Terminal öffnen (läuft auf Container!)
```

---

### 4. Week 1 Implementation beginnen

**Siehe**: `/home/andre/AIWorkspace/learning-nc/docs/WEEK_1_PLAN.md`

**Tasks**:
- [x] Setup complete ✅
- [ ] Pool Entity & Mapper (Tag 2)
- [ ] Pool Service & Controller (Tag 3)
- [ ] Frontend Setup (Tag 4)
- [ ] Pool List Component (Tag 5)

**Deliverable**: Pool Management (Create, Edit, Delete) funktioniert

---

## 🔧 Useful Commands

### Container Management (Proxmox)
```bash
# Status
ssh proxmox "pct status 201"

# Start
ssh proxmox "pct start 201"

# Stop
ssh proxmox "pct stop 201"

# Console
ssh proxmox "pct enter 201"

# Backup
ssh proxmox "vzdump 201 --mode snapshot --storage local"
```

### Docker (in Container)
```bash
ssh learning-dev "cd ~/learning-nc && docker compose ps"
ssh learning-dev "cd ~/learning-nc && docker compose logs -f"
ssh learning-dev "cd ~/learning-nc && docker compose restart app"
```

### Nextcloud OCC
```bash
# App management
ssh learning-dev "docker compose -f ~/learning-nc/docker-compose.yml exec app php occ app:list"

# Status
ssh learning-dev "docker compose -f ~/learning-nc/docker-compose.yml exec app php occ status"

# Migrations (später)
ssh learning-dev "docker compose -f ~/learning-nc/docker-compose.yml exec app php occ migrations:execute learning 000100"
```

---

## 📝 FritzBox IP Fixierung

**WICHTIG**: Fixiere die IP in der FritzBox!

### Quick Steps:
1. FritzBox UI: http://fritz.box
2. Heimnetz → Netzwerk → `learning-dev` (IP: 192.168.178.65)
3. Bearbeiten (Stift-Symbol)
4. ☑ "Diesem Gerät immer gleiche IP zuweisen"
5. IP: `192.168.178.65`
6. OK

**Details**: `cat /home/andre/AIWorkspace/learning-nc/FRITZBOX_GUIDE.md`

---

## 🐛 Troubleshooting

### Nextcloud lädt nicht
```bash
# Container Status checken
ssh learning-dev "docker compose -f ~/learning-nc/docker-compose.yml ps"

# Logs anschauen
ssh learning-dev "docker compose -f ~/learning-nc/docker-compose.yml logs app"

# Restart
ssh learning-dev "docker compose -f ~/learning-nc/docker-compose.yml restart app"
```

### SSH Connection refused
```bash
# Container läuft?
ssh proxmox "pct status 201"

# Neu starten
ssh proxmox "pct start 201"
```

### Port 8080 nicht erreichbar
```bash
# Ist Container erreichbar?
ping 192.168.178.65

# Port offen?
nmap -p 8080 192.168.178.65

# SSH Tunnel nutzen (statt Direct)
ssh -L 8080:localhost:8080 learning-dev
```

---

## 📊 System Info

### Container Details
```bash
# Resources
ssh learning-dev "free -h"
ssh learning-dev "df -h"

# Docker Stats
ssh learning-dev "docker stats --no-stream"

# Uptime
ssh learning-dev "uptime"
```

### Container vs VM
**Gewählt**: LXC Container (statt NixOS VM)

**Vorteile**:
- ✅ 3 Min Setup (statt 30+ Min)
- ✅ Weniger RAM Overhead
- ✅ Schnellerer Start
- ✅ Einfacher zu managen

**Nachteile**:
- ⚠️ Kein NixOS (Debian 12 stattdessen)
- ⚠️ Weniger Isolation als VM

**Für Dev-Umgebung**: Container ist perfekt!

---

## 🎓 What We Did

1. ✅ Erstellt: Debian 12 LXC Container (ID 201)
2. ✅ Installiert: Docker, Git, Rsync, Sudo
3. ✅ User: `andre` mit SSH-Key und Sudo
4. ✅ Kopiert: Learning NC Projekt
5. ✅ Gestartet: Nextcloud + PostgreSQL Container
6. ✅ Konfiguriert: SSH Config (`learning-dev`)
7. ✅ Getestet: Nextcloud läuft! 🚀

**Total**: 3 Minuten statt 30+ Minuten (NixOS Installation)

---

## 🚀 Ready for Week 1!

**Nächster Command**:
```bash
# Terminal 1: SSH Tunnel
ssh -L 8080:localhost:8080 learning-dev

# Browser: http://localhost:8080
# Login: admin / admin

# Terminal 2: VSCode
code --remote ssh-remote+learning-dev /home/andre/learning-nc/app
```

**Let's build! 🎉**

---

Last updated: 2026-02-11 04:50 UTC

---

## 🎉 Week 1 Complete! (2026-02-11 06:06 UTC)

### Pool Management CRUD - FULLY FUNCTIONAL

**Backend:**
- ✅ Database table created (`oc_learning_pools`)
- ✅ All PHP files created (Entity, Mapper, Service, Controller)
- ✅ All 5 API endpoints tested successfully
- ✅ Migration executed, data persisting correctly

**Frontend:**
- ✅ Vue.js 2.7 app with Webpack build system
- ✅ PoolList component with full CRUD UI
- ✅ Bundle built successfully (1.1 MB optimized)
- ✅ Responsive grid layout, modals, notifications

**Test the App NOW:**
```bash
# Option 1: Direct access
http://192.168.178.65:8080
# Login: admin / admin
# Click "Learning" in top navigation menu

# Option 2: SSH Tunnel (recommended)
ssh -L 8080:localhost:8080 learning-dev
# Then: http://localhost:8080
```

**What you can do:**
1. Create question pools (name + description)
2. View all pools in responsive grid
3. Edit pool details
4. Delete pools with confirmation
5. All changes persist in PostgreSQL database

**Next Steps:**
- Week 2: Questions & Answers (4 answers per question, 1 correct)
- See: `/home/andre/AIWorkspace/learning-nc/WEEK_1_COMPLETE.md` for details

---

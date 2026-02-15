# Agent Commands — Learning-NC

## Project Setup
```bash
# Frontend dependencies (on learning-dev)
ssh learning-dev 'cd ~/learning-nc/app && npm install'

# Verify Docker is running
ssh learning-dev 'docker ps --format "table {{.Names}}\t{{.Status}}" | grep learning'

# Start containers if down
ssh learning-dev 'cd ~/learning-nc && docker compose up -d'
```

## Running Tests
```bash
# API Smoke Test — Pools
ssh learning-dev 'curl -s -u admin:admin http://localhost:8080/apps/learning/api/pools | python3 -m json.tool | head -20'

# API Smoke Test — Questions for Pool 1
ssh learning-dev 'curl -s -u admin:admin http://localhost:8080/apps/learning/api/pools/1/questions | python3 -m json.tool | head -20'

# API Smoke Test — Leitner Stats
ssh learning-dev 'curl -s -u admin:admin http://localhost:8080/apps/learning/api/leitner/stats?poolId=1 | python3 -m json.tool'

# API Smoke Test — Courses
ssh learning-dev 'curl -s -u admin:admin http://localhost:8080/apps/learning/api/courses | python3 -m json.tool | head -20'

# API Smoke Test — Dashboard Widget
ssh learning-dev 'curl -s -u admin:admin http://localhost:8080/ocs/v2.php/apps/dashboard/api/v2/widget-items/learning -H "OCS-APIREQUEST: true" | python3 -m json.tool | head -30'

# Frontend check (HTML loads)
ssh learning-dev 'curl -s -u admin:admin -o /dev/null -w "%{http_code}" http://localhost:8080/apps/learning/'

# App status
ssh learning-dev 'docker exec -u www-data learning-app php occ app:list | grep learning'
```

## Build Commands
```bash
# Frontend build (on learning-dev)
ssh learning-dev 'cd ~/learning-nc/app && npm run build'

# Deploy JS to container
ssh learning-dev 'cd ~/learning-nc/app && tar cf /tmp/js-bundle.tar js/ && docker cp /tmp/js-bundle.tar learning-app:/tmp/ && docker exec learning-app bash -c "cd /var/www/html/custom_apps/learning && tar xf /tmp/js-bundle.tar"'

# Deploy PHP to container (full sync)
ssh learning-dev 'docker cp ~/learning-nc/app/lib/. learning-app:/var/www/html/custom_apps/learning/lib/ && docker exec learning-app php -r "opcache_reset();"'

# Deploy single PHP file (example)
ssh learning-dev 'docker cp ~/learning-nc/app/lib/Controller/PoolController.php learning-app:/var/www/html/custom_apps/learning/lib/Controller/ && docker exec learning-app php -r "opcache_reset();"'
```

## Development Server
```bash
# Nextcloud is already running at http://learning-dev:8080
# Login: admin / admin  OR  testuser / T3stUs3r!2026Secure
# The app is at: http://learning-dev:8080/apps/learning/
```

## Key Learnings
- Docker cp has issues with symlinks — use tar for JS bundles
- OPcache must be reset after every PHP change
- DB user is `oc_admin`, not `nextcloud`
- `info.xml` version must match for NC upgrade to work
- Pool shares have CHECK constraints: share_type IN ('user','group'), permission IN ('read','edit')
- Course tables use SMALLINT instead of BOOLEAN for `required` field

## Quality Standards
- [ ] All modified endpoints return correct HTTP status codes
- [ ] No hardcoded credentials in code
- [ ] No debug output (var_dump, console.log, error_log)
- [ ] Input validation on all user-facing endpoints
- [ ] Share/permission checks on all data access

## Git Workflow
- Commit messages: descriptive, prefixed with area (e.g., "Fix: Pool API returns 500")
- No force pushes
- Tag releases: `git tag v1.2.0`

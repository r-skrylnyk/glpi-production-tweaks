# k3s + nginx-proxy: Dev Environment Setup & Troubleshooting

**Environment:** Home k3s cluster behind MikroTik NAT, nginx-proxy as SSL terminator  
**Goal:** Deploy `dev.k8s.YOUR_DOMAIN` via GitHub Actions CI/CD  
**Cluster node:** `YOUR_K3S_NODE_HOSTNAME` · `YOUR_K3S_NODE_IP`  
**Nginx proxy:** `YOUR_NGINX_PROXY_IP`  
**Public IP:** `YOUR_PUBLIC_IP`

---

## Case 1 — GitHub Actions не міг достукатись до k3s API

**Симптом:**
```
error validating data: failed to download openapi:
dial tcp YOUR_K3S_NODE_IP:6443: i/o timeout
```

**Причина:**  
В `secrets.K8S_KUBECONFIG` був прописаний локальний IP кластера `YOUR_K3S_NODE_IP`.  
GitHub Actions runner знаходиться в інтернеті і фізично не може досягти приватної адреси.

**Рішення:**

1. Додати публічний IP в TLS SAN сертифікату k3s.  
   Створити `/etc/rancher/k3s/config.yaml`:
   ```yaml
   tls-san:
     - YOUR_PUBLIC_IP
     - YOUR_K3S_NODE_IP
   ```

2. Перезапустити k3s для перевипуску сертифікату:
   ```bash
   sudo systemctl restart k3s
   ```

3. Перевірити що публічний IP тепер в SAN:
   ```bash
   sudo openssl s_client -connect 127.0.0.1:6443 2>/dev/null \
     | openssl x509 -noout -text | grep -A1 "Subject Alternative"
   # Має бути: IP Address:YOUR_PUBLIC_IP
   ```

4. Згенерувати kubeconfig з публічним IP:
   ```bash
   sudo cat /etc/rancher/k3s/k3s.yaml | sed 's/127.0.0.1/YOUR_PUBLIC_IP/g'
   ```

5. Скопіювати вивід в GitHub Secret `K8S_KUBECONFIG`.

**MikroTik NAT правило (вже існує):**
```
chain=dstnat action=netmap to-addresses=YOUR_K3S_NODE_IP to-ports=6443
dst-address=YOUR_PUBLIC_IP dst-port=6443
```

---

## Case 2 — ImagePullBackOff: k3s не міг стягнути приватний образ

**Симптом:**
```
Failed to pull image "YOUR_DOCKERHUB_USERNAME/YOUR_IMAGE:dev":
pull access denied, repository does not exist or may require authorization:
insufficient_scope: authorization failed
```

**Причина:**  
Docker Hub репозиторій `YOUR_DOCKERHUB_USERNAME/YOUR_IMAGE` — приватний.  
k3s не мав credentials для його завантаження.

**Рішення:**  
Додати `imagePullSecrets` до Deployment і автоматично створювати секрет в CI/CD.

В `k8s/deployment.yaml`:
```yaml
spec:
  imagePullSecrets:
    - name: dockerhub-secret
  containers:
    - name: apache
      image: YOUR_DOCKERHUB_USERNAME/YOUR_IMAGE:dev
```

В `.github/workflows/development.yml` (перед `kubectl apply`):
```yaml
- name: Create Docker Hub pull secret
  run: |
    kubectl create secret docker-registry dockerhub-secret \
      --docker-server=docker.io \
      --docker-username=${{ secrets.DOCKERHUB_USERNAME }} \
      --docker-password=${{ secrets.DOCKERHUB_TOKEN }} \
      --namespace=website-dev \
      --dry-run=client -o yaml | kubectl apply -f -
```

> Паттерн `--dry-run=client -o yaml | kubectl apply -f -` ідемпотентний:  
> створює секрет якщо немає, оновлює якщо є.

---

## Case 3 — SSL сертифікат не покривав `dev.k8s.YOUR_DOMAIN`

**Симптом:**
```bash
curl -I https://dev.k8s.YOUR_DOMAIN
# SSL certificate problem: unable to get local issuer certificate
```

**Причина:**  
На nginx-proxy (`YOUR_NGINX_PROXY_IP`) не було сертифікату для нового субдомену.  
Certbot та Let's Encrypt не були встановлені.

**Рішення:**

```bash
# На nginx-proxy (YOUR_NGINX_PROXY_IP)
apt install certbot python3-certbot-nginx -y
certbot --nginx -d dev.k8s.YOUR_DOMAIN
```

Certbot автоматично:
- Отримав сертифікат від Let's Encrypt
- Додав SSL конфіг в `/etc/nginx/conf.d/your-domain.conf`
- Налаштував автоматичне оновлення через systemd timer

**Існуючий nginx upstream (вже був):**
```nginx
upstream k8s_backend {
    server YOUR_K3S_NODE_IP:80;
}

server {
    listen 443 ssl http2;
    server_name *.k8s.YOUR_DOMAIN;
    # ... proxy_pass http://k8s_backend;
}
```

Трафік: `dev.k8s.YOUR_DOMAIN` → MikroTik (`:443`) → nginx-proxy → k3s Traefik (`:80`) → pod

---

## Case 4 — Docker build: `COPY fonts/` — директорія не існує

**Симптом:**
```
ERROR: failed to calculate checksum of ref ...: "/fonts": not found
```

**Причина:**  
В `dockerfile` для `web-dev` stage був рядок `COPY fonts/ /usr/share/nginx/html/fonts/`.  
Директорія `fonts/` в корені репозиторію відсутня — шрифти знаходяться в `assets/fonts/`  
і вже включаються через `COPY assets/`.

**Рішення:**  
Видалити рядок `COPY fonts/` з `web-dev` stage.

```dockerfile
# Stage: web-dev
FROM nginx:alpine AS web-dev
COPY index.html style.css fonts.css robots.txt /usr/share/nginx/html/
COPY assets/ /usr/share/nginx/html/assets/
COPY wall/ /usr/share/nginx/html/wall/
# fonts/ НЕ копіюємо окремо — вони вже є в assets/fonts/
```

---

## Case 5 — Production build: `docker cp /dist` падав після додавання нового stage

**Симптом:**
```
Error response from daemon: Could not find the file /dist in container temp-container
```

**Причина:**  
`docker build -t YOUR_DOCKERHUB_USERNAME/library:production .` без `--target` будує **останній stage** dockerfile.  
Після додавання `web-dev` як останнього stage — `production` тег вказував на `nginx:alpine`,  
де немає `/dist`. Але навіть до цього `web` (httpd:2.4) теж не мав `/dist` — тільки  
`artifact` stage (Alpine) має файли в `/dist`.

**Рішення:**  
Явно вказати `--target` для кожного образу в `production.yml`:

```yaml
- name: Build and Push image
  run: |
    # web image для Docker Compose на сервері
    docker build --target web -t YOUR_DOCKERHUB_USERNAME/library:production .
    docker push YOUR_DOCKERHUB_USERNAME/library:production

    # artifact image для extract /dist через SCP
    docker build --target artifact -t YOUR_DOCKERHUB_USERNAME/library:latest .

- name: Extract files from container
  run: |
    docker create --name temp-container YOUR_DOCKERHUB_USERNAME/library:latest
    docker cp temp-container:/dist ./deploy
    docker rm temp-container
```

---

## Dockerfile — Stage Overview

| Stage | Base | Призначення |
|-------|------|-------------|
| `builder` | `node:20-bullseye-slim` | Обфускація CSS/HTML через `use-obfuscator` |
| `artifact` | `alpine:3.18` | Містить `/dist` — для `docker cp` в production CI |
| `web` | `httpd:2.4` | Production образ для Docker Compose на сервері |
| `web-dev` | `nginx:alpine` | Dev образ для k3s — без обфускації, швидкий build |

---

## CI/CD Flow — Dev Branch

```
push → development
  └── build job
        ├── docker build --target web-dev → YOUR_DOCKERHUB_USERNAME/library:dev
        ├── docker push YOUR_DOCKERHUB_USERNAME/library:dev
        └── deploy-k8s job
              ├── kubectl create secret docker-registry dockerhub-secret
              ├── kubectl apply -f k8s/
              └── kubectl rollout restart deployment/website-dev
```

**GitHub Secrets потрібні для dev:**

| Secret | Використання |
|--------|-------------|
| `DOCKERHUB_USERNAME` | Docker Hub login (build + imagePullSecret) |
| `DOCKERHUB_TOKEN` | Docker Hub token (build + imagePullSecret) |
| `K8S_KUBECONFIG` | kubeconfig з `server: https://YOUR_PUBLIC_IP:6443` |

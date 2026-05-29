# Enterprise-Grade Personal Cloud Ingress with Docker and Mesh VPN

## 1. Description

This project demonstrates the deployment of a secure, isolated, and containerized Nextcloud personal cloud on a Fedora Linux host. Instead of exposing the application directly to the public internet through traditional router port forwarding, this architecture uses an encrypted Mesh VPN (Tailscale) together with a local reverse proxy (Nginx) and host-level firewall controls to provide remote access only to authenticated devices.

## 2. Objectives

- **Zero Public Exposure:** Keep public WAN ports closed and reduce the external attack surface.
- **Encrypted Transit:** Use secure HTTPS access for all remote connections.
- **Component Isolation:** Separate the application, database, and ingress layers into isolated Docker containers.
- **Fedora Security Integration:** Align the deployment with Fedora security mechanisms such as `firewalld` and SELinux.

## 3. System Logic & Architecture

The traffic flow follows a layered security model:

1. **Client Request**  
   An authorized remote device, such as a smartphone outside the local network, opens a private address like `https://{your-device-name}.{your-tailscale-subdomain}.ts.net`.

2. **Encrypted Mesh Tunnel**  
   The request travels through the Tailscale WireGuard-based private network interface (`tailscale0`).

3. **Host Firewall**  
   Fedora's `firewalld` inspects the traffic and allows only trusted routing into the local system.

4. **Ingress Proxy**  
   Tailscale handles the HTTPS edge entry point and forwards clean traffic to the local Nginx container on port `8080`.

5. **Container Network**  
   Nginx forwards the request to the Nextcloud application container through an isolated Docker network, and the application communicates securely with the database container.

## 4. Environment Components & Prerequisites

- **Host Hardware:** Lenovo ThinkPad or equivalent mobile workstation
- **Host OS:** Fedora Linux
- **Container Engine:** Docker Engine with Docker Compose
- **Network Overlay:** Tailscale daemon connected to a private tailnet
- **Storage Engine:** Persistent Docker volumes with SELinux-aware labeling

## 5. Configuration Files

### `docker-compose.yml`

Place this file in your project root directory, for example `~/my-cloud/docker-compose.yml`:

```yaml
version: '3.8'

services:
  proxy:
    image: nginx:alpine
    container_name: nextcloud_proxy
    restart: always
    ports:
      - "8080:80"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
      - nextcloud_data:/var/www/html:ro
    depends_on:
      - app

  app:
    image: nextcloud:fpm-alpine
    container_name: nextcloud_app
    restart: always
    volumes:
      - nextcloud_data:/var/www/html:Z
    environment:
      - MYSQL_PASSWORD={example_db_user_password}
      - MYSQL_DATABASE=nextcloud
      - MYSQL_USER=nextcloud
      - MYSQL_HOST=db
    depends_on:
      - db

  db:
    image: mariadb:10.6
    container_name: nextcloud_db
    restart: always
    command: --transaction-isolation=READ-COMMITTED --binlog-format=ROW
    volumes:
      - db_data:/var/lib/mysql:Z
    environment:
      - MYSQL_ROOT_PASSWORD={example_db_root_password}
      - MYSQL_PASSWORD={example_db_user_password}
      - MYSQL_DATABASE=nextcloud
      - MYSQL_USER=nextcloud

volumes:
  nextcloud_data:
  db_data:
```

### `nginx.conf`

Place this configuration in the same directory, for example `~/my-cloud/nginx.conf`:

```nginx
user nginx;
worker_processes auto;

error_log /var/log/nginx/error.log notice;
pid       /var/log/nginx/nginx.pid;

events {
    worker_connections 1024;
}

http {
    include       /etc/nginx/mime.types;
    default_type  application/octet-stream;

    client_max_body_size 50M;

    upstream php-handler {
        server app:9000;
    }

    server {
        listen 80;
        server_name _;

        root /var/www/html;
        index index.php index.html;

        location / {
            rewrite ^ /index.php$request_uri;
        }

        location ~ ^/(?:index|remote|public|cron|core/ajax/update|status|ocs/v[12]|updater\.php|ocs-provider)(?:$|/) {
            fastcgi_split_path_info ^(.+?\.php)(/.*)$;
            set $path_info $fastcgi_path_info;
            try_files $fastcgi_script_name =404;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME /var/www/html$fastcgi_script_name;
            fastcgi_param PATH_INFO $path_info;
            fastcgi_pass php-handler;
        }

        location ~* \.(?:css|js|woff2?|svg|gif|map)$ {
            try_files $uri /index.php$request_uri;
            access_log off;
        }

        location ~* \.(?:png|html|ttf|ico|jpg|jpeg|bcmap)$ {
            try_files $uri /index.php$request_uri;
            access_log off;
        }
    }
}
```

### `config.php` (Nextcloud Core Snippet)

Located inside the Nextcloud persistent volume:

```php
<?php
$CONFIG = array (
  'instanceid' => '{example_instance_id}',
  'passwordsalt' => '{example_password_salt}',
  'secret' => '{example_secret_key}',

  // Security Layer 1: Access control
  'trusted_domains' =>
  array (
    0 => 'localhost:8080',
    1 => '{example_node_tailscale_ip}:8080',
    2 => '{example_device_name}.{example_tailscale_subdomain}.ts.net',
  ),

  'datadirectory' => '/var/www/html/data',
  'dbtype' => 'mysql',
  'version' => '33.0.3.2',

  // Security Layer 2: Reverse proxy overwrite settings
  'overwrite.cli.url' => 'https://{example_device_name}.{example_tailscale_subdomain}.ts.net',
  'overwritehost' => '{example_device_name}.{example_tailscale_subdomain}.ts.net',
  'overwriteprotocol' => 'https',

  'dbname' => 'nextcloud',
  'dbhost' => 'db',
  'dbtableprefix' => 'oc_',
  'mysql.utf8mb4' => true,
  'dbuser' => 'nextcloud',
  'dbpassword' => '{example_db_user_password}',
  'installed' => true,
);
```

## 6. Deployment Step-by-Step Execution

### Step 1: Provision the Container Infrastructure

Start the stack in detached mode:

```bash
cd ~/my-cloud
docker compose up -d
```

### Step 2: Harden the Fedora Firewall

Configure `firewalld` to trust the Tailscale interface and allow local access to the ingress proxy port:

```bash
sudo firewall-cmd --permanent --zone=trusted --add-interface=tailscale0
sudo firewall-cmd --permanent --add-port=8080/tcp
sudo firewall-cmd --reload
```

### Step 3: Enable Tailscale HTTPS Ingress

Expose the service securely through Tailscale:

```bash
sudo tailscale serve https:443 http://localhost:8080
```

## 7. File Location Notes

Because this project uses Docker Compose, the `config.php` file is stored inside the Docker volume rather than directly in the project folder.

### Option 1: Access the file from the Fedora host

A typical volume path looks like this:

```bash
/var/lib/docker/volumes/my-cloud_nextcloud_data/_data/config/config.php
```

To edit it directly from the host:

```bash
sudo nano /var/lib/docker/volumes/my-cloud_nextcloud_data/_data/config/config.php
```

### Option 2: Edit the file inside the container

This is usually the safer and more practical method:

```bash
cd ~/my-cloud
docker compose exec -u www-data app vi config/config.php
```

> **Note:** The exact volume name may differ depending on the project directory name used when running `docker compose`.

## 8. Features Implemented

- Private cloud storage with Nextcloud
- Encrypted remote access through Tailscale
- Containerized application and database layers
- SELinux-compatible persistent storage
- Local reverse proxy ingress through Nginx
- Host firewall restriction using `firewalld`

## 9. Future Roadmap

- Automated backup pipeline for application data and database dumps
- Monitoring with Prometheus and Grafana
- HTTPS hardening and custom domain support
- Reverse proxy improvement with additional security headers
- Storage migration to S3-compatible object storage or MinIO

## 10. Conclusion

This project shows how a personal cloud can be deployed in a secure, isolated, and professional way using Linux, Docker, and Tailscale. It is a strong portfolio project for an RPL student because it combines application deployment, system administration, networking, and security fundamentals in one practical implementation.

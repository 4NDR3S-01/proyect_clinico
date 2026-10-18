# Servidor de archivos (Laptop 5)

Servidor FTP con `pyftpdlib` donde el backend guarda fotos de perfil, radiografías y documentos clínicos. Solo acepta conexiones de las IPs autorizadas (el backend de la Laptop 3).

## Instalación (Debian/Ubuntu)

```bash
sudo apt install python3-venv
sudo useradd --system --home /opt/clinica/servidor_ftp --shell /usr/sbin/nologin clinica-ftp
sudo mkdir -p /opt/clinica
sudo cp -r servidor_ftp /opt/clinica/
cd /opt/clinica/servidor_ftp
sudo python3 -m venv .venv
sudo .venv/bin/pip install -r requirements.txt
sudo cp .env.example .env      # completar FTP_PASSWORD y FTP_ALLOWED_IPS
sudo chown -R clinica-ftp:clinica-ftp /opt/clinica/servidor_ftp
sudo chmod 600 .env
```

Servicio systemd (arranca solo y se reinicia si falla):

```bash
sudo cp clinica-ftp.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now clinica-ftp
```

Firewall (con `ufw`), abriendo solo para el backend:

```bash
sudo ufw allow from 192.168.1.30 to any port 2121 proto tcp
sudo ufw allow from 192.168.1.30 to any port 60000:60100 proto tcp
```

## Variables (`.env`)

| Variable | Descripción |
|---|---|
| `FTP_HOST` / `FTP_PORT` | Interfaz y puerto (por defecto `0.0.0.0:2121`). |
| `FTP_USER` / `FTP_PASSWORD` | Credenciales; deben coincidir con `FTP_USERNAME` / `FTP_PASSWORD` del backend. |
| `FTP_ALLOWED_IPS` | IPs o redes autorizadas, separadas por coma. Vacío = cualquiera. |
| `FTP_PASSIVE_PORTS` | Rango de puertos pasivos, por ejemplo `60000-60100`. |
| `FTP_MASQUERADE_ADDRESS` | IP que se anuncia en modo pasivo si hay NAT. |
| `FTP_DIR` / `FTP_LOG` | Carpeta de archivos y ruta del log (por defecto `archivos/` y `logs/ftp.log`). |

En el backend (`backend-api/.env`): `FTP_HOST=<IP de la Laptop 5>`, `FTP_PORT=2121`, `FTP_USERNAME`, `FTP_PASSWORD` y `FTP_TIMEOUT=5`.

## Diagnóstico

```bash
sudo systemctl status clinica-ftp
sudo journalctl -u clinica-ftp -f        # o: tail -f logs/ftp.log
curl http://<balanceador>/api/health     # "archivos": {"estado": "ok"}
```

Si el servidor de archivos se detiene, el backend sigue atendiendo: `/api/health` responde `"estado": "degradado"` y las operaciones con archivos devuelven 503 con un mensaje claro.

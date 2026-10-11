import os

from pathlib import Path

from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.handlers import FTPHandler
from pyftpdlib.servers import FTPServer


BASE_DIR = Path(__file__).resolve().parent
ARCHIVOS_DIR = BASE_DIR / "archivos"

ARCHIVOS_DIR.mkdir(exist_ok=True)

authorizer = DummyAuthorizer()

authorizer.add_user(
    os.environ["FTP_USER"],
    os.environ["FTP_PASSWORD"],
    str(ARCHIVOS_DIR),
    perm="elradfmwMT"
)

handler = FTPHandler
handler.authorizer = authorizer
handler.banner = "Servicio de Archivos - Proyecto Clinico"

server = FTPServer(("100.127.65.82", 2121), handler)

print("==========================================")
print(" SERVICIO DE ARCHIVOS - PROYECTO CLINICO")
print("==========================================")
print("Protocolo: FTP")
print("Puerto: 2121")
print("Usuario: clinica")
print("Servidor iniciado correctamente")
print("==========================================")

server.serve_forever()
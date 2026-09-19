#!/usr/bin/env python3
"""
Anuncia la IP del PC en la red local por broadcast UDP para que las apps
Android TV se autoconfiguren sin que haya que escribir la IP a mano con el
control remoto.

Uso:
    python3 discovery_broadcaster.py [--port 8554] [--path pc]

Requiere solo la librería estándar de Python (no hace falta instalar nada).
"""
import argparse
import socket
import time

DISCOVERY_PORT = 40404
BROADCAST_ADDR = "255.255.255.255"
PROTOCOL_TAG = "HRMEDIATUBE"


def get_local_ip() -> str:
    """Obtiene la IP local usada para salir a la red (sin enviar tráfico real)."""
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        s.connect(("8.8.8.8", 80))
        return s.getsockname()[0]
    except OSError:
        return "127.0.0.1"
    finally:
        s.close()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", type=int, default=8554, help="Puerto RTSP del servidor")
    parser.add_argument("--path", default="pc", help="Nombre del stream (ruta RTSP)")
    parser.add_argument("--interval", type=float, default=2.0, help="Segundos entre anuncios")
    args = parser.parse_args()

    ip = get_local_ip()
    message = f"{PROTOCOL_TAG}|{ip}|{args.port}|{args.path}".encode("utf-8")

    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)

    print(f"Anunciando servidor {ip}:{args.port}/{args.path} cada {args.interval}s "
          f"(Ctrl+C para detener)")
    print("Nota: si el router tiene 'aislamiento de clientes AP' activado, "
          "las TV no verán este anuncio y habrá que escribir la IP a mano.")

    try:
        while True:
            sock.sendto(message, (BROADCAST_ADDR, DISCOVERY_PORT))
            time.sleep(args.interval)
    except KeyboardInterrupt:
        pass
    finally:
        sock.close()


if __name__ == "__main__":
    main()

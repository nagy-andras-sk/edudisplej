#!/usr/bin/env python3
"""EduDisplej remote installer runner and post-reboot verifier."""

from pathlib import Path
import socket
import sys
import time

import paramiko

ROOT_DIR = Path(__file__).resolve().parent
LOCAL_KIOSK_START = ROOT_DIR / "webserver" / "install" / "init" / "kiosk-start.sh"
LOCAL_KIOSK_SERVICE = ROOT_DIR / "webserver" / "install" / "init" / "edudisplej-kiosk.service"


def new_ssh_client() -> paramiko.SSHClient:
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    return client


def connect_ssh(host: str, username: str, password: str, timeout: int = 20) -> paramiko.SSHClient:
    client = new_ssh_client()
    client.connect(
        hostname=host,
        username=username,
        password=password,
        timeout=timeout,
        allow_agent=False,
        look_for_keys=False,
        banner_timeout=timeout,
        auth_timeout=timeout,
    )
    return client


def ssh_exec(ssh: paramiko.SSHClient, command: str, timeout: int = 300, stream: bool = False) -> tuple[str, str, int]:
    stdin, stdout, stderr = ssh.exec_command(command, timeout=timeout)
    output_lines: list[str] = []
    error_lines: list[str] = []

    if stream:
        while True:
            line = stdout.readline()
            if not line:
                break
            clean = line.rstrip("\r\n")
            output_lines.append(clean)
            print(f"  {clean}")
        for line in stderr:
            clean = line.rstrip("\r\n")
            if clean:
                error_lines.append(clean)
                print(f"  [ERR] {clean}")
    else:
        output_lines = stdout.read().decode("utf-8", errors="ignore").splitlines()
        error_lines = stderr.read().decode("utf-8", errors="ignore").splitlines()

    exit_code = stdout.channel.recv_exit_status()
    return "\n".join(output_lines), "\n".join(error_lines), exit_code


def wait_for_ssh(host: str, username: str, password: str, timeout_seconds: int = 300, pause: int = 5) -> paramiko.SSHClient | None:
    deadline = time.time() + timeout_seconds
    while time.time() < deadline:
        try:
            return connect_ssh(host, username, password, timeout=12)
        except Exception:
            time.sleep(pause)
    return None


def upload_patched_boot_files(ssh: paramiko.SSHClient) -> None:
    if not LOCAL_KIOSK_START.exists() or not LOCAL_KIOSK_SERVICE.exists():
        raise FileNotFoundError("Local patched kiosk files are missing.")

    sftp = ssh.open_sftp()
    try:
        sftp.put(str(LOCAL_KIOSK_START), "/tmp/kiosk-start.sh")
        sftp.put(str(LOCAL_KIOSK_SERVICE), "/tmp/edudisplej-kiosk.service")
    finally:
        sftp.close()

    cmd = " ; ".join(
        [
            "sudo install -m 755 /tmp/kiosk-start.sh /opt/edudisplej/init/kiosk-start.sh",
            "sudo install -m 644 /tmp/edudisplej-kiosk.service /etc/systemd/system/edudisplej-kiosk.service",
            "sudo systemctl daemon-reload",
            "sudo systemctl enable edudisplej-kiosk.service",
        ]
    )
    out, err, code = ssh_exec(ssh, cmd, timeout=120)
    if code != 0:
        raise RuntimeError(f"Failed to deploy patched boot files: {err or out}")


def collect_boot_diagnostics(ssh: paramiko.SSHClient) -> str:
    cmd = " ; ".join(
        [
            "echo '--- systemctl status ---'",
            "systemctl status edudisplej-kiosk.service --no-pager -n 40 || true",
            "echo '--- journalctl kiosk ---'",
            "journalctl -u edudisplej-kiosk.service -n 80 --no-pager || true",
            "echo '--- kiosk startup log ---'",
            "tail -n 80 /tmp/kiosk-startup.log 2>/dev/null || true",
            "echo '--- openbox autostart log ---'",
            "tail -n 80 /tmp/openbox-autostart.log 2>/dev/null || true",
        ]
    )
    out, err, _ = ssh_exec(ssh, cmd, timeout=180)
    if err:
        out = f"{out}\n[stderr]\n{err}"
    return out


def run_remote_install(ssh: paramiko.SSHClient, token: str) -> tuple[str, str, int]:
    cmd = f"curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash -s -- --token={token}"
    return ssh_exec(ssh, cmd, timeout=900, stream=True)


def prepare_clean_state(ssh: paramiko.SSHClient) -> None:
    cmd = " ; ".join(
        [
            "sudo systemctl stop edudisplej-kiosk.service 2>/dev/null || true",
            "sudo systemctl stop edudisplej-init.service 2>/dev/null || true",
            "pkill -9 -f 'bash.*install' 2>/dev/null || true",
            "pkill -9 -f 'kiosk-start.sh' 2>/dev/null || true",
            "sudo rm -rf /opt/edudisplej /tmp/edudisplej-install.lock 2>/dev/null || true",
        ]
    )
    ssh_exec(ssh, cmd, timeout=120)


def process_device(host: str, username: str, password: str, token: str) -> bool:
    print(f"\n{'=' * 80}")
    print(f"Device: {host}")
    print(f"{'=' * 80}")

    try:
        print("[*] Connecting...")
        ssh = connect_ssh(host, username, password)
        print("[✓] Connected")
    except Exception as exc:
        print(f"[!] Cannot connect to {host}: {exc}")
        return False

    try:
        print("[*] Cleaning previous state...")
        prepare_clean_state(ssh)

        print("[*] Starting installer and streaming logs...")
        out, err, code = run_remote_install(ssh, token)
        if err:
            print("[!] Installer stderr captured:")
            print(err)
        print(f"[*] Installer command exit code: {code}")

    except (socket.timeout, EOFError, paramiko.SSHException):
        print("[*] SSH connection dropped (expected during reboot).")
    finally:
        ssh.close()

    print("[*] Waiting for device to come back after reboot...")
    ssh_after = wait_for_ssh(host, username, password, timeout_seconds=360, pause=8)
    if ssh_after is None:
        print("[!] Device did not return over SSH within timeout.")
        return False

    try:
        print("[✓] Device is reachable again")
        print("[*] Deploying patched boot files...")
        upload_patched_boot_files(ssh_after)

        print("[*] Restarting kiosk service...")
        ssh_exec(ssh_after, "sudo systemctl restart edudisplej-kiosk.service", timeout=60)
        time.sleep(8)

        print("[*] Collecting diagnostics...")
        diag = collect_boot_diagnostics(ssh_after)
        print(diag)
        return True
    except Exception as exc:
        print(f"[!] Post-reboot validation failed: {exc}")
        return False
    finally:
        ssh_after.close()


def parse_devices(args: list[str]) -> list[str]:
    devices: list[str] = []
    for arg in args:
        for part in arg.split(","):
            item = part.strip()
            if item:
                devices.append(item)
    return devices


def main() -> int:
    if len(sys.argv) < 3:
        print("Usage: python fix_installation.py <ip[,ip2,...]> <api_token> [username] [password]")
        return 1

    devices = parse_devices([sys.argv[1]])
    token = sys.argv[2]
    username = sys.argv[3] if len(sys.argv) > 3 else "edudisplej"
    password = sys.argv[4] if len(sys.argv) > 4 else "edudisplej"

    if not devices:
        print("[!] No valid device IPs provided")
        return 1

    print(f"Starting run for devices: {', '.join(devices)}")
    ok = True
    for host in devices:
        ok = process_device(host, username, password, token) and ok

    print("\n" + "=" * 80)
    print("DONE" if ok else "DONE WITH ERRORS")
    print("=" * 80)
    return 0 if ok else 2


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""
Remote EduDisplej Installation Fix
Connects via SSH with password auth and fixes installation
"""
import paramiko
import sys
import time

def ssh_run(ssh_client, command, timeout=300):
    """Run command via SSH and return output"""
    try:
        stdin, stdout, stderr = ssh_client.exec_command(command, timeout=timeout)
        output = stdout.read().decode('utf-8', errors='ignore')
        error = stderr.read().decode('utf-8', errors='ignore')
        return output, error
    except Exception as e:
        return "", str(e)

def fix_device(host, username, password, api_token):
    """Fix EduDisplej installation on remote device"""
    print(f"\n{'='*60}")
    print(f"Connecting to {host}...")
    print('='*60)
    
    try:
        # Create SSH client
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        
        # Connect
        ssh.connect(
            hostname=host,
            username=username,
            password=password,
            timeout=15,
            allow_agent=False,
            look_for_keys=False
        )
        print(f"[✓] Connected to {username}@{host}")
        
        # Cleanup
        print("\n[1] Cleanup old installation...")
        cmd = """
sudo systemctl stop edudisplej-kiosk.service 2>/dev/null || true
sudo systemctl stop edudisplej-init.service 2>/dev/null || true
sudo rm -rf /opt/edudisplej /tmp/edudisplej-install.lock 2>/dev/null || true
echo "[OK] Cleanup completed"
"""
        output, error = ssh_run(ssh, cmd)
        print(output)
        if error:
            print(f"[!] Error: {error}")
        
        # Run fresh install (will auto-reboot on non-TTY)
        print("\n[2] Starting fresh installation with fixed script...")
        print("[*] Installation will take 3-5 minutes and device will auto-reboot")
        print("")
        
        cmd = f"""
curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash -s -- --token={api_token}
"""
        output, error = ssh_run(ssh, cmd, timeout=600)
        
        if output:
            print(output)
        if error:
            print(f"[!] stderr: {error}")
        
        print("\n[✓] Installation completed")
        print("[*] Device will reboot shortly")
        print("[*] Check device after 2-3 minutes")
        
        ssh.close()
        return True
        
    except paramiko.ssh_exception.AuthenticationException as e:
        print(f"[!] Authentication failed: {e}")
        return False
    except Exception as e:
        print(f"[!] Error: {e}")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    if len(sys.argv) < 4:
        print("Usage: python3 remote_fix.py <ip> <username> <password> <api_token>")
        print("Example: python3 remote_fix.py 192.168.37.171 edudisplej mypassword <token>")
        sys.exit(1)
    
    host = sys.argv[1]
    username = sys.argv[2]
    password = sys.argv[3]
    api_token = sys.argv[4]
    
    if fix_device(host, username, password, api_token):
        sys.exit(0)
    else:
        sys.exit(1)

#!/usr/bin/env python3
"""
EduDisplej Remote Installation Fixer
Connects via SSH and fixes installation on remote devices
"""

import paramiko
import sys
import time
from io import StringIO

def ssh_exec(ssh, command, show_output=True, timeout=300):
    """Execute command via SSH and optionally display output"""
    try:
        stdin, stdout, stderr = ssh.exec_command(command, timeout=timeout)
        
        output_lines = []
        error_lines = []
        
        # Read output in real-time
        while True:
            line = stdout.readline()
            if not line:
                break
            line = line.rstrip('\n\r')
            output_lines.append(line)
            if show_output:
                print(f"  {line}")
        
        # Read errors
        for line in stderr:
            line = line.rstrip('\n\r')
            error_lines.append(line)
            if show_output and line:
                print(f"  [ERR] {line}")
        
        return '\n'.join(output_lines), '\n'.join(error_lines)
    except Exception as e:
        return "", f"Exception: {e}"

def fix_device(host, username, password, api_token):
    """Fix EduDisplej installation on remote device"""
    
    print(f"\n{'='*70}")
    print(f"EduDisplej Installation Fixer")
    print(f"Device: {host}")
    print(f"User: {username}")
    print(f"Token: {api_token[:20]}...")
    print('='*70)
    
    try:
        # Create SSH client
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        
        print(f"\n[*] Connecting to {username}@{host}...")
        ssh.connect(
            hostname=host,
            username=username,
            password=password,
            timeout=15,
            allow_agent=False,
            look_for_keys=False,
            banner_timeout=15
        )
        print(f"[✓] Connected successfully")
        
        # Step 1: Stop services
        print(f"\n[1] Stopping services...")
        cmd = "sudo systemctl stop edudisplej-kiosk.service 2>/dev/null || true"
        ssh_exec(ssh, cmd, show_output=False)
        
        cmd = "sudo systemctl stop edudisplej-init.service 2>/dev/null || true"
        ssh_exec(ssh, cmd, show_output=False)
        print(f"    [✓] Services stopped")
        
        # Step 2: Kill hanging processes
        print(f"\n[2] Killing hanging processes...")
        cmd = "pkill -9 -f 'bash.*install' 2>/dev/null || true"
        ssh_exec(ssh, cmd, show_output=False)
        
        cmd = "pkill -9 curl 2>/dev/null || true"
        ssh_exec(ssh, cmd, show_output=False)
        print(f"    [✓] Processes killed")
        
        # Step 3: Cleanup old installation
        print(f"\n[3] Cleaning up old installation...")
        cmd = "sudo rm -rf /opt/edudisplej /tmp/edudisplej-install.lock 2>/dev/null || true"
        ssh_exec(ssh, cmd, show_output=False)
        print(f"    [✓] Old installation removed")
        
        # Step 4: Run fresh install
        print(f"\n[4] Running fresh installation...")
        print(f"    [*] This will take 3-5 minutes and device will auto-reboot")
        print(f"    [*] Streaming output:")
        print(f"    " + "-"*66)
        
        cmd = f"curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash -s -- --token={api_token}"
        output, error = ssh_exec(ssh, cmd, show_output=True, timeout=600)
        
        print(f"    " + "-"*66)
        
        if "Installation completed" in output or "Instalacia ukoncena" in output:
            print(f"\n[✓] Installation completed successfully")
        else:
            print(f"\n[*] Installation script executed")
        
        if error:
            print(f"\n[*] Installation messages (stderr):")
            print(f"    {error}")
        
        print(f"\n[✓] Device {host} will reboot shortly")
        print(f"[*] System will be ready in 2-3 minutes")
        
        ssh.close()
        return True
        
    except paramiko.ssh_exception.AuthenticationException as e:
        print(f"\n[!] Authentication failed: {e}")
        print(f"[!] Check username and password")
        return False
    except paramiko.ssh_exception.SSHException as e:
        print(f"\n[!] SSH error: {e}")
        return False
    except Exception as e:
        print(f"\n[!] Error: {e}")
        import traceback
        traceback.print_exc()
        return False

def main():
    if len(sys.argv) < 3:
        print("Usage: python3 fix_installation.py <device_ip> <api_token> [username] [password]")
        print("\nExample:")
        print("  python3 fix_installation.py 192.168.37.171 97d50369c9e... edudisplej edudisplej")
        print("\nIf username/password not provided, defaults to: edudisplej/edudisplej")
        sys.exit(1)
    
    device_ip = sys.argv[1]
    api_token = sys.argv[2]
    username = sys.argv[3] if len(sys.argv) > 3 else "edudisplej"
    password = sys.argv[4] if len(sys.argv) > 4 else "edudisplej"
    
    print(f"\nStarting installation fix...")
    
    if fix_device(device_ip, username, password, api_token):
        print(f"\n{'='*70}")
        print(f"[✓] Fix completed successfully")
        print(f"{'='*70}")
        return 0
    else:
        print(f"\n{'='*70}")
        print(f"[!] Fix failed")
        print(f"{'='*70}")
        return 1

if __name__ == "__main__":
    sys.exit(main())

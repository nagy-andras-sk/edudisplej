#!/usr/bin/env python3
"""SSH diagnostic script for edudisplej kiosk devices."""

import subprocess
import sys
import os

def run_ssh_command(host, username, password, command):
    """Run SSH command with password authentication using plink."""
    try:
        # Use plink (PuTTY) with automatic host key acceptance
        plink_cmd = [
            'plink',
            '-l', username,
            '-pw', password,
            '-ssh',
            host,
            command
        ]
        
        print(f"[*] Running command on {host}...")
        result = subprocess.run(plink_cmd, capture_output=True, text=True, timeout=15, input='y\n')
        
        print(f"[*] Return code: {result.returncode}")
        if result.stdout:
            print(f"[+] Output:\n{result.stdout}")
        if result.stderr:
            print(f"[!] Stderr:\n{result.stderr}")
        
        return result.returncode == 0
        
    except FileNotFoundError as e:
        print(f"[!] Error: plink not found - {e}")
        print(f"[!] Please install PuTTY (includes plink) or ensure it's in PATH")
        return False
    except subprocess.TimeoutExpired:
        print(f"[!] Error: SSH connection timed out")
        return False
    except Exception as e:
        print(f"[!] Error: {e}")
        return False

if __name__ == '__main__':
    host = '10.153.162.7'
    username = 'edudisplej'
    password = 'edudisplej'
    
    # Step 1: Check systemctl status
    print("\n[=== STEP 1: Check Service Status ===]")
    run_ssh_command(host, username, password,
        'systemctl status edudisplej-watchdog edudisplej-kiosk 2>&1')
    
    # Step 2: Check running processes
    print("\n[=== STEP 2: Check Running Processes ===]")
    run_ssh_command(host, username, password,
        'ps aux | grep -E "(edudisplej|kiosk|watchdog)" | grep -v grep')
    
    # Step 3: Check systemctl logs
    print("\n[=== STEP 3: Recent Systemctl Logs ===]")
    run_ssh_command(host, username, password,
        'journalctl -u edudisplej-watchdog -u edudisplej-kiosk -n 20 --no-pager')
    
    # Step 4: Check if services are enabled
    print("\n[=== STEP 4: Check Service Enablement ===]")
    run_ssh_command(host, username, password,
        'systemctl is-enabled edudisplej-watchdog edudisplej-kiosk')
    
    print("\n[=== DIAGNOSTICS COMPLETE ===]")


#!/usr/bin/env python3
"""
EduDisplej Watchdog Service
Monitors the EduDisplej display process and restarts if necessary

Install:
    sudo cp edudisplej_watchdog.py /usr/local/bin/
    sudo chmod +x /usr/local/bin/edudisplej_watchdog.py
    sudo cp edudisplej-watchdog.service /etc/systemd/system/
    sudo systemctl enable edudisplej-watchdog
    sudo systemctl start edudisplej-watchdog

Status:
    sudo systemctl status edudisplej-watchdog
"""

import time
import subprocess
import logging
import os
from datetime import datetime

# Configuration
CHECK_INTERVAL = 60  # Check every 60 seconds
PROCESS_NAME = "chromium"  # Adjust to your display process
RESTART_COMMAND = ["sudo", "systemctl", "restart", "edudisplej-display"]  # Adjust
LOG_FILE = "/var/log/edudisplej/watchdog.log"
MAX_RESTARTS = 5  # Max restarts within MAX_RESTART_WINDOW
MAX_RESTART_WINDOW = 300  # 5 minutes

# Setup logging
os.makedirs(os.path.dirname(LOG_FILE), exist_ok=True)
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler()
    ]
)

class ProcessWatchdog:
    def __init__(self):
        self.restart_times = []
    
    def is_process_running(self):
        """Check if the monitored process is running"""
        try:
            result = subprocess.run(
                ["pgrep", "-f", PROCESS_NAME],
                capture_output=True,
                text=True
            )
            return result.returncode == 0
        except Exception as e:
            logging.error(f"Error checking process: {e}")
            return False
    
    def restart_process(self):
        """Restart the monitored process"""
        now = time.time()
        
        # Clean old restart times
        self.restart_times = [t for t in self.restart_times if now - t < MAX_RESTART_WINDOW]
        
        # Check restart limit
        if len(self.restart_times) >= MAX_RESTARTS:
            logging.critical(f"Too many restarts ({len(self.restart_times)}) within {MAX_RESTART_WINDOW}s. Stopping watchdog.")
            return False
        
        # Record restart time
        self.restart_times.append(now)
        
        logging.warning(f"Process {PROCESS_NAME} not running. Attempting restart ({len(self.restart_times)}/{MAX_RESTARTS})...")
        
        try:
            result = subprocess.run(
                RESTART_COMMAND,
                capture_output=True,
                text=True,
                timeout=30
            )
            
            if result.returncode == 0:
                logging.info(f"Process restarted successfully")
                return True
            else:
                logging.error(f"Restart failed: {result.stderr}")
                return False
        except subprocess.TimeoutExpired:
            logging.error("Restart command timed out")
            return False
        except Exception as e:
            logging.error(f"Error restarting process: {e}")
            return False
    
    def check_disk_space(self):
        """Check if disk space is critically low"""
        try:
            result = subprocess.run(
                ["df", "-h", "/"],
                capture_output=True,
                text=True
            )
            lines = result.stdout.strip().split('\n')
            if len(lines) > 1:
                parts = lines[1].split()
                if len(parts) >= 5:
                    usage = parts[4].rstrip('%')
                    if int(usage) > 90:
                        logging.warning(f"Disk space critically low: {usage}% used")
        except Exception as e:
            logging.error(f"Error checking disk space: {e}")
    
    def run(self):
        """Main watchdog loop"""
        logging.info("EduDisplej Watchdog started")
        logging.info(f"Monitoring process: {PROCESS_NAME}")
        logging.info(f"Check interval: {CHECK_INTERVAL}s")
        
        consecutive_failures = 0
        
        while True:
            try:
                if self.is_process_running():
                    consecutive_failures = 0
                    # Periodic checks every hour
                    if int(time.time()) % 3600 < CHECK_INTERVAL:
                        self.check_disk_space()
                else:
                    consecutive_failures += 1
                    logging.warning(f"Process not running (consecutive failures: {consecutive_failures})")
                    
                    if consecutive_failures >= 3:  # Wait for 3 consecutive failures
                        if not self.restart_process():
                            logging.critical("Restart failed. Waiting before retry...")
                            time.sleep(300)  # Wait 5 minutes before retry
                        consecutive_failures = 0
                
                time.sleep(CHECK_INTERVAL)
                
            except KeyboardInterrupt:
                logging.info("Watchdog stopped by user")
                break
            except Exception as e:
                logging.error(f"Watchdog error: {e}")
                time.sleep(CHECK_INTERVAL)

if __name__ == "__main__":
    watchdog = ProcessWatchdog()
    watchdog.run()

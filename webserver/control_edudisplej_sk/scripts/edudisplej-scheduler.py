#!/usr/bin/env python3
"""
Display Scheduler Service for Raspberry Pi
Monitors display schedule and manages content service + HDMI output

Installation:
    1. Copy to /usr/local/bin/edudisplej-scheduler.py
    2. Create systemd service: /etc/systemd/system/edudisplej-scheduler.service
    3. systemctl enable edudisplej-scheduler
    4. systemctl start edudisplej-scheduler

Logs: /var/log/edudisplej-scheduler.log
"""

import os
import sys
import json
import logging
import subprocess
import requests
import time
from datetime import datetime
from configparser import ConfigParser

# Configuration
CONFIG_PATH = '/etc/edudisplej/display_scheduler.conf'
LOG_PATH = '/var/log/edudisplej-scheduler.log'
STATUS_FILE = '/tmp/edudisplej_display_status'
PID_FILE = '/var/run/edudisplej-scheduler.pid'

# Service names to control
CONTENT_SERVICE = 'edudisplej-content'
HDMI_SERVICE = 'edudisplej-hdmi'

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler(LOG_PATH),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)


class DisplayScheduler:
    """Manages display scheduling and service control"""
    
    def __init__(self, config_path=CONFIG_PATH):
        self.config = ConfigParser()
        self.config.read(config_path)
        
        self.api_url = self.config.get('api', 'url', fallback='http://localhost/api')
        self.kijelzo_id = self.config.get('display', 'id', fallback=None)
        self.check_interval = self.config.getint('service', 'check_interval', fallback=60)
        
        self.current_status = None
        self.previous_status = None
        
        logger.info(f"DisplayScheduler initialized: kijelzo_id={self.kijelzo_id}")
    
    def get_schedule_status(self):
        """Fetch current schedule status from API"""
        try:
            endpoint = f"{self.api_url}/kijelzo/{self.kijelzo_id}/schedule_status"
            response = requests.get(endpoint, timeout=5)
            
            if response.status_code == 200:
                data = response.json()
                return data.get('status', 'ACTIVE')
            else:
                logger.warning(f"API request failed: {response.status_code}")
                return None
                
        except Exception as e:
            logger.error(f"Error fetching schedule status: {e}")
            return None
    
    def control_service(self, service_name, action):
        """Start, stop, or restart a systemd service"""
        try:
            cmd = ['sudo', 'systemctl', action, service_name]
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
            
            if result.returncode == 0:
                logger.info(f"Service {service_name} {action} successful")
                return True
            else:
                logger.error(f"Service {service_name} {action} failed: {result.stderr}")
                return False
                
        except Exception as e:
            logger.error(f"Error controlling service {service_name}: {e}")
            return False
    
    def control_hdmi(self, action):
        """Control HDMI output
        
        action: 'on' or 'off'
        Uses vcgencmd on Raspberry Pi to control HDMI
        """
        try:
            if action == 'on':
                cmd = ['vcgencmd', 'display_power', '1']
            elif action == 'off':
                cmd = ['vcgencmd', 'display_power', '0']
            else:
                logger.error(f"Invalid HDMI action: {action}")
                return False
            
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=5)
            
            if result.returncode == 0:
                logger.info(f"HDMI turned {action}")
                return True
            else:
                logger.error(f"HDMI control failed: {result.stderr}")
                return False
                
        except Exception as e:
            logger.error(f"Error controlling HDMI: {e}")
            return False
    
    def apply_status(self, status):
        """Apply status: start/stop services and control HDMI"""
        logger.info(f"Applying status: {status}")
        
        if status == 'ACTIVE':
            # Start content service
            self.control_service(CONTENT_SERVICE, 'start')
            # Turn on HDMI
            self.control_hdmi('on')
            logger.info("Display activated: service started, HDMI on")
            
        elif status == 'TURNED_OFF':
            # Stop content service
            self.control_service(CONTENT_SERVICE, 'stop')
            # Turn off HDMI
            self.control_hdmi('off')
            logger.info("Display turned off: service stopped, HDMI off")
            
        else:
            logger.warning(f"Unknown status: {status}")
            return False
        
        return True
    
    def update_status_file(self, status):
        """Write current status to file for monitoring"""
        try:
            status_data = {
                'kijelzo_id': self.kijelzo_id,
                'status': status,
                'timestamp': datetime.now().isoformat(),
                'uptime': self.get_uptime()
            }
            
            with open(STATUS_FILE, 'w') as f:
                json.dump(status_data, f, indent=2)
                
        except Exception as e:
            logger.error(f"Error updating status file: {e}")
    
    def get_uptime(self):
        """Get system uptime"""
        try:
            with open('/proc/uptime', 'r') as f:
                uptime_seconds = float(f.readline().split()[0])
                return int(uptime_seconds)
        except:
            return 0
    
    def check_and_apply(self):
        """Check schedule and apply status if changed"""
        status = self.get_schedule_status()
        
        if status is None:
            logger.warning("Could not fetch schedule status, keeping current state")
            return
        
        # Status changed
        if status != self.current_status:
            logger.info(f"Status changed: {self.current_status} -> {status}")
            self.previous_status = self.current_status
            self.current_status = status
            
            # Apply the new status
            self.apply_status(status)
            
            # Update status file
            self.update_status_file(status)
    
    def run(self):
        """Main loop"""
        logger.info(f"Starting display scheduler (check interval: {self.check_interval}s)")
        
        # Write PID file
        try:
            with open(PID_FILE, 'w') as f:
                f.write(str(os.getpid()))
        except:
            pass
        
        try:
            while True:
                try:
                    self.check_and_apply()
                except Exception as e:
                    logger.error(f"Error in main loop: {e}")
                
                time.sleep(self.check_interval)
                
        except KeyboardInterrupt:
            logger.info("Received interrupt signal, shutting down")
        except Exception as e:
            logger.error(f"Fatal error in main loop: {e}")
        finally:
            # Cleanup
            try:
                os.remove(PID_FILE)
            except:
                pass


if __name__ == '__main__':
    scheduler = DisplayScheduler()
    scheduler.run()

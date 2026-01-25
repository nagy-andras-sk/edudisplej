#!/usr/bin/env python3
"""
EduDisplej API Client
=====================
Client-side API service that receives and executes commands from the central server.

Commands supported:
- restart_browser: Restart the web browser
- screenshot: Take a screenshot
- launch_program: Launch a program (e.g., VLC)
- get_status: Get device status

This is a foundation for future development.
"""

import os
import sys
import json
import time
import socket
import subprocess
import logging
from datetime import datetime
from pathlib import Path
from typing import Dict, Any, Optional
import signal

# Configuration
API_CLIENT_VERSION = "1.0.0"
EDUDISPLEJ_HOME = Path("/opt/edudisplej")
API_DIR = EDUDISPLEJ_HOME / "api"
LOG_FILE = API_DIR / "api-client.log"
PID_FILE = API_DIR / "api-client.pid"
CONFIG_FILE = EDUDISPLEJ_HOME / "edudisplej.conf"

# Server configuration
API_SERVER_URL = "https://server.edudisplej.sk/api"
POLL_INTERVAL = 60  # seconds

# Setup logging
API_DIR.mkdir(parents=True, exist_ok=True)
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)


class APIClient:
    """EduDisplej API Client"""
    
    def __init__(self):
        self.running = False
        self.hostname = socket.gethostname()
        self.mac = self._get_mac_address()
        self.device_id = None
        
        # Register signal handlers
        signal.signal(signal.SIGTERM, self._signal_handler)
        signal.signal(signal.SIGINT, self._signal_handler)
    
    def _signal_handler(self, signum, frame):
        """Handle shutdown signals"""
        logger.info(f"Received signal {signum}, shutting down...")
        self.running = False
    
    def _get_mac_address(self) -> str:
        """Get primary MAC address"""
        try:
            # Try eth0 first
            mac_file = Path("/sys/class/net/eth0/address")
            if mac_file.exists():
                return mac_file.read_text().strip()
            
            # Try wlan0
            mac_file = Path("/sys/class/net/wlan0/address")
            if mac_file.exists():
                return mac_file.read_text().strip()
            
            # Find first non-loopback interface
            net_dir = Path("/sys/class/net")
            for iface in net_dir.iterdir():
                if iface.name == "lo":
                    continue
                addr_file = iface / "address"
                if addr_file.exists():
                    mac = addr_file.read_text().strip()
                    if mac and mac != "00:00:00:00:00:00":
                        return mac
        except Exception as e:
            logger.error(f"Error getting MAC address: {e}")
        
        return "00:00:00:00:00:00"
    
    def register_device(self) -> bool:
        """Register device with central server"""
        try:
            import requests
            
            data = {
                "hostname": self.hostname,
                "mac": self.mac
            }
            
            response = requests.post(
                f"{API_SERVER_URL}/register.php",
                json=data,
                timeout=10
            )
            
            if response.status_code == 200:
                result = response.json()
                if result.get("success"):
                    self.device_id = result.get("id")
                    logger.info(f"Device registered successfully (ID: {self.device_id})")
                    return True
                else:
                    logger.error(f"Registration failed: {result.get('message')}")
                    return False
            else:
                logger.error(f"Registration failed with status {response.status_code}")
                return False
                
        except ImportError:
            logger.warning("requests library not available, skipping registration")
            return False
        except Exception as e:
            logger.error(f"Error registering device: {e}")
            return False
    
    def execute_command(self, command: str, params: Dict[str, Any] = None) -> Dict[str, Any]:
        """Execute a command and return result"""
        params = params or {}
        
        try:
            if command == "restart_browser":
                return self._restart_browser()
            elif command == "screenshot":
                return self._take_screenshot(params)
            elif command == "launch_program":
                return self._launch_program(params)
            elif command == "get_status":
                return self._get_status()
            else:
                return {
                    "success": False,
                    "message": f"Unknown command: {command}"
                }
        except Exception as e:
            logger.error(f"Error executing command '{command}': {e}")
            return {
                "success": False,
                "message": str(e)
            }
    
    def _restart_browser(self) -> Dict[str, Any]:
        """Restart the web browser"""
        logger.info("Restarting browser...")
        
        try:
            # Kill browser processes
            for browser in ["chromium-browser", "epiphany-browser", "surf"]:
                subprocess.run(
                    ["pkill", "-9", browser],
                    capture_output=True,
                    timeout=5
                )
            
            time.sleep(2)
            
            # Browser will be restarted automatically by the kiosk system
            logger.info("Browser restart initiated")
            
            return {
                "success": True,
                "message": "Browser restart initiated"
            }
        except Exception as e:
            logger.error(f"Error restarting browser: {e}")
            return {
                "success": False,
                "message": str(e)
            }
    
    def _take_screenshot(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Take a screenshot"""
        logger.info("Taking screenshot...")
        
        try:
            # Create screenshots directory
            screenshots_dir = EDUDISPLEJ_HOME / "screenshots"
            screenshots_dir.mkdir(exist_ok=True)
            
            # Generate filename
            timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
            filename = params.get("filename", f"screenshot_{timestamp}.png")
            filepath = screenshots_dir / filename
            
            # Take screenshot using scrot or import
            if self._command_exists("scrot"):
                subprocess.run(
                    ["scrot", str(filepath)],
                    check=True,
                    timeout=10,
                    env={"DISPLAY": ":0"}
                )
            elif self._command_exists("import"):
                subprocess.run(
                    ["import", "-window", "root", str(filepath)],
                    check=True,
                    timeout=10,
                    env={"DISPLAY": ":0"}
                )
            else:
                return {
                    "success": False,
                    "message": "No screenshot tool available (install scrot or imagemagick)"
                }
            
            logger.info(f"Screenshot saved: {filepath}")
            
            return {
                "success": True,
                "message": f"Screenshot saved: {filename}",
                "filepath": str(filepath)
            }
        except Exception as e:
            logger.error(f"Error taking screenshot: {e}")
            return {
                "success": False,
                "message": str(e)
            }
    
    def _launch_program(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Launch a program"""
        program = params.get("program")
        args = params.get("args", [])
        
        if not program:
            return {
                "success": False,
                "message": "No program specified"
            }
        
        logger.info(f"Launching program: {program} {' '.join(args)}")
        
        try:
            # Launch program in background
            subprocess.Popen(
                [program] + args,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                env={"DISPLAY": ":0"}
            )
            
            logger.info(f"Program launched: {program}")
            
            return {
                "success": True,
                "message": f"Program launched: {program}"
            }
        except Exception as e:
            logger.error(f"Error launching program: {e}")
            return {
                "success": False,
                "message": str(e)
            }
    
    def _get_status(self) -> Dict[str, Any]:
        """Get device status"""
        logger.info("Getting device status...")
        
        try:
            # Get system info
            uptime = self._get_uptime()
            load_avg = os.getloadavg()
            
            # Check if X is running
            x_running = subprocess.run(
                ["pgrep", "Xorg"],
                capture_output=True
            ).returncode == 0
            
            # Check browser status
            browser_running = False
            for browser in ["chromium-browser", "epiphany-browser", "surf"]:
                if subprocess.run(["pgrep", browser], capture_output=True).returncode == 0:
                    browser_running = True
                    break
            
            return {
                "success": True,
                "status": {
                    "hostname": self.hostname,
                    "mac": self.mac,
                    "uptime": uptime,
                    "load": {
                        "1min": load_avg[0],
                        "5min": load_avg[1],
                        "15min": load_avg[2]
                    },
                    "x_running": x_running,
                    "browser_running": browser_running,
                    "timestamp": datetime.now().isoformat()
                }
            }
        except Exception as e:
            logger.error(f"Error getting status: {e}")
            return {
                "success": False,
                "message": str(e)
            }
    
    def _get_uptime(self) -> int:
        """Get system uptime in seconds"""
        try:
            with open("/proc/uptime", "r") as f:
                uptime_seconds = float(f.readline().split()[0])
                return int(uptime_seconds)
        except:
            return 0
    
    def _command_exists(self, command: str) -> bool:
        """Check if a command exists"""
        return subprocess.run(
            ["which", command],
            capture_output=True
        ).returncode == 0
    
    def run(self):
        """Main run loop"""
        logger.info("=" * 60)
        logger.info(f"EduDisplej API Client v{API_CLIENT_VERSION}")
        logger.info("=" * 60)
        logger.info(f"Hostname: {self.hostname}")
        logger.info(f"MAC: {self.mac}")
        logger.info(f"Log file: {LOG_FILE}")
        logger.info("")
        
        # Register device
        self.register_device()
        
        self.running = True
        
        logger.info("API client started")
        logger.info("Waiting for commands...")
        
        # Main loop - for now, just demonstrate the framework
        # In production, this would poll the server or listen for webhooks
        while self.running:
            try:
                # Placeholder for future development
                # Here you would:
                # 1. Poll server for commands
                # 2. Execute commands
                # 3. Report results back to server
                
                time.sleep(POLL_INTERVAL)
                
                # Example: Get and log status periodically
                status = self._get_status()
                if status.get("success"):
                    logger.debug(f"Status: {status['status']}")
                
            except KeyboardInterrupt:
                break
            except Exception as e:
                logger.error(f"Error in main loop: {e}")
                time.sleep(10)
        
        logger.info("API client stopped")


def write_pid_file():
    """Write PID file"""
    try:
        with open(PID_FILE, "w") as f:
            f.write(str(os.getpid()))
    except Exception as e:
        logger.error(f"Error writing PID file: {e}")


def remove_pid_file():
    """Remove PID file"""
    try:
        PID_FILE.unlink(missing_ok=True)
    except Exception as e:
        logger.error(f"Error removing PID file: {e}")


def main():
    """Main entry point"""
    try:
        write_pid_file()
        
        client = APIClient()
        client.run()
        
    except Exception as e:
        logger.error(f"Fatal error: {e}")
        sys.exit(1)
    finally:
        remove_pid_file()


if __name__ == "__main__":
    main()

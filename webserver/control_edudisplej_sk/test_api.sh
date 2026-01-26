#!/bin/bash
# Test script for EduDisplej API
# This script simulates a kiosk registering and syncing with the control panel

API_URL="${1:-http://localhost/control_edudisplej_sk/api.php}"
MAC="001122334455"
HOSTNAME="test-kiosk-$(date +%s)"

echo "======================================"
echo "EduDisplej API Test Script"
echo "======================================"
echo ""
echo "API URL: $API_URL"
echo "Test MAC: $MAC"
echo "Test Hostname: $HOSTNAME"
echo ""

# Test 1: Register kiosk
echo "[Test 1] Registering kiosk..."
response=$(curl -s -X POST "$API_URL?action=register" \
    -H "Content-Type: application/json" \
    -d "{\"mac\":\"$MAC\",\"hostname\":\"$HOSTNAME\",\"hw_info\":{\"test\":true}}")

echo "Response: $response"

if echo "$response" | grep -q '"success":true'; then
    echo "✓ Registration successful"
    kiosk_id=$(echo "$response" | grep -o '"kiosk_id":[0-9]*' | cut -d: -f2)
    echo "  Kiosk ID: $kiosk_id"
else
    echo "✗ Registration failed"
fi

echo ""

# Test 2: Sync kiosk
echo "[Test 2] Syncing kiosk..."
response=$(curl -s -X POST "$API_URL?action=sync" \
    -H "Content-Type: application/json" \
    -d "{\"mac\":\"$MAC\",\"hostname\":\"$HOSTNAME\",\"hw_info\":{\"test\":true}}")

echo "Response: $response"

if echo "$response" | grep -q '"success":true'; then
    echo "✓ Sync successful"
    sync_interval=$(echo "$response" | grep -o '"sync_interval":[0-9]*' | cut -d: -f2)
    echo "  Sync interval: $sync_interval seconds"
else
    echo "✗ Sync failed"
fi

echo ""

# Test 3: Heartbeat
echo "[Test 3] Sending heartbeat..."
response=$(curl -s -X POST "$API_URL?action=heartbeat" \
    -H "Content-Type: application/json" \
    -d "{\"mac\":\"$MAC\"}")

echo "Response: $response"

if echo "$response" | grep -q '"success":true'; then
    echo "✓ Heartbeat successful"
else
    echo "✗ Heartbeat failed"
fi

echo ""
echo "======================================"
echo "Tests completed!"
echo "======================================"
echo ""
echo "Check the admin panel to see the registered kiosk:"
echo "http://localhost/control_edudisplej_sk/admin.php"
echo ""

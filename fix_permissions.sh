#!/bin/bash
# AvionDash — Fix storage/ Permissions
# Run this as root if fault toggles are not persisting between page loads.
#
#   sudo bash /var/www/html/aviondash/fix_permissions.sh

set -e

APP_DIR="/var/www/html/aviondash"
STORAGE="${APP_DIR}/storage"
FAULTS_JSON="${STORAGE}/faults.json"

echo "=== AvionDash — Fixing storage/ permissions ==="
echo ""

# 1. Ensure the storage directory and file exist
if [ ! -d "$STORAGE" ]; then
  echo "Creating storage/ directory..."
  mkdir -p "$STORAGE"
fi

if [ ! -f "$FAULTS_JSON" ]; then
  echo "Creating default faults.json..."
  cat > "$FAULTS_JSON" << 'JSON'
{
  "slow_flights_query": false,
  "n_plus_one_pilots": false,
  "missing_index_scan": false,
  "connection_pool_exhaust": false,
  "page_500_aircraft": false,
  "slow_page_reports": false,
  "memory_leak_dashboard": false,
  "bad_data_passengers": false,
  "cpu_spike_query_runner": false,
  "log_flood": false,
  "auth_flap": false,
  "alert_cascade": false,
  "large_result_no_limit": false,
  "disk_io_spike": false,
  "session_lock_contention": false,
  "health_check_flap": false,
  "exception_silencer": false,
  "slow_third_party": false,
  "timezone_corruption": false,
  "high_cardinality_tags": false
}
JSON
fi

# 2. Set ownership — Apache must own the file to write to it
echo "Setting ownership to apache:apache..."
chown apache:apache "$STORAGE"
chown apache:apache "$FAULTS_JSON"

# 3. Set permissions — directory: rwxr-x---, file: rw-rw----
echo "Setting permissions..."
chmod 750 "$STORAGE"
chmod 660 "$FAULTS_JSON"

# 4. Fix SELinux context (critical on RHEL/Rocky/Alma)
if command -v semanage &>/dev/null && command -v restorecon &>/dev/null; then
  echo "Applying SELinux file context (httpd_sys_rw_content_t)..."
  semanage fcontext -a -t httpd_sys_rw_content_t "${APP_DIR}/storage(/.*)?" 2>/dev/null || \
  semanage fcontext -m -t httpd_sys_rw_content_t "${APP_DIR}/storage(/.*)?" 2>/dev/null || true
  restorecon -Rv "$STORAGE"
else
  echo "SELinux tools not found — skipping context fix"
fi

# 5. Verify
echo ""
echo "=== Result ==="
ls -laZ "$STORAGE" 2>/dev/null || ls -la "$STORAGE"

# 6. Test write access as apache
echo ""
echo "=== Testing write access as apache user ==="
if sudo -u apache sh -c "echo 'test' >> '${STORAGE}/.write_test' && rm -f '${STORAGE}/.write_test'" 2>/dev/null; then
  echo "✓ Apache can write to storage/ — fault persistence should work now"
else
  echo "✗ Apache STILL cannot write to storage/"
  echo "  Check: ausearch -c httpd --raw | audit2why"
fi

echo ""
echo "Done. Reload the Chaos Control Panel to verify."

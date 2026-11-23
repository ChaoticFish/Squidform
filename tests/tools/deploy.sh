#!/bin/bash
# Copies the working source onto both test sites (no install step), for quick test runs.
# A real install (./build.sh, then install the zip) is still done before a release.
R=$(cd "$(dirname "$0")/../.." && pwd)
for S in /home/claude/joomla/j6 /home/claude/joomla/j54; do
  rsync -a --delete "$R/com_squidform/admin/" "$S/administrator/components/com_squidform/" --exclude language --exclude examples --exclude squidform.xml
  rsync -a "$R/com_squidform/admin/language/" "$S/administrator/language/" 2>/dev/null || true
  rsync -a --delete "$R/com_squidform/admin/examples/" "$S/administrator/components/com_squidform/examples/"
  rsync -a --delete "$R/com_squidform/site/" "$S/components/com_squidform/" --exclude language
  rsync -a "$R/com_squidform/site/language/" "$S/language/" 2>/dev/null || true
  cp "$R/com_squidform/squidform.xml" "$S/administrator/components/com_squidform/squidform.xml" # the manifest (Joomla's namespace map reads it)
  rsync -a --delete "$R/com_squidform/media/" "$S/media/com_squidform/"
  for m in mod_squidform mod_squidform_viewer; do rsync -a --delete "$R/$m/" "$S/modules/$m/" --exclude language; done
  rsync -a --delete "$R/plg_system_squidform/" "$S/plugins/system/squidform/" --exclude language
done
echo "deployed"

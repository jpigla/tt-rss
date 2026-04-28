#!/usr/bin/env bash
# upstream-sync.sh — Upstream-Sync für tt-rss Fork
# Geeignet für manuelle Ausführung und KI-Agenten-Workflows.
# Alle Ausgaben sind strukturiert und maschinenlesbar.
#
# Verwendung:
#   ./utils/upstream-sync.sh [--dry-run] [--target-branch <branch>]
#
# Optionen:
#   --dry-run             Nur analysieren, keine Git-Operationen ausführen
#   --target-branch       Branch, der gesynct werden soll (Standard: aktueller Branch)
#
# Exit-Codes:
#   0  Erfolg
#   1  Fehler (Details in Ausgabe)
#   2  Konflikte vorhanden (manueller Eingriff erforderlich)
#   3  Dirty Working Tree (uncommitted changes)

set -euo pipefail

# ──────────────────────────────────────────────────────────────
# Konfiguration
# ──────────────────────────────────────────────────────────────
UPSTREAM_REMOTE="upstream"
UPSTREAM_BRANCH="main"
ORIGIN_REMOTE="origin"
SYNC_BRANCH="main"           # Sauberer upstream-Spiegel
DRY_RUN=false
TARGET_BRANCH=""

# Kritische Dateien — Änderungen hier explizit melden
CRITICAL_FILES=(
  "classes/PluginHost.php"
  "classes/Config.php"
  "classes/Prefs.php"
  "classes/Db_Migrations.php"
  "sql/pgsql/schema.sql"
)

# ──────────────────────────────────────────────────────────────
# Hilfsfunktionen
# ──────────────────────────────────────────────────────────────
section()  { echo; echo "══════════════════════════════════════════════"; echo "  $1"; echo "══════════════════════════════════════════════"; }
info()     { echo "[INFO]    $*"; }
ok()       { echo "[OK]      $*"; }
warn()     { echo "[WARN]    $*"; }
error()    { echo "[ERROR]   $*" >&2; }
action()   { echo "[ACTION]  $*"; }
conflict() { echo "[CONFLICT] $*"; }

die() {
  error "$1"
  exit "${2:-1}"
}

# ──────────────────────────────────────────────────────────────
# Argumente parsen
# ──────────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run)         DRY_RUN=true; shift ;;
    --target-branch)   TARGET_BRANCH="$2"; shift 2 ;;
    -h|--help)
      grep "^#" "$0" | grep -v "^#!" | sed 's/^# \?//'
      exit 0
      ;;
    *) die "Unbekannte Option: $1" ;;
  esac
done

# ──────────────────────────────────────────────────────────────
# Voraussetzungen prüfen
# ──────────────────────────────────────────────────────────────
section "VORAUSSETZUNGEN"

REPO_ROOT=$(git rev-parse --show-toplevel 2>/dev/null) \
  || die "Kein Git-Repository gefunden."
info "Repository: $REPO_ROOT"
cd "$REPO_ROOT"

CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
info "Aktueller Branch: $CURRENT_BRANCH"

[[ -z "$TARGET_BRANCH" ]] && TARGET_BRANCH="$CURRENT_BRANCH"
info "Ziel-Branch: $TARGET_BRANCH"

if [[ "$DRY_RUN" == "true" ]]; then
  warn "DRY-RUN aktiv — keine Git-Operationen werden ausgeführt."
fi

# Working Tree prüfen
DIRTY=$(git status --porcelain)
if [[ -n "$DIRTY" ]]; then
  error "Working Tree hat uncommitted Changes:"
  git status --short
  echo
  error "Bitte erst committen oder stashen:"
  echo "  git stash push -m 'vor-upstream-sync'"
  echo "  ./utils/upstream-sync.sh"
  echo "  git stash pop"
  exit 3
fi
ok "Working Tree sauber."

# Remotes prüfen
git remote get-url "$UPSTREAM_REMOTE" &>/dev/null \
  || die "Remote '$UPSTREAM_REMOTE' nicht gefunden. Einrichten mit:\n  git remote add upstream https://github.com/tt-rss/tt-rss.git"
ok "Remote '$UPSTREAM_REMOTE' vorhanden: $(git remote get-url $UPSTREAM_REMOTE)"

# ──────────────────────────────────────────────────────────────
# Upstream fetchen
# ──────────────────────────────────────────────────────────────
section "UPSTREAM FETCHEN"

if [[ "$DRY_RUN" == "false" ]]; then
  info "Fetche $UPSTREAM_REMOTE/$UPSTREAM_BRANCH ..."
  git fetch "$UPSTREAM_REMOTE" "$UPSTREAM_BRANCH" 2>&1 | sed 's/^/  /'
  ok "Fetch abgeschlossen."
else
  action "ÜBERSPRUNGEN (dry-run): git fetch $UPSTREAM_REMOTE $UPSTREAM_BRANCH"
fi

# ──────────────────────────────────────────────────────────────
# Neue Upstream-Commits analysieren
# ──────────────────────────────────────────────────────────────
section "UPSTREAM-ÄNDERUNGEN ANALYSIEREN"

UPSTREAM_REF="$UPSTREAM_REMOTE/$UPSTREAM_BRANCH"
SYNC_BRANCH_REF="$SYNC_BRANCH"

# Commits in upstream seit letztem Sync
NEW_COMMITS=$(git log "$SYNC_BRANCH_REF".."$UPSTREAM_REF" --oneline 2>/dev/null || true)
NEW_COUNT=$(echo "$NEW_COMMITS" | grep -c . || true)

if [[ -z "$NEW_COMMITS" ]]; then
  ok "$SYNC_BRANCH ist bereits auf aktuellem upstream-Stand."
  NEW_COUNT=0
else
  info "$NEW_COUNT neue Upstream-Commit(s) seit letztem Sync:"
  echo "$NEW_COMMITS" | sed 's/^/  /'
fi

# Kritische Dateien: Was hat sich geändert?
section "KRITISCHE DATEIEN PRÜFEN"

CRITICAL_CHANGED=()
if [[ $NEW_COUNT -gt 0 ]]; then
  for file in "${CRITICAL_FILES[@]}"; do
    CHANGES=$(git log "$SYNC_BRANCH_REF".."$UPSTREAM_REF" --oneline -- "$file" 2>/dev/null || true)
    if [[ -n "$CHANGES" ]]; then
      CRITICAL_CHANGED+=("$file")
      warn "KRITISCHE ÄNDERUNG in: $file"
      echo "$CHANGES" | sed 's/^/    /'
      echo "  Diff-Vorschau (erste 40 Zeilen):"
      git diff "$SYNC_BRANCH_REF".."$UPSTREAM_REF" -- "$file" 2>/dev/null | head -40 | sed 's/^/    /'
    fi
  done
  if [[ ${#CRITICAL_CHANGED[@]} -eq 0 ]]; then
    ok "Keine Änderungen in kritischen Dateien."
  fi
else
  ok "Keine neuen Upstream-Commits — Analyse übersprungen."
fi

# Schema-Version prüfen
UPSTREAM_SCHEMA=$(git show "$UPSTREAM_REF":classes/Config.php 2>/dev/null \
  | grep -oP "SCHEMA_VERSION\s*=\s*\K\d+" | head -1 || true)
LOCAL_SCHEMA=$(grep -oP "SCHEMA_VERSION\s*=\s*\K\d+" classes/Config.php | head -1 || true)

if [[ -n "$UPSTREAM_SCHEMA" && -n "$LOCAL_SCHEMA" ]]; then
  if [[ "$UPSTREAM_SCHEMA" != "$LOCAL_SCHEMA" ]]; then
    warn "SCHEMA_VERSION geändert: lokal=$LOCAL_SCHEMA → upstream=$UPSTREAM_SCHEMA"
    warn "DB-Migrationen prüfen! Eigene Plugin-Tabellen auf Kompatibilität testen."
  else
    ok "SCHEMA_VERSION unverändert: $LOCAL_SCHEMA"
  fi
fi

# ──────────────────────────────────────────────────────────────
# main-Branch mit Upstream mergen
# ──────────────────────────────────────────────────────────────
section "SYNC-BRANCH '$SYNC_BRANCH' AKTUALISIEREN"

if [[ $NEW_COUNT -eq 0 ]]; then
  ok "Kein Merge erforderlich."
else
  if [[ "$DRY_RUN" == "false" ]]; then
    info "Wechsle zu $SYNC_BRANCH ..."
    git checkout "$SYNC_BRANCH"

    info "Merge $UPSTREAM_REF → $SYNC_BRANCH (fast-forward bevorzugt) ..."
    MERGE_OUTPUT=$(git merge --ff-only "$UPSTREAM_REF" 2>&1 || true)
    MERGE_EXIT=$?

    if [[ $MERGE_EXIT -eq 0 ]]; then
      ok "Fast-forward Merge erfolgreich."
      echo "$MERGE_OUTPUT" | sed 's/^/  /'
    else
      # Fallback: regulärer Merge (sollte bei reinem upstream-Spiegel nicht nötig sein)
      warn "Fast-forward nicht möglich — versuche regulären Merge ..."
      MERGE_OUTPUT=$(git merge "$UPSTREAM_REF" -m "merge: upstream $UPSTREAM_BRANCH" 2>&1 || true)
      MERGE_EXIT=$?

      if [[ $MERGE_EXIT -ne 0 ]]; then
        error "Merge von $UPSTREAM_REF in $SYNC_BRANCH gescheitert."
        error "Konflikte:"
        git diff --name-only --diff-filter=U | sed 's/^/  KONFLIKT: /'
        error "Manuelle Auflösung erforderlich:"
        echo "  git status"
        echo "  # Konflikte beheben"
        echo "  git add <datei>"
        echo "  git commit"
        git checkout "$CURRENT_BRANCH" 2>/dev/null || true
        exit 2
      fi
      ok "Merge erfolgreich (mit Merge-Commit)."
    fi

    info "Pushe $SYNC_BRANCH zu $ORIGIN_REMOTE ..."
    git push "$ORIGIN_REMOTE" "$SYNC_BRANCH" 2>&1 | sed 's/^/  /'
    ok "$SYNC_BRANCH gepusht."
  else
    action "ÜBERSPRUNGEN (dry-run): Merge $UPSTREAM_REF → $SYNC_BRANCH"
    action "ÜBERSPRUNGEN (dry-run): git push $ORIGIN_REMOTE $SYNC_BRANCH"
  fi
fi

# ──────────────────────────────────────────────────────────────
# Target-Branch aktualisieren
# ──────────────────────────────────────────────────────────────
section "TARGET-BRANCH '$TARGET_BRANCH' AKTUALISIEREN"

if [[ "$TARGET_BRANCH" == "$SYNC_BRANCH" ]]; then
  ok "Target-Branch ist identisch mit Sync-Branch — kein weiterer Merge nötig."
else
  # Commits in Target-Branch, die nicht in main sind
  OWN_COMMITS=$(git log "$SYNC_BRANCH".."$TARGET_BRANCH" --oneline 2>/dev/null || true)
  OWN_COUNT=$(echo "$OWN_COMMITS" | grep -c . || true)
  info "$OWN_COUNT eigene Commit(s) auf $TARGET_BRANCH (nicht in $SYNC_BRANCH):"
  echo "$OWN_COMMITS" | sed 's/^/  /'

  if [[ "$DRY_RUN" == "false" ]]; then
    info "Wechsle zu $TARGET_BRANCH ..."
    git checkout "$TARGET_BRANCH"

    info "Merge $SYNC_BRANCH → $TARGET_BRANCH ..."
    MERGE_OUTPUT=$(git merge "$SYNC_BRANCH" -m "merge: upstream via $SYNC_BRANCH" 2>&1 || true)
    MERGE_EXIT=$?

    if [[ $MERGE_EXIT -ne 0 ]]; then
      error "Merge von $SYNC_BRANCH in $TARGET_BRANCH gescheitert."
      error "Konflikte (bitte manuell auflösen):"
      git diff --name-only --diff-filter=U | sed 's/^/  KONFLIKT: /'
      echo
      error "Nächste Schritte:"
      echo "  1. git status                  — Konflikte anzeigen"
      echo "  2. Konflikte in Editor öffnen und <<<< ==== >>>> Marker auflösen"
      echo "  3. git add <datei>             — aufgelöste Dateien stagen"
      echo "  4. git commit                  — Merge abschließen"
      echo "  5. git push $ORIGIN_REMOTE $TARGET_BRANCH"
      exit 2
    fi
    ok "Merge $SYNC_BRANCH → $TARGET_BRANCH erfolgreich."
    echo "$MERGE_OUTPUT" | sed 's/^/  /'

    info "Pushe $TARGET_BRANCH zu $ORIGIN_REMOTE ..."
    git push "$ORIGIN_REMOTE" "$TARGET_BRANCH" 2>&1 | sed 's/^/  /'
    ok "$TARGET_BRANCH gepusht."
  else
    action "ÜBERSPRUNGEN (dry-run): Merge $SYNC_BRANCH → $TARGET_BRANCH"
    action "ÜBERSPRUNGEN (dry-run): git push $ORIGIN_REMOTE $TARGET_BRANCH"
  fi
fi

# ──────────────────────────────────────────────────────────────
# Abschluss-Report
# ──────────────────────────────────────────────────────────────
section "ABSCHLUSS-REPORT"

FINAL_BRANCH=$(git rev-parse --abbrev-ref HEAD)
FINAL_COMMIT=$(git log --oneline -1)
ok "Finaler Zustand:"
info "  Branch:  $FINAL_BRANCH"
info "  HEAD:    $FINAL_COMMIT"

if [[ ${#CRITICAL_CHANGED[@]} -gt 0 ]]; then
  echo
  warn "HANDLUNGSBEDARF — kritische Dateien geändert:"
  for f in "${CRITICAL_CHANGED[@]}"; do
    warn "  → $f"
  done
  warn "Eigene Plugins auf Kompatibilität testen!"
  warn "Besonders: Hook-Signaturen, Prefs::get(), DB-Schema"
fi

echo
ok "Upstream-Sync abgeschlossen."
[[ "$DRY_RUN" == "true" ]] && info "(Dry-run — keine Änderungen vorgenommen)"

exit 0

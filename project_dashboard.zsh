#!/bin/zsh

# Project Dashboard Script - Simplified Version
# Author: Dave Peloso
# Date: 2025-04-10
# Version: 2.2
#
# Usage: ./project_dashboard.zsh [options]
# Options:
#   -n <number> : Show only the most recent N projects (default: all)
#   -s <status> : Filter by status (success, failed, partial, process-only)
#   -d          : Enable debug output
#   -h          : Show help

# --- Configuration ---
LOG_DIR="$HOME/Library/Logs/flambient_logs"
MAX_PROJECTS=0  # 0 means show all
STATUS_FILTER=""
DEBUG_MODE=false

# --- Parse Command Line Options ---
while getopts "n:s:dh" opt; do
  case $opt in
    n) MAX_PROJECTS=$OPTARG ;;
    s) STATUS_FILTER=$OPTARG ;;
    d) DEBUG_MODE=true ;;
    h)
      echo "Usage: $0 [options]"
      echo "Options:"
      echo "  -n <number> : Show only the most recent N projects"
      echo "  -s <status> : Filter by status (success, failed, partial, process-only)"
      echo "  -d          : Enable debug output"
      echo "  -h          : Show this help message"
      exit 0
      ;;
    \?)
      echo "Invalid option: -$OPTARG" >&2
      exit 1
      ;;
  esac
done

# --- Helper Functions ---
debug_print() {
  if [ "$DEBUG_MODE" = true ]; then
    echo "$@"
  fi
}

format_duration() {
  local seconds=$1
  local minutes=$((seconds / 60))
  local remaining_seconds=$((seconds % 60))

  if [ $minutes -eq 0 ]; then
    echo "${remaining_seconds}s"
  else
    echo "${minutes}m ${remaining_seconds}s"
  fi
}

get_status_icon() {
  local project_status=$1
    case "$project_status" in
    "success") echo "✅" ;;
    "failed") echo "❌" ;;
    "partial") echo "⚠️" ;;
    "process-only") echo "🔄" ;;
    *) echo "❓" ;;
    esac
}

# Determine if a project is process-only
determine_process_only() {
  local file="$1"
  local magick_exists_status
  local upload_exists_status

  # Check if ImageMagick_Processing key exists and is not null/false
  jq -e '.steps.ImageMagick_Processing' "$file" >/dev/null 2>&1
  magick_exists_status=$?

  # Check if ImagenAI_Upload key exists and is not null/false
  jq -e '.steps.ImagenAI_Upload' "$file" >/dev/null 2>&1
  upload_exists_status=$?

  # Logic: magick exists (status 0) AND upload does NOT exist (status non-zero)
  if [ $magick_exists_status -eq 0 ] && [ $upload_exists_status -ne 0 ]; then
    echo "true"
  else
    echo "false"
  fi
}

display_dashboard() {
  echo "===== FLAMBIENT WORKFLOW DASHBOARD ====="
  echo "Generated: $(date)"
  echo "=======================================\n"

  if [ ! -d "$LOG_DIR" ]; then
    echo "No project logs found. Log directory does not exist: $LOG_DIR"
    return
  fi

  # Find all JSON log files
  project_files=( ${(f)"$(find "$LOG_DIR" -name "*.json" -type f | sort -r)"} )
  if [ ${#project_files[@]} -eq 0 ]; then
    echo "No project logs found in $LOG_DIR"
    return
  fi

  # Check if jq is installed
  if ! command -v jq &>/dev/null; then
    echo "⚠️ JQ not installed. Please install JQ for dashboard display: brew install jq"
    echo "Displaying basic project list instead.\n"

    for file in "${project_files[@]}"; do
      project_name=$(basename "$file" .json)
      echo "Project: $project_name"
    done
    return
  fi

  # --- Validation Logic ---
  valid_files=()
  debug_print "--- Starting Validation Loop ---"

  for file in "${project_files[@]}"; do
    debug_print "DEBUG: Processing potential file: [$file]"

    # Check 1: Is it a regular file?
    if [ ! -f "$file" ]; then
      debug_print "DEBUG: [$file] is NOT a regular file. Skipping."
      continue
    fi

    # Check 2: Is it non-empty?
    if [ ! -s "$file" ]; then
      debug_print "DEBUG: [$file] is EMPTY. Skipping."
      continue
    fi

    # Check 3: Is it valid JSON according to jq?
    if jq empty "$file" >/dev/null 2>&1; then
      debug_print "DEBUG: [$file] is valid JSON. Adding to list."
      valid_files+=("$file")
    else
      debug_print "DEBUG: [$file] is NOT valid JSON. Skipping."
    fi
  done

  debug_print "--- Finished Validation Loop ---"
  debug_print "DEBUG: Number of valid files found: ${#valid_files[@]}"

  if [ ${#valid_files[@]} -eq 0 ]; then
    echo "No valid project logs found."
    return
  fi
  # --- End Validation Logic ---

  # The rest of the script continues below...
  project_files=("${valid_files[@]}")

  # Apply status filter if specified
  if [ -n "$STATUS_FILTER" ]; then
    filtered_files=()

    for file in "${project_files[@]}"; do
      if [ "$STATUS_FILTER" = "process-only" ]; then
        # Special handling for process-only filter
        is_process_only=$(determine_process_only "$file")
        if [ "$is_process_only" = "true" ]; then
          filtered_files+=("$file")
        fi
      else
        # Normal status filtering
        project_status=$(jq -r '.overall_status // ""' "$file")
        if [ "$project_status" = "$STATUS_FILTER" ]; then
          filtered_files+=("$file")
        fi
      fi
    done

    project_files=("${filtered_files[@]}")

    if [ ${#project_files[@]} -eq 0 ]; then
      echo "No projects found with status: $STATUS_FILTER"
      return
    fi

    echo "Filtering projects with status: $STATUS_FILTER\n"
  fi

  # Apply maximum projects limit
  if [ $MAX_PROJECTS -gt 0 ] && [ ${#project_files[@]} -gt $MAX_PROJECTS ]; then
    project_files=("${project_files[@]:0:$MAX_PROJECTS}")
    echo "Showing the $MAX_PROJECTS most recent projects\n"
  fi

  # Create a mapping between display numbers and project names
  typeset -A project_map
  project_names=()

  # Print table header
  printf "%-4s %-24s %-12s %-10s %-20s %-20s\n" \
    "#" "PROJECT" "STATUS" "DURATION" "DATE" "DIRECTORY"
  printf "%-4s %-24s %-12s %-10s %-20s %-20s\n" \
    "----" "$(printf '%0.s-' {1..24})" "$(printf '%0.s-' {1..12})" \
    "$(printf '%0.s-' {1..10})" "$(printf '%0.s-' {1..20})" \
    "$(printf '%0.s-' {1..20})"

  # Summary counters
  total_projects=0
  successful_projects=0
  failed_projects=0
  partial_projects=0
  process_only_projects=0
  total_duration=0

  # Print each project
  for file in "${project_files[@]}"; do
    project_name=$(basename "$file" .json)
    project_names+=("$project_name")
    project_map[$((total_projects + 1))]="$project_name"
    
    if [ ${#project_name} -gt 24 ]; then
      display_name="${project_name:0:21}..."
    else
      display_name="$project_name"
    fi

    # Get basic project info
    project_status=$(jq -r '.overall_status // "unknown"' "$file")
    duration=$(jq -r '.total_duration_seconds // 0' "$file")
    start_time=$(jq -r '.start_time // "unknown"' "$file")

    # Check if this is a process-only run
    is_process_only=$(determine_process_only "$file")
    if [ "$is_process_only" = "true" ]; then
      project_status="process-only"
    fi

    status_icon=$(get_status_icon "$project_status")
    formatted_duration=$(format_duration $duration)

    # Get directory (truncate if too long)
    directory=$(jq -r '.image_directory // "unknown"' "$file")
    if [ ${#directory} -gt 20 ]; then
      directory="...${directory: -17}"
    fi

    printf "%-4s %-24s %-12s %-10s %-20s %-20s\n" \
      "$((total_projects + 1))" \
      "$display_name" "$status_icon $project_status" "$formatted_duration" \
      "$start_time" "$directory"

    # Update counters
    total_projects=$((total_projects + 1))
    case "$project_status" in
      "success") successful_projects=$((successful_projects + 1)) ;;
      "failed") failed_projects=$((failed_projects + 1)) ;;
      "partial") partial_projects=$((partial_projects + 1)) ;;
      "process-only") process_only_projects=$((process_only_projects + 1)) ;;
    esac
    total_duration=$((total_duration + duration))
  done

  # Print summary
  echo "\n--- Summary ---"
  echo "Total Projects:    $total_projects"
  echo "Successful:        $successful_projects"
  echo "Failed:            $failed_projects"
  echo "Partial Success:   $partial_projects"
  echo "Process-only:      $process_only_projects"
  echo "Total Time:        $(format_duration $total_duration)"

  # Interactive project selection
  if [ $total_projects -gt 0 ]; then
    # Check if view_project_logs.zsh exists in the current directory
    local view_script="./view_project_logs.zsh"
    if [ ! -x "$view_script" ]; then
      view_script=$(command -v view_project_logs.zsh 2>/dev/null)
    fi

    if [ -x "$view_script" ]; then
      echo "\nOptions:"
      echo "Enter project number to view details, or 'q' to quit:"
      read choice
      
      if [[ "$choice" != "q" ]]; then
        if [[ "$choice" =~ ^[0-9]+$ ]] && [ -n "${project_map[$choice]}" ]; then
          selected_project="${project_map[$choice]}"
          echo "\nViewing details for project: $selected_project"
          "$view_script" "$selected_project"
        else
          echo "Invalid selection: $choice"
        fi
      fi
    else
      echo "\nNote: Install view_project_logs.zsh to enable interactive project viewing."
    fi
  fi
}

# --- Main Script ---
display_dashboard
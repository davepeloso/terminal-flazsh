#!/bin/zsh

# View Project Logs Script - Simplified Version
# Author: Dave Peloso
# Date: 2025-04-10
# Version: 2.1
#
# Usage: ./view_project_logs.zsh [options] [project_name]
# Options:
#   -r          : Show raw JSON (for debugging)
#   -d          : Enable debug output
#   -h          : Show help

# --- Configuration ---
LOG_DIR="$HOME/Library/Logs/flambient_logs"
SHOW_RAW=false
DEBUG_MODE=false

# --- Parse Command Line Options ---
while getopts "rdh" opt; do
  case $opt in
    r) SHOW_RAW=true ;;
    d) DEBUG_MODE=true ;;
    h)
      echo "Usage: $0 [options] [project_name]"
      echo "Options:"
      echo "  -r          : Show raw JSON (for debugging)"
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

# Shift the options to get the positional parameters
shift $((OPTIND - 1))
PROJECT_NAME="$1"

# --- Helper Functions ---
debug_print() {
  if [ "$DEBUG_MODE" = true ]; then
    echo "$@"
  fi
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

# List related log files for a project
list_related_logs() {
  local project="$1"
  local files=$(find "$LOG_DIR" -name "${project}_*.log" | sort)

  if [ -n "$files" ]; then
    echo "\nRelated log files:"
    echo "----------------"
    for file in $files; do
      echo "$(basename "$file")"
    done
    return 0
  fi
  return 1
}

list_projects() {
  echo "Available projects:"
  echo "-----------------"

  # Find all JSON log files
  project_files=( ${(f)"$(find "$LOG_DIR" -name "*.json" -type f | sort -r)"} )

  if [ ${#project_files[@]} -eq 0 ]; then
    echo "No project logs found."
    exit 0
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
    exit 0
  fi
  # --- End Validation Logic ---

  # Create a mapping between display numbers and file paths
  typeset -A file_map
  i=1
  
  for file in "${valid_files[@]}"; do
    file_map[$i]="$file"
    project_name=$(basename "$file" .json)

    # Get status
    project_status=$(jq -r '.overall_status // "unknown"' "$file")

    # Check if process-only
    is_process_only=$(determine_process_only "$file")
    if [ "$is_process_only" = "true" ]; then
      project_status="process-only"
    fi

    # Get timestamp
    start_time=$(jq -r '.start_time // "unknown"' "$file")

    # Get status icon
    case "$project_status" in
      "success") status_icon="✅" ;;
      "failed") status_icon="❌" ;;
      "partial") status_icon="⚠️" ;;
      "process-only") status_icon="🔄" ;;
      *) status_icon="❓" ;;
    esac

    printf "%3d. %s %s - %s\n" $i "$status_icon" "$project_name" "$start_time"
    i=$((i + 1))
  done

  # Keep prompting until valid choice or quit
  while true; do
    echo "\nEnter project number to view details, or 'q' to quit:"
    read choice

    if [[ "$choice" == "q" ]]; then
      exit 0
    fi

    if [[ "$choice" =~ ^[0-9]+$ ]] && [ -n "${file_map[$choice]}" ]; then
      selected_file="${file_map[$choice]}"
      debug_print "Selected file path: $selected_file"
      
      if [ -f "$selected_file" ]; then
        selected_project=$(basename "$selected_file" .json)
        view_project_details "$selected_project"
        break
      else
        echo "Error: File not found: $selected_file"
      fi
    else
      echo "Invalid selection. Please try again."
      # No exit here, loop continues
    fi
  done
}

view_project_details() {
  local project="$1"
  local log_file="$LOG_DIR/${project}.json"
  
  debug_print "Opening log file: $log_file"

  if [ ! -f "$log_file" ]; then
    echo "Error: Project log not found: $log_file"
    exit 1
  fi

  # Show raw JSON if requested
  if [ "$SHOW_RAW" = true ]; then
    echo "\n=== Raw JSON for $project ==="
    cat "$log_file"
    exit 0
  fi

  # Show project details
  echo "\n=== Project Details: $project ==="

  # Basic information
  start_time=$(jq -r '.start_time // "Unknown"' "$log_file")
  end_time=$(jq -r '.end_time // "Unknown"' "$log_file")
  duration=$(jq -r '.total_duration_seconds // 0' "$log_file")
  image_dir=$(jq -r '.image_directory // "Unknown"' "$log_file")

  echo "Date:      $start_time"

  minutes=$((duration / 60))
  seconds=$((duration % 60))
  echo "Duration:  ${minutes}m ${seconds}s"

  # Status with process-only handling
  project_status=$(jq -r '.overall_status // "unknown"' "$log_file")
  is_process_only=$(determine_process_only "$log_file")

  if [ "$is_process_only" = "true" ]; then
    echo "Status:    Process-only (Image processing only)"
  else
    echo "Status:    $project_status"
  fi

  echo "Directory: $image_dir"

  # Extract UUID only if not process-only
  if [ "$is_process_only" = "false" ]; then
    # Try to extract UUID from log outputs
    uuid_output=$(jq -r '.steps.ImagenAI_Status.details.output // ""' "$log_file")
    if [ -n "$uuid_output" ]; then
      # Use full path to head command to avoid any confusion
      uuid=$(echo "$uuid_output" | grep -o "UUID: [0-9a-f]\{24\}" | /usr/bin/head -n 1 | cut -d' ' -f2)
      if [ -n "$uuid" ]; then
        echo "UUID:      $uuid"
      fi
    fi
  fi

  # Show workflow steps
  echo "\nWorkflow Steps:"
  echo "---------------------"

  # Process steps
  jq -r '.steps | keys[]' "$log_file" | while read step_name; do
    step_status=$(jq -r ".steps[\"$step_name\"].status // \"unknown\"" "$log_file")
    duration=$(jq -r ".steps[\"$step_name\"].duration_seconds // 0" "$log_file")

    case "$step_status" in
      "success") icon="✅" ;;
      "failed") icon="❌" ;;
      *) icon="⚠️" ;;
    esac

    printf "%-25s %s %s (%ds)\n" "$step_name" "$icon" "$step_status" "$duration"
  done

  # Show related log files
  list_related_logs "$project"

  # Menu options
  echo "\nOptions:"
  echo "1. View step details"
  echo "2. View related log file"
  echo "3. Exit"

  echo "\nEnter choice:"
  read option

  case "$option" in
    1)
      # Get available steps
      steps=$(jq -r '.steps | keys[]' "$log_file")

      echo "\nAvailable steps:"
      echo "-----------------"
      echo "$steps"
      echo "all (show all steps)"

      echo "\nEnter step name:"
      read step

      if [ "$step" = "all" ]; then
        echo "\n=== All Steps Output ==="
        # Fixed jq command with simpler quoting - this avoids the syntax error
        jq -r '.steps | to_entries[] | "=== " + .key + " ===\n" + (.value.details.output // "No output available")' "$log_file"
      else
        output=$(jq -r ".steps[\"$step\"].details.output // \"No output available\"" "$log_file")
        if [ "$output" != "null" ]; then
          echo "\n=== Output for $step ==="
          echo "$output"
        else
          echo "Step not found: $step"
        fi
      fi
      ;;

    2)
      # If we have related logs
      if list_related_logs "$project"; then
        echo "\nEnter log file name to view:"
        read log_name

        if [ -f "$LOG_DIR/$log_name" ]; then
          echo "\n=== Content of $log_name ==="
          cat "$LOG_DIR/$log_name"
        else
          echo "Log file not found: $log_name"
        fi
      else
        echo "No related log files found."
      fi
      ;;

    *)
      echo "Exiting."
      ;;
  esac
}

# --- Main Script ---
if [ ! -d "$LOG_DIR" ]; then
  echo "No project logs found. Log directory does not exist: $LOG_DIR"
  exit 1
fi

# Check if a project name was provided
if [ -z "$PROJECT_NAME" ]; then
  list_projects
else
  view_project_details "$PROJECT_NAME"
fi
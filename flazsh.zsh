#!/bin/zsh

# Flambient Workflow Dashboard
# Author: Dave Peloso
# Date: 2025-04-11
# Version: 1.0
#
# A dashboard interface that orchestrates existing flambient scripts
# without modifying them - maintains proper separation of concerns

# --- Configuration ---
: ${SCRIPTS_DIR:="$(dirname "$0")"}
: ${LOG_DIR:="$HOME/Library/Logs/flambient_logs"}
: ${DEFAULT_IMAGE_DIR:="$(pwd)"}
: ${DEFAULT_OUTPUT_DIR:="$(pwd)/imagen_output"}
: ${DEBUG:=false}  # Debug mode, set to true to enable detailed logs

# Ensure log directory exists
mkdir -p "$LOG_DIR"

# --- Helper Functions ---

# Print debug info when debug mode is enabled
debug_print() {
  if [ "$DEBUG" = "true" ]; then
    echo "[DEBUG] $1" >&2
  fi
}

# Determine if a project is process-only (no ImagenAI upload)
determine_process_only() {
  local file="$1"

  # Check if ImageMagick step exists and is not null/false
  jq -e '.steps.ImageMagick' "$file" >/dev/null 2>&1
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

# --- Terminal UI Elements ---

# Colors for better readability
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
CYAN='\033[0;36m'
RESET='\033[0m'

# Display a stylish banner
show_banner() {
  clear
    echo "${MAGENTA}"
  echo "#    █████▒██▓    ▄▄▄           ▒███████▒  ██████  ██░ ██ "
  echo "#  ▓██   ▒▓██▒   ▒████▄         ▒ ▒ ▒ ▄▀░▒██    ▒ ▓██░ ██▒"
  echo "#  ▒████ ░▒██░   ▒██  ▀█▄       ░ ▒ ▄▀▒░ ░ ▓██▄   ▒██▀▀██░"
  echo "#  ░▓█▒  ░▒██░   ░██▄▄▄▄██        ▄▀▒   ░  ▒   ██▒░▓█ ░██ "
  echo "#  ░▒█░   ░██████▒▓█   ▓██▒ ██▓ ▒███████▒▒██████▒▒░▓█▒░██▓"
  echo "#   ▒ ░   ░ ▒░▓  ░▒▒   ▓▒█░ ▒▓▒ ░▒▒ ▓░▒░▒▒ ▒▓▒ ▒ ░ ▒ ░░▒░▒"
  echo "#   ░     ░ ░ ▒  ░ ▒   ▒▒ ░ ░▒  ░░▒ ▒ ░ ▒░ ░▒  ░ ░ ▒ ░▒░ ░"
  echo "#   ░ ░     ░ ░    ░   ▒    ░   ░ ░ ░ ░ ░░  ░  ░   ░  ░░ ░"
  echo "#             ░  ░     ░  ░  ░    ░ ░          ░   ░  ░  ░"
  echo "#                            ░  ░                         "
  echo "${RESET}"
  echo "${BLUE}=======================================================${RESET}"
  echo "${MAGENTA}     FL4SH/\mb1ent  WORKFLOW DASHBOARD v1.0         ${RESET}"
  echo "${BLUE}=======================================================${RESET}"
  echo "${CYAN}Date: $(date '+%Y-%m-%d %H:%M:%S')${RESET}"
  echo "${CYAN}User: $USER${RESET}"
  echo "${CYAN}Working Folder:$PWD${RESET}"
  echo "${BLUE}=======================================================${RESET}"
  echo ""
}

# Check if a script exists and is executable
check_script() {
  local script="$SCRIPTS_DIR/$1"
  if [ ! -f "$script" ]; then
    echo "${RED}Error: Script not found: $script${RESET}"
    return 1
  fi

  if [ ! -x "$script" ]; then
    echo "${YELLOW}Making script executable: $script${RESET}"
    chmod +x "$script"
  fi

  return 0
}

# --- Project Management Functions ---

# List all projects
list_projects() {
  echo "Available projects:"
  echo "-----------------"

  # Find all JSON log files
  project_files=( ${(f)"$(find "$LOG_DIR" -name "*.json" -type f | sort -r)"} )

  if [ ${#project_files[@]} -eq 0 ]; then
    echo "No project logs found."
    return 1
  fi

  # --- Validation Logic ---
  valid_files=()
  debug_print "--- Starting Validation Loop ---"

  for file in "${project_files[@]}"; do
    debug_print "Processing potential file: [$file]"

    # Check 1: Is it a regular file?
    if [ ! -f "$file" ]; then
      debug_print "[$file] is NOT a regular file. Skipping."
      continue
    fi

    # Check 2: Is it non-empty?
    if [ ! -s "$file" ]; then
      debug_print "[$file] is EMPTY. Skipping."
      continue
    fi

    # Check 3: Is it valid JSON according to jq?
    if jq empty "$file" >/dev/null 2>&1; then
      debug_print "[$file] is valid JSON. Adding to list."
      valid_files+=("$file")
    else
      debug_print "[$file] is NOT valid JSON. Skipping."
    fi
  done

  debug_print "--- Finished Validation Loop ---"
  debug_print "Number of valid files found: ${#valid_files[@]}"

  if [ ${#valid_files[@]} -eq 0 ]; then
    echo "No valid project logs found."
    return 1
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
      return 0
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
    fi
  done

  return 0
}

# View details of a specific project
view_project_details() {
  local project="$1"
  local log_file="$LOG_DIR/${project}.json"

  debug_print "Opening log file: $log_file"

  if [ ! -f "$log_file" ]; then
    echo "Error: Project log not found: $log_file"
    return 1
  fi

  # Show raw JSON if requested
  if [ "$SHOW_RAW" = true ]; then
    echo "\n=== Raw JSON for $project ==="
    cat "$log_file"
    return 0
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
  echo "3. Check status (if UUID available)"
  echo "4. Download results (if UUID available)"
  echo "5. Return to main menu"

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

    3)
      # Check status if UUID available
      if [ -n "$uuid" ]; then
        if check_script "002a_checkStatus.zsh"; then
          echo "\nChecking status for UUID: $uuid"
          "$SCRIPTS_DIR/002a_checkStatus.zsh" "$uuid"
        else
          echo "${RED}Status checking script not found${RESET}"
        fi
      else
        echo "${YELLOW}No UUID available for this project${RESET}"
      fi
      ;;

    4)
      # Download results if UUID available
      if [ -n "$uuid" ]; then
        if check_script "005_downloadResults.zsh"; then
          echo "\nDownloading results for UUID: $uuid"
          echo "Enter output directory (or press Enter for default):"
          read download_dir
          if [ -z "$download_dir" ]; then
            "$SCRIPTS_DIR/005_downloadResults.zsh" "$uuid"
          else
            "$SCRIPTS_DIR/005_downloadResults.zsh" "$uuid" "" "$download_dir"
          fi
        else
          echo "${RED}Download script not found${RESET}"
        fi
      else
        echo "${YELLOW}No UUID available for this project${RESET}"
      fi
      ;;

    *)
      echo "Returning to main menu."
      ;;
  esac
}

# --- Menu Functions ---

# Start a new flambient workflow
start_workflow() {
  show_banner
  echo "${CYAN}START NEW WORKFLOW${RESET}"
  echo "${CYAN}-----------------${RESET}"

  # Check if master script exists
  if ! check_script "master_workflow.zsh"; then
    echo "${RED}Error: Master workflow script not found${RESET}"
    read -p "Press Enter to continue..."
    return 1
  fi

  # Get project name
  echo "Enter project name (or leave empty for auto-generated name):"
  read project_name

  # Get image directory
  echo "Enter source image directory (default: $DEFAULT_IMAGE_DIR):"
  read image_dir
  image_dir=${image_dir:-$DEFAULT_IMAGE_DIR}

  # Expand path if ~ is used
  image_dir=${image_dir/#\~/$HOME}

  # Validate directory
  if [ ! -d "$image_dir" ]; then
    echo "${RED}Error: Directory does not exist: $image_dir${RESET}"
    read -p "Press Enter to continue..."
    return 1
  fi

  # Process selection
  echo "Select processing mode:"
  echo "1. Full workflow with Imagen AI integration"
  echo "2. Image processing only (no Imagen AI)"
  read -k 1 mode
  echo ""

  # Prepare command
  cmd="$SCRIPTS_DIR/master_workflow.zsh"
  if [ "$mode" = "2" ]; then
    cmd="$cmd -p"
    echo "${GREEN}Selected: Processing only (no Imagen AI)${RESET}"
  else
    echo "${GREEN}Selected: Full workflow with Imagen AI${RESET}"
  fi

  if [ -n "$project_name" ]; then
    cmd="$cmd \"$project_name\""
  fi

  cmd="$cmd \"$image_dir\""

  # Confirm and run
  echo ""
  echo "Ready to start workflow with command:"
  echo "${CYAN}$cmd${RESET}"
  echo ""
  echo "Proceed? (y/n)"
  read -k 1 confirm
  echo ""

  if [ "$confirm" = "y" ] || [ "$confirm" = "Y" ]; then
    echo "${GREEN}Starting workflow...${RESET}"
    echo ""

    # Run command
    eval "$cmd"
    status=$?

    echo ""
    if [ $status -eq 0 ]; then
      echo "${GREEN}Workflow completed successfully!${RESET}"
    else
      echo "${RED}Workflow completed with errors (status: $status)${RESET}"
    fi

    read -p "Press Enter to continue..."
  else
    echo "${YELLOW}Workflow cancelled.${RESET}"
    read -p "Press Enter to continue..."
  fi
}

# View dashboard of existing projects
view_projects() {
  show_banner
  echo "${CYAN}PROJECT DASHBOARD${RESET}"
  echo "${CYAN}----------------${RESET}"

  # Enable debug mode for this function if requested
  local old_debug="$DEBUG"

  echo "Enable debug mode? (y/n)"
  read -k 1 debug_option
  echo ""

  if [[ "$debug_option" == "y" || "$debug_option" == "Y" ]]; then
    DEBUG="true"
    echo "${YELLOW}Debug mode enabled${RESET}"
  fi

  # Show raw JSON option
  echo "Show raw JSON? (y/n)"
  read -k 1 raw_option
  echo ""

  if [[ "$raw_option" == "y" || "$raw_option" == "Y" ]]; then
    SHOW_RAW="true"
    echo "${YELLOW}Raw JSON display enabled${RESET}"
  else
    SHOW_RAW="false"
  fi

  # List and interact with projects
  list_projects

  # Restore debug setting
  DEBUG="$old_debug"

  read -p "Press Enter to return to main menu..."
}

# Check the status of an Imagen AI project
check_imagen_status() {
  show_banner
  echo "${CYAN}CHECK IMAGEN AI STATUS${RESET}"
  echo "${CYAN}---------------------${RESET}"

  # Check if status check script exists
  if ! check_script "002a_checkStatus.zsh"; then
    echo "${RED}Error: Status check script not found${RESET}"
    read -p "Press Enter to continue..."
    return 1
  fi

  # Get project UUID
  echo "Enter Imagen AI project UUID:"
  read uuid

  if [ -z "$uuid" ]; then
    echo "${RED}Error: UUID is required${RESET}"
    read -p "Press Enter to continue..."
    return 1
  fi

  # Get API key (optional)
  echo "Enter API key (or leave empty to use from .env):"
  read api_key

  # Prepare command
  cmd="$SCRIPTS_DIR/002a_checkStatus.zsh \"$uuid\""
  if [ -n "$api_key" ]; then
    cmd="$cmd \"$api_key\""
  fi

  # Debug option
  echo "Enable debug output? (y/n)"
  read -k 1 debug_option
  echo ""
  if [[ "$debug_option" == "y" || "$debug_option" == "Y" ]]; then
    cmd="DEBUG=true $cmd"
  fi

  # Run the command
  echo ""
  echo "${GREEN}Running status check...${RESET}"
  echo ""
  eval "$cmd"

  read -p "Press Enter to continue..."
}

# Download results from an Imagen AI project
download_results() {
  show_banner
  echo "${CYAN}DOWNLOAD IMAGEN AI RESULTS${RESET}"
  echo "${CYAN}-------------------------${RESET}"

  # Check if download script exists
  if ! check_script "005_downloadResults.zsh"; then
    echo "${RED}Error: Download script not found${RESET}"
    read -p "Press Enter to continue..."
    return 1
  fi

  # Get project UUID
  echo "Enter Imagen AI project UUID:"
  read uuid

  if [ -z "$uuid" ]; then
    echo "${RED}Error: UUID is required${RESET}"
    read -p "Press Enter to continue..."
    return 1
  fi

  # Get API key (optional)
  echo "Enter API key (or leave empty to use from .env):"
  read api_key

  # Get output directory (optional)
  echo "Enter output directory (default: $DEFAULT_OUTPUT_DIR):"
  read output_dir
  output_dir=${output_dir:-$DEFAULT_OUTPUT_DIR}

  # Expand path if ~ is used
  output_dir=${output_dir/#\~/$HOME}

  # Create directory if it doesn't exist
  if [ ! -d "$output_dir" ]; then
    echo "${YELLOW}Output directory does not exist. Creating it...${RESET}"
    mkdir -p "$output_dir"
  fi

  # Prepare command
  cmd="$SCRIPTS_DIR/005_downloadResults.zsh \"$uuid\""
  if [ -n "$api_key" ]; then
    cmd="$cmd \"$api_key\""
  fi
  cmd="$cmd \"$output_dir\""

  # Run the command
  echo ""
  echo "${GREEN}Starting download...${RESET}"
  echo ""
  eval "$cmd"

  read -p "Press Enter to continue..."
}

# Upload images to Imagen AI
upload_to_imagen() {
  show_banner
  echo "${CYAN}UPLOAD TO IMAGEN AI${RESET}"
  echo "${CYAN}------------------${RESET}"

  # Check if upload script exists
  if ! check_script "002_imagenUpload.zsh"; then
    echo "${RED}Error: Upload script not found${RESET}"
    read -p "Press Enter to continue..."
    return 1
  fi

  # Get image directory
  echo "Enter image directory to upload:"
  read image_dir

  if [ -z "$image_dir" ]; then
    echo "${RED}Error: Directory is required${RESET}"
    read -p "Press Enter to continue..."
    return 1
  fi

  # Expand path if ~ is used
  image_dir=${image_dir/#\~/$HOME}

  # Validate directory
  if [ ! -d "$image_dir" ]; then
    echo "${RED}Error: Directory does not exist: $image_dir${RESET}"
    read -p "Press Enter to continue..."
    return 1
  fi

  # Get profile key or name
  echo "Enter Imagen AI profile key (e.g., 309406):"
  read profile_key

  # Get API key (optional)
  echo "Enter API key (or leave empty to use from .env):"
  read api_key

  # Debug option
  echo "Enable debug output? (y/n)"
  read -k 1 debug_option
  echo ""

  # Build command
  cmd="$SCRIPTS_DIR/002_imagenUpload.zsh -d \"$image_dir\""

  if [ -n "$profile_key" ]; then
    cmd="$cmd -P \"$profile_key\""
  fi

  if [ -n "$api_key" ]; then
    cmd="$cmd -k \"$api_key\""
  fi

  if [[ "$debug_option" == "y" || "$debug_option" == "Y" ]]; then
    cmd="$cmd -v"
  fi

  # Environment profile option
  echo "Enter environment profile name (optional):"
  read env_profile
  if [ -n "$env_profile" ]; then
    cmd="$cmd -p \"$env_profile\""
  fi

  # Confirm and run
  echo ""
  echo "Ready to upload with command:"
  echo "${CYAN}$cmd${RESET}"
  echo ""
  echo "Proceed? (y/n)"
  read -k 1 confirm
  echo ""

  if [ "$confirm" = "y" ] || [ "$confirm" = "Y" ]; then
    echo "${GREEN}Starting upload...${RESET}"
    echo ""

    # Run command
    eval "$cmd"
    status=$?

    echo ""
    if [ $status -eq 0 ]; then
      echo "${GREEN}Upload completed successfully!${RESET}"
    else
      echo "${RED}Upload completed with errors (status: $status)${RESET}"
    fi

    read -p "Press Enter to continue..."
  else
    echo "${YELLOW}Upload cancelled.${RESET}"
    read -p "Press Enter to continue..."
  fi
}

# Run ImageMagick processing directly
run_image_magick() {
  show_banner
  echo "${CYAN}RUN IMAGEMAGICK PROCESSING${RESET}"
  echo "${CYAN}-------------------------${RESET}"

  # Check if ImageMagick script exists
  if ! check_script "001_imageMagick.zsh"; then
    echo "${RED}Error: ImageMagick script not found${RESET}"
    read -p "Press Enter to continue..."
    return 1
  fi

  # Get image directory
  echo "Enter source image directory:"
  read image_dir

  if [ -z "$image_dir" ]; then
    echo "${RED}Error: Directory is required${RESET}"
    read -p "Press Enter to continue..."
    return 1
  fi

  # Expand path if ~ is used
  image_dir=${image_dir/#\~/$HOME}

  # Validate directory
  if [ ! -d "$image_dir" ]; then
    echo "${RED}Error: Directory does not exist: $image_dir${RESET}"
    read -p "Press Enter to continue..."
    return 1
  fi

  # Ask for optional parameters
  echo "Enter level_low (default: 40%):"
  read level_low

  echo "Enter level_high (default: 140%):"
  read level_high

  echo "Enter gamma (default: 1.0):"
  read gamma

  echo "Enter output name prefix (default: flambient):"
  read output_prefix

  echo "Use ambient name for output? (y/n) (default: n)"
  read -k 1 use_ambient
  echo ""

  # Build command
  cmd="$SCRIPTS_DIR/001_imageMagick.zsh \"$image_dir\""

  if [ -n "$level_low" ]; then
    cmd="$cmd \"$level_low\""
  fi

  if [ -n "$level_high" ]; then
    cmd="$cmd \"$level_high\""
  fi

  if [ -n "$gamma" ]; then
    cmd="$cmd \"$gamma\""
  fi

  if [ -n "$output_prefix" ]; then
    cmd="$cmd \"$output_prefix\""
  fi

  if [[ "$use_ambient" == "y" || "$use_ambient" == "Y" ]]; then
    cmd="$cmd --use-ambient-name"
  fi

  # Confirm and run
  echo ""
  echo "Ready to start ImageMagick processing with command:"
  echo "${CYAN}$cmd${RESET}"
  echo ""
  echo "Proceed? (y/n)"
  read -k 1 confirm
  echo ""

  if [ "$confirm" = "y" ] || [ "$confirm" = "Y" ]; then
    echo "${GREEN}Starting ImageMagick processing...${RESET}"
    echo ""

    # Run command
    eval "$cmd"
    status=$?

    echo ""
    if [ $status -eq 0 ]; then
      echo "${GREEN}ImageMagick processing completed successfully!${RESET}"
    else
      echo "${RED}ImageMagick processing completed with errors (status: $status)${RESET}"
    fi

    read -p "Press Enter to continue..."
  else
    echo "${YELLOW}Processing cancelled.${RESET}"
    read -p "Press Enter to continue..."
  fi
}

# Configure settings
configure_settings() {
  show_banner
  echo "${CYAN}CONFIGURE SETTINGS${RESET}"
  echo "${CYAN}-----------------${RESET}"

  echo "Current settings:"
  echo "1. Scripts Directory: $SCRIPTS_DIR"
  echo "2. Log Directory: $LOG_DIR"
  echo "3. Default Image Directory: $DEFAULT_IMAGE_DIR"
  echo "4. Default Output Directory: $DEFAULT_OUTPUT_DIR"
  echo "5. Debug Mode: $DEBUG"
  echo ""
  echo "Select setting to change (1-5) or 'q' to go back:"
  read -k 1 option
  echo ""

  case $option in
    1)
      echo "Enter new Scripts Directory path:"
      read new_path
      if [ -d "$new_path" ]; then
        SCRIPTS_DIR="$new_path"
        echo "${GREEN}Scripts directory updated to: $SCRIPTS_DIR${RESET}"
      else
        echo "${RED}Error: Directory does not exist: $new_path${RESET}"
      fi
      ;;
    2)
      echo "Enter new Log Directory path:"
      read new_path
      LOG_DIR="$new_path"
      mkdir -p "$LOG_DIR"
      echo "${GREEN}Log directory updated to: $LOG_DIR${RESET}"
      ;;
    3)
      echo "Enter new Default Image Directory path:"
      read new_path
      DEFAULT_IMAGE_DIR="$new_path"
      echo "${GREEN}Default Image Directory updated to: $DEFAULT_IMAGE_DIR${RESET}"
      ;;
    4)
      echo "Enter new Default Output Directory path:"
      read new_path
      DEFAULT_OUTPUT_DIR="$new_path"
      echo "${GREEN}Default Output Directory updated to: $DEFAULT_OUTPUT_DIR${RESET}"
      ;;
    5)
      if [ "$DEBUG" = "true" ]; then
        DEBUG="false"
        echo "${GREEN}Debug mode disabled${RESET}"
      else
        DEBUG="true"
        echo "${GREEN}Debug mode enabled${RESET}"
      fi
      ;;
    q|Q)
      return
      ;;
    *)
      echo "${YELLOW}Invalid option${RESET}"
      ;;
  esac

  read -p "Press Enter to continue..."
  configure_settings  # Show the menu again
}

# Main menu function
show_main_menu() {
  local selection

  while true; do
    show_banner
    echo "${CYAN}MAIN MENU${RESET}"
    echo "${CYAN}---------${RESET}"
    echo "1. Start new workflow"
    echo "2. View projects dashboard"
    echo "3. Check Imagen AI project status"
    echo "4. Download Imagen AI results"
    echo "5. Upload images to Imagen AI"
    echo "6. Run ImageMagick processing directly"
    echo "7. Configure settings"
    echo "q. Quit"
    echo ""
    echo "Select an option:"

    read -k 1 selection
    echo ""

    case $selection in
      1) start_workflow ;;
      2) view_projects ;;
      3) check_imagen_status ;;
      4) download_results ;;
      5) upload_to_imagen ;;
      6) run_image_magick ;;
      7) configure_settings ;;
      q|Q)
        echo "${GREEN}Exiting...${RESET}"
        exit 0
        ;;
      *)
        echo "${YELLOW}Invalid selection${RESET}"
        sleep 1
        ;;
    esac
  done
}

# --- Main Execution ---
show_main_menu
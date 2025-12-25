#!/bin/zsh

# Master Workflow Script for Image Processing
# Author: Dave Peloso
# Date: 2025-04-10
# Version: 1.5
#
# Usage: ./master_workflow.zsh [options] [project_name] [image_directory]
# Options:
#   -p          : Process images only (don't upload to Imagen AI)
#   -h          : Show help
#
# This script orchestrates the complete workflow:
# 1. Run ImageMagick processing (001_imageMagick.zsh)
# 2. Upload to Imagen AI (002_imagenUpload.zsh)
# 3. Monitor processing status (002a_checkStatus.zsh)
# 4. Download results when complete (005_downloadResults.zsh)
# 5. Log all details for future reference

# --- Configuration ---
LOG_DIR="$HOME/Library/Logs/flambient_logs"
SCRIPTS_DIR=$(dirname "$0")
DATE_FORMAT="%Y-%m-%d_%H-%M-%S"
PROCESS_ONLY=false
# --- Parse command line options ---
while getopts "ph" opt; do
  case $opt in
    p)
      PROCESS_ONLY=true
      ;;
    h)
      echo "Usage: $0 [options] [project_name] [image_directory]"
      echo "Options:"
      echo "  -p          : Process images only (don't upload to Imagen AI)"
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

# Load .env file from the script directory if exists
ENV_FILE="$SCRIPTS_DIR/.env"
if [[ -f "$ENV_FILE" ]]; then
  echo "Loading configuration from $ENV_FILE"
  source "$ENV_FILE"
fi

# --- Argument Handling ---
PROJECT_NAME="${1:-$(date +$DATE_FORMAT)}"
IMAGE_DIR="${2:-$PWD}"

# Create absolute paths
IMAGE_DIR=$(cd "$IMAGE_DIR" && pwd)
FLAMBIENT_DIR="$IMAGE_DIR/flambient"
OUTPUT_DIR="$FLAMBIENT_DIR"

# --- Setup Logging ---
mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/${PROJECT_NAME}.json"
TEMP_LOG=$(mktemp)

# --- Helper Functions ---
log() {
  timestamp=$(date +"%Y-%m-%d %H:%M:%S")
  echo "[$timestamp] $1"
}

append_to_json() {
  local step="$1"
  local step_result="$2"  # Changed from 'status' to 'step_result'
  local start_time="$3"
  local end_time="$4"
  local duration="$5"
  local details="$6"
  
  # Format times for display
  local start_time_fmt=$(date -r "$start_time" "+%Y-%m-%d %H:%M:%S" 2>/dev/null || echo "unknown")
  local end_time_fmt=$(date -r "$end_time" "+%Y-%m-%d %H:%M:%S" 2>/dev/null || echo "unknown")
  local curr_time_fmt=$(date "+%Y-%m-%d %H:%M:%S")
  local workflow_start_fmt=$(date -r "$WORKFLOW_START_TIME" "+%Y-%m-%d %H:%M:%S" 2>/dev/null || echo "unknown")
  
  cat > "$TEMP_LOG" << EOF
{
  "project_name": "$PROJECT_NAME",
  "image_directory": "$IMAGE_DIR",
  "start_time": "$workflow_start_fmt",
  "steps": {
    "$step": {
      "status": "$step_result",
      "start_time": "$start_time_fmt",
      "end_time": "$end_time_fmt",
      "duration_seconds": $duration,
      "details": $details
    }$(if [[ -f "$LOG_FILE" ]]; then echo ','; fi)
EOF

  # Append to existing JSON if it exists
  if [[ -f "$LOG_FILE" ]]; then
    # Extract existing steps
    sed -n '/^  "steps": {/,/^  }/p' "$LOG_FILE" | sed '1d;$d' >> "$TEMP_LOG"
  fi

  # Calculate total workflow duration so far (in seconds)
  local total_duration=$(($(date +%s) - WORKFLOW_START_TIME))

  # Determine overall status
  local overall_result="success"
  if [[ "$step_result" == "failed" ]]; then
    overall_result="failed"
  elif [[ -f "$LOG_FILE" && $(grep -c "\"status\": \"failed\"" "$LOG_FILE") -gt 0 ]]; then
    overall_result="partial"
  fi

  # Close JSON structure
  cat >> "$TEMP_LOG" << EOF
  },
  "end_time": "$curr_time_fmt",
  "total_duration_seconds": $total_duration,
  "overall_status": "$overall_result"
}
EOF

  mv "$TEMP_LOG" "$LOG_FILE"
}

run_step() {
  local step_name="$1"
  local command="$2"
  local step_output_file=$(mktemp)
  
  log "Starting step: $step_name"
  local start_time=$(date +%s)
  
  # Execute the command and capture output
  eval "$command" > "$step_output_file" 2>&1
  local exit_code=$?
  
  local end_time=$(date +%s)
  local duration=$((end_time - start_time))
  local step_result
  
  if [ $exit_code -eq 0 ]; then
    log "✅ $step_name completed successfully in $duration seconds"
    step_result="success"
  else
    log "❌ $step_name failed with status code $exit_code in $duration seconds"
    step_result="failed"
  fi
  
  # Format output for JSON - carefully escaping quotes and newlines
  local details
  if command -v jq &> /dev/null; then
    details=$(cat "$step_output_file" | jq -R -s '{output: .}')
  else
    # Fallback if jq is not available
    local sanitized_output=$(cat "$step_output_file" | tr '\n' ' ' | tr '"' "'")
    details="{\"output\": \"$sanitized_output\"}"
  fi
  
  append_to_json "$step_name" "$step_result" "$start_time" "$end_time" "$duration" "$details"
  
  # Save step output to separate file for debugging
  cat "$step_output_file" > "$LOG_DIR/${PROJECT_NAME}_${step_name}.log"
  
  # Output the step content for debugging if failed
  if [ $exit_code -ne 0 ]; then
    log "Error details for $step_name step:"
    cat "$step_output_file"
  fi
  
  rm "$step_output_file"
  
  return $exit_code
}

extract_project_uuid() {
  local log_file="$1"
  if [ -f "$log_file" ]; then
    # FIX: Directly save the content of the log file to a variable for easier debugging
    local log_content=$(cat "$log_file")
    log "Extracting UUID from log file: ${log_file}"
    
    # FIX: Display the content to help with debugging
    log "Log file content (first 5 lines):"
    echo "$log_content" | head -n 5 | while IFS= read -r line; do
      log "    $line"
    done
    
    # FIX: More direct approach to extract UUID
    local uuid
    # Look for the specific UUID in the format shown in the example
    uuid=$(echo "$log_content" | grep -o "Created project with UUID: [0-9a-f]\{24\}" | sed 's/Created project with UUID: //')
    
    # If that fails, try other patterns
    if [ -z "$uuid" ]; then
      uuid=$(echo "$log_content" | grep -o "project_uuid.*[0-9a-f]\{24\}" | grep -o "[0-9a-f]\{24\}")
    fi
    
    if [ -z "$uuid" ]; then
      uuid=$(echo "$log_content" | grep -o "project.*[0-9a-f]\{24\}" | grep -o "[0-9a-f]\{24\}")
    fi
    
    if [ -z "$uuid" ]; then
      # Last resort - look for anything that looks like a 24-character hex UUID
      uuid=$(echo "$log_content" | grep -o "[0-9a-f]\{24\}" | head -n 1)
    fi
    
    if [ -n "$uuid" ]; then
      log "Found UUID: $uuid"
    else
      log "No UUID found in log file"
    fi
    
    echo "$uuid"
  else
    log "Warning: Log file not found: $log_file"
    echo ""
  fi
}

# --- Main Workflow ---
WORKFLOW_START_TIME=$(date +%s)

log "=== Starting workflow for project: $PROJECT_NAME ==="
log "Image directory: $IMAGE_DIR"
log "Output directory: $OUTPUT_DIR"
log "Log file: $LOG_FILE"

if [ "$PROCESS_ONLY" = true ]; then
  log "Process-only mode enabled: Will stop after image processing"
fi

# Step 1: Run ImageMagick Processing
if run_step "ImageMagick_Processing" "$SCRIPTS_DIR/001_imageMagick.zsh \"$IMAGE_DIR\""; then
  log "Image processing completed successfully"
else
  log "Warning: Image processing reported issues, but continuing workflow"
fi

# If process-only mode is enabled, exit after image processing
if [ "$PROCESS_ONLY" = true ]; then
  log "=== Process-only mode: Stopping after image processing ==="
  log "Processed images are available in: $FLAMBIENT_DIR"
  
  # --- Workflow Summary ---
  WORKFLOW_END_TIME=$(date +%s)
  TOTAL_DURATION=$((WORKFLOW_END_TIME - WORKFLOW_START_TIME))
  MINUTES=$((TOTAL_DURATION / 60))
  SECONDS=$((TOTAL_DURATION % 60))
  
  log "=== Workflow completed (process-only) ==="
  log "Project: $PROJECT_NAME"
  log "Total duration: ${MINUTES}m ${SECONDS}s"
  log "Log file: $LOG_FILE"
  
  exit 0
fi

# Step 2: Upload to Imagen AI
# First ensure the script is executable
chmod +x "$SCRIPTS_DIR/002_imagenUpload.zsh"

# Check if the flambient directory exists and has files
if [ ! -d "$FLAMBIENT_DIR" ]; then
  log "Error: Flambient directory does not exist: $FLAMBIENT_DIR"
  exit 1
fi

# Count JPG files in the flambient directory
jpg_count=$(find "$FLAMBIENT_DIR" -type f -name "*.jpg" | wc -l)
if [ "$jpg_count" -eq 0 ]; then
  log "Error: No JPG files found in $FLAMBIENT_DIR"
  log "Check if ImageMagick processing created any output files"
  exit 1
fi

log "Found $jpg_count JPG files in $FLAMBIENT_DIR to upload"

# Run the upload script with explicit -d flag exactly as in your successful example
# This ensures the .env file is found correctly
UPLOAD_LOG="$LOG_DIR/${PROJECT_NAME}_ImagenAI_Upload.log"
if run_step "ImagenAI_Upload" "cd \"$SCRIPTS_DIR\" && ./002_imagenUpload.zsh -d \"$FLAMBIENT_DIR\""; then
  log "Upload to Imagen AI completed successfully"
  
  # Extract PROJECT_UUID from logs - making it simpler for now
  PROJECT_UUID=""
  
  # First try to parse the log file
  if [ -f "$UPLOAD_LOG" ]; then
    # Simple grep to get the UUID - avoiding using head with options
    PROJECT_UUID=$(grep -o "Created project with UUID: [0-9a-f]\{24\}" "$UPLOAD_LOG" | sed 's/Created project with UUID: //' | sed -n '1p')
    
    # If not found, try a more general approach
    if [ -z "$PROJECT_UUID" ]; then
      PROJECT_UUID=$(grep -o "[0-9a-f]\{24\}" "$UPLOAD_LOG" | sed -n '1p')
    fi
  fi
  
  # If UUID is still empty, extract it from stdout logs
  if [ -z "$PROJECT_UUID" ]; then
    log "Couldn't find UUID in log file, extracting from step output"
    PROJECT_UUID=$(cat "$LOG_DIR/${PROJECT_NAME}_ImagenAI_Upload.log" | grep -o "[0-9a-f]\{24\}" | sed -n '1p')
  fi
  
  if [ -n "$PROJECT_UUID" ]; then
    log "Extracted Project UUID: $PROJECT_UUID"
  else
    log "Error: Could not extract Project UUID from upload logs"
    log "Check the upload log file for details: $UPLOAD_LOG"
    exit 1
  fi
else
  log "Error: Upload to Imagen AI failed"
  log "Check the upload log file for details: $UPLOAD_LOG"
  exit 1
fi

# Step 3: Check Status until complete
log "Monitoring project status (UUID: $PROJECT_UUID)"
PROJECT_STATUS="Processing"
CHECK_COUNT=0
MAX_CHECKS=120 # 2 hours maximum wait (120 checks @ 1 minute each)

# Ensure the check status script is executable
chmod +x "$SCRIPTS_DIR/002a_checkStatus.zsh"

while [ "$PROJECT_STATUS" != "Completed" ] && [ "$PROJECT_STATUS" != "Failed" ] && [ $CHECK_COUNT -lt $MAX_CHECKS ]; do
  CHECK_COUNT=$((CHECK_COUNT + 1))
  
  CHECK_STATUS_LOG=$(mktemp)
  # Fixed: Using a different approach to run in the correct directory
  if DEBUG=false cd "$SCRIPTS_DIR" && ./002a_checkStatus.zsh "$PROJECT_UUID" > "$CHECK_STATUS_LOG" 2>&1; then
    # Extract status from the log
    PROJECT_STATUS=$(grep "Status:" "$CHECK_STATUS_LOG" | cut -d':' -f2 | tr -d ' ')
    PROGRESS=$(grep "Progress:" "$CHECK_STATUS_LOG" | cut -d':' -f2 | tr -d ' %')
    
    log "Check #$CHECK_COUNT - Status: $PROJECT_STATUS, Progress: ${PROGRESS:-0}%"
    
    if [ "$PROJECT_STATUS" = "Completed" ]; then
      log "✅ Project processing completed successfully!"
      break
    elif [ "$PROJECT_STATUS" = "Failed" ]; then
      log "❌ Project processing failed!"
      cat "$CHECK_STATUS_LOG" >> "$LOG_DIR/${PROJECT_NAME}_Status_Checks.log"
      run_step "ImagenAI_Status" "cat \"$CHECK_STATUS_LOG\""
      rm "$CHECK_STATUS_LOG"
      exit 1
    fi
  else
    log "⚠️ Status check failed, will retry"
  fi
  
  cat "$CHECK_STATUS_LOG" >> "$LOG_DIR/${PROJECT_NAME}_Status_Checks.log"
  rm "$CHECK_STATUS_LOG"
  
  if [ "$PROJECT_STATUS" != "Completed" ] && [ "$PROJECT_STATUS" != "Failed" ]; then
    log "Waiting 60 seconds before next status check..."
    sleep 60
  fi
done

if [ $CHECK_COUNT -ge $MAX_CHECKS ]; then
  log "❌ Exceeded maximum wait time ($MAX_CHECKS minutes). Project may still be processing."
  run_step "ImagenAI_Status" "echo \"Timeout after $MAX_CHECKS status checks. Project UUID: $PROJECT_UUID\""
  exit 1
fi

run_step "ImagenAI_Status" "echo \"Project processing completed after $CHECK_COUNT checks. Status: $PROJECT_STATUS, Progress: ${PROGRESS:-0}%\""

# Step 4: Download Results
# Ensure the download script is executable
chmod +x "$SCRIPTS_DIR/005_downloadResults.zsh"

if [ "$PROJECT_STATUS" = "Completed" ]; then
  # Fixed: Using proper syntax for the download command
  if run_step "Download_Results" "cd \"$SCRIPTS_DIR\" && ./005_downloadResults.zsh \"$PROJECT_UUID\" \"$API_KEY\" \"$OUTPUT_DIR\""; then
    log "✅ Results downloaded successfully to: $OUTPUT_DIR"
  else
    log "❌ Failed to download results"
    exit 1
  fi
else
  log "⚠️ Skipping download as project status is: $PROJECT_STATUS"
fi

# --- Workflow Summary ---
WORKFLOW_END_TIME=$(date +%s)
TOTAL_DURATION=$((WORKFLOW_END_TIME - WORKFLOW_START_TIME))
MINUTES=$((TOTAL_DURATION / 60))
SECONDS=$((TOTAL_DURATION % 60))

log "=== Workflow completed ==="
log "Project: $PROJECT_NAME"
log "Total duration: ${MINUTES}m ${SECONDS}s"
log "Project UUID: $PROJECT_UUID"
log "Results directory: $OUTPUT_DIR"
log "Log file: $LOG_FILE"

exit 0
#!/bin/zsh

# Imagen API Upload Script
# Author: Dave Peloso
# Updated: 2025-04-02
#
# Usage: ./002_imagenUpload.zsh [options]
# Options:
#   -d <directory>   Image directory to upload
#   -P <profile_key> Imagen AI profile key
#   -k <api_key>     API key (overrides .env)
#   -v               Enable debug mode
#   -p <profile>     Load settings from .env.<profile> file
#
# Configuration can be set in .env file instead of using command-line options

# Load base configuration from .env file if it exists
if [[ -f "$PWD/.env" ]]; then
  echo "Loading settings from .env file..."
  source "$PWD/.env"
fi

# Default values (used if not set in .env or command line)
: ${DEBUG:=false}
: ${PROFILE_KEY:="309406"}
: ${PROFILE_NAME:=""}
: ${API_KEY:="$IMAGEN_AI_API_KEY"}
: ${API_BASE_URL:="https://api-beta.imagen-ai.com/v1"}

# Edit request parameters (customizable via .env)
: ${CROP:=false}
: ${WINDOW_PULL:=true}
: ${PERSPECTIVE_CORRECTION:=false}
: ${HDR_MERGE:=false}
: ${PHOTOGRAPHY_TYPE:="REAL_ESTATE"}

# Parse command line arguments (overrides .env settings)
while getopts "d:P:k:vp:" opt; do
  case $opt in
  d) IMAGE_DIR="$OPTARG" ;;   # Set IMAGE_DIR from argument
  P) PROFILE_KEY="$OPTARG" ;; # Set PROFILE_KEY from argument
  k) API_KEY="$OPTARG" ;;     # Set API_KEY from argument
  v) DEBUG=true ;;            # Enable debugging
  p) ENV_PROFILE="$OPTARG" ;; # Load profile-specific settings
  \?)
    echo "Invalid option: -$opt" >&2
    exit 1
    ;; # Handle invalid options
  esac
done

# Load profile-specific settings if requested (highest priority)
if [[ -n "$ENV_PROFILE" ]]; then
  if [[ -f "$PWD/.env.$ENV_PROFILE" ]]; then
    echo "Loading profile: $ENV_PROFILE"
    source "$PWD/.env.$ENV_PROFILE"
  else
    echo "Warning: Profile file .env.$ENV_PROFILE not found"
  fi
fi

# Validate required arguments
if [ -z "$IMAGE_DIR" ]; then
  echo "Error: Missing required argument -d (IMAGE_DIR) or IMAGE_DIR in .env" >&2
  exit 1
fi

if [ -z "$API_KEY" ]; then
  echo "Error: API key not provided. Use -k, set API_KEY in .env, or set IMAGEN_AI_API_KEY environment variable." >&2
  exit 1
fi

# Optional Debug Information
if [ "$DEBUG" = true ]; then
  echo "Debug mode enabled"
  echo "IMAGE_DIR=$IMAGE_DIR"
  echo "PROFILE_KEY=$PROFILE_KEY"
  echo "API_KEY=${API_KEY:0:4}******" # Mask API key in debug output
  echo "CROP=$CROP"
  echo "WINDOW_PULL=$WINDOW_PULL"
  echo "PERSPECTIVE_CORRECTION=$PERSPECTIVE_CORRECTION"
  echo "HDR_MERGE=$HDR_MERGE"
  echo "PHOTOGRAPHY_TYPE=$PHOTOGRAPHY_TYPE"
fi

# Validate inputs
if [ ! -d "$IMAGE_DIR" ]; then
  echo "Error: Image directory does not exist: $IMAGE_DIR" >&2
  exit 1
fi

if [ -z "$PROFILE_KEY" ] && [ -z "$PROFILE_NAME" ]; then
  echo "Error: Either profile name or profile key is required" >&2
  exit 1
fi

# Function to log debug info
debug() {
  if [ "$DEBUG" = true ]; then
    echo "[DEBUG] $1" >&2
  fi
}

# Count JPG files
file_count=$(find "$IMAGE_DIR" -type f -iname "*.jpg" | wc -l)
echo "Found $(printf "%8d" $file_count) JPG files in $IMAGE_DIR"

# Function to make API requests
make_request() {
  local method="$1"
  local endpoint="$2"
  local data="$3"
  local url="${API_BASE_URL}${endpoint}"

  debug "Making $method request to: $url"
  if [ -n "$data" ]; then
    debug "Request data: $data"
  fi

  # Create temp file for response
  local response_file=$(mktemp)

  if [ "$method" = "GET" ]; then
    # For GET requests
    curl -s -X GET \
      -H "x-api-key: $API_KEY" \
      -H "Content-Type: application/json" \
      "$url" >"$response_file"
  else
    # For POST requests
    curl -s -X POST \
      -H "x-api-key: $API_KEY" \
      -H "Content-Type: application/json" \
      -d "$data" \
      "$url" >"$response_file"
  fi

  local status_code=$?
  if [ $status_code -ne 0 ]; then
    echo "curl failed with exit code $status_code"
    rm "$response_file"
    return 1
  fi

  local response=$(cat "$response_file")
  debug "Response body: $response"

  # Check for empty response
  if [ -z "$response" ]; then
    echo "Error: Empty response from API"
    rm "$response_file"
    return 1
  fi

  echo "$response"
  rm "$response_file"
  return 0
}

# Step 1: Get profile key if profile name provided
if [ -n "$PROFILE_NAME" ] && [ -z "$PROFILE_KEY" ]; then
  echo "Getting profile key for: $PROFILE_NAME"
  profiles_response=$(make_request "GET" "/profiles")

  # Extract profile key from response
  if command -v jq &>/dev/null; then
    PROFILE_KEY=$(echo "$profiles_response" | jq -r ".data.profiles[] | select(.profile_name == \"$PROFILE_NAME\").profile_key")
  else
    echo "JQ not installed. Please specify profile key directly with -P."
    exit 1
  fi

  if [ -z "$PROFILE_KEY" ]; then
    echo "Error: Could not find profile key for profile name: $PROFILE_NAME"
    exit 1
  fi

  echo "Found profile key: $PROFILE_KEY"
fi

# Step 2: Create a new project
echo "Creating new ImagenAI project..."
create_project_response=$(make_request "POST" "/projects/" "{}")
debug "Project creation response: $create_project_response"

# Extract project UUID - using grep as a fallback if jq isn't available
if command -v jq &>/dev/null; then
  PROJECT_UUID=$(echo "$create_project_response" | jq -r '.data.project_uuid')
else
  # Using grep/sed as fallback
  PROJECT_UUID=$(echo "$create_project_response" | grep -o '"project_uuid":"[^"]*"' | sed 's/"project_uuid":"//;s/"//g')
fi

# Verify we got a UUID
if [ -z "$PROJECT_UUID" ]; then
  echo "Error: Could not extract project UUID from response"
  echo "Response was: $create_project_response"
  exit 1
fi

echo "Created project with UUID: $PROJECT_UUID"
echo "Project URL: https://app.imagen-ai.com/projects/$PROJECT_UUID"

# Step 3: Generate files list for upload
echo "Preparing file list for upload..."
FILES_JSON="{\"files_list\": ["

first=true
for file in $(find "$IMAGE_DIR" -type f -iname "*.jpg" | sort); do
  filename=$(basename "$file")
  if [ "$first" = true ]; then
    first=false
  else
    FILES_JSON="${FILES_JSON},"
  fi
  FILES_JSON="${FILES_JSON}{\"file_name\":\"$filename\"}"
done

FILES_JSON="${FILES_JSON}]}"
debug "Request JSON: $FILES_JSON"

# Step 4: Get temporary upload links
echo "Requesting temporary upload links..."
upload_links_response=$(make_request "POST" "/projects/$PROJECT_UUID/get_temporary_upload_links" "$FILES_JSON")

# Step 5: Upload the files
echo "Processing upload links..."
if command -v jq &>/dev/null; then
  # Using jq to parse the response
  echo "$upload_links_response" | jq -c '.data.files_list[]' | while read -r file_object; do
    file_name=$(echo "$file_object" | jq -r '.file_name')
    upload_link=$(echo "$file_object" | jq -r '.upload_link')

    echo "Uploading: $file_name"
    curl -s -X PUT -T "$IMAGE_DIR/$file_name" "$upload_link"
    echo "✓ Uploaded: $file_name"
  done
else
  # Fallback without jq - crude but might work
  echo "WARNING: JQ not installed. Upload might fail."
  echo "Install JQ for better compatibility: brew install jq"

  # Try to parse with grep/sed
  echo "$upload_links_response" | grep -o '{[^}]*}' | while read -r object; do
    file_name=$(echo "$object" | grep -o '"file_name":"[^"]*"' | cut -d'"' -f4)
    upload_link=$(echo "$object" | grep -o '"upload_link":"[^"]*"' | cut -d'"' -f4)

    if [ -n "$file_name" ] && [ -n "$upload_link" ]; then
      echo "Uploading: $file_name"
      curl -s -X PUT -T "$IMAGE_DIR/$file_name" "$upload_link"
      echo "✓ Uploaded: $file_name"
    fi
  done
fi

# Step 6: Send project for edit
echo "Sending project for editing with profile key: $PROFILE_KEY"
edit_request="{\"crop\":$CROP,\"window_pull\":$WINDOW_PULL,\"perspective_correction\":$PERSPECTIVE_CORRECTION,\"profile_key\":\"$PROFILE_KEY\",\"hdr_merge\":$HDR_MERGE,\"photography_type\":\"$PHOTOGRAPHY_TYPE\"}"
edit_response=$(make_request "POST" "/projects/$PROJECT_UUID/edit" "$edit_request")

echo ""
echo "Project submitted for editing."
echo "Check status with: curl -H \"x-api-key: $API_KEY\" $API_BASE_URL/projects/$PROJECT_UUID/edit/status"
echo ""
echo "Project URL (might take a few minutes to be accessible):"
echo "https://app.imagen-ai.com/projects/$PROJECT_UUID"

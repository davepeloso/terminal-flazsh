#!/bin/bash

# ImagenAI Download Results Script
# Author: Dave Peloso
# Date: 2025-04-02
# Version: 2.0
#
# Usage: ./download_results.sh [project_uuid] [api_key] [output_dir]
#
# Parameters:
#   - project_uuid: Required UUID of the project to download
#   - api_key: Optional, can be set in .env file
#   - output_dir: Optional, can be set in .env file

# Load values from .env file if it exists
if [[ -f "$PWD/.env" ]]; then
    echo "Loading settings from .env file..."
    source "$PWD/.env"
fi

# Set defaults if not in .env (lowest priority)
: ${API_BASE_URL:="https://api-beta.imagen-ai.com/v1"}
: ${OUTPUT_DIR:="flambient"}
: ${API_KEY:="$IMAGEN_AI_API_KEY"}  # Fall back to environment variable

# Get project UUID from command line (required)
PROJECT_UUID="$1"

# Override with command-line arguments if provided (highest priority)
if [ -n "$2" ]; then API_KEY="$2"; fi
if [ -n "$3" ]; then OUTPUT_DIR="$3"; fi

# Check required parameters
if [ -z "$PROJECT_UUID" ]; then
  echo "Error: Project UUID required"
  echo "Usage: $0 [project_uuid] [api_key] [output_dir]"
  exit 1
fi

if [ -z "$API_KEY" ]; then
  echo "Error: API key not found"
  echo "Please provide API key as second parameter or set API_KEY in .env file or IMAGEN_AI_API_KEY environment variable"
  exit 1
fi

# Create output directory if it doesn't exist
mkdir -p "$OUTPUT_DIR"

echo "Downloading results for project: $PROJECT_UUID"
echo "Using API key: ${API_KEY:0:5}...${API_KEY:(-5)}" # Show first/last 5 chars for security
echo "Output directory: $OUTPUT_DIR"

# Get temporary download links
echo "Getting download links..."
response=$(curl -s -X GET \
  -H "x-api-key: $API_KEY" \
  -H "Content-Type: application/json" \
  "$API_BASE_URL/projects/$PROJECT_UUID/edit/get_temporary_download_links")

# Check for errors in response
if [[ "$response" == *"error"* ]]; then
  echo "Error in API response:"
  echo "$response"
  exit 1
fi

# Parse the response to get download links
if command -v jq &> /dev/null; then
  # Using jq (preferred)
  count=$(echo "$response" | jq '.data.files_list | length')
  echo "Found $count files to download"

  for ((i=0; i<$count; i++)); do
    file_name=$(echo "$response" | jq -r ".data.files_list[$i].file_name")
    download_link=$(echo "$response" | jq -r ".data.files_list[$i].download_link")

    echo "[$((i+1))/$count] Downloading: $file_name"
    curl -s -o "$OUTPUT_DIR/$file_name" "$download_link"
    if [ $? -eq 0 ]; then
      echo "✓ Downloaded: $file_name"
    else
      echo "✗ Failed to download: $file_name"
    fi
  done
else
  # Fallback if jq is not available
  echo "Warning: jq not installed. Falling back to grep/sed extraction."
  echo "For more reliable operation, install jq: brew install jq"

  # Extract file info using grep/sed
  files_info=$(echo "$response" | grep -o '{[^}]*}')

  # Loop through each file info
  echo "$files_info" | while read -r file_info; do
    file_name=$(echo "$file_info" | grep -o '"file_name":"[^"]*"' | sed 's/"file_name":"//;s/"//')
    download_link=$(echo "$file_info" | grep -o '"download_link":"[^"]*"' | sed 's/"download_link":"//;s/"//')

    if [ -n "$file_name" ] && [ -n "$download_link" ]; then
      echo "Downloading: $file_name"
      curl -s -o "$OUTPUT_DIR/$file_name" "$download_link"
      if [ $? -eq 0 ]; then
        echo "✓ Downloaded: $file_name"
      else
        echo "✗ Failed to download: $file_name"
      fi
    fi
  done
fi

echo "Download complete! Files saved to: $OUTPUT_DIR"
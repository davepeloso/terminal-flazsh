#!/bin/zsh

# Usage: ./check_status.sh [project_uuid] [api_key]
# DEBUG=true ./check_status.sh [project_uuid] [api_key]

# Load API_KEY from .env file if it exists
if [[ -f "$PWD/.env" ]]; then
  # Extract just the API_KEY and API_BASE_URL from .env file to avoid syntax errors
  API_KEY_FROM_ENV=$(grep "^API_KEY=" "$PWD/.env" | cut -d'=' -f2)
  API_BASE_URL_FROM_ENV=$(grep "^API_BASE_URL=" "$PWD/.env" | cut -d'=' -f2)
  
  if [[ -n "$API_KEY_FROM_ENV" ]]; then
    API_KEY="$API_KEY_FROM_ENV"
    echo "Using API key from .env file"
  fi
  
  if [[ -n "$API_BASE_URL_FROM_ENV" ]]; then
    API_BASE_URL="$API_BASE_URL_FROM_ENV"
  fi
else
  API_BASE_URL="https://api-beta.imagen-ai.com/v1"
fi

# Set default for API_BASE_URL if not set
: ${API_BASE_URL:="https://api-beta.imagen-ai.com/v1"}
# Keep original debug setting
DEBUG=${DEBUG:-true}

# Get project UUID from command line
PROJECT_UUID="$1"

# Override API_KEY with command-line argument if provided
if [ -n "$2" ]; then 
  API_KEY="$2"
  echo "Using API key from command line"
fi

# Check required parameters
if [ -z "$PROJECT_UUID" ]; then
  echo "Error: Project UUID required"
  echo "Usage: $0 PROJECT_UUID [API_KEY]"
  echo "Example: $0 67e26d3bc3cfd570eaf5414b"
  exit 1
fi

if [ -z "$API_KEY" ]; then
  echo "Error: API key not found"
  echo "Please provide API key as second parameter or set API_KEY in .env file"
  exit 1
fi

# Function to log debug info
debug() {
  if [ "$DEBUG" = true ]; then
    echo "[DEBUG] $1" >&2
  fi
}

echo "Checking status of project: $PROJECT_UUID"
echo "Web URL: https://app.imagen-ai.com/projects/$PROJECT_UUID"

# Make API request to check status
debug "Making API request to: $API_BASE_URL/projects/$PROJECT_UUID/edit/status"
response_file=$(mktemp)
http_code=$(curl -s -w "%{http_code}" -X GET \
  -H "x-api-key: $API_KEY" \
  -H "Content-Type: application/json" \
  "$API_BASE_URL/projects/$PROJECT_UUID/edit/status" -o "$response_file")

response=$(cat "$response_file")
debug "HTTP Status Code: $http_code"
debug "API Response: $response"

# Check for errors
if [ "$http_code" -ne 200 ]; then
  echo "Error: HTTP status code $http_code"
  echo "Response: $response"
  rm "$response_file"
  exit 1
fi

# Extract status - using local variables to avoid read-only issues
if command -v jq &> /dev/null; then
  if echo "$response" | jq -e '.data.status' > /dev/null 2>&1; then
    local_status=$(echo "$response" | jq -r '.data.status')
    local_progress=$(echo "$response" | jq -r '.data.progress // 0')
  else
    echo "Error: Could not find status in API response"
    echo "Response: $response"
    rm "$response_file"
    exit 1
  fi
else
  # Fallback without jq
  local_status=$(echo "$response" | grep -o '"status":"[^"]*"' | head -1 | cut -d'"' -f4)
  local_progress=$(echo "$response" | grep -o '"progress":[0-9]*' | head -1 | cut -d':' -f2)
  
  if [ -z "$local_status" ]; then
    echo "Error: Could not extract status from response (jq not installed)"
    echo "Response: $response"
    rm "$response_file"
    exit 1
  fi
fi

rm "$response_file"

echo "Status: $local_status"
echo "Progress: ${local_progress:-0}%"

# Check if completed
if [ "$local_status" = "Completed" ]; then
  echo "Project processing is complete!"
  echo "Run ./download_results.sh $PROJECT_UUID [output_dir] to download"
elif [ "$local_status" = "Failed" ]; then
  echo "Project processing has failed."
fi
#!/bin/zsh

# Description: Extract metadata from images, group them, and generate ImageMagick
#              scripts for flambient processing with enhanced configuration options.
# Author: Dave Peloso
# Email: davepeloso@gmail.com
# Date: 2025-04-05
# Version: 4.5 (Remove line continuations from .mgk scripts)
# License: MIT
# Dependencies: ImageMagick (magick command), Exiftool, awk, sort, sed, mkdir, mv, cut, tr
# Notes: This script extracts metadata, identifies ambient/flash shots,
#        groups them, generates ImageMagick scripts to blend them,
#        and provides a master script to run the processing.
#        Supports configuration via command line arguments or .env file.
#
# Usage: ./001_imageMagick.zsh /path/to/images [level_low] [level_high] [gamma] [output_name_prefix] [--use-ambient-name]

# --- Default Configuration ---
DEFAULT_LEVEL_LOW="40%"
DEFAULT_LEVEL_HIGH="140%"
DEFAULT_GAMMA="1.0"
DEFAULT_OUTPUT_NAME_PREFIX="flambient"
DEFAULT_USE_AMBIENT_NAME=0
DEFAULT_ENABLE_DARKEN_EXPORT="true"
DEFAULT_DARKEN_SUFFIX="_tmp"

# Function to load configuration from .env file and command line arguments
load_config() {
  # Start with default values
  LEVEL_LOW="$DEFAULT_LEVEL_LOW"
  LEVEL_HIGH="$DEFAULT_LEVEL_HIGH"
  GAMMA="$DEFAULT_GAMMA"
  OUTPUT_NAME_PREFIX="$DEFAULT_OUTPUT_NAME_PREFIX"
  USE_AMBIENT_NAME_FLAG="$DEFAULT_USE_AMBIENT_NAME"
  ENABLE_DARKEN_EXPORT="$DEFAULT_ENABLE_DARKEN_EXPORT"
  DARKEN_SUFFIX="$DEFAULT_DARKEN_SUFFIX"

  local img_path_arg="" level_low_arg="" level_high_arg="" gamma_arg="" output_name_prefix_arg="" use_ambient_name_flag_arg=""
  local positional_count=0
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --use-ambient-name)
      use_ambient_name_flag_arg=1
      shift
      ;;
    *)
      positional_count=$((positional_count + 1))
      case $positional_count in
      1) img_path_arg="$1" ;; 2) level_low_arg="$1" ;; 3) level_high_arg="$1" ;;
      4) gamma_arg="$1" ;; 5) output_name_prefix_arg="$1" ;;
      esac
      shift
      ;;
    esac
  done

  if [[ -f "$PWD/.env" ]]; then
    print -r -- "Loading settings from .env file..."
    . "$PWD/.env"
    if [[ "$USE_AMBIENT_NAME" = "true" ]]; then
      USE_AMBIENT_NAME_FLAG=1
    elif [[ "$USE_AMBIENT_NAME" = "false" ]]; then USE_AMBIENT_NAME_FLAG=0; fi
    if [[ "$ENABLE_DARKEN_EXPORT" != "true" && "$ENABLE_DARKEN_EXPORT" != "false" ]]; then
      if [[ -v ENABLE_DARKEN_EXPORT ]]; then print -r -- "Warning: ENABLE_DARKEN_EXPORT invalid. Using default."; fi
      ENABLE_DARKEN_EXPORT="$DEFAULT_ENABLE_DARKEN_EXPORT"
    fi
    DARKEN_SUFFIX=${DARKEN_SUFFIX:-$DEFAULT_DARKEN_SUFFIX}
  fi

  if [[ -n "$img_path_arg" ]]; then IMG_PATH="$img_path_arg"; fi
  if [[ -n "$level_low_arg" ]]; then LEVEL_LOW="$level_low_arg"; fi
  if [[ -n "$level_high_arg" ]]; then LEVEL_HIGH="$level_high_arg"; fi
  if [[ -n "$gamma_arg" ]]; then GAMMA="$gamma_arg"; fi
  if [[ -n "$output_name_prefix_arg" ]]; then OUTPUT_NAME_PREFIX="$output_name_prefix_arg"; fi
  if [[ -n "$use_ambient_name_flag_arg" ]]; then USE_AMBIENT_NAME_FLAG="$use_ambient_name_flag_arg"; fi

  if [ -z "$IMG_PATH" ]; then
    print -r -- "Usage: $0 /path/to/images ..."
    exit 1
  fi

  print -r -- "Configuration:" # ... (rest of config display) ...
  print -r -- "  Image path:          $IMG_PATH"
  print -r -- "  Level low:           $LEVEL_LOW"
  print -r -- "  Level high:          $LEVEL_HIGH"
  print -r -- "  Gamma:               $GAMMA"
  if [ "$USE_AMBIENT_NAME_FLAG" -eq 0 ]; then
    print -r -- "  Output name prefix:  '$OUTPUT_NAME_PREFIX' (Using Prefix Naming)"
  else print -r -- "  Use ambient name:    Yes"; fi
  print -r -- "  Enable darken export: $ENABLE_DARKEN_EXPORT"
  print -r -- "  Darken suffix:       '$DARKEN_SUFFIX'"
}

# Function to check for required command-line tools
check_dependencies() {
  print -r -- "Checking dependencies..."
  local missing_deps=0
  local -a dependencies
  dependencies=("exiftool" "magick" "awk" "sort" "sed" "mkdir" "mv" "cut" "tr" "head" "tail")
  for cmd in "${dependencies[@]}"; do if ! command -v "$cmd" >/dev/null 2>&1; then
    print -r -- "Error: '$cmd' not found."
    missing_deps=1
  else print -r -- "  Found: $(command -v $cmd)"; fi; done
  if [ "$missing_deps" -eq 1 ]; then
    print -r -- "Please install missing dependencies."
    exit 1
  fi
  print -r -- "All dependencies found."
}

# Function to set up the environment, directories, and export variables
setupEnvironment() {
  if [ ! -d "$IMG_PATH" ]; then
    print -r -- "Error: Input directory '$IMG_PATH' not found."
    exit 1
  fi
  IMG_PATH=$(cd "$IMG_PATH" && pwd) || {
    print -r -- "Error: Cannot access '$IMG_PATH'."
    exit 1
  }
  print -r -- "Processing images from: $IMG_PATH"

  METADATA_DIR="$IMG_PATH/metadata"
  SCRIPTS_DIR="$IMG_PATH/scripts"
  FLAMBIENT_DIR="$IMG_PATH/flambient"
  TEMP_DIR="$IMG_PATH/temp"
  EXPOSURE_DIR="$IMG_PATH/exposures"
  print -r -- "Creating output directories..."
  if ! mkdir -p "$METADATA_DIR" "$SCRIPTS_DIR" "$FLAMBIENT_DIR" "$TEMP_DIR" "$EXPOSURE_DIR"; then
    print -r -- "Error creating directories."
    exit 1
  fi
  print -r -- "  Metadata directory: $METADATA_DIR"
  print -r -- "  Scripts directory: $SCRIPTS_DIR"
  print -r -- "  Flambient output directory: $FLAMBIENT_DIR"
  print -r -- "  Temporary darken directory: $TEMP_DIR"
  print -r -- "  Original exposure directory: $EXPOSURE_DIR"

  IM_MERGE_OPTS="-compose lighten -composite"
  IM_MASK_LEVEL_OPTS="-channel B -separate -level ${LEVEL_LOW},${LEVEL_HIGH},${GAMMA}"
  IM_MASK_COMPOSITE_OPTS="-compose CopyOpacity -composite"
  IM_LUMINIZE_COMPOSITE_OPTS="-compose Luminize -composite"
  IM_OVER_COMPOSITE_OPTS="-compose Over -composite"
  IM_COLORIZE_COMPOSITE_OPTS="-compose Colorize -composite"
  IM_DARKEN_COMPOSITE_OPTS="-compose darken -composite"
  export IMG_PATH METADATA_DIR SCRIPTS_DIR FLAMBIENT_DIR TEMP_DIR EXPOSURE_DIR LEVEL_LOW LEVEL_HIGH GAMMA OUTPUT_NAME_PREFIX
  export USE_AMBIENT_NAME_FLAG ENABLE_DARKEN_EXPORT DARKEN_SUFFIX IM_MERGE_OPTS IM_MASK_LEVEL_OPTS IM_MASK_COMPOSITE_OPTS
  export IM_LUMINIZE_COMPOSITE_OPTS IM_OVER_COMPOSITE_OPTS IM_COLORIZE_COMPOSITE_OPTS IM_DARKEN_COMPOSITE_OPTS

  print -r -- "Using adjustment values: Level Low=$LEVEL_LOW, Level High=$LEVEL_HIGH, Gamma=$GAMMA"
  if [ "$USE_AMBIENT_NAME_FLAG" -eq 1 ]; then print -r -- "Output naming mode: Ambient Name"; else print -r -- "Output naming mode: Prefix '$OUTPUT_NAME_PREFIX'"; fi
  print -r -- "Darken export: $([ "$ENABLE_DARKEN_EXPORT" = "true" ] && echo 'Enabled' || echo 'Disabled')"
  if [ "$ENABLE_DARKEN_EXPORT" = "true" ]; then print -r -- "Darken suffix: '$DARKEN_SUFFIX'"; fi
}

# Function to extract metadata using Exiftool, sort, and classify images
extract_metadata() {
  local output_file="$METADATA_DIR/metadata_raw.csv"
  local sorted_file="$METADATA_DIR/metadata_sorted.csv"
  local final_file="$METADATA_DIR/metadata_final.csv"
  print -r -- "Extracting metadata from JPG images in '$IMG_PATH'..."
  local total_files=$(find "$IMG_PATH" -maxdepth 1 -name "*.jpg" -type f 2>/dev/null | wc -l)
  print -r -- "Found $total_files JPG files to process."

  if ! exiftool -q -i HIDDEN -csv -ext jpg -Filename -DateTimeOriginal -MeteringMode -ShutterSpeed -ApertureValue -ISO -Flash# -WhiteBalance "$IMG_PATH" >"$output_file"; then
    print -r -- "Error: Exiftool failed." >&2
    return 1
  fi
  if [ ! -s "$output_file" ]; then
    print -r -- "Error: Exiftool output empty." >&2
    return 1
  fi
  if [ "$(awk 'END{print NR}' "$output_file")" -le 1 ]; then print -r -- "Warning: No image metadata found."; fi
  print -r -- "Raw metadata extracted."

  print -r -- "Sorting metadata by DateTimeOriginal..."
  {
    sed -n '1p' "$output_file"
    sed '1d' "$output_file" | sort -t, -k3
  } >"$sorted_file"
  if [ $? -ne 0 ]; then
    print -r -- "Error: Failed sorting." >&2
    rm -f "$output_file"
    return 1
  fi
  if [ ! -s "$sorted_file" ]; then
    print -r -- "Error: Sorted file empty." >&2
    rm -f "$output_file"
    return 1
  fi
  print -r -- "Metadata sorted."

  print -r -- "Adding Type and Group columns..."
  if ! awk -F, 'BEGIN{OFS=","; prev_type=""; Group=0; first=1;} NR==1{print $0,"Type","Group";next} {flash=$8; type=(flash==16?"Ambient":"Flash"); if(type=="Ambient"&&first==1){Group++;first=0;} else if(type=="Flash"){first=1;} if(Group==0){Group=1;} print $0,type,Group}' "$sorted_file" >"$final_file"; then
    print -r -- "Error: AWK classification failed." >&2
    rm -f "$output_file" "$sorted_file"
    return 1
  fi
  if [ ! -s "$final_file" ]; then
    print -r -- "Error: Final metadata empty." >&2
    rm -f "$output_file" "$sorted_file"
    return 1
  fi
  print -r -- "Final metadata saved to '$final_file'"

  rm "$output_file" "$sorted_file"
  print -r -- "Cleaned up intermediate files."
  generate_scripts "$final_file"
  return $?
}

# Function to generate ImageMagick (.mgk) scripts based on the final CSV
generate_scripts() {
  local input_csv="$1"
  if [ ! -f "$input_csv" ]; then
    echo "Error: Final CSV file not found: '$input_csv'"
    return 1
  fi

  echo "Generating ImageMagick scripts from '$input_csv'..."

  # Pass necessary shell variables into awk
  awk -F, \
    -v img_path="$IMG_PATH" \
    -v scripts_dir="$SCRIPTS_DIR" \
    -v flambient_dir="$FLAMBIENT_DIR" \
    -v output_name_prefix="$OUTPUT_NAME_PREFIX" \
    -v use_ambient_name="$USE_AMBIENT_NAME_FLAG" \
    -v enable_darken_export="$ENABLE_DARKEN_EXPORT" \
    -v darken_suffix="$DARKEN_SUFFIX" \
    -v im_merge_opts="$IM_MERGE_OPTS" \
    -v im_mask_level_opts="$IM_MASK_LEVEL_OPTS" \
    -v im_mask_composite_opts="$IM_MASK_COMPOSITE_OPTS" \
    -v im_luminize_composite_opts="$IM_LUMINIZE_COMPOSITE_OPTS" \
    -v im_over_composite_opts="$IM_OVER_COMPOSITE_OPTS" \
    -v im_colorize_composite_opts="$IM_COLORIZE_COMPOSITE_OPTS" \
    -v im_darken_composite_opts="$IM_DARKEN_COMPOSITE_OPTS" \
    '
    BEGIN {
        group = ""; ambientFiles = ""; flashFiles = "";
        max_group = 0;
        FS=",";
        print "Starting AWK script generation..." > "/dev/stderr";
    }

    NR > 1 {
        current_group = $11;
        current_filename = $2;
        current_type = $10;

        if (current_group != group && group != "") {
            generate_script(group, ambientFiles, flashFiles);
            ambientFiles = ""; flashFiles = "";
        }

        group = current_group;
        if (group > max_group) max_group = group;

        if (current_type == "Ambient") {
            ambientFiles = (ambientFiles == "") ? current_filename : ambientFiles "," current_filename;
        } else if (current_type == "Flash") {
            flashFiles = (flashFiles == "") ? current_filename : flashFiles "," current_filename;
        }
    }

    END {
        if (group != "") {
            generate_script(group, ambientFiles, flashFiles);
        }
        print "Finished processing CSV. Max group found: " max_group > "/dev/stderr";

        if (max_group > 0) {
             create_run_all_script(max_group);
        } else {
             print "Warning: No groups found to generate scripts for." > "/dev/stderr";
        }
    }

    # AWK function to generate the ImageMagick commands for a single group
    function generate_script(group_num, ambient_list, flash_list) {
        padded_group = sprintf("%02d", group_num);
        script_file = scripts_dir "/group_" padded_group "_script.mgk";

        print "Generating script for Group " padded_group ": " script_file > "/dev/stderr";

        print "# ImageMagick script for Group " padded_group > script_file;
        print "# Ambient files: " (ambient_list ? ambient_list : "None") >> script_file;
        print "# Flash files:   " (flash_list ? flash_list : "None") >> script_file;
        print "" >> script_file;

        split(ambient_list, ambientArray, ",");
        numAmbient = length(ambientArray);
        if (ambient_list == "") numAmbient = 0;

        split(flash_list, flashArray, ",");
        numFlash = length(flashArray);
         if (flash_list == "") numFlash = 0;

        # 1. Create Ambient Merge (if ambient images exist)
        if (numAmbient > 0) {
            cmd = "";
            for (i = 1; i <= numAmbient; i++) {
                filepath = "\"" img_path "/" ambientArray[i] "\"";
                if (cmd == "") { cmd = filepath; } else { cmd = cmd " " filepath " " im_merge_opts; }
            }
            print cmd " -write mpr:ambient_merge +delete" >> script_file;
        } else {
             print "# No ambient images for Group " padded_group >> script_file;
        }

        # 2. Create Flash Merge (if flash images exist)
        if (numFlash > 0) {
            cmd = "";
            for (i = 1; i <= numFlash; i++) {
                 filepath = "\"" img_path "/" flashArray[i] "\"";
                if (cmd == "") { cmd = filepath; } else { cmd = cmd " " filepath " " im_merge_opts; }
            }
            print cmd " -write mpr:flash_merge +delete" >> script_file;

            # Add the dark export functionality if enabled
            if (enable_darken_export == "true") {
                print "" >> script_file;
                print "# --- Dark Export: Create darkened flash composite ---" >> script_file;
                darken_cmd = "";
                for (i = 1; i <= numFlash; i++) {
                    filepath = "\"" img_path "/" flashArray[i] "\"";
                    if (darken_cmd == "") { darken_cmd = filepath; } else { darken_cmd = darken_cmd " " filepath " " im_darken_composite_opts; }
                }

                # Determine output filename for darkened image
                dark_output_jpg = "";
                if (use_ambient_name == 1 && numAmbient > 0) {
                    # Use first ambient image name with darken suffix
                    firstAmbientFile = ambientArray[1];
                    # Remove extension from the ambient filename
                    sub(/\.[^.]+$/, "", firstAmbientFile);
                    dark_output_jpg = flambient_dir "/" firstAmbientFile darken_suffix ".jpg";
                    print "# Dark output based on first ambient image: " ambientArray[1] >> script_file;
                } else {
                    # Use prefix + group number + darken suffix
                    dark_output_jpg = flambient_dir "/" output_name_prefix "_" padded_group darken_suffix ".jpg";
                    print "# Dark output based on prefix and group number" >> script_file;
                }

                print darken_cmd " -write \"" dark_output_jpg "\" +delete" >> script_file;
                print "# Darkened flash composite saved to: " dark_output_jpg >> script_file;
            }
        } else {
             print "# No flash images for Group " padded_group >> script_file;
        }

        # 3. Perform Flambient Blending (only if BOTH ambient and flash merges were created)
        if (numAmbient > 0 && numFlash > 0) {
            print "" >> script_file;
            print "# --- Flambient Blending Steps ---" >> script_file;
            print "mpr:ambient_merge " im_mask_level_opts " -write mpr:ambient_mask_alpha +delete" >> script_file;
            print "mpr:flash_merge mpr:ambient_mask_alpha " im_mask_composite_opts " -write mpr:flash_mask +delete" >> script_file;
            print "mpr:flash_merge mpr:ambient_merge " im_luminize_composite_opts " -write mpr:luminize_flambient +delete" >> script_file;
            print "mpr:luminize_flambient mpr:flash_mask " im_over_composite_opts " -write mpr:ungraded_flambient +delete" >> script_file;

            # --- Determine Output Filename ---
            output_jpg = "";
            if (use_ambient_name == 1) {
                # Use first ambient image name
                firstAmbientFile = ambientArray[1];
                # Remove extension from the ambient filename
                sub(/\.[^.]+$/, "", firstAmbientFile);
                output_jpg = flambient_dir "/" firstAmbientFile ".jpg";
                print "# Output name based on first ambient image: " ambientArray[1] >> script_file;
            } else {
                # Use prefix + group number
                output_jpg = flambient_dir "/" output_name_prefix "_" padded_group ".jpg";
                 print "# Output name based on prefix and group number." >> script_file;
            }
            # --- End Determine Output Filename ---


            # Final colorization step and save output JPG
            print "mpr:ungraded_flambient mpr:flash_merge " im_colorize_composite_opts " -write \"" output_jpg "\"" >> script_file;
            print "# Final output path: " output_jpg >> script_file;

        } else {
             print "" >> script_file;
             print "# Warning: Group " padded_group " lacks either ambient or flash images. Skipping blend operations." >> script_file;
        }
    }

    # AWK function to create the run_all_scripts.sh file
    function create_run_all_script(max_group_num) {
        run_all_file = scripts_dir "/run_all_scripts.sh";
        print "#!/bin/bash" > run_all_file;
        print "" >> run_all_file;
        print "# Master script to run all generated ImageMagick group scripts." >> run_all_file;
        print "# Generated by process_flambient.zsh on $(date)" >> run_all_file; # Use bash date command
        print "" >> run_all_file;
        print "echo \"Starting Flambient processing for all groups...\"" >> run_all_file;
        # Change directory for context, ensures relative paths in scripts work if needed
        # Although we use absolute paths mostly, this can be good practice.
        print "cd \"" scripts_dir "/..\" || exit 1 # Change to parent directory (IMG_PATH)" >> run_all_file
        print "" >> run_all_file;
        print "error_count=0" >> run_all_file;
        print "success_count=0" >> run_all_file;
        print "" >> run_all_file;

        for (i = 1; i <= max_group_num; i++) {
            padded_num = sprintf("%02d", i);
            script_path = scripts_dir "/group_" padded_num "_script.mgk";
            # Use bash test -f for file existence check within the generated script
            print "script_file=\"" script_path "\"" >> run_all_file;
            print "if [ -f \"$script_file\" ]; then" >> run_all_file;
                print "    echo \"----------------------------------------\"" >> run_all_file;
                print "    echo \"Processing Group " padded_num " ($script_file)...\"" >> run_all_file;
                print "    magick -script \"$script_file\"" >> run_all_file;
                print "    exit_status=$?" >> run_all_file;
                print "    if [ $exit_status -ne 0 ]; then" >> run_all_file;
                print "        echo \"Error processing Group " padded_num ". Exit status: $exit_status\"" >> run_all_file;
                print "        error_count=$((error_count + 1))" >> run_all_file;
                print "    else" >> run_all_file;
                print "        echo \"Group " padded_num " processed successfully.\"" >> run_all_file;
                print "        success_count=$((success_count + 1))" >> run_all_file;
                print "    fi" >> run_all_file;
            print "else" >> run_all_file
                 print "    echo \"# Skipping Group " padded_num ": Script file '$script_file' not found.\"" >> run_all_file;
            print "fi" >> run_all_file;
            print "" >> run_all_file;
        }

        print "echo \"----------------------------------------\"" >> run_all_file;
        print "echo \"Flambient processing complete.\"" >> run_all_file;
        print "echo \"Summary: $success_count groups succeeded, $error_count groups failed.\"" >> run_all_file
        print "echo \"Output images saved to: " flambient_dir "\"" >> run_all_file;

        system("chmod +x \"" run_all_file "\"");
        print "Created master run script: " run_all_file > "/dev/stderr";
    }
    ' "$input_csv" # End of AWK script execution

  if [ $? -ne 0 ]; then
    echo "Error: AWK script for generating .mgk files failed."
    return 1
  fi

  echo "ImageMagick script generation completed."
  echo "Output images will potentially be saved to '$FLAMBIENT_DIR' upon execution."
  echo "To run all scripts, execute: \"$SCRIPTS_DIR/run_all_scripts.sh\""
  return 0 # Success
}

# Function to move processed images to an "exposure" directory
move_processed_images() {
  local metadata_file="$METADATA_DIR/metadata_final.csv"
  local exposure_dir="$EXPOSURE_DIR"
  if [ ! -f "$metadata_file" ]; then
    print -r -- "Warning: Meta CSV not found. Cannot move." >&2
    return 1
  fi
  print -r -- "Moving original exposures to: $exposure_dir"
  if ! mkdir -p "$exposure_dir"; then
    print -r -- "Error creating '$exposure_dir'." >&2
    return 1
  fi

  local moved=0 errors=0 skipped=0
  tail -n +2 "$metadata_file" | cut -d, -f2 | while IFS= read -r filename; do
    filename=$(print -r -- "$filename" | sed 's/^[[:space:]"]*//;s/[[:space:]"]*$//')
    if [[ -z "$filename" ]]; then continue; fi
    local source="$IMG_PATH/$filename"
    local dest="$exposure_dir/$filename"
    if [ -f "$source" ]; then
      if mv -n -- "$source" "$dest"; then
        moved=$((moved + 1))
      else
        if [ -e "$dest" ]; then
          print -r -- "Skip move (exists): '$filename'" >&2
          skipped=$((skipped + 1))
        else
          print -r -- "Error moving: '$filename'" >&2
          errors=$((errors + 1))
        fi
      fi
    else print -r -- "Warning: Src not found for move: '$source'" >&2; fi
  done
  print -r -- "Finished moving exposures. Moved: $moved, Skipped: $skipped, Errors: $errors"

  # Handle moving darkened export files to temp directory
  if [ "$ENABLE_DARKEN_EXPORT" = "true" ] && [ -n "$DARKEN_SUFFIX" ]; then
    print -r -- "Moving darkened temporary files to: $TEMP_DIR"
    if ! mkdir -p "$TEMP_DIR"; then
      print -r -- "Error creating '$TEMP_DIR'." >&2
      return 1
    fi

    local darkened_moved=0 darkened_errors=0 darkened_skipped=0
    # Find all files in FLAMBIENT_DIR with the DARKEN_SUFFIX pattern
    find "$FLAMBIENT_DIR" -type f -name "*${DARKEN_SUFFIX}.jpg" | while IFS= read -r darkfile; do
      local darkfilename=$(basename "$darkfile")
      local dark_dest="$TEMP_DIR/$darkfilename"

      if [ -f "$darkfile" ]; then
        if mv -n -- "$darkfile" "$dark_dest"; then
          darkened_moved=$((darkened_moved + 1))
          print -r -- "Moved darkened file: $darkfilename"
        else
          if [ -e "$dark_dest" ]; then
            print -r -- "Skip move (exists): '$darkfilename'" >&2
            darkened_skipped=$((darkened_skipped + 1))
          else
            print -r -- "Error moving darkened file: '$darkfilename'" >&2
            darkened_errors=$((darkened_errors + 1))
          fi
        fi
      fi
    done

    print -r -- "Finished moving darkened files. Moved: $darkened_moved, Skipped: $darkened_skipped, Errors: $darkened_errors"
  else
    print -r -- "Darkened file processing skipped (disabled or no suffix defined)"
  fi

  if [ "$errors" -gt 0 ] || [ "$darkened_errors" -gt 0 ]; then return 1; fi
  return 0
}

# --- Main Execution ---
check_dependencies
load_config "$@"

main() {
  if ! setupEnvironment; then return 1; fi
  if ! extract_metadata; then return 1; fi
  local run_all_script="$SCRIPTS_DIR/run_all_scripts.sh"
  if [ -f "$run_all_script" ]; then
    print -r -- "----------------------------------------"
    print -r -- "Executing: $run_all_script"
    print -r -- "----------------------------------------"
    if "$run_all_script"; then
      print -r -- "----------------------------------------"
      print -r -- "Master script OK."
      print -r -- "----------------------------------------"
      if ! move_processed_images; then print -r -- "Warning: Issues moving processed images." >&2; fi
      return 0
    else
      local st=$?
      print -r -- "----------------------------------------" >&2
      print -r -- "Error: Master script failed (status: $st)." >&2
      print -r -- "----------------------------------------" >&2
      return 1
    fi
  else
    print -r -- "Error: Master script '$run_all_script' not found." >&2
    return 1
  fi
}

if main "$@"; then
  print -r -- ""
  print -r -- "Script finished successfully."
  exit 0
else
  print -r -- "" >&2
  print -r -- "Script finished with errors." >&2
  exit 1
fi

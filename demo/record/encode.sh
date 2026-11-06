#!/usr/bin/env bash
# encode.sh in.webm out.mp4 [start-seconds]
# H.264, yuv420p, faststart, no metadata or encoder tags.
set -eu
in="$1"; out="$2"; ss="${3:-0}"
ffmpeg -y -v error -ss "$ss" -i "$in" -an \
  -c:v libx264 -preset slow -crf "${CRF:-24}" -pix_fmt yuv420p -r 25 \
  -x264-params no-info=1 -map_metadata -1 -fflags +bitexact -flags:v +bitexact \
  -movflags +faststart "$out"

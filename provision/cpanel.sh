#!/bin/bash
# cPanel API helpers for the Namecheap Stellar account (token auth, no password).
# Requires env: CP_USER, CP_HOST (serverXXX.web-hosting.com), CP_TOKEN.
# Usage: source provision/cpanel.sh ; uapi DomainInfo list_domains
: "${CP_USER:?}" "${CP_HOST:?}" "${CP_TOKEN:?}"
CP_BASE="https://$CP_HOST:2083"
CP_AUTH="Authorization: cpanel $CP_USER:$CP_TOKEN"

_cp_form() { # key=value pairs -> curl --data-urlencode args (values may contain spaces)
	local kv; CP_ARGS=()
	for kv in "$@"; do CP_ARGS+=(--data-urlencode "$kv"); done
}

# UAPI: uapi Module function [key=value ...]
uapi() {
	local mod=$1 fn=$2; shift 2; _cp_form "$@"
	curl -sS -m 300 -H "$CP_AUTH" "${CP_ARGS[@]}" "$CP_BASE/execute/$mod/$fn"
}

# API2 (still needed for addon domains and archive extraction): api2 Module function [key=value ...]
api2() {
	local mod=$1 fn=$2; shift 2
	_cp_form "cpanel_jsonapi_user=$CP_USER" cpanel_jsonapi_apiversion=2 "cpanel_jsonapi_module=$mod" "cpanel_jsonapi_func=$fn" "$@"
	curl -sS -m 600 -H "$CP_AUTH" "${CP_ARGS[@]}" "$CP_BASE/json-api/cpanel"
}

# Upload one local file into a remote directory (overwrites).
cp_upload() {
	curl -sS -m 1800 -H "$CP_AUTH" -F "dir=$2" -F "overwrite=1" -F "file-1=@$1" "$CP_BASE/execute/Fileman/upload_files"
}

# Extract an archive that is already on the server into a directory.
cp_extract() {
	api2 Fileman fileop op=extract "sourcefiles=$1" "destfiles=$2" doubledecode=1
}

# Write a local text file to the server as dir/name.
cp_write() {
	curl -sS -m 120 -H "$CP_AUTH" --data-urlencode "dir=$1" --data-urlencode "file=$2" --data-urlencode "content@$3" \
		"$CP_BASE/execute/Fileman/save_file_content"
}

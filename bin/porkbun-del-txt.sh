#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${PORKBUN_API_KEY:-}" || -z "${PORKBUN_API_SECRET:-}" ]]; then
  jq -n --arg error "Missing PORKBUN_API_KEY or PORKBUN_API_SECRET" '{error:$error}'
  exit 1
fi

domain="${1:?domain required}"
name="${2:?subdomain required}"

jq -n --arg action "del_txt" --arg domain "$domain" --arg name "$name" '{action:$action, domain:$domain, name:$name}'

payload=$(jq -n \
  --arg apikey "$PORKBUN_API_KEY" \
  --arg secretapikey "$PORKBUN_API_SECRET" \
  '{apikey:$apikey, secretapikey:$secretapikey}')

if ! response=$(curl -s -X POST "https://api.porkbun.com/api/json/v3/dns/deleteByNameType/${domain}/TXT/${name}" \
  -H "Content-Type: application/json" \
  --data "$payload"); then
  curl_status=$?
  printf 'curl failed with status %s\n' "$curl_status" >&2
  exit "$curl_status"
fi

status=$(printf '%s' "$response" | jq -r '.status')
if [[ "$status" != "SUCCESS" ]]; then
  message=$(printf '%s' "$response" | jq -r '.message // empty')
  printf 'API error: %s\n' "$message" >&2
  exit 1
fi

printf '%s' "$response" | jq --arg action "response" '{action:$action} + .'

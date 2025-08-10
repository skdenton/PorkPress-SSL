#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${PORKBUN_API_KEY:-}" || -z "${PORKBUN_API_SECRET:-}" ]]; then
  jq -n --arg error "Missing PORKBUN_API_KEY or PORKBUN_API_SECRET" '{error:$error}'
  exit 1
fi

domain="${1:?domain required}"
name="${2:?subdomain required}"
content="${3:?content required}"
ttl="${4:-600}"

jq -n --arg action "add_txt" --arg domain "$domain" --arg name "$name" --arg ttl "$ttl" '{action:$action, domain:$domain, name:$name, ttl:$ttl}'

payload=$(jq -n \
  --arg apikey "$PORKBUN_API_KEY" \
  --arg secretapikey "$PORKBUN_API_SECRET" \
  --arg name "$name" \
  --arg content "$content" \
  --arg ttl "$ttl" \
  '{apikey:$apikey, secretapikey:$secretapikey, name:$name, type:"TXT", content:$content, ttl:$ttl}')

if ! response=$(curl -s -X POST "https://api.porkbun.com/api/json/v3/dns/create/${domain}" \
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

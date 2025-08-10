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

response=$(curl -s -X POST "https://api.porkbun.com/api/json/v3/dns/deleteByNameType/$domain/TXT/$name" \
  -H "Content-Type: application/json" \
  --data "$payload")

echo "$response" | jq --arg action "response" '{action:$action} + .'

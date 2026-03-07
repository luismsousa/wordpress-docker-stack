variable "cloudflare_zone_id" {
  type        = string
  description = "The Cloudflare zone ID."
  default     = "your-cloudflare-zone-id"
}

variable "domain" {
  type        = string
  description = "Primary apex domain."
  default     = "example.com"
}

variable "apex_ipv4" {
  type        = string
  description = "Origin IPv4 for apex A record."
  default     = "203.0.113.1"
}

# Apex DNS record (already exists in Cloudflare; import before apply).
resource "cloudflare_dns_record" "root" {
  zone_id = var.cloudflare_zone_id
  name    = "@"
  type    = "A"
  content = var.apex_ipv4
  proxied = true
  ttl     = 1
}

# www -> apex DNS CNAME (already exists in Cloudflare; import before apply).
resource "cloudflare_dns_record" "www" {
  zone_id = var.cloudflare_zone_id
  name    = "www"
  type    = "CNAME"
  content = var.domain
  proxied = true
  ttl     = 1
}

# img subdomain -> apex (Traefik serves imgproxy on same origin IP).
resource "cloudflare_dns_record" "img" {
  zone_id = var.cloudflare_zone_id
  name    = "img"
  type    = "CNAME"
  content = var.domain
  proxied = true
  ttl     = 1
}

# Existing zone-level dynamic redirect ruleset is imported and managed here.
resource "cloudflare_ruleset" "canonical_redirects" {
  zone_id     = var.cloudflare_zone_id
  name        = "Default Redirect Rules"
  description = ""
  kind        = "zone"
  phase       = "http_request_dynamic_redirect"

  rules = [
    {
      ref         = "your-redirect-rule-ref"
      description = "Canonical www to apex"
      expression  = "(http.host eq \"www.example.com\")"
      enabled     = true
      action      = "redirect"

      action_parameters = {
        from_value = {
          status_code = 301
          target_url = {
            expression = "concat(\"https://example.com\", http.request.uri)"
          }
          preserve_query_string = true
        }
      }
    }
  ]
}

# Cache transformed image responses served from imgproxy host.
resource "cloudflare_ruleset" "imgproxy_cache_settings" {
  zone_id     = var.cloudflare_zone_id
  name        = "Imgproxy Cache Settings"
  description = "Cache policy for transformed images on img subdomain"
  kind        = "zone"
  phase       = "http_request_cache_settings"

  rules = [
    {
      ref         = "imgproxy_cache_transforms"
      description = "Cache transformed image responses for 30 days"
      expression  = "(http.host eq \"img.example.com\" and (ends_with(lower(http.request.uri.path), \".avif\") or ends_with(lower(http.request.uri.path), \".gif\") or ends_with(lower(http.request.uri.path), \".jpg\") or ends_with(lower(http.request.uri.path), \".jpeg\") or ends_with(lower(http.request.uri.path), \".png\") or ends_with(lower(http.request.uri.path), \".webp\")))"
      enabled     = true
      action      = "set_cache_settings"

      action_parameters = {
        cache = true
        edge_ttl = {
          mode    = "override_origin"
          default = 2592000
        }
      }
    },
    {
      ref         = "imgproxy_bypass_non_images"
      description = "Bypass cache for non-image endpoints on img host"
      expression  = "(http.host eq \"img.example.com\" and not (ends_with(lower(http.request.uri.path), \".avif\") or ends_with(lower(http.request.uri.path), \".gif\") or ends_with(lower(http.request.uri.path), \".jpg\") or ends_with(lower(http.request.uri.path), \".jpeg\") or ends_with(lower(http.request.uri.path), \".png\") or ends_with(lower(http.request.uri.path), \".webp\")))"
      enabled     = true
      action      = "set_cache_settings"

      action_parameters = {
        cache = false
      }
    }
  ]
}
# SSL Certificate Setup

## Prerequisites

Before setting up SSL, ensure DNS is configured:

1. **Point `am.1pwrafrica.com` to EC2 instance IP: `16.28.64.221`**
   - If using Route 53: Create A record
   - If using external DNS: Update A record
   - Also create A record for `www.am.1pwrafrica.com` → `16.28.64.221`

2. **Verify DNS propagation:**
   ```bash
   nslookup am.1pwrafrica.com
   # Should return: 16.28.64.221
   ```

## SSL Certificate Installation

Once DNS is configured, run:

```bash
# SSH into the server
ssh -i 1pwrAM.pem ec2-user@16.28.64.221

# Request SSL certificate
sudo certbot --apache \
  -d am.1pwrafrica.com \
  -d www.am.1pwrafrica.com \
  --non-interactive \
  --agree-tos \
  --email mso@1pwrafrica.com \
  --redirect
```

This will:
- Request a Let's Encrypt SSL certificate
- Automatically configure Apache for HTTPS
- Set up automatic HTTP to HTTPS redirect
- Configure auto-renewal

## Verify SSL

After installation, test:
```bash
curl -I https://am.1pwrafrica.com
```

## Auto-Renewal

Certbot automatically sets up renewal. Verify it's enabled:
```bash
sudo systemctl status certbot-renew.timer
sudo systemctl enable certbot-renew.timer
```

Certificates renew automatically 30 days before expiration.

## Manual Renewal (if needed)

```bash
sudo certbot renew
sudo systemctl reload httpd
```

## Troubleshooting

### DNS Not Resolving
- Wait for DNS propagation (can take up to 48 hours, usually < 1 hour)
- Verify DNS records are correct
- Check security group allows HTTPS (443) from internet

### Certificate Request Fails
- Ensure domain points to correct IP
- Check Apache is accessible from internet
- Verify port 80 is open (required for Let's Encrypt validation)

### SSL Configuration Error
If you see errors about missing certificate files, the SSL config has been fixed. Just ensure DNS is configured and retry.

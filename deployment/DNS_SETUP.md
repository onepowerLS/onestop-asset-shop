# DNS Configuration Guide

## Current Status

- **EC2 Instance IP**: `16.28.64.221`
- **Domain**: `am.1pwrafrica.com`
- **DNS Status**: ⚠️ Not configured yet

## Setup Instructions

### Option 1: AWS Route 53 (Recommended)

If `1pwrafrica.com` is managed in Route 53:

1. **Go to Route 53 Console**
   - Navigate to: https://console.aws.amazon.com/route53/
   - Select hosted zone for `1pwrafrica.com`

2. **Create A Record**
   - Click "Create record"
   - **Record name**: `am`
   - **Record type**: `A`
   - **Value**: `16.28.64.221`
   - **TTL**: `300` (5 minutes) or `3600` (1 hour)
   - Click "Create record"

3. **Create www Subdomain (Optional)**
   - Click "Create record"
   - **Record name**: `www.am`
   - **Record type**: `A`
   - **Value**: `16.28.64.221`
   - **TTL**: `300` or `3600`
   - Click "Create record"

### Option 2: External DNS Provider

If `1pwrafrica.com` is managed elsewhere (cPanel, GoDaddy, etc.):

1. **Log into your DNS provider**
2. **Add A Record:**
   - **Host/Name**: `am`
   - **Type**: `A`
   - **Value/Points to**: `16.28.64.221`
   - **TTL**: `3600` (1 hour)

3. **Add www Subdomain (Optional):**
   - **Host/Name**: `www.am`
   - **Type**: `A`
   - **Value/Points to**: `16.28.64.221`
   - **TTL**: `3600`

## Verify DNS

After creating the records, verify propagation:

```bash
# Windows PowerShell
nslookup am.1pwrafrica.com

# Linux/Mac
dig am.1pwrafrica.com
# or
nslookup am.1pwrafrica.com
```

Expected output should show:
```
Name:    am.1pwrafrica.com
Address: 16.28.64.221
```

## DNS Propagation Time

- **Typical**: 5-30 minutes
- **Maximum**: Up to 48 hours (rare)
- **Route 53**: Usually < 5 minutes

## After DNS is Configured

Once DNS is working:

1. **Test HTTP access:**
   ```bash
   curl -I http://am.1pwrafrica.com
   ```

2. **Set up SSL certificate** (see `SSL_SETUP.md`)

3. **Test HTTPS access:**
   ```bash
   curl -I https://am.1pwrafrica.com
   ```

## Important Notes

- The EC2 instance uses a **dynamic IP** by default
- If the instance is stopped/started, the IP may change
- Consider using an **Elastic IP** for production:
  1. Go to EC2 → Elastic IPs
  2. Allocate Elastic IP
  3. Associate with your instance
  4. Update DNS to point to the Elastic IP

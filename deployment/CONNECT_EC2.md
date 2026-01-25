# Connect to EC2 Instance

## Quick Connect Command

Open PowerShell and run:

```powershell
cd C:\Users\it\Downloads
ssh -i 1pwrAM.pem ec2-user@16.28.64.221
```

## First Time Connection

On first connection, you'll see:
```
The authenticity of host '16.28.64.221' can't be established.
ECDSA key fingerprint is SHA256:...
Are you sure you want to continue connecting (yes/no/[fingerprint])?
```

Type `yes` and press Enter.

## If Connection Fails

1. **Check key file permissions** (Windows usually handles this automatically)
2. **Verify instance is running** in AWS Console
3. **Check security group** allows SSH (port 22) from your IP
4. **Try with verbose output**:
   ```powershell
   ssh -v -i 1pwrAM.pem ec2-user@16.28.64.221
   ```

## Once Connected

You should see:
```
[ec2-user@ip-172-31-2-97 ~]$
```

Then proceed with the setup commands from `QUICK_SETUP.md`.
